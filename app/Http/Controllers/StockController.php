<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\FulfillmentService;
use App\Services\OrderService;
use App\Services\Suppliers\SupplierManager;
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

    public static function bridgeToken(): string
    {
        return hash_hmac('sha256', 'smt-supplier-bridge-v1', (string) config('app.key'));
    }

    public static function deferBackgroundSync(): void
    {
        if (app()->runningUnitTests() || ! Cache::add('auto_supplier_sync_tick', 1, 35)) {
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
            SupplierOrder::query()
                ->where('status', SupplierOrderStatus::Unknown)
                ->where(function ($q) {
                    $q->where('error', 'like', '%Connection timed out%')
                        ->orWhere('error', 'like', '%Failed to connect%')
                        ->orWhere('error', 'like', '%Could not resolve host%');
                })
                ->update(['status' => SupplierOrderStatus::Failed]);

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
                app(DatabaseBackupService::class)->checkAndRunScheduledBackup();
            } catch (Throwable $e) {
                report($e);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Separate stateless endpoint for manual/forced supplier sync and Admin Browser Bridge relay.
     */
    public function sync(Request $request): JsonResponse
    {
        $bridgeAction = (string) ($request->input('bridge_action') ?? $request->query('bridge_action', ''));
        if ($bridgeAction !== '') {
            $providedToken = (string) ($request->input('bridge_token') ?? $request->query('bridge_token', ''));
            $validToken = hash_equals(self::bridgeToken(), $providedToken);

            if (! $validToken) {
                return response()->json(['ok' => false, 'error' => 'Unauthorized bridge token.'], 403);
            }

            $manager = app(SupplierManager::class);
            $syncService = app(SupplierSyncService::class);
            $fulfillment = app(FulfillmentService::class);

            if ($bridgeAction === 'status') {
                $railwaySuppliers = [];
                foreach (Supplier::query()->where('is_active', true)->orderBy('id')->get() as $sup) {
                    $driver = $manager->driver($sup);
                    if (method_exists($driver, 'isRailwayHost') && $driver->isRailwayHost()) {
                        $needsSync = $sup->last_synced_at === null
                            || $sup->last_synced_at->lt(now()->subSeconds(40))
                            || $sup->last_sync_error !== null
                            || Cache::has('supplier_needs_bridge_sync_'.$sup->id);

                        $railwaySuppliers[] = [
                            'id' => $sup->id,
                            'name' => $sup->name,
                            'base_url' => method_exists($driver, 'getNormalizedBaseUrl') ? $driver->getNormalizedBaseUrl() : rtrim(trim((string) $sup->base_url), '/'),
                            'api_key' => trim((string) $sup->api_key),
                            'products_path' => method_exists($driver, 'getProductsEndpoint') ? $driver->getProductsEndpoint() : '/products',
                            'balance_path' => method_exists($driver, 'getBalanceEndpoint') ? $driver->getBalanceEndpoint() : '/balance',
                            'needs_sync' => $needsSync,
                            'last_sync_error' => $sup->last_sync_error,
                        ];
                    }
                }

                $reviewCount = Order::query()->where('status', OrderStatus::PaymentReview)->count();
                $attentionCount = Order::query()->where('status', OrderStatus::NeedsAttention)->count();
                $processingCount = Order::query()->where('status', OrderStatus::Processing)->count();
                $latestOrderId = (int) (Order::query()->max('id') ?? 0);
                $latestPaymentId = (int) (Payment::query()->max('id') ?? 0);

                $recentOrders = Order::query()
                    ->with(['items', 'paymentMethod', 'latestPayment'])
                    ->orderByRaw("CASE WHEN status IN ('payment_review', 'needs_attention', 'processing') THEN 0 ELSE 1 END ASC")
                    ->latest('id')
                    ->limit(12)
                    ->get()
                    ->map(fn (Order $o): array => [
                        'id' => $o->id,
                        'number' => $o->number,
                        'status' => $o->status->value,
                        'status_label' => $o->status->label(),
                        'status_color' => $o->status->color(),
                        'total' => money($o->total),
                        'customer' => $o->customer_name ?: $o->email,
                        'product' => $o->items->map(fn ($i) => $i->quantity.'× '.$i->product_name)->implode(', '),
                        'gateway' => $o->paymentMethod?->name ?? 'Instant',
                        'tid' => $o->latestPayment?->transaction_id,
                        'time_ago' => $o->created_at?->diffForHumans(short: true) ?? 'now',
                        'url' => url('/admin/orders/'.$o->number),
                        'is_actionable' => in_array($o->status, [OrderStatus::PaymentReview, OrderStatus::NeedsAttention, OrderStatus::Processing], true),
                    ])
                    ->values()
                    ->all();

                return response()->json([
                    'ok' => true,
                    'suppliers' => $railwaySuppliers,
                    'order_jobs' => $fulfillment->getPendingBridgeOrderJobs(),
                    'notifications' => [
                        'actionable_count' => $reviewCount + $attentionCount + $processingCount,
                        'review_count' => $reviewCount,
                        'attention_count' => $attentionCount,
                        'processing_count' => $processingCount,
                        'latest_order_id' => $latestOrderId,
                        'latest_payment_id' => $latestPaymentId,
                        'items' => $recentOrders,
                    ],
                ]);
            }

            if ($bridgeAction === 'ingest') {
                $supplier = Supplier::findOrFail((int) $request->input('supplier_id'));
                $productsPayload = $request->input('products');
                $balancePayload = $request->input('balance');

                if (! is_array($productsPayload)) {
                    return response()->json(['ok' => false, 'error' => 'Invalid products payload'], 422);
                }

                $stats = $syncService->ingestExternalPayload(
                    $supplier,
                    $productsPayload,
                    is_array($balancePayload) ? $balancePayload : null
                );

                $supplier->refresh();

                return response()->json([
                    'ok' => true,
                    'supplier_id' => $supplier->id,
                    'balance' => $supplier->balance,
                    'last_synced_at' => $supplier->last_synced_at?->toIso8601String(),
                    'stats' => $stats,
                ]);
            }

            if ($bridgeAction === 'order_complete') {
                $order = $fulfillment->completeBridgedSupplierOrder(
                    orderId: (int) $request->input('order_id'),
                    orderItemId: (int) $request->input('order_item_id'),
                    supplierProductId: (int) $request->input('supplier_product_id'),
                    requestId: (string) $request->input('request_id'),
                    httpStatus: (int) $request->input('http_status', 200),
                    responseBody: is_array($request->input('body')) ? $request->input('body') : [],
                );

                return response()->json([
                    'ok' => true,
                    'order_id' => $order?->id ?? 0,
                    'order_status' => $order?->status?->value ?? 'direct_completed',
                ]);
            }
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
