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
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->visible())
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        $activeCategory = $request->filled('category')
            ? $categories->firstWhere('slug', $request->string('category')->toString())
            : null;

        $search = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->visible()
            ->with('category')
            ->withCount('approvedReviews')
            ->withAvg('approvedReviews', 'rating')
            ->when($activeCategory, fn ($q) => $q->where('category_id', $activeCategory->id))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_').'%'))
            ->orderByDesc('is_featured')
            ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
            ->orderBy('sort')
            ->orderByDesc('sold_count')
            ->paginate(24)
            ->withQueryString();

        return view('store.home', compact('categories', 'activeCategory', 'products', 'search'));
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
