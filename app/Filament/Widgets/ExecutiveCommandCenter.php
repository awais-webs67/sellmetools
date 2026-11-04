<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SupplierOrderStatus;
use App\Filament\Pages\SalesStatement;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SupplierOrders\SupplierOrderResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\SupplierSyncService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

class ExecutiveCommandCenter extends Widget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.executive-command-center';

    public string $range = 'today';

    protected const PAID_STATUSES = [
        OrderStatus::Processing,
        OrderStatus::Completed,
        OrderStatus::NeedsAttention,
    ];

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isSalesStaff());
    }

    public function setRange(string $range): void
    {
        if (in_array($range, ['today', 'yesterday', '7d', 'this_month', 'all'], true)) {
            $this->range = $range;
        }
    }

    public function syncSupplierNow(int $supplierId): void
    {
        $supplier = Supplier::find($supplierId);
        if (! $supplier) {
            return;
        }

        try {
            $res = app(SupplierSyncService::class)->sync($supplier);
            Notification::make()
                ->title("{$supplier->name} Synced!")
                ->body("Products: {$res['synced']} · Balance: ".number_format((float) ($res['balance'] ?? $supplier->balance), 2).' '.$supplier->currency)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title("Sync Note ({$supplier->name})")
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    public function syncAllSuppliers(): void
    {
        $suppliers = Supplier::query()->where('is_active', true)->orderBy('priority')->get();
        $ok = 0;

        foreach ($suppliers as $supplier) {
            try {
                app(SupplierSyncService::class)->sync($supplier);
                $ok++;
            } catch (\Throwable) {
                // Handled by browser bridge if Railway cooldown
            }
        }

        Notification::make()
            ->title("Synced {$ok} active supplier(s)")
            ->body('Live prices, stock & wallet balances updated.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboardMetrics(): array
    {
        PaymentService::syncOrphanedPendingPayments();

        $now = now();
        [$start, $end, $rangeLabel] = match ($this->range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'Today ('.$now->format('d M').')'],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), 'Yesterday'],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay(), 'Last 7 Days'],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay(), $now->format('F Y')],
            default => [null, null, 'All Time'],
        };

        // 1. Period Paid Orders
        $ordersQuery = Order::query()
            ->with(['paymentMethod', 'items.supplierOrders.supplier', 'items.product'])
            ->whereIn('status', self::PAID_STATUSES);

        if ($start) {
            $ordersQuery->whereRaw('COALESCE(paid_at, created_at) >= ?', [$start->toDateTimeString()]);
        }
        if ($end) {
            $ordersQuery->whereRaw('COALESCE(paid_at, created_at) <= ?', [$end->toDateTimeString()]);
        }

        $paidOrders = $ordersQuery->get();

        $revenuePkr = 0.0;
        $orderCostPkr = 0.0;
        $gatewayTotals = [];

        foreach ($paidOrders as $order) {
            $rev = (float) $order->total;
            $cost = $this->computeOrderCost($order);
            $revenuePkr += $rev;
            $orderCostPkr += $cost;

            $gw = $order->paymentMethod?->name ?? ($rev <= 0 ? 'Free / Promo' : 'Wallet / Manual');
            if (! isset($gatewayTotals[$gw])) {
                $gatewayTotals[$gw] = ['name' => $gw, 'count' => 0, 'total' => 0.0];
            }
            $gatewayTotals[$gw]['count']++;
            $gatewayTotals[$gw]['total'] += $rev;
        }

        // Direct Admin Buys in Period
        $directQuery = SupplierOrder::query()
            ->with('supplier')
            ->whereNull('order_item_id')
            ->where('status', SupplierOrderStatus::Success);
        if ($start) {
            $directQuery->where('created_at', '>=', $start);
        }
        if ($end) {
            $directQuery->where('created_at', '<=', $end);
        }
        $directBuys = $directQuery->get();
        $directCostPkr = 0.0;
        foreach ($directBuys as $db) {
            $usd = (float) ($db->cost ?? 0);
            $directCostPkr += $db->supplier ? $db->supplier->toPkr($usd) : ($usd * 280);
        }

        $netProfitPkr = round($revenuePkr - $orderCostPkr, 2);
        $marginPct = $revenuePkr > 0 ? round(($netProfitPkr / $revenuePkr) * 100, 1) : 0.0;
        $paidCount = $paidOrders->count();
        $avgOrderPkr = $paidCount > 0 ? round($revenuePkr / $paidCount, 0) : 0.0;

        // 2. Live Action Queues
        $pendingPaymentsCount = Payment::query()->where('status', PaymentStatus::Pending)->count();
        $needsAttentionCount = Order::query()->where('status', OrderStatus::NeedsAttention)->count();
        $unfulfilledVendorCount = SupplierOrder::query()
            ->whereIn('status', [SupplierOrderStatus::Failed, SupplierOrderStatus::Unknown])
            ->whereHas('orderItem', function ($q): void {
                $q->whereRaw('order_items.delivered_qty < order_items.quantity')
                    ->whereHas('order', function ($oq): void {
                        $oq->whereNotIn('orders.status', [
                            OrderStatus::Completed->value,
                            OrderStatus::Cancelled->value,
                            OrderStatus::Refunded->value,
                            OrderStatus::Expired->value,
                        ]);
                    });
            })
            ->count();

        // 3. 7-Day Daily Trend (Revenue & Profit)
        $sevenDaysStart = $now->copy()->subDays(6)->startOfDay();
        $recent7dOrders = Order::query()
            ->with(['items.supplierOrders.supplier', 'items.product'])
            ->whereIn('status', self::PAID_STATUSES)
            ->whereRaw('COALESCE(paid_at, created_at) >= ?', [$sevenDaysStart->toDateTimeString()])
            ->get();

        $dailyBuckets = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $now->copy()->subDays($i);
            $key = $d->format('Y-m-d');
            $dailyBuckets[$key] = [
                'label' => $d->format('D'),
                'date_short' => $d->format('d M'),
                'revenue' => 0.0,
                'profit' => 0.0,
                'orders' => 0,
            ];
        }

        foreach ($recent7dOrders as $o) {
            $dt = ($o->paid_at ?? $o->created_at)?->format('Y-m-d');
            if ($dt && isset($dailyBuckets[$dt])) {
                $r = (float) $o->total;
                $c = $this->computeOrderCost($o);
                $dailyBuckets[$dt]['revenue'] += $r;
                $dailyBuckets[$dt]['profit'] += max(0.0, $r - $c);
                $dailyBuckets[$dt]['orders']++;
            }
        }

        $maxDailyRev = max(1.0, collect($dailyBuckets)->max('revenue') ?: 1.0);
        foreach ($dailyBuckets as $k => $b) {
            $dailyBuckets[$k]['height_pct'] = max(8, (int) round(($b['revenue'] / $maxDailyRev) * 100));
            $dailyBuckets[$k]['profit_pct'] = $b['revenue'] > 0
                ? max(6, (int) round(($b['profit'] / $maxDailyRev) * 100))
                : 4;
        }

        // 4. Top Selling Products in Period
        $topItemsQuery = OrderItem::query()
            ->with('product')
            ->whereHas('order', function ($q) use ($start, $end): void {
                $q->whereIn('orders.status', array_map(fn (OrderStatus $s) => $s->value, self::PAID_STATUSES));
                if ($start) {
                    $q->whereRaw('COALESCE(orders.paid_at, orders.created_at) >= ?', [$start->toDateTimeString()]);
                }
                if ($end) {
                    $q->whereRaw('COALESCE(orders.paid_at, orders.created_at) <= ?', [$end->toDateTimeString()]);
                }
            })
            ->selectRaw('product_id, product_name, SUM(quantity) as units_sold, SUM(line_total) as total_rev')
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('total_rev')
            ->limit(5)
            ->get();

        // 5. Gateway Mix sorted
        uasort($gatewayTotals, fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        foreach ($gatewayTotals as $k => $g) {
            $gatewayTotals[$k]['share_pct'] = $revenuePkr > 0 ? round(($g['total'] / $revenuePkr) * 100, 1) : 0.0;
        }

        // 6. Suppliers Live Wallets
        $suppliers = Supplier::query()
            ->withCount('products')
            ->orderByDesc('is_active')
            ->orderBy('priority')
            ->get();

        // 7. Catalog & Customer Quick Counters
        $activeProductsCount = Product::query()->where('is_active', true)->count();
        $inStockProductsCount = Product::query()
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->where('stock_unlimited', true)->orWhere('available_stock', '>', 0);
            })
            ->count();
        $customersCount = User::query()->where('is_admin', false)->count();

        return [
            'range_label' => $rangeLabel,
            'revenue_pkr' => round($revenuePkr, 0),
            'order_cost_pkr' => round($orderCostPkr, 0),
            'direct_cost_pkr' => round($directCostPkr, 0),
            'net_profit_pkr' => round($netProfitPkr, 0),
            'margin_pct' => $marginPct,
            'paid_orders_count' => $paidCount,
            'direct_buys_count' => $directBuys->count(),
            'avg_order_pkr' => $avgOrderPkr,
            'pending_payments_count' => $pendingPaymentsCount,
            'needs_attention_count' => $needsAttentionCount,
            'unfulfilled_vendor_count' => $unfulfilledVendorCount,
            'daily_chart' => array_values($dailyBuckets),
            'top_products' => $topItemsQuery,
            'gateways' => array_slice(array_values($gatewayTotals), 0, 5),
            'suppliers' => $suppliers,
            'active_products_count' => $activeProductsCount,
            'in_stock_products_count' => $inStockProductsCount,
            'out_of_stock_count' => max(0, $activeProductsCount - $inStockProductsCount),
            'customers_count' => $customersCount,
            'urls' => [
                'orders' => OrderResource::getUrl('index'),
                'review_orders' => OrderResource::getUrl('index', ['activeTab' => 'review']),
                'attention_orders' => OrderResource::getUrl('index', ['activeTab' => 'attention']),
                'vendor_orders' => SupplierOrderResource::getUrl('index'),
                'unfulfilled_vendor' => SupplierOrderResource::getUrl('index', ['activeTab' => 'failed']),
                'direct_vendor' => SupplierOrderResource::getUrl('index', ['activeTab' => 'direct']),
                'statement' => SalesStatement::getUrl(),
                'products' => ProductResource::getUrl('index'),
                'create_product' => ProductResource::getUrl('create'),
                'suppliers' => SupplierResource::getUrl('index'),
            ],
        ];
    }

    protected function computeOrderCost(Order $order): float
    {
        if ($order->total_cost !== null && (float) $order->total_cost > 0) {
            return (float) $order->total_cost;
        }

        $cost = 0.0;
        foreach ($order->items as $item) {
            if ($item->cost !== null && (float) $item->cost > 0) {
                $cost += (float) $item->cost;

                continue;
            }
            $so = $item->supplierOrders->firstWhere('status', SupplierOrderStatus::Success);
            if ($so && $so->supplier) {
                $cost += $so->supplier->toPkr((float) $so->cost);

                continue;
            }
            if ($item->product && (float) $item->product->cost_price > 0) {
                $cost += ((float) $item->product->cost_price * max(1, (int) $item->quantity));
            }
        }

        return $cost;
    }
}
