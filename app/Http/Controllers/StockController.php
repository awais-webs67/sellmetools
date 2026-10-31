<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lightweight endpoint polled by the storefront for live stock updates. */
class StockController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(60);

        $stock = Product::query()
            ->whereIn('id', $ids)
            ->get(['id', 'available_stock', 'stock_unlimited', 'is_active', 'price'])
            ->mapWithKeys(fn (Product $p) => [$p->id => [
                'qty' => $p->is_active ? $p->available_stock : 0,
                'unlimited' => $p->is_active && $p->stock_unlimited,
                'in_stock' => $p->is_active && $p->inStock(),
                'price' => (float) $p->price,
            ]]);

        return response()->json($stock)->header('Cache-Control', 'no-store');
    }
}
