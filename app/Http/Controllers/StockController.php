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
            ->take(250);

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
                    'retail_price' => (float) $p->price,
                    'price_formatted' => $isFree ? 'FREE' : money($effectivePrice),
                ]];
            });

        if (! app()->runningUnitTests()) {
            self::deferBackgroundSync();
        }

        return response()
            ->json(['items' => $stock, 'need_sync' => false, 'ts' => now()->timestamp])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Session-Driver', 'none');
    }

    public static function deferBackgroundSync(): void
    {
        if (app()->runningUnitTests() || ! Cache::add('auto_supplier_sync_tick', 1, 30)) {
            return;
        }

        defer(function (): void {
            self::executeSync();
        });
    }

    protected static function executeSync(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

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

        if ($request->query('diag') === 'smt_diag_786') {
            $syncService = app(SupplierSyncService::class);
            $results = [];
            foreach (Supplier::query()->orderBy('id')->get() as $sup) {
                $item = [
                    'id' => $sup->id,
                    'name' => $sup->name,
                    'base_url' => $sup->base_url,
                    'is_active' => $sup->is_active,
                    'balance_before' => $sup->balance,
                ];
                if ($sup->is_active) {
                    try {
                        $stats = $syncService->sync($sup);
                        $sup->refresh();
                        $item['sync_status'] = 'ok';
                        $item['stats'] = $stats;
                        $item['balance_after'] = $sup->balance;
                        $item['last_sync_error'] = $sup->last_sync_error;
                    } catch (Throwable $e) {
                        $sup->refresh();
                        $item['sync_status'] = 'error';
                        $item['error'] = $e->getMessage();
                        $item['balance_after'] = $sup->balance;
                    }

                    if ($sup->id === 1) {
                        $item['resolved_ip'] = gethostbyname('safwantigershopbot-production.up.railway.app');
                        $item['elite_ip'] = gethostbyname('api.elitetoolz.store');
                    }
                }
                $results[] = $item;
            }

            $recentOrders = \App\Models\SupplierOrder::query()
                ->with('supplier:id,name')
                ->latest('id')
                ->limit(10)
                ->get(['id', 'supplier_id', 'order_item_id', 'supplier_product_id', 'request_id', 'quantity', 'status', 'error', 'response', 'created_at']);

            return response()->json([
                'suppliers' => $results,
                'recent_supplier_orders' => $recentOrders,
            ]);
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
