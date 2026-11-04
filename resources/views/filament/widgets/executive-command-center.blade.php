@php
    $m = $this->getDashboardMetrics();
    $ranges = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        'this_month' => 'This month',
        'all' => 'All time',
    ];
@endphp

<x-filament-widgets::widget>
    <style>
        .smt-pro-dash {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        /* 1. Minimalist Stripe / Linear Top Control Bar */
        .smt-toolbar {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.65rem 0.9rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
        }
        .dark .smt-toolbar {
            background: #0f172a;
            border-color: #1e293b;
        }
        .smt-toolbar-left {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
        }
        .smt-seg-group {
            display: inline-flex;
            flex-wrap: wrap;
            background: #f1f5f9;
            padding: 0.2rem;
            border-radius: 0.5rem;
            border: 1px solid #e2e8f0;
            gap: 2px;
        }
        .dark .smt-seg-group {
            background: #1e293b;
            border-color: #334155;
        }
        .smt-seg-btn {
            padding: 0.32rem 0.7rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #475569;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: all 0.12s ease;
        }
        .smt-seg-btn:hover {
            color: #0f172a;
        }
        .smt-seg-btn.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
        }
        .dark .smt-seg-btn {
            color: #94a3b8;
        }
        .dark .smt-seg-btn:hover {
            color: #f8fafc;
        }
        .dark .smt-seg-btn.active {
            background: #0f172a;
            color: #f8fafc;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
        }
        .smt-period-meta {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .smt-toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.45rem;
        }
        .smt-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.38rem;
            padding: 0.38rem 0.72rem;
            border-radius: 0.45rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #334155;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.12s ease;
            line-height: 1.2;
        }
        .smt-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0f172a;
        }
        .smt-btn.dark-primary {
            background: #0f172a;
            border-color: #0f172a;
            color: #ffffff;
        }
        .smt-btn.dark-primary:hover {
            background: #1e293b;
        }
        .dark .smt-btn {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        .dark .smt-btn:hover {
            background: #334155;
            color: #ffffff;
        }
        .dark .smt-btn.dark-primary {
            background: #4f46e5;
            border-color: #4f46e5;
            color: #ffffff;
        }
        .smt-icon {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }

        /* 2. Clean 4-Column Metric Grid (Stripe / Linear Style) */
        .smt-metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.85rem;
        }
        @media (max-width: 1100px) {
            .smt-metrics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 580px) {
            .smt-metrics-grid { grid-template-columns: 1fr; }
        }
        .smt-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1rem 1.15rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-decoration: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        a.smt-panel:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.06);
        }
        .dark .smt-panel {
            background: #0f172a;
            border-color: #1e293b;
        }
        .dark a.smt-panel:hover {
            border-color: #334155;
        }
        .smt-metric-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .smt-metric-label {
            font-size: 0.76rem;
            font-weight: 600;
            color: #64748b;
        }
        .smt-metric-svg {
            width: 16px;
            height: 16px;
            color: #94a3b8;
            flex-shrink: 0;
        }
        .smt-metric-value {
            font-size: 1.55rem;
            font-weight: 700;
            letter-spacing: -0.025em;
            color: #0f172a;
            margin-top: 0.45rem;
            font-variant-numeric: tabular-nums;
            line-height: 1.15;
        }
        .dark .smt-metric-value {
            color: #f8fafc;
        }
        .smt-metric-bottom {
            margin-top: 0.65rem;
            padding-top: 0.55rem;
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.4rem;
            font-size: 0.72rem;
            color: #64748b;
        }
        .dark .smt-metric-bottom {
            border-top-color: #1e293b;
        }
        .smt-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.1rem 0.45rem;
            border-radius: 0.35rem;
            font-size: 0.68rem;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        .smt-tag.neutral { background: #f1f5f9; color: #334155; }
        .smt-tag.emerald { background: #ecfdf5; color: #065f46; }
        .smt-tag.amber   { background: #fffbeb; color: #92400e; }
        .smt-tag.rose    { background: #fff1f2; color: #9f1239; }
        .dark .smt-tag.neutral { background: #1e293b; color: #cbd5e1; }
        .dark .smt-tag.emerald { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; }
        .dark .smt-tag.amber   { background: rgba(245, 158, 11, 0.15); color: #fcd34d; }
        .dark .smt-tag.rose    { background: rgba(244, 63, 94, 0.15); color: #fda4af; }

        /* 3. Two-Column Layout Sections */
        .smt-two-col {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 0.85rem;
        }
        @media (max-width: 1024px) {
            .smt-two-col { grid-template-columns: 1fr; }
        }
        .smt-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            padding-bottom: 0.7rem;
            margin-bottom: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .dark .smt-panel-header {
            border-bottom-color: #1e293b;
        }
        .smt-panel-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: #0f172a;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }
        .dark .smt-panel-title {
            color: #f8fafc;
        }
        .smt-panel-link {
            font-size: 0.72rem;
            font-weight: 600;
            color: #475569;
            text-decoration: none;
        }
        .smt-panel-link:hover {
            color: #0f172a;
            text-decoration: underline;
        }
        .dark .smt-panel-link { color: #94a3b8; }

        /* 7-Day Chart */
        .smt-chart-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 0.5rem;
            align-items: end;
            height: 145px;
            padding-top: 0.5rem;
        }
        .smt-chart-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            height: 100%;
            gap: 0.35rem;
        }
        .smt-chart-num {
            font-size: 0.64rem;
            font-weight: 600;
            color: #64748b;
            font-variant-numeric: tabular-nums;
        }
        .smt-chart-track {
            width: 100%;
            max-width: 34px;
            height: 98px;
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 0.35rem;
            display: flex;
            align-items: flex-end;
            overflow: hidden;
            position: relative;
        }
        .dark .smt-chart-track {
            background: #1e293b;
            border-color: #334155;
        }
        .smt-chart-bar-rev {
            width: 100%;
            background: #334155;
            border-radius: 0.25rem 0.25rem 0 0;
            position: relative;
            transition: height 0.25s ease;
        }
        .dark .smt-chart-bar-rev { background: #64748b; }
        .smt-chart-bar-prof {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: #10b981;
        }
        .smt-chart-day {
            font-size: 0.68rem;
            font-weight: 600;
            color: #475569;
        }
        .dark .smt-chart-day { color: #94a3b8; }

        /* Supplier Table Rows */
        .smt-sup-table {
            width: 100%;
            border-collapse: collapse;
        }
        .smt-sup-table td {
            padding: 0.48rem 0.25rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.76rem;
            vertical-align: middle;
        }
        .smt-sup-table tr:last-child td { border-bottom: none; }
        .dark .smt-sup-table td { border-bottom-color: #1e293b; }
        .smt-dot {
            width: 6px;
            height: 6px;
            border-radius: 9999px;
            display: inline-block;
            margin-right: 0.35rem;
        }
        .smt-icon-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.24rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.68rem;
            font-weight: 600;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.12s ease;
        }
        .smt-icon-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #cbd5e1;
        }
        .dark .smt-icon-btn {
            background: #1e293b;
            border-color: #334155;
            color: #cbd5e1;
        }

        /* Progress Bar */
        .smt-bar-bg {
            height: 4px;
            width: 100%;
            background: #f1f5f9;
            border-radius: 9999px;
            overflow: hidden;
            margin-top: 0.3rem;
        }
        .dark .smt-bar-bg { background: #1e293b; }
        .smt-bar-fg {
            height: 100%;
            background: #0f172a;
            border-radius: 9999px;
        }
        .dark .smt-bar-fg { background: #6366f1; }
    </style>

    <div class="smt-pro-dash">
        {{-- 1. Minimalist Segmented Control & Action Toolbar --}}
        <div class="smt-toolbar">
            <div class="smt-toolbar-left">
                <div class="smt-seg-group" role="tablist" aria-label="Date range">
                    @foreach ($ranges as $key => $label)
                        <button type="button"
                                wire:click="setRange('{{ $key }}')"
                                class="smt-seg-btn {{ $range === $key ? 'active' : '' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="smt-period-meta">
                    <span>Showing: <strong style="color:#0f172a;" class="dark:!text-white">{{ $m['range_label'] }}</strong></span>
                    <span>·</span>
                    <span>Catalog: <strong style="color:#0f172a;" class="dark:!text-white">{{ $m['in_stock_products_count'] }}/{{ $m['active_products_count'] }}</strong> in stock</span>
                </div>
            </div>

            <div class="smt-toolbar-actions">
                <a href="{{ $m['urls']['statement'] }}" class="smt-btn dark-primary">
                    <svg class="smt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z"/></svg>
                    <span>Bank Statement</span>
                </a>
                <a href="{{ $m['urls']['vendor_orders'] }}" class="smt-btn">
                    <svg class="smt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>
                    <span>Vendor Orders</span>
                </a>
                <a href="{{ $m['urls']['create_product'] }}" class="smt-btn">
                    <svg class="smt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14m-7-7h14"/></svg>
                    <span>New Product</span>
                </a>
                <button type="button" wire:click="syncAllSuppliers" wire:loading.attr="disabled" class="smt-btn">
                    <svg class="smt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                    <span wire:loading.remove wire:target="syncAllSuppliers">Sync Suppliers</span>
                    <span wire:loading wire:target="syncAllSuppliers">Syncing...</span>
                </button>
            </div>
        </div>

        {{-- 2. Clean 4-Column Metric Grid --}}
        <div class="smt-metrics-grid">
            {{-- Gross Revenue --}}
            <a href="{{ $m['urls']['statement'] }}" class="smt-panel">
                <div>
                    <div class="smt-metric-top">
                        <span class="smt-metric-label">Gross Revenue</span>
                        <svg class="smt-metric-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
                    </div>
                    <div class="smt-metric-value">
                        Rs {{ number_format($m['revenue_pkr'], 0) }}
                    </div>
                </div>
                <div class="smt-metric-bottom">
                    <span class="smt-tag neutral">{{ $m['paid_orders_count'] }} paid orders</span>
                    <span>Avg Rs {{ number_format($m['avg_order_pkr'], 0) }}</span>
                </div>
            </a>

            {{-- Net Profit --}}
            <a href="{{ $m['urls']['statement'] }}" class="smt-panel">
                <div>
                    <div class="smt-metric-top">
                        <span class="smt-metric-label">Net Profit</span>
                        <svg class="smt-metric-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                    </div>
                    <div class="smt-metric-value" style="color: {{ $m['net_profit_pkr'] >= 0 ? '#059669' : '#e11d48' }};">
                        {{ $m['net_profit_pkr'] >= 0 ? '+Rs ' : '-Rs ' }}{{ number_format(abs($m['net_profit_pkr']), 0) }}
                    </div>
                </div>
                <div class="smt-metric-bottom">
                    <span class="smt-tag {{ $m['net_profit_pkr'] >= 0 ? 'emerald' : 'rose' }}">{{ $m['margin_pct'] }}% margin</span>
                    <span>Cost Rs {{ number_format($m['order_cost_pkr'], 0) }}</span>
                </div>
            </a>

            {{-- Payment Verification --}}
            <a href="{{ $m['urls']['review_orders'] }}" class="smt-panel">
                <div>
                    <div class="smt-metric-top">
                        <span class="smt-metric-label">Payment Review</span>
                        <svg class="smt-metric-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="smt-metric-value">
                        {{ $m['pending_payments_count'] }}
                    </div>
                </div>
                <div class="smt-metric-bottom">
                    @if ($m['pending_payments_count'] > 0)
                        <span class="smt-tag amber">Awaiting TID verification</span>
                    @else
                        <span class="smt-tag emerald">All verified</span>
                    @endif
                    <span>Review queue →</span>
                </div>
            </a>

            {{-- Fulfillment Queue --}}
            <a href="{{ $m['unfulfilled_vendor_count'] > 0 ? $m['urls']['unfulfilled_vendor'] : $m['urls']['attention_orders'] }}" class="smt-panel">
                <div>
                    <div class="smt-metric-top">
                        <span class="smt-metric-label">Fulfillment Queue</span>
                        <svg class="smt-metric-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                    </div>
                    <div class="smt-metric-value">
                        {{ $m['needs_attention_count'] }}
                    </div>
                </div>
                <div class="smt-metric-bottom">
                    @if ($m['unfulfilled_vendor_count'] > 0)
                        <span class="smt-tag rose">{{ $m['unfulfilled_vendor_count'] }} vendor unfulfilled</span>
                    @elseif ($m['needs_attention_count'] > 0)
                        <span class="smt-tag amber">Manual delivery pending</span>
                    @else
                        <span class="smt-tag emerald">All orders delivered</span>
                    @endif
                    <span>Direct buys: {{ $m['direct_buys_count'] }}</span>
                </div>
            </a>
        </div>

        {{-- 3. Middle Row: 7-Day Revenue/Profit Chart + Supplier Wallets Table --}}
        <div class="smt-two-col">
            {{-- 7-Day Revenue & Profit Trend --}}
            <div class="smt-panel">
                <div>
                    <div class="smt-panel-header">
                        <span class="smt-panel-title">
                            <svg class="smt-icon" style="color:#64748b;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            <span>7-Day Revenue &amp; Profit</span>
                        </span>
                        <div style="display:flex;align-items:center;gap:0.75rem;font-size:0.68rem;font-weight:600;color:#64748b;">
                            <span style="display:inline-flex;align-items:center;gap:4px;">
                                <span style="width:8px;height:8px;border-radius:2px;background:#334155;"></span> Revenue
                            </span>
                            <span style="display:inline-flex;align-items:center;gap:4px;">
                                <span style="width:8px;height:8px;border-radius:2px;background:#10b981;"></span> Profit
                            </span>
                        </div>
                    </div>

                    <div class="smt-chart-grid">
                        @foreach ($m['daily_chart'] as $day)
                            <div class="smt-chart-col" title="{{ $day['date_short'] }}: Revenue Rs {{ number_format($day['revenue'], 0) }} | Profit Rs {{ number_format($day['profit'], 0) }} ({{ $day['orders'] }} orders)">
                                <span class="smt-chart-num">
                                    {{ $day['revenue'] >= 1000 ? round($day['revenue'] / 1000, 1).'k' : ($day['revenue'] > 0 ? number_format($day['revenue'], 0) : '0') }}
                                </span>
                                <div class="smt-chart-track">
                                    <div class="smt-chart-bar-rev" style="height: {{ $day['height_pct'] }}%;">
                                        <div class="smt-chart-bar-prof" style="height: {{ $day['revenue'] > 0 ? min(100, round(($day['profit'] / max(1, $day['revenue'])) * 100)) : 0 }}%;"></div>
                                    </div>
                                </div>
                                <span class="smt-chart-day">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Supplier Wallets & API Status --}}
            <div class="smt-panel">
                <div>
                    <div class="smt-panel-header">
                        <span class="smt-panel-title">
                            <svg class="smt-icon" style="color:#64748b;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                            <span>Supplier API Wallets</span>
                        </span>
                        <a href="{{ $m['urls']['suppliers'] }}" class="smt-panel-link">Manage suppliers →</a>
                    </div>

                    <table class="smt-sup-table">
                        <tbody>
                            @foreach ($m['suppliers'] as $sup)
                                @php
                                    $bal = (float) ($sup->balance ?? 0);
                                    $low = (float) ($sup->low_balance_threshold ?? 1.0);
                                    $isLow = $bal < max(0.5, $low);
                                    $pkrEq = $sup->toPkr($bal);
                                @endphp
                                <tr>
                                    <td>
                                        <span class="smt-dot" style="background: {{ $sup->is_active ? ($isLow ? '#f59e0b' : '#10b981') : '#94a3b8' }};"></span>
                                        <strong style="color:#0f172a;" class="dark:!text-white">{{ $sup->name }}</strong>
                                        <span style="font-size:0.68rem;color:#64748b;margin-left:4px;">({{ $sup->products_count }})</span>
                                    </td>
                                    <td style="text-align:right;font-family:monospace;font-weight:700;color:{{ $isLow ? '#e11d48' : '#0f172a' }};" class="{{ ! $isLow ? 'dark:!text-white' : '' }}">
                                        {{ number_format($bal, 2) }} {{ $sup->currency ?: 'USDT' }}
                                        <span style="display:block;font-size:0.65rem;color:#64748b;font-weight:500;">≈ Rs {{ number_format($pkrEq, 0) }}</span>
                                    </td>
                                    <td style="text-align:right;width:68px;">
                                        <button type="button"
                                                wire:click="syncSupplierNow({{ $sup->id }})"
                                                wire:loading.attr="disabled"
                                                class="smt-icon-btn"
                                                title="Sync {{ $sup->name }}">
                                            <svg style="width:12px;height:12px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                                            <span>Sync</span>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- 4. Bottom Row: Top Products + Payment Gateways --}}
        <div class="smt-two-col">
            {{-- Top Selling Products --}}
            <div class="smt-panel">
                <div>
                    <div class="smt-panel-header">
                        <span class="smt-panel-title">
                            <svg class="smt-icon" style="color:#64748b;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                            <span>Top Products ({{ $m['range_label'] }})</span>
                        </span>
                        <a href="{{ $m['urls']['products'] }}" class="smt-panel-link">View catalog →</a>
                    </div>

                    <table class="smt-sup-table">
                        <tbody>
                            @forelse ($m['top_products'] as $idx => $tp)
                                <tr>
                                    <td style="width:28px;font-family:monospace;font-size:0.7rem;color:#64748b;font-weight:600;">
                                        0{{ $idx + 1 }}
                                    </td>
                                    <td>
                                        <div style="font-weight:600;color:#0f172a;line-height:1.25;" class="dark:!text-white">{{ $tp->product_name }}</div>
                                        <div style="font-size:0.68rem;color:#64748b;">{{ (int) $tp->units_sold }} sold</div>
                                    </td>
                                    <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;" class="dark:!text-white">
                                        Rs {{ number_format((float) $tp->total_rev, 0) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" style="text-align:center;padding:1.25rem 0;color:#64748b;">
                                        No product sales recorded in this period.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Payment Gateways --}}
            <div class="smt-panel">
                <div>
                    <div class="smt-panel-header">
                        <span class="smt-panel-title">
                            <svg class="smt-icon" style="color:#64748b;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            <span>Payment Methods ({{ $m['range_label'] }})</span>
                        </span>
                        <a href="{{ $m['urls']['statement'] }}" class="smt-panel-link">Statement ledger →</a>
                    </div>

                    @forelse ($m['gateways'] as $gw)
                        <div style="padding:0.42rem 0;">
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.75rem;">
                                <span style="font-weight:600;color:#0f172a;" class="dark:!text-white">
                                    {{ $gw['name'] }} <span style="font-size:0.68rem;color:#64748b;font-weight:400;">({{ $gw['count'] }})</span>
                                </span>
                                <span style="font-family:monospace;font-weight:700;color:#0f172a;" class="dark:!text-white">
                                    Rs {{ number_format($gw['total'], 0) }} <span style="color:#64748b;font-weight:500;">({{ $gw['share_pct'] }}%)</span>
                                </span>
                            </div>
                            <div class="smt-bar-bg">
                                <div class="smt-bar-fg" style="width: {{ max(4, $gw['share_pct']) }}%;"></div>
                            </div>
                        </div>
                    @empty
                        <div style="text-align:center;padding:1.25rem 0;font-size:0.76rem;color:#64748b;">
                            No payments recorded in this period.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
