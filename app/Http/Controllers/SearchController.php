<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Instant search suggestions shown under every storefront search box while the customer types.
 * Stateless and cached briefly, so it stays fast even with many keystrokes.
 */
class SearchController extends Controller
{
    private const LIMIT = 6;

    public function __invoke(Request $request): JsonResponse
    {
        $term = Str::of((string) $request->query('q', ''))->squish()->limit(60, '')->lower()->toString();

        if (mb_strlen($term) < 2) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $payload = Cache::remember('search.suggest.'.md5($term), 60, fn () => $this->lookup($term));

        return response()->json($payload)->header('Cache-Control', 'public, max-age=30');
    }

    /** @return array{products: array<int, array<string, mixed>>, categories: array<int, array<string, string>>} */
    private function lookup(string $term): array
    {
        $words = array_slice(array_filter(explode(' ', $term)), 0, 5);
        $escape = fn (string $w): string => '%'.addcslashes($w, '%_').'%';

        // Short words (e.g. "ai") match only at the start of a word, so "ai" finds "AI Tools" but not "Entertainment".
        $matches = function ($query, string $column, string $word) use ($escape) {
            if (mb_strlen($word) >= 3) {
                return $query->where($column, 'like', $escape($word));
            }
            $safe = addcslashes($word, '%_');

            return $query->where(fn ($q) => $q->where($column, 'like', $safe.'%')->orWhere($column, 'like', '% '.$safe.'%'));
        };

        $products = Product::query()
            ->visible()
            ->with('category:id,name,slug')
            ->where(function ($q) use ($words, $matches) {
                foreach ($words as $word) {
                    $q->where(fn ($w) => $w
                        ->where(fn ($n) => $matches($n, 'name', $word))
                        ->orWhereHas('category', fn ($c) => $matches($c, 'name', $word)));
                }
            })
            ->orderByRaw('CASE WHEN LOWER(name) LIKE ? THEN 0 ELSE 1 END', [addcslashes($term, '%_').'%'])
            ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
            ->orderByDesc('sold_count')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'slug', 'image', 'price', 'category_id', 'available_stock', 'stock_unlimited']);

        $categories = Category::query()
            ->where('is_active', true)
            ->where(fn ($q) => $matches($q, 'name', $term))
            ->orderBy('sort')
            ->limit(3)
            ->get(['name', 'slug']);

        return [
            'products' => $products->map(fn (Product $p) => [
                'name' => $p->name,
                'url' => route('products.show', $p),
                'image' => $p->imageUrl(),
                'price' => money($p->price),
                'price_raw' => (float) $p->price,
                'category' => $p->category?->name,
                'in_stock' => $p->inStock(),
            ])->all(),
            'categories' => $categories->map(fn (Category $c) => [
                'name' => $c->name,
                'url' => route('categories.show', $c),
            ])->all(),
        ];
    }
}
