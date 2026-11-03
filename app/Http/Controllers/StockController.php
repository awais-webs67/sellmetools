<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
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

        $reseller = $request->filled('reseller')
            ? User::query()->where('is_reseller', true)->with('resellerProductPrices')->find((int) $request->query('reseller'))
            : null;

        $stock = Product::query()
            ->whereIn('id', $ids)
            ->get(['id', 'available_stock', 'stock_unlimited', 'is_active', 'is_free', 'price'])
            ->mapWithKeys(function (Product $p) use ($reseller) {
                $isFree = $p->isFree($reseller);
                $effectivePrice = $p->priceFor($reseller);

                return [$p->id => [
                    'qty' => $p->is_active ? $p->available_stock : 0,
                    'unlimited' => $p->is_active && $p->stock_unlimited,
                    'in_stock' => $p->is_active && $p->inStock(),
                    'price' => (float) $effectivePrice,
                    'price_formatted' => $isFree ? 'FREE' : money($effectivePrice),
                ]];
            });

        if (! app()->runningUnitTests()) {
            self::deferBackgroundSync();
        }

        return response()
            ->json(['items' => $stock, 'need_sync' => false])
            ->header('Cache-Control', 'no-store')
            ->header('Session-Driver', 'none');
    }

    public static function deferBackgroundSync(): void
    {
        if (app()->runningUnitTests() || ! Cache::add('auto_supplier_sync_tick', 1, 45)) {
            return;
        }

        defer(function (): void {
            self::executeSync();
        });
    }

    protected static function executeSync(): void
    {
        $lock = Cache::lock('auto_supplier_sync_running', 25);
        if (! $lock->get()) {
            return;
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

            try {
                app(\App\Services\DatabaseBackupService::class)->checkAndRunScheduledBackup();
            } catch (Throwable $e) {
                report($e);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Separate stateless endpoint for manual/forced supplier sync so customer clicks
     * and session locks are NEVER blocked while waiting for external supplier APIs.
     */
    public function sync(Request $request): JsonResponse
    {
        if (app()->runningUnitTests()) {
            return response()->json(['synced' => false]);
        }

        $force = $request->boolean('force');
        if (! $force && ! Cache::add('auto_supplier_sync_tick', 1, 45)) {
            return response()->json(['synced' => false]);
        }
        if ($force) {
            Cache::put('auto_supplier_sync_tick', 1, 45);
            self::executeSync();
        } else {
            defer(fn () => self::executeSync());
        }

        return response()->json(['synced' => true, 'at' => now()->toIso8601String()]);
    }
}
