<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class ProductController extends Controller
{
    private const CARD_COLUMNS = [
        'id', 'category_id', 'name', 'slug', 'short_description', 'image',
        'price', 'compare_at_price', 'available_stock', 'stock_unlimited',
        'is_active', 'is_featured', 'is_free', 'sold_count',
    ];

    public function index(Request $request): View
    {
        if ($request->user()?->is_reseller) {
            $request->user()->loadMissing('resellerProductPrices');
        }

        $categories = $this->activeCategories();

        $activeCategory = $request->filled('category')
            ? $categories->firstWhere('slug', $request->string('category')->toString())
            : null;

        $search = trim((string) $request->query('q', ''));
        $seed = $request->integer('seed') ?: random_int(1000, 999999);

        $products = $this->catalogQuery($activeCategory, $search, 'random', false, $seed)
            ->paginate($request->boolean('all') ? 500 : 12)
            ->withQueryString()
            ->appends(['seed' => $seed]);

        return view('store.home', compact('categories', 'activeCategory', 'products', 'search'));
    }

    public function shop(Request $request): View
    {
        if ($request->user()?->is_reseller) {
            $request->user()->loadMissing('resellerProductPrices');
        }

        $categories = $this->activeCategories();

        $activeCategory = $request->filled('category')
            ? $categories->firstWhere('slug', $request->string('category')->toString())
            : null;

        $search = trim((string) $request->query('q', ''));
        $sort = $request->string('sort', 'featured')->toString();
        $inStockOnly = $request->boolean('in_stock');
        $seed = $request->integer('seed') ?: random_int(1000, 999999);

        $products = $this->catalogQuery($activeCategory, $search, $sort, $inStockOnly, $seed)
            ->paginate($request->boolean('all') ? 500 : 24)
            ->withQueryString()
            ->appends(['seed' => $seed]);

        return view('store.shop', compact('categories', 'activeCategory', 'products', 'search', 'sort', 'inStockOnly'));
    }

    public function category(Request $request, Category $category): View
    {
        abort_unless($category->is_active, 404);

        if ($request->user()?->is_reseller) {
            $request->user()->loadMissing('resellerProductPrices');
        }

        $categories = $this->activeCategories();

        $activeCategory = $category;
        $search = trim((string) $request->query('q', ''));
        $seed = $request->integer('seed') ?: random_int(1000, 999999);

        $products = $this->catalogQuery($activeCategory, $search, 'random', false, $seed)
            ->paginate($request->boolean('all') ? 500 : 12)
            ->withQueryString()
            ->appends(['seed' => $seed]);

        return view('store.home', compact('categories', 'activeCategory', 'products', 'search'));
    }

    private function activeCategories()
    {
        return Cache::remember('smt_active_categories_v2', 120, fn () => Category::query()
            ->where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->visible()])
            ->orderBy('sort')
            ->orderBy('name')
            ->get());
    }

    private function catalogQuery(?Category $activeCategory, string $search, string $sort = 'featured', bool $inStockOnly = false, ?int $seed = null)
    {
        $tokens = collect(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $t) => addcslashes($t, '%_'))
            ->filter()
            ->values();

        $query = Product::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->with('category:id,name,slug')
            ->withCount('approvedReviews')
            ->withAvg('approvedReviews', 'rating')
            ->when($activeCategory, fn ($q) => $q->where('category_id', $activeCategory->id))
            ->when($inStockOnly, fn ($q) => $q->where(fn ($sub) => $sub->where('available_stock', '>', 0)->orWhere('stock_unlimited', true)))
            ->when($tokens->isNotEmpty(), function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $q->where(fn ($sub) => $sub
                        ->where('name', 'like', "%{$token}%")
                        ->orWhere('short_description', 'like', "%{$token}%"));
                }
            })
            // Always push out-of-stock (0 stock) products to the very end
            ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END');

        return match ($sort) {
            'price_asc' => $query
                ->orderBy('price')
                ->orderBy('id'),
            'price_desc' => $query
                ->orderByDesc('price')
                ->orderBy('id'),
            'popular' => $query
                ->orderByDesc('sold_count')
                ->orderByDesc('is_featured'),
            'newest' => $query
                ->orderByDesc('id'),
            default => $query
                ->inRandomOrder($seed ?: ''),
        };
    }

    public function show(Request $request, Product $product): View
    {
        abort_unless($product->is_active, 404);

        if ($request->user()?->is_reseller) {
            $request->user()->loadMissing('resellerProductPrices');
        }

        $product->load(['category', 'approvedReviews'])->loadCount('approvedReviews')->loadAvg('approvedReviews', 'rating');

        $related = Product::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->whereKeyNot($product->id)
            ->with('category:id,name,slug')
            ->withCount('approvedReviews')
            ->withAvg('approvedReviews', 'rating')
            ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
            ->inRandomOrder()
            ->limit(4)
            ->get();

        return view('store.product', compact('product', 'related'));
    }

    public function storeReview(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:80'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'min:3', 'max:600'],
        ]);

        Review::create([
            'product_id' => $product->id,
            'user_id' => $request->user()?->id,
            'customer_name' => $data['customer_name'],
            'rating' => (int) $data['rating'],
            'comment' => $data['comment'],
            'is_approved' => true,
        ]);

        return redirect()->to(route('products.show', $product).'#reviews')->with('success', 'Thank you for your review!');
    }
}
