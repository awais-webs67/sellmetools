<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\SupplierSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Lightweight endpoint polled by the storefront for live stock updates & background supplier sync. */
class StockController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->triggerBackgroundSyncIfNeeded();

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

        return response()->json($stock)->header('Cache-Control', 'no-store');
    }

    /**
     * Automatically syncs active suppliers in the background at most once every 10 seconds
     * after the HTTP response is sent, so live hosting stays in sync even without a cron job.
     */
    protected function triggerBackgroundSyncIfNeeded(): void
    {
        if (app()->runningUnitTests() || ! Cache::add('auto_supplier_sync_tick', 1, 10)) {
            return;
        }

        dispatch(function () {
            $lock = Cache::lock('auto_supplier_sync_running', 25);
            if (! $lock->get()) {
                return;
            }

            try {
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
        })->afterResponse();
    }
}
