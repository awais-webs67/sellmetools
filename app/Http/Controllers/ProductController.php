<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $categories = $this->activeCategories();

        $activeCategory = $request->filled('category')
            ? $categories->firstWhere('slug', $request->string('category')->toString())
            : null;

        $search = trim((string) $request->query('q', ''));

        $products = $this->catalogQuery($activeCategory, $search)
            ->paginate($request->boolean('all') ? 500 : 12)
            ->withQueryString();

        return view('store.home', compact('categories', 'activeCategory', 'products', 'search'));
    }

    public function shop(Request $request): View
    {
        $categories = $this->activeCategories();

        $activeCategory = $request->filled('category')
            ? $categories->firstWhere('slug', $request->string('category')->toString())
            : null;

        $search = trim((string) $request->query('q', ''));
        $sort = $request->string('sort', 'featured')->toString();
        $inStockOnly = $request->boolean('in_stock');

        $products = $this->catalogQuery($activeCategory, $search, $sort, $inStockOnly)
            ->paginate($request->boolean('all') ? 500 : 24)
            ->withQueryString();

        return view('store.shop', compact('categories', 'activeCategory', 'products', 'search', 'sort', 'inStockOnly'));
    }

    public function category(Request $request, Category $category): View
    {
        abort_unless($category->is_active, 404);

        $categories = $this->activeCategories();

        $activeCategory = $category;
        $search = trim((string) $request->query('q', ''));

        $products = $this->catalogQuery($activeCategory, $search)
            ->paginate($request->boolean('all') ? 500 : 12)
            ->withQueryString();

        return view('store.home', compact('categories', 'activeCategory', 'products', 'search'));
    }

    private function activeCategories()
    {
        return Category::query()
            ->where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->visible()])
            ->orderBy('sort')
            ->orderBy('name')
            ->get();
    }

    private function catalogQuery(?Category $activeCategory, string $search, string $sort = 'featured', bool $inStockOnly = false)
    {
        $tokens = collect(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $t) => addcslashes($t, '%_'))
            ->filter()
            ->values();

        $query = Product::query()
            ->visible()
            ->with('category')
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
            });

        return match ($sort) {
            'price_asc' => $query
                ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
                ->orderBy('price')
                ->orderBy('id'),
            'price_desc' => $query
                ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
                ->orderByDesc('price')
                ->orderBy('id'),
            'popular' => $query
                ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
                ->orderByDesc('sold_count')
                ->orderByDesc('is_featured'),
            'newest' => $query
                ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
                ->orderByDesc('id'),
            default => $query
                ->orderByDesc('is_featured')
                ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
                ->orderBy('sort')
                ->orderByDesc('sold_count'),
        };
    }

    public function show(Product $product): View
    {
        abort_unless($product->is_active, 404);

        $product->load(['category', 'approvedReviews'])->loadCount('approvedReviews')->loadAvg('approvedReviews', 'rating');

        $related = Product::query()
            ->visible()
            ->whereKeyNot($product->id)
            ->withCount('approvedReviews')
            ->withAvg('approvedReviews', 'rating')
            ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->orderByDesc('sold_count')
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
