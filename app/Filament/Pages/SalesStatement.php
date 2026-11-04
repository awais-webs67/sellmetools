<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SupplierOrders\SupplierOrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class SalesStatement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Sales & Profit Reports';

    protected static ?string $title = 'Sales & Profit Reports';

    protected static ?string $slug = 'sales-statement';

    protected string $view = 'filament.pages.sales-statement';

    public string $preset = 'this_month';

    public string $fromDate = '';

    public string $toDate = '';

    public string $entryType = 'all';

    public string $search = '';

    public string $sortDirection = 'desc';

    protected const PAID_STATUSES = [
        OrderStatus::Processing,
        OrderStatus::Completed,
        OrderStatus::NeedsAttention,
    ];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isSalesStaff());
    }

    public function mount(): void
    {
        $this->applyPreset('this_month');
    }

    public function applyPreset(string $preset): void
    {
        $this->preset = $preset;
        $now = now();

        match ($preset) {
            'today' => [
                $this->fromDate = $now->copy()->startOfDay()->format('Y-m-d'),
                $this->toDate = $now->copy()->endOfDay()->format('Y-m-d'),
            ],
            'yesterday' => [
                $this->fromDate = $now->copy()->subDay()->startOfDay()->format('Y-m-d'),
                $this->toDate = $now->copy()->subDay()->endOfDay()->format('Y-m-d'),
            ],
            '7d' => [
                $this->fromDate = $now->copy()->subDays(6)->startOfDay()->format('Y-m-d'),
                $this->toDate = $now->copy()->endOfDay()->format('Y-m-d'),
            ],
            '30d' => [
                $this->fromDate = $now->copy()->subDays(29)->startOfDay()->format('Y-m-d'),
                $this->toDate = $now->copy()->endOfDay()->format('Y-m-d'),
            ],
            'this_month' => [
                $this->fromDate = $now->copy()->startOfMonth()->format('Y-m-d'),
                $this->toDate = $now->copy()->endOfDay()->format('Y-m-d'),
            ],
            'last_month' => [
                $this->fromDate = $now->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
                $this->toDate = $now->copy()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'),
            ],
            'all' => [
                $this->fromDate = '',
                $this->toDate = '',
            ],
            default => null,
        };
    }

    public function updatedFromDate(): void
    {
        $this->preset = 'custom';
    }

    public function updatedToDate(): void
    {
        $this->preset = 'custom';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportStatementCsv()),
            Action::make('printStatement')
                ->label('Print / Save PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('primary')
                ->extraAttributes([
                    'onclick' => 'window.print(); return false;',
                ]),
        ];
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon, 2: string}
     */
    protected function resolveDateRange(): array
    {
        $start = filled($this->fromDate) ? Carbon::parse($this->fromDate)->startOfDay() : null;
        $end = filled($this->toDate) ? Carbon::parse($this->toDate)->endOfDay() : null;

        if ($start && $end && $start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $label = match ($this->preset) {
            'today' => 'Today · '.now()->format('d M Y'),
            'yesterday' => 'Yesterday · '.now()->subDay()->format('d M Y'),
            '7d' => 'Last 7 Days',
            '30d' => 'Last 30 Days',
            'this_month' => now()->format('F Y'),
            'last_month' => now()->subMonthNoOverflow()->format('F Y'),
            'all' => 'All Time',
            default => ($start ? $start->format('d M Y') : 'Start').' – '.($end ? $end->format('d M Y') : 'Today'),
        };

        return [$start, $end, $label];
    }

    /**
     * Computes the full bank statement dataset, KPIs, gateway breakdown, supplier breakdown, and ledger rows.
     *
     * @return array<string, mixed>
     */
    public function getStatementData(): array
    {
        [$start, $end, $periodLabel] = $this->resolveDateRange();

        // 1. Paid Customer Orders in range
        $ordersQuery = Order::query()
            ->with([
                'paymentMethod',
                'latestPayment',
                'items.product.activeSources.supplier',
                'items.product.sources.supplier',
                'items.deliveries',
                'items.supplierOrders.supplier',
            ])
            ->whereIn('status', self::PAID_STATUSES);

        if ($start) {
            $ordersQuery->whereRaw('COALESCE(paid_at, created_at) >= ?', [$start->toDateTimeString()]);
        }
        if ($end) {
            $ordersQuery->whereRaw('COALESCE(paid_at, created_at) <= ?', [$end->toDateTimeString()]);
        }

        $paidOrders = $ordersQuery->orderByRaw('COALESCE(paid_at, created_at) ASC')->get();

        // 2. Direct Admin Supplier Orders (order_item_id IS NULL and status = Success) in range
        $directQuery = SupplierOrder::query()
            ->with(['supplier', 'supplierProduct'])
            ->whereNull('order_item_id')
            ->where('status', SupplierOrderStatus::Success);

        if ($start) {
            $directQuery->where('created_at', '>=', $start);
        }
        if ($end) {
            $directQuery->where('created_at', '<=', $end);
        }

        $directBuys = $directQuery->orderBy('created_at', 'asc')->get();

        // 3. Pipeline counts in range
        $allOrdersRangeQuery = Order::query();
        if ($start) {
            $allOrdersRangeQuery->where('created_at', '>=', $start);
        }
        if ($end) {
            $allOrdersRangeQuery->where('created_at', '<=', $end);
        }
        $statusCounts = $allOrdersRangeQuery
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        // 4. Build unified chronological entries
        $rawEntries = [];
        $gatewayMap = [];
        $supplierMap = [];

        foreach (Supplier::query()->orderBy('priority')->get() as $sup) {
            $supplierMap[$sup->id] = [
                'id' => $sup->id,
                'name' => $sup->name,
                'balance_usdt' => (float) ($sup->balance ?? 0),
                'currency' => $sup->currency ?: 'USDT',
                'is_active' => (bool) $sup->is_active,
                'orders_count' => 0,
                'direct_count' => 0,
                'spend_usdt' => 0.0,
                'spend_pkr' => 0.0,
            ];
        }

        $grossSalesPkr = 0.0;
        $orderCostPkr = 0.0;
        $directBuyCostPkr = 0.0;

        foreach ($paidOrders as $order) {
            $revenuePkr = round((float) $order->total, 2);
            $costPkr = $this->resolveOrderCostPkr($order);
            $profitPkr = round($revenuePkr - $costPkr, 2);
            $marginPct = $revenuePkr > 0 ? round(($profitPkr / $revenuePkr) * 100, 1) : 0.0;

            $grossSalesPkr += $revenuePkr;
            $orderCostPkr += $costPkr;

            $gwName = $order->paymentMethod?->name ?? ((float) $order->total <= 0 ? 'Free / Promo' : 'Wallet / Manual');
            if (! isset($gatewayMap[$gwName])) {
                $gatewayMap[$gwName] = [
                    'name' => $gwName,
                    'count' => 0,
                    'total_pkr' => 0.0,
                ];
            }
            $gatewayMap[$gwName]['count']++;
            $gatewayMap[$gwName]['total_pkr'] += $revenuePkr;

            // Track supplier spend from successful supplier orders attached to this order
            foreach ($order->items as $item) {
                foreach ($item->supplierOrders as $so) {
                    if ($so->status === SupplierOrderStatus::Success && $so->supplier_id && isset($supplierMap[$so->supplier_id])) {
                        $supCostUsd = (float) ($so->cost ?? 0);
                        $supCostPkr = $so->supplier ? $so->supplier->toPkr($supCostUsd) : round($supCostUsd * 280, 2);
                        $supplierMap[$so->supplier_id]['orders_count']++;
                        $supplierMap[$so->supplier_id]['spend_usdt'] += $supCostUsd;
                        $supplierMap[$so->supplier_id]['spend_pkr'] += $supCostPkr;
                    }
                }
            }

            $itemsSummary = $order->items->map(fn (OrderItem $i): string => $i->product_name.' ×'.$i->quantity)->implode(', ');
            $sourceSummary = $order->adminSupplierSummary(false);
            $tid = $order->latestPayment?->transaction_id;
            $timestamp = $order->paid_at ?? $order->created_at;

            $rawEntries[] = [
                'timestamp' => $timestamp,
                'date_formatted' => $timestamp?->format('d M Y') ?? '-',
                'time_formatted' => $timestamp?->format('h:i A') ?? '-',
                'type' => 'sale',
                'type_label' => 'Sale',
                'ref' => $order->number,
                'url' => OrderResource::getUrl('view', ['record' => $order]),
                'title' => $itemsSummary ?: 'Store Order',
                'subtitle' => trim(($order->email ?: '').($order->phone ? ' · '.$order->phone : '').($tid ? ' · TID: '.$tid : '')),
                'channel' => $gwName,
                'source' => $sourceSummary,
                'status_label' => $order->status->label(),
                'credit_pkr' => $revenuePkr,
                'debit_pkr' => $costPkr,
                'debit_usdt' => null,
                'net_pkr' => $profitPkr,
                'margin_pct' => $marginPct,
            ];
        }

        foreach ($directBuys as $direct) {
            $costUsd = (float) ($direct->cost ?? 0);
            $costPkr = $direct->supplier ? round($direct->supplier->toPkr($costUsd), 2) : round($costUsd * 280, 2);
            $resp = is_array($direct->response) ? $direct->response : [];
            $isCustomerSale = ! empty($resp['for_customer']);
            $sellPkr = $isCustomerSale ? round((float) ($resp['customer_sell_price_pkr'] ?? 0), 2) : 0.0;
            $customerNote = trim((string) ($resp['customer_note'] ?? ''));

            if ($isCustomerSale) {
                $grossSalesPkr += $sellPkr;
                $orderCostPkr += $costPkr;
                $gwName = 'Direct Customer Sale';
                if (! isset($gatewayMap[$gwName])) {
                    $gatewayMap[$gwName] = [
                        'name' => $gwName,
                        'count' => 0,
                        'total_pkr' => 0.0,
                    ];
                }
                $gatewayMap[$gwName]['count']++;
                $gatewayMap[$gwName]['total_pkr'] += $sellPkr;
            } else {
                $directBuyCostPkr += $costPkr;
            }

            if ($direct->supplier_id && isset($supplierMap[$direct->supplier_id])) {
                $supplierMap[$direct->supplier_id]['direct_count']++;
                $supplierMap[$direct->supplier_id]['spend_usdt'] += $costUsd;
                $supplierMap[$direct->supplier_id]['spend_pkr'] += $costPkr;
            }

            $supName = $direct->supplier?->name ?? 'Supplier API';
            $prodName = $direct->supplierProduct?->name ?? 'Direct Vendor Item';
            $timestamp = $direct->created_at;
            $netPkr = $isCustomerSale ? round($sellPkr - $costPkr, 2) : -$costPkr;
            $marginPct = ($isCustomerSale && $sellPkr > 0) ? round(($netPkr / $sellPkr) * 100, 1) : null;

            $rawEntries[] = [
                'timestamp' => $timestamp,
                'date_formatted' => $timestamp?->format('d M Y') ?? '-',
                'time_formatted' => $timestamp?->format('h:i A') ?? '-',
                'type' => 'direct',
                'type_label' => $isCustomerSale ? 'Direct Sale' : 'Vendor Buy',
                'ref' => 'DIRECT-#'.$direct->id,
                'url' => SupplierOrderResource::getUrl('index', ['activeTab' => 'direct']),
                'title' => $prodName.' ×'.$direct->quantity,
                'subtitle' => ($isCustomerSale ? 'Direct Customer Sale'.($customerNote !== '' ? " ({$customerNote})" : '') : 'Direct Admin Buy').' via '.$supName.($direct->external_order_id ? ' · Ext #'.$direct->external_order_id : ''),
                'channel' => $isCustomerSale ? 'Direct Customer' : 'Supplier Wallet',
                'source' => $supName,
                'status_label' => $isCustomerSale ? 'Sold to Customer' : 'Delivered to Admin',
                'credit_pkr' => $sellPkr,
                'debit_pkr' => $costPkr,
                'debit_usdt' => $costUsd,
                'net_pkr' => $netPkr,
                'margin_pct' => $marginPct,
            ];
        }

        // Sort chronologically ascending first to compute accurate running balance
        usort($rawEntries, function (array $a, array $b): int {
            $tA = $a['timestamp']?->getTimestamp() ?? 0;
            $tB = $b['timestamp']?->getTimestamp() ?? 0;

            return $tA <=> $tB;
        });

        $runningBalance = 0.0;
        foreach ($rawEntries as $idx => $entry) {
            $runningBalance = round($runningBalance + $entry['net_pkr'], 2);
            $rawEntries[$idx]['running_balance_pkr'] = $runningBalance;
        }

        // Filter by entryType and search query for display
        $filteredEntries = array_values(array_filter($rawEntries, function (array $entry): bool {
            if ($this->entryType === 'sales' && $entry['type'] !== 'sale') {
                return false;
            }
            if ($this->entryType === 'direct' && $entry['type'] !== 'direct') {
                return false;
            }
            if (filled($this->search)) {
                $q = strtolower(trim($this->search));
                $haystack = strtolower(implode(' ', [
                    $entry['ref'],
                    $entry['title'],
                    $entry['subtitle'],
                    $entry['channel'],
                    $entry['source'],
                ]));
                if (! str_contains($haystack, $q)) {
                    return false;
                }
            }

            return true;
        }));

        if ($this->sortDirection === 'desc') {
            $filteredEntries = array_reverse($filteredEntries);
        }

        // Sort gateway breakdown by highest revenue
        uasort($gatewayMap, fn (array $a, array $b): int => $b['total_pkr'] <=> $a['total_pkr']);
        foreach ($gatewayMap as $k => $gw) {
            $gatewayMap[$k]['share_pct'] = $grossSalesPkr > 0 ? round(($gw['total_pkr'] / $grossSalesPkr) * 100, 1) : 0.0;
        }

        $totalCostPkr = round($orderCostPkr + $directBuyCostPkr, 2);
        $salesNetProfitPkr = round($grossSalesPkr - $orderCostPkr, 2);
        $netCashflowPkr = round($grossSalesPkr - $totalCostPkr, 2);
        $salesMarginPct = $grossSalesPkr > 0 ? round(($salesNetProfitPkr / $grossSalesPkr) * 100, 1) : 0.0;
        $paidOrdersCount = $paidOrders->count();
        $avgOrderValuePkr = $paidOrdersCount > 0 ? round($grossSalesPkr / $paidOrdersCount, 0) : 0.0;

        return [
            'period_label' => $periodLabel,
            'gross_sales_pkr' => round($grossSalesPkr, 2),
            'order_cost_pkr' => round($orderCostPkr, 2),
            'direct_buy_cost_pkr' => round($directBuyCostPkr, 2),
            'total_cost_pkr' => $totalCostPkr,
            'sales_net_profit_pkr' => $salesNetProfitPkr,
            'net_cashflow_pkr' => $netCashflowPkr,
            'sales_margin_pct' => $salesMarginPct,
            'paid_orders_count' => $paidOrdersCount,
            'direct_buys_count' => $directBuys->count(),
            'avg_order_value_pkr' => $avgOrderValuePkr,
            'completed_count' => (int) ($statusCounts[OrderStatus::Completed->value] ?? 0),
            'pending_review_count' => (int) ($statusCounts[OrderStatus::PaymentReview->value] ?? 0) + (int) ($statusCounts[OrderStatus::NeedsAttention->value] ?? 0),
            'cancelled_count' => (int) ($statusCounts[OrderStatus::Cancelled->value] ?? 0) + (int) ($statusCounts[OrderStatus::Refunded->value] ?? 0) + (int) ($statusCounts[OrderStatus::Expired->value] ?? 0),
            'gateways' => array_values($gatewayMap),
            'suppliers' => array_values($supplierMap),
            'entries' => $filteredEntries,
        ];
    }

    protected function resolveOrderCostPkr(Order $order): float
    {
        if ($order->total_cost !== null && (float) $order->total_cost > 0) {
            return round((float) $order->total_cost, 2);
        }

        $itemsCost = 0.0;
        foreach ($order->items as $item) {
            if ($item->cost !== null && (float) $item->cost > 0) {
                $itemsCost += (float) $item->cost;

                continue;
            }

            $successSo = $item->supplierOrders
                ->firstWhere('status', SupplierOrderStatus::Success);

            if ($successSo && $successSo->supplier) {
                $itemsCost += $successSo->supplier->toPkr((float) $successSo->cost);

                continue;
            }

            if ($item->product && (float) $item->product->cost_price > 0) {
                $itemsCost += ((float) $item->product->cost_price * max(1, (int) $item->quantity));
            }
        }

        return round($itemsCost, 2);
    }

    public function exportStatementCsv(): StreamedResponse
    {
        $statement = $this->getStatementData();
        $filename = 'sellmetools-sales-report-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($statement): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'Date',
                'Time',
                'Reference',
                'Type',
                'Product / Particulars',
                'Customer / Details',
                'Payment Gateway',
                'Fulfillment Source',
                'Status',
                'Credit In (PKR)',
                'Debit Cost (PKR)',
                'Net Profit (PKR)',
                'Running Balance (PKR)',
            ]);

            foreach ($statement['entries'] as $row) {
                fputcsv($out, [
                    $row['date_formatted'],
                    $row['time_formatted'],
                    $row['ref'],
                    $row['type_label'],
                    $row['title'],
                    $row['subtitle'],
                    $row['channel'],
                    $row['source'],
                    $row['status_label'],
                    number_format((float) $row['credit_pkr'], 2, '.', ''),
                    number_format((float) $row['debit_pkr'], 2, '.', ''),
                    number_format((float) $row['net_pkr'], 2, '.', ''),
                    number_format((float) $row['running_balance_pkr'], 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
