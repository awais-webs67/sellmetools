@php
    $data = $this->getStatementData();
    $presets = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'all' => 'All time',
    ];
@endphp

<x-filament-panels::page>
    <style>
        .smt-rep-wrap {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .smt-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
            overflow: hidden;
        }
        .dark .smt-panel {
            background: #0f172a;
            border-color: #1e293b;
        }

        /* 1. Top Control & Filter Bar (Stripe / Linear Style) */
        .smt-filter-panel {
            padding: 0.85rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .smt-filter-top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.65rem;
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
            padding: 0.32rem 0.68rem;
            border-radius: 0.375rem;
            font-size: 0.74rem;
            font-weight: 600;
            color: #475569;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: all 0.12s ease;
        }
        .smt-seg-btn:hover { color: #0f172a; }
        .smt-seg-btn.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
        }
        .dark .smt-seg-btn { color: #94a3b8; }
        .dark .smt-seg-btn:hover { color: #f8fafc; }
        .dark .smt-seg-btn.active {
            background: #0f172a;
            color: #f8fafc;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
        }
        .smt-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
            gap: 0.65rem;
            align-items: end;
            padding-top: 0.65rem;
            border-top: 1px solid #f1f5f9;
        }
        .dark .smt-filter-grid { border-top-color: #1e293b; }
        .smt-field label {
            display: block;
            font-size: 0.68rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        .smt-field input, .smt-field select {
            width: 100%;
            border-radius: 0.45rem;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            padding: 0.38rem 0.6rem;
            font-size: 0.78rem;
            color: #0f172a;
        }
        .dark .smt-field input, .dark .smt-field select {
            background: #1e293b;
            border-color: #334155;
            color: #f8fafc;
        }

        /* 2. 4-Column Metric Grid */
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
        .smt-metric-card {
            padding: 1rem 1.15rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
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
        .smt-metric-val {
            font-size: 1.55rem;
            font-weight: 700;
            letter-spacing: -0.025em;
            color: #0f172a;
            margin-top: 0.45rem;
            font-variant-numeric: tabular-nums;
            line-height: 1.15;
        }
        .dark .smt-metric-val { color: #f8fafc; }
        .smt-metric-foot {
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
        .dark .smt-metric-foot { border-top-color: #1e293b; }

        .smt-tag {
            display: inline-flex;
            align-items: center;
            padding: 0.1rem 0.45rem;
            border-radius: 0.35rem;
            font-size: 0.68rem;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .smt-tag.neutral { background: #f1f5f9; color: #334155; }
        .smt-tag.emerald { background: #ecfdf5; color: #065f46; }
        .smt-tag.amber   { background: #fffbeb; color: #92400e; }
        .smt-tag.rose    { background: #fff1f2; color: #9f1239; }
        .dark .smt-tag.neutral { background: #1e293b; color: #cbd5e1; }
        .dark .smt-tag.emerald { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; }
        .dark .smt-tag.amber   { background: rgba(245, 158, 11, 0.15); color: #fcd34d; }
        .dark .smt-tag.rose    { background: rgba(244, 63, 94, 0.15); color: #fda4af; }

        /* 3. Split Breakdown Grid */
        .smt-split-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
            gap: 0.85rem;
        }
        .smt-panel-head {
            padding: 0.75rem 1rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .dark .smt-panel-head {
            background: #1e293b;
            border-bottom-color: #334155;
        }
        .smt-panel-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #0f172a;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .dark .smt-panel-title { color: #f8fafc; }

        /* 4. Clean Data Tables */
        .smt-table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .smt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.76rem;
        }
        .smt-table th {
            text-align: left;
            padding: 0.55rem 0.85rem;
            font-size: 0.67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.045em;
            color: #64748b;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .dark .smt-table th {
            background: #0f172a;
            color: #94a3b8;
            border-bottom-color: #1e293b;
        }
        .smt-table td {
            padding: 0.52rem 0.85rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .dark .smt-table td { border-bottom-color: #1e293b; }
        .smt-table tr:hover td { background: rgba(15, 23, 42, 0.015); }
        .dark .smt-table tr:hover td { background: rgba(255, 255, 255, 0.02); }

        @media print {
            .fi-sidebar, .fi-topbar, .smt-no-print, .fi-header-actions { display: none !important; }
            .fi-main { padding: 0 !important; }
            .smt-panel { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
        }
    </style>

    <div class="smt-rep-wrap">
        {{-- 1. Segmented Date Presets & Custom Filters --}}
        <div class="smt-panel smt-filter-panel smt-no-print">
            <div class="smt-filter-top">
                <div class="smt-seg-group" role="tablist">
                    @foreach ($presets as $key => $label)
                        <button type="button"
                                wire:click="applyPreset('{{ $key }}')"
                                class="smt-seg-btn {{ $preset === $key ? 'active' : '' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <span style="font-size:0.74rem;color:#64748b;font-weight:500;">
                    Period: <strong style="color:#0f172a;" class="dark:!text-white">{{ $data['period_label'] }}</strong>
                </span>
            </div>

            <div class="smt-filter-grid">
                <div class="smt-field">
                    <label>From date</label>
                    <input type="date" wire:model.live="fromDate">
                </div>
                <div class="smt-field">
                    <label>To date</label>
                    <input type="date" wire:model.live="toDate">
                </div>
                <div class="smt-field">
                    <label>Transaction type</label>
                    <select wire:model.live="entryType">
                        <option value="all">All transactions</option>
                        <option value="sales">Customer sales only</option>
                        <option value="direct">Direct vendor buys only</option>
                    </select>
                </div>
                <div class="smt-field">
                    <label>Sort order</label>
                    <select wire:model.live="sortDirection">
                        <option value="desc">Newest first</option>
                        <option value="asc">Oldest first (chronological)</option>
                    </select>
                </div>
                <div class="smt-field">
                    <label>Search order, email, product or TID</label>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter records...">
                </div>
            </div>
        </div>

        {{-- 2. Clean 4-Column Metric Grid --}}
        <div class="smt-metrics-grid">
            <div class="smt-panel smt-metric-card">
                <div>
                    <div class="smt-metric-top">
                        <span class="smt-metric-label">Gross Revenue</span>
                        <svg class="smt-metric-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
                    </div>
                    <div class="smt-metric-val">
                        Rs {{ number_format($data['gross_sales_pkr'], 0) }}
                    </div>
                </div>
                <div class="smt-metric-foot">
                    <span class="smt-tag neutral">{{ $data['paid_orders_count'] }} paid orders</span>
                    <span>Avg Rs {{ number_format($data['avg_order_value_pkr'], 0) }}</span>
                </div>
            </div>

            <div class="smt-panel smt-metric-card">
                <div>
                    <div class="smt-metric-top">
                        <span class="smt-metric-label">Total Cost</span>
                        <svg class="smt-metric-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                    </div>
                    <div class="smt-metric-val">
                        Rs {{ number_format($data['total_cost_pkr'], 0) }}
                    </div>
                </div>
                <div class="smt-metric-foot">
                    <span>Orders: Rs {{ number_format($data['order_cost_pkr'], 0) }}</span>
                    <span>Direct: Rs {{ number_format($data['direct_buy_cost_pkr'], 0) }}</span>
                </div>
            </div>

            <div class="smt-panel smt-metric-card">
                <div>
                    <div class="smt-metric-top">
                        <span class="smt-metric-label">Net Profit</span>
                        <svg class="smt-metric-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                    </div>
                    <div class="smt-metric-val" style="color:{{ $data['sales_net_profit_pkr'] >= 0 ? '#059669' : '#e11d48' }};">
                        {{ $data['sales_net_profit_pkr'] >= 0 ? '+Rs ' : '-Rs ' }}{{ number_format(abs($data['sales_net_profit_pkr']), 0) }}
                    </div>
                </div>
                <div class="smt-metric-foot">
                    <span class="smt-tag {{ $data['sales_net_profit_pkr'] >= 0 ? 'emerald' : 'rose' }}">{{ $data['sales_margin_pct'] }}% margin</span>
                    <span>Net cashflow: Rs {{ number_format($data['net_cashflow_pkr'], 0) }}</span>
                </div>
            </div>

            <div class="smt-panel smt-metric-card">
                <div>
                    <div class="smt-metric-top">
                        <span class="smt-metric-label">Orders Breakdown</span>
                        <svg class="smt-metric-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    </div>
                    <div class="smt-metric-val">
                        {{ $data['completed_count'] }} <span style="font-size:0.82rem;font-weight:500;color:#64748b;">delivered</span>
                    </div>
                </div>
                <div class="smt-metric-foot">
                    <span>Pending: {{ $data['pending_review_count'] }}</span>
                    <span>Direct buys: {{ $data['direct_buys_count'] }}</span>
                    <span>Closed: {{ $data['cancelled_count'] }}</span>
                </div>
            </div>
        </div>

        {{-- 3. Payment Methods & Supplier Wallets Breakdown --}}
        <div class="smt-split-grid">
            <div class="smt-panel">
                <div class="smt-panel-head">
                    <span class="smt-panel-title">
                        <svg style="width:14px;height:14px;color:#64748b;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        <span>Revenue by Payment Method</span>
                    </span>
                    <span style="font-size:0.7rem;color:#64748b;font-weight:600;">{{ count($data['gateways']) }} methods</span>
                </div>
                <div class="smt-table-wrap">
                    <table class="smt-table">
                        <thead>
                            <tr>
                                <th>Payment Method</th>
                                <th>Orders</th>
                                <th style="text-align:right;">Revenue</th>
                                <th style="text-align:right;">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($data['gateways'] as $gw)
                                <tr>
                                    <td style="font-weight:600;color:#0f172a;" class="dark:!text-white">{{ $gw['name'] }}</td>
                                    <td style="color:#64748b;">{{ $gw['count'] }}</td>
                                    <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;" class="dark:!text-white">Rs {{ number_format($gw['total_pkr'], 0) }}</td>
                                    <td style="text-align:right;">
                                        <span class="smt-tag neutral">{{ $gw['share_pct'] }}%</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" style="text-align:center;color:#64748b;padding:1.25rem;">No paid orders in this period.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="smt-panel">
                <div class="smt-panel-head">
                    <span class="smt-panel-title">
                        <svg style="width:14px;height:14px;color:#64748b;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                        <span>Supplier Wallets &amp; Spend</span>
                    </span>
                    <span style="font-size:0.7rem;color:#64748b;font-weight:600;">Live balances</span>
                </div>
                <div class="smt-table-wrap">
                    <table class="smt-table">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>Wallet Balance</th>
                                <th>Orders</th>
                                <th style="text-align:right;">Period Spend</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($data['suppliers'] as $sup)
                                <tr>
                                    <td style="font-weight:600;color:#0f172a;" class="dark:!text-white">
                                        {{ $sup['name'] }}
                                        @if (! $sup['is_active'])
                                            <span style="font-size:0.65rem;color:#94a3b8;">(disabled)</span>
                                        @endif
                                    </td>
                                    <td style="font-family:monospace;font-weight:700;color:{{ $sup['balance_usdt'] < 1 ? '#e11d48' : '#0f172a' }};" class="{{ $sup['balance_usdt'] >= 1 ? 'dark:!text-white' : '' }}">
                                        {{ number_format($sup['balance_usdt'], 2) }} {{ $sup['currency'] }}
                                    </td>
                                    <td style="color:#64748b;">
                                        {{ $sup['orders_count'] }} sale · {{ $sup['direct_count'] }} direct
                                    </td>
                                    <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;" class="dark:!text-white">
                                        Rs {{ number_format($sup['spend_pkr'], 0) }}
                                        <span style="display:block;font-size:0.64rem;color:#64748b;font-weight:400;">
                                            ${{ number_format($sup['spend_usdt'], 3) }} {{ $sup['currency'] }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" style="text-align:center;color:#64748b;padding:1.25rem;">No suppliers configured.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- 4. Chronological Transactions & Profit Ledger --}}
        <div class="smt-panel">
            <div class="smt-panel-head">
                <div>
                    <span class="smt-panel-title">
                        <svg style="width:14px;height:14px;color:#64748b;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <span>Transactions &amp; Profit Ledger ({{ $data['period_label'] }})</span>
                    </span>
                </div>
                <span style="font-size:0.74rem;font-weight:600;color:#64748b;">
                    {{ count($data['entries']) }} records · Net Cashflow: <strong style="color:#0f172a;" class="dark:!text-white">Rs {{ number_format($data['net_cashflow_pkr'], 0) }}</strong>
                </span>
            </div>

            <div class="smt-table-wrap">
                <table class="smt-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Order / Ref</th>
                            <th>Type</th>
                            <th>Product &amp; Customer</th>
                            <th>Method / Source</th>
                            <th style="text-align:right;">Revenue</th>
                            <th style="text-align:right;">Cost</th>
                            <th style="text-align:right;">Net Profit</th>
                            <th style="text-align:right;">Cumulative</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['entries'] as $row)
                            <tr>
                                <td style="white-space:nowrap;">
                                    <div style="font-weight:600;color:#0f172a;" class="dark:!text-white">{{ $row['date_formatted'] }}</div>
                                    <div style="font-size:0.66rem;color:#64748b;">{{ $row['time_formatted'] }}</div>
                                </td>
                                <td style="white-space:nowrap;">
                                    <a href="{{ $row['url'] }}" style="font-family:monospace;font-weight:700;color:#4f46e5;text-decoration:none;">
                                        {{ $row['ref'] }}
                                    </a>
                                    <div style="font-size:0.65rem;color:#64748b;">{{ $row['status_label'] }}</div>
                                </td>
                                <td>
                                    <span class="smt-tag {{ $row['type'] === 'sale' ? 'emerald' : 'amber' }}">
                                        {{ $row['type_label'] }}
                                    </span>
                                </td>
                                <td style="min-width:200px;max-width:300px;">
                                    <div style="font-weight:600;color:#0f172a;line-height:1.25;" class="dark:!text-white">{{ $row['title'] }}</div>
                                    @if ($row['subtitle'])
                                        <div style="font-size:0.67rem;color:#64748b;margin-top:2px;word-break:break-all;">{{ $row['subtitle'] }}</div>
                                    @endif
                                </td>
                                <td style="white-space:nowrap;">
                                    <div style="font-weight:600;color:#0f172a;" class="dark:!text-white">{{ $row['channel'] }}</div>
                                    <div style="font-size:0.66rem;color:#64748b;">{{ $row['source'] }}</div>
                                </td>
                                <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;white-space:nowrap;" class="dark:!text-white">
                                    {{ $row['credit_pkr'] > 0 ? 'Rs '.number_format($row['credit_pkr'], 0) : '—' }}
                                </td>
                                <td style="text-align:right;font-family:monospace;font-weight:600;color:#64748b;white-space:nowrap;">
                                    {{ $row['debit_pkr'] > 0 ? 'Rs '.number_format($row['debit_pkr'], 0) : 'Rs 0' }}
                                    @if ($row['debit_usdt'] !== null)
                                        <span style="display:block;font-size:0.64rem;color:#94a3b8;">${{ number_format($row['debit_usdt'], 3) }}</span>
                                    @endif
                                </td>
                                <td style="text-align:right;font-family:monospace;font-weight:700;white-space:nowrap;color:{{ $row['net_pkr'] >= 0 ? '#059669' : '#e11d48' }};">
                                    {{ $row['net_pkr'] >= 0 ? '+Rs ' : '-Rs ' }}{{ number_format(abs($row['net_pkr']), 0) }}
                                    @if ($row['margin_pct'] !== null)
                                        <span style="display:block;font-size:0.64rem;color:#64748b;font-weight:500;">{{ $row['margin_pct'] }}%</span>
                                    @endif
                                </td>
                                <td style="text-align:right;font-family:monospace;font-weight:700;white-space:nowrap;color:#0f172a;" class="dark:!text-white">
                                    Rs {{ number_format($row['running_balance_pkr'], 0) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" style="text-align:center;color:#64748b;padding:2rem;">
                                    No transactions found for the selected period or search filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if (count($data['entries']) > 0)
                        <tfoot>
                            <tr style="background:#f8fafc;font-weight:700;border-top:1px solid #cbd5e1;" class="dark:!bg-slate-900">
                                <td colspan="5" style="text-align:right;font-size:0.72rem;color:#475569;">
                                    Period Totals ({{ $data['period_label'] }}):
                                </td>
                                <td style="text-align:right;font-family:monospace;color:#0f172a;" class="dark:!text-white">
                                    Rs {{ number_format($data['gross_sales_pkr'], 0) }}
                                </td>
                                <td style="text-align:right;font-family:monospace;color:#64748b;">
                                    Rs {{ number_format($data['total_cost_pkr'], 0) }}
                                </td>
                                <td style="text-align:right;font-family:monospace;color:#059669;">
                                    {{ $data['sales_net_profit_pkr'] >= 0 ? '+Rs ' : '-Rs ' }}{{ number_format(abs($data['sales_net_profit_pkr']), 0) }}
                                </td>
                                <td style="text-align:right;font-family:monospace;color:#0f172a;" class="dark:!text-white">
                                    Rs {{ number_format($data['net_cashflow_pkr'], 0) }}
                                </td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
