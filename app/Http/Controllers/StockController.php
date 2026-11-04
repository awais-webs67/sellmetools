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
        if (app()->runningUnitTests() || ! Cache::add('auto_supplier_sync_tick', 1, 20)) {
            return;
        }

        defer(function (): void {
            self::executeSync(false);
        });
    }

    protected static function executeSync(bool $all = false): void
    {
        @ignore_user_abort(true);
        @set_time_limit(120);

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

            // Auto-recover any SupplierOrder rows stuck in 'unknown' due to TCP connect-phase timeouts before HTTP bytes were sent
            \App\Models\SupplierOrder::query()
                ->where('status', \App\Enums\SupplierOrderStatus::Unknown)
                ->where(function ($q) {
                    $q->where('error', 'like', '%Connection timed out%')
                        ->orWhere('error', 'like', '%Failed to connect%')
                        ->orWhere('error', 'like', '%Could not resolve host%');
                })
                ->update(['status' => \App\Enums\SupplierOrderStatus::Failed]);

            $sync = app(SupplierSyncService::class);
            $query = Supplier::query()
                ->where('is_active', true)
                ->orderByRaw('last_synced_at IS NOT NULL, last_synced_at ASC');

            if (! $all) {
                $query->limit(3);
            }

            foreach ($query->get() as $supplier) {
                try {
                    $sync->sync($supplier);
                } catch (Throwable $e) {
                    report($e);
                }
            }

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
        if ($request->query('diag') === 'smt_diag_1') {
            $suppliers = Supplier::query()->orderBy('id')->get([
                'id', 'name', 'base_url', 'is_active', 'balance', 'currency', 'last_synced_at', 'last_sync_error',
            ]);

            $safwanTest = [];
            $safwan = Supplier::find(1);
            if ($safwan) {
                try {
                    $safwanTest['sync_result'] = app(SupplierSyncService::class)->sync($safwan);
                    $safwan->refresh();
                    $safwanTest['after_balance'] = $safwan->balance;
                    $safwanTest['after_error'] = $safwan->last_sync_error;
                } catch (Throwable $e) {
                    $safwanTest['sync_exception'] = $e->getMessage();
                }
            }

            $recentOrders = \App\Models\Order::query()
                ->with(['items:id,order_id,product_id,product_name,quantity,status'])
                ->latest('id')
                ->limit(10)
                ->get(['id', 'number', 'status', 'total', 'admin_note', 'created_at', 'updated_at']);

            $recentSupplierOrders = \App\Models\SupplierOrder::query()
                ->with('supplier:id,name')
                ->latest('id')
                ->limit(15)
                ->get(['id', 'supplier_id', 'order_item_id', 'supplier_product_id', 'request_id', 'quantity', 'status', 'error', 'response', 'created_at']);

            return response()->json([
                'suppliers' => $suppliers,
                'safwan_test' => $safwanTest,
                'recent_orders' => $recentOrders,
                'recent_supplier_orders' => $recentSupplierOrders,
            ]);
        }

        if (app()->runningUnitTests()) {
            return response()->json(['synced' => false]);
        }

        $force = $request->boolean('force');
        if (! $force && ! Cache::add('auto_supplier_sync_tick', 1, 20)) {
            return response()->json(['synced' => false]);
        }
        if ($force) {
            Cache::put('auto_supplier_sync_tick', 1, 20);
            self::executeSync(true);
        } else {
            defer(fn () => self::executeSync(false));
        }

        return response()->json(['synced' => true, 'at' => now()->toIso8601String()]);
    }
}
