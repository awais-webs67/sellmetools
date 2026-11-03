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
        if ($request->boolean('catalog')) {
            return response()->json(self::instantCatalog())->header('Cache-Control', 'public, max-age=120');
        }

        $term = Str::of((string) $request->query('q', ''))->squish()->limit(60, '')->lower()->toString();

        if (mb_strlen($term) < 1) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $payload = Cache::remember('search.suggest.'.md5($term), 60, fn () => $this->lookup($term));

        return response()->json($payload)->header('Cache-Control', 'public, max-age=30');
    }

    /**
     * Lightweight preloaded catalog index for 0ms client-side instant search across all search bars.
     *
     * @return array{products: array<int, array<string, mixed>>, categories: array<int, array<string, string>>}
     */
    public static function instantCatalog(): array
    {
        try {
            return Cache::remember('smt_search_catalog_v1', 86400, function (): array {
                $totalVisible = Product::query()->visible()->count();

                $products = Product::query()
                    ->visible()
                    ->with('category:id,name,slug')
                    ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
                    ->orderByDesc('is_featured')
                    ->orderByDesc('sold_count')
                    ->limit(150)
                    ->get(['id', 'name', 'slug', 'image', 'price', 'is_free', 'category_id', 'available_stock', 'stock_unlimited']);

                $categories = Category::query()
                    ->where('is_active', true)
                    ->orderBy('sort')
                    ->get(['name', 'slug']);

                return [
                    'has_more' => $totalVisible > 150,
                    'products' => $products->map(fn (Product $p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'url' => route('products.show', $p),
                        'image' => $p->imageUrl(),
                        'price' => $p->isFree() ? 'FREE' : money($p->price),
                        'price_raw' => $p->isFree() ? 0.0 : (float) $p->price,
                        'is_free' => $p->isFree(),
                        'category' => $p->category?->name,
                        'in_stock' => $p->inStock(),
                        'search' => mb_strtolower($p->name.' '.($p->category?->name ?? '')),
                    ])->all(),
                    'categories' => $categories->map(fn (Category $c) => [
                        'name' => $c->name,
                        'url' => route('categories.show', $c),
                        'search' => mb_strtolower($c->name),
                    ])->all(),
                ];
            });
        } catch (\Throwable) {
            return ['has_more' => true, 'products' => [], 'categories' => []];
        }
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
            ->get(['id', 'name', 'slug', 'image', 'price', 'is_free', 'category_id', 'available_stock', 'stock_unlimited']);

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
                'price' => $p->isFree() ? 'FREE' : money($p->price),
                'price_raw' => $p->isFree() ? 0.0 : (float) $p->price,
                'is_free' => $p->isFree(),
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
