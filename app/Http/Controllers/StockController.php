<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\OrderService;
use App\Services\SupplierSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

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
                'price_formatted' => money($p->price),
            ]]);

        return response()
            ->json(['items' => $stock, 'need_sync' => ! Cache::has('auto_supplier_sync_tick')])
            ->header('Cache-Control', 'no-store')
            ->header('Session-Driver', 'none');
    }

    /**
     * Separate stateless endpoint for background supplier sync so customer clicks
     * and session locks are NEVER blocked while waiting for external supplier APIs.
     */
    public function sync(): JsonResponse
    {
        if (app()->runningUnitTests() || ! Cache::add('auto_supplier_sync_tick', 1, 8)) {
            return response()->json(['synced' => false]);
        }

        $lock = Cache::lock('auto_supplier_sync_running', 20);
        if (! $lock->get()) {
            return response()->json(['synced' => false]);
        }

        try {
            app(OrderService::class)->expireStale();

            $sync = app(SupplierSyncService::class);
            Supplier::query()->where('is_active', true)->each(function (Supplier $supplier) use ($sync) {
                try {
                    $sync->sync($supplier);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        } finally {
            $lock->release();
        }

        return response()->json(['synced' => true, 'at' => now()->toIso8601String()]);
    }
}
