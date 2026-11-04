@php
    $data = $this->getStatementData();
    $presets = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 Days',
        '30d' => 'Last 30 Days',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'all' => 'All Time',
    ];
@endphp

<x-filament-panels::page>
    <style>
        .smt-stmt-wrap { display: flex; flex-direction: column; gap: 1.25rem; }
        .smt-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.875rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .dark .smt-card {
            background: #0f172a;
            border-color: #1e293b;
        }
        .smt-filter-bar {
            padding: 1rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
        }
        .smt-preset-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.65rem;
        }
        .smt-preset-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }
        .smt-preset-btn {
            padding: 0.38rem 0.78rem;
            border-radius: 0.5rem;
            font-size: 0.76rem;
            font-weight: 700;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .smt-preset-btn:hover { background: #e2e8f0; }
        .smt-preset-btn.active {
            background: #4f46e5;
            border-color: #4f46e5;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.35);
        }
        .dark .smt-preset-btn {
            background: #1e293b;
            border-color: #334155;
            color: #cbd5e1;
        }
        .dark .smt-preset-btn.active {
            background: #4f46e5;
            border-color: #6366f1;
            color: #ffffff;
        }
        .smt-custom-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 0.75rem;
            align-items: end;
            padding-top: 0.75rem;
            border-top: 1px dashed #e2e8f0;
        }
        .dark .smt-custom-filters { border-top-color: #1e293b; }
        .smt-field label {
            display: block;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        .smt-field input, .smt-field select {
            width: 100%;
            border-radius: 0.5rem;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            padding: 0.42rem 0.65rem;
            font-size: 0.8rem;
            color: #0f172a;
        }
        .dark .smt-field input, .dark .smt-field select {
            background: #1e293b;
            border-color: #334155;
            color: #f8fafc;
        }

        /* KPI Grid */
        .smt-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(225px, 1fr));
            gap: 1rem;
        }
        .smt-kpi-box {
            padding: 1.15rem 1.25rem;
            position: relative;
            border-top: 4px solid #6366f1;
        }
        .smt-kpi-box.credit { border-top-color: #10b981; }
        .smt-kpi-box.debit { border-top-color: #f43f5e; }
        .smt-kpi-box.profit { border-top-color: #4f46e5; }
        .smt-kpi-box.pipeline { border-top-color: #f59e0b; }
        .smt-kpi-label {
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
        }
        .smt-kpi-val {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            margin-top: 0.25rem;
            color: #0f172a;
            font-variant-numeric: tabular-nums;
        }
        .dark .smt-kpi-val { color: #f8fafc; }
        .smt-kpi-sub {
            font-size: 0.74rem;
            color: #64748b;
            margin-top: 0.35rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            align-items: center;
        }

        /* 2-Col Breakdown */
        .smt-split-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1rem;
        }
        .smt-card-head {
            padding: 0.85rem 1.15rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .dark .smt-card-head {
            background: #1e293b;
            border-bottom-color: #334155;
        }
        .smt-card-title {
            font-size: 0.84rem;
            font-weight: 800;
            color: #0f172a;
        }
        .dark .smt-card-title { color: #f8fafc; }

        /* Statement Table */
        .smt-table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .smt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.78rem;
        }
        .smt-table th {
            text-align: left;
            padding: 0.6rem 0.75rem;
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #475569;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .dark .smt-table th {
            background: #1e293b;
            color: #94a3b8;
            border-bottom-color: #334155;
        }
        .smt-table td {
            padding: 0.55rem 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .dark .smt-table td { border-bottom-color: #1e293b; }
        .smt-table tr:hover td { background: rgba(79, 70, 229, 0.03); }
        .smt-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.14rem 0.45rem;
            border-radius: 9999px;
            font-size: 0.66rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .smt-badge-sale { background: #dcfce7; color: #166534; }
        .smt-badge-direct { background: #fef3c7; color: #92400e; }
        .dark .smt-badge-sale { background: rgba(22, 163, 74, 0.2); color: #86efac; }
        .dark .smt-badge-direct { background: rgba(217, 119, 6, 0.2); color: #fcd34d; }

        @media print {
            .fi-sidebar, .fi-topbar, .smt-no-print, .fi-header-actions { display: none !important; }
            .fi-main { padding: 0 !important; }
            .smt-card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
        }
    </style>

    <div class="smt-stmt-wrap">
        {{-- 1. Filter & Custom Date Range Bar --}}
        <div class="smt-card smt-filter-bar smt-no-print">
            <div class="smt-preset-row">
                <div class="smt-preset-pills">
                    @foreach ($presets as $key => $label)
                        <button type="button"
                                wire:click="applyPreset('{{ $key }}')"
                                class="smt-preset-btn {{ $preset === $key ? 'active' : '' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <span style="font-size:0.76rem;font-weight:700;color:#4f46e5;">
                    📅 Period: {{ $data['period_label'] }}
                </span>
            </div>

            <div class="smt-custom-filters">
                <div class="smt-field">
                    <label>Custom From Date</label>
                    <input type="date" wire:model.live="fromDate">
                </div>
                <div class="smt-field">
                    <label>Custom To Date</label>
                    <input type="date" wire:model.live="toDate">
                </div>
                <div class="smt-field">
                    <label>Transaction Type</label>
                    <select wire:model.live="entryType">
                        <option value="all">All Entries (Sales + Direct Buys)</option>
                        <option value="sales">Customer Sales Only (Credits +)</option>
                        <option value="direct">Direct Vendor Buys Only (Debits -)</option>
                    </select>
                </div>
                <div class="smt-field">
                    <label>Sort Order</label>
                    <select wire:model.live="sortDirection">
                        <option value="desc">Newest First</option>
                        <option value="asc">Oldest First (Chronological)</option>
                    </select>
                </div>
                <div class="smt-field">
                    <label>Search Order # / Email / Product / TID</label>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search statement...">
                </div>
            </div>
        </div>

        {{-- 2. Executive Financial KPI Cards --}}
        <div class="smt-kpi-grid">
            <div class="smt-card smt-kpi-box credit">
                <div class="smt-kpi-label">Total Revenue (Credits In +)</div>
                <div class="smt-kpi-val" style="color:#059669;">
                    +Rs {{ number_format($data['gross_sales_pkr'], 0) }}
                </div>
                <div class="smt-kpi-sub">
                    <span><strong>{{ $data['paid_orders_count'] }}</strong> Paid Orders</span>
                    <span>·</span>
                    <span>Avg: <strong>Rs {{ number_format($data['avg_order_value_pkr'], 0) }}</strong></span>
                </div>
            </div>

            <div class="smt-card smt-kpi-box debit">
                <div class="smt-kpi-label">Total Supplier &amp; Stock Cost (Debits -)</div>
                <div class="smt-kpi-val" style="color:#e11d48;">
                    -Rs {{ number_format($data['total_cost_pkr'], 0) }}
                </div>
                <div class="smt-kpi-sub">
                    <span>Orders Cost: <strong>Rs {{ number_format($data['order_cost_pkr'], 0) }}</strong></span>
                    <span>·</span>
                    <span>Direct Buys: <strong>Rs {{ number_format($data['direct_buy_cost_pkr'], 0) }}</strong></span>
                </div>
            </div>

            <div class="smt-card smt-kpi-box profit">
                <div class="smt-kpi-label">Net Profit &amp; Margin</div>
                <div class="smt-kpi-val" style="color:{{ $data['sales_net_profit_pkr'] >= 0 ? '#4f46e5' : '#e11d48' }};">
                    {{ $data['sales_net_profit_pkr'] >= 0 ? '+Rs ' : '-Rs ' }}{{ number_format(abs($data['sales_net_profit_pkr']), 0) }}
                </div>
                <div class="smt-kpi-sub">
                    <span class="smt-badge smt-badge-sale">{{ $data['sales_margin_pct'] }}% Margin</span>
                    <span>Net Cashflow: <strong>Rs {{ number_format($data['net_cashflow_pkr'], 0) }}</strong></span>
                </div>
            </div>

            <div class="smt-card smt-kpi-box pipeline">
                <div class="smt-kpi-label">Period Order Summary</div>
                <div class="smt-kpi-val">
                    {{ $data['completed_count'] }} <span style="font-size:0.85rem;font-weight:600;color:#64748b;">Delivered</span>
                </div>
                <div class="smt-kpi-sub">
                    <span>Pending/Review: <strong>{{ $data['pending_review_count'] }}</strong></span>
                    <span>·</span>
                    <span>Direct Buys: <strong>{{ $data['direct_buys_count'] }}</strong></span>
                    <span>·</span>
                    <span>Closed: <strong>{{ $data['cancelled_count'] }}</strong></span>
                </div>
            </div>
        </div>

        {{-- 3. Payment Gateways vs Supplier Wallets Breakdown --}}
        <div class="smt-split-grid">
            {{-- Payment Gateway Breakdown --}}
            <div class="smt-card">
                <div class="smt-card-head">
                    <span class="smt-card-title">💳 Payment Gateways (Money In by Method)</span>
                    <span style="font-size:0.72rem;color:#64748b;font-weight:600;">{{ count($data['gateways']) }} Active Methods</span>
                </div>
                <div class="smt-table-wrap">
                    <table class="smt-table">
                        <thead>
                            <tr>
                                <th>Gateway / Method</th>
                                <th>Orders</th>
                                <th>Total Received</th>
                                <th>Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($data['gateways'] as $gw)
                                <tr>
                                    <td style="font-weight:700;">{{ $gw['name'] }}</td>
                                    <td>{{ $gw['count'] }}</td>
                                    <td style="font-weight:800;color:#059669;">Rs {{ number_format($gw['total_pkr'], 0) }}</td>
                                    <td>
                                        <span class="smt-badge smt-badge-sale">{{ $gw['share_pct'] }}%</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" style="text-align:center;color:#64748b;padding:1.25rem;">No paid orders in this date range.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Supplier Wallets & Spend Breakdown --}}
            <div class="smt-card">
                <div class="smt-card-head">
                    <span class="smt-card-title">⚡ Supplier Wallets &amp; Period Spend (Money Out)</span>
                    <span style="font-size:0.72rem;color:#64748b;font-weight:600;">Live Balances &amp; Costs</span>
                </div>
                <div class="smt-table-wrap">
                    <table class="smt-table">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>Live Wallet</th>
                                <th>Orders (Sale / Direct)</th>
                                <th>Period Spend</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($data['suppliers'] as $sup)
                                <tr>
                                    <td style="font-weight:700;">
                                        {{ $sup['name'] }}
                                        @if (! $sup['is_active'])
                                            <span style="font-size:0.65rem;color:#94a3b8;">(Off)</span>
                                        @endif
                                    </td>
                                    <td style="font-family:monospace;font-weight:800;color:{{ $sup['balance_usdt'] < 1 ? '#e11d48' : '#059669' }};">
                                        {{ number_format($sup['balance_usdt'], 2) }} {{ $sup['currency'] }}
                                    </td>
                                    <td>
                                        {{ $sup['orders_count'] }} sale · {{ $sup['direct_count'] }} direct
                                    </td>
                                    <td style="font-weight:700;color:#e11d48;">
                                        Rs {{ number_format($sup['spend_pkr'], 0) }}
                                        <span style="display:block;font-size:0.66rem;color:#64748b;font-weight:500;">
                                            (${{ number_format($sup['spend_usdt'], 3) }} {{ $sup['currency'] }})
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

        {{-- 4. Full Chronological Bank Statement Ledger --}}
        <div class="smt-card">
            <div class="smt-card-head">
                <div>
                    <span class="smt-card-title">🏦 Official Bank Statement Ledger ({{ $data['period_label'] }})</span>
                    <span style="display:block;font-size:0.72rem;color:#64748b;">
                        Showing {{ count($data['entries']) }} transaction(s) with Credit, Debit, Net Profit &amp; Running Balance
                    </span>
                </div>
                <span style="font-size:0.75rem;font-weight:800;color:#4f46e5;">
                    Closing Net Balance: Rs {{ number_format($data['net_cashflow_pkr'], 0) }}
                </span>
            </div>

            <div class="smt-table-wrap">
                <table class="smt-table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Ref / Order #</th>
                            <th>Type</th>
                            <th>Product &amp; Customer Details</th>
                            <th>Gateway / Source</th>
                            <th style="text-align:right;">Credit In (+)</th>
                            <th style="text-align:right;">Debit Cost (-)</th>
                            <th style="text-align:right;">Net Profit</th>
                            <th style="text-align:right;">Running Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['entries'] as $row)
                            <tr>
                                <td style="white-space:nowrap;">
                                    <div style="font-weight:700;">{{ $row['date_formatted'] }}</div>
                                    <div style="font-size:0.68rem;color:#64748b;">{{ $row['time_formatted'] }}</div>
                                </td>
                                <td style="white-space:nowrap;">
                                    <a href="{{ $row['url'] }}" style="font-family:monospace;font-weight:800;color:#4f46e5;text-decoration:none;">
                                        {{ $row['ref'] }}
                                    </a>
                                    <div style="font-size:0.66rem;color:#64748b;">{{ $row['status_label'] }}</div>
                                </td>
                                <td>
                                    <span class="smt-badge {{ $row['type'] === 'sale' ? 'smt-badge-sale' : 'smt-badge-direct' }}">
                                        {{ $row['type_label'] }}
                                    </span>
                                </td>
                                <td style="min-width:210px;max-width:310px;">
                                    <div style="font-weight:700;line-height:1.25;">{{ $row['title'] }}</div>
                                    @if ($row['subtitle'])
                                        <div style="font-size:0.68rem;color:#64748b;margin-top:2px;word-break:break-all;">{{ $row['subtitle'] }}</div>
                                    @endif
                                </td>
                                <td style="white-space:nowrap;">
                                    <div style="font-weight:700;">{{ $row['channel'] }}</div>
                                    <div style="font-size:0.68rem;color:#64748b;">⚡ {{ $row['source'] }}</div>
                                </td>
                                <td style="text-align:right;font-family:monospace;font-weight:800;color:#059669;white-space:nowrap;">
                                    {{ $row['credit_pkr'] > 0 ? '+Rs '.number_format($row['credit_pkr'], 0) : '—' }}
                                </td>
                                <td style="text-align:right;font-family:monospace;font-weight:700;color:#e11d48;white-space:nowrap;">
                                    {{ $row['debit_pkr'] > 0 ? '-Rs '.number_format($row['debit_pkr'], 0) : 'Rs 0' }}
                                    @if ($row['debit_usdt'] !== null)
                                        <span style="display:block;font-size:0.65rem;color:#64748b;">(${{ number_format($row['debit_usdt'], 3) }})</span>
                                    @endif
                                </td>
                                <td style="text-align:right;font-family:monospace;font-weight:800;white-space:nowrap;color:{{ $row['net_pkr'] >= 0 ? '#059669' : '#e11d48' }};">
                                    {{ $row['net_pkr'] >= 0 ? '+Rs ' : '-Rs ' }}{{ number_format(abs($row['net_pkr']), 0) }}
                                    @if ($row['margin_pct'] !== null)
                                        <span style="display:block;font-size:0.65rem;color:#64748b;font-weight:600;">{{ $row['margin_pct'] }}%</span>
                                    @endif
                                </td>
                                <td style="text-align:right;font-family:monospace;font-weight:800;white-space:nowrap;color:#0f172a;" class="dark:!text-white">
                                    Rs {{ number_format($row['running_balance_pkr'], 0) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" style="text-align:center;color:#64748b;padding:2rem;">
                                    No transactions found for the selected date range or search filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if (count($data['entries']) > 0)
                        <tfoot>
                            <tr style="background:#f8fafc;font-weight:800;border-top:2px solid #cbd5e1;" class="dark:!bg-slate-900">
                                <td colspan="5" style="text-align:right;text-transform:uppercase;font-size:0.72rem;letter-spacing:0.04em;">
                                    Statement Totals ({{ $data['period_label'] }}):
                                </td>
                                <td style="text-align:right;font-family:monospace;color:#059669;">
                                    +Rs {{ number_format($data['gross_sales_pkr'], 0) }}
                                </td>
                                <td style="text-align:right;font-family:monospace;color:#e11d48;">
                                    -Rs {{ number_format($data['total_cost_pkr'], 0) }}
                                </td>
                                <td style="text-align:right;font-family:monospace;color:#4f46e5;">
                                    {{ $data['sales_net_profit_pkr'] >= 0 ? '+Rs ' : '-Rs ' }}{{ number_format(abs($data['sales_net_profit_pkr']), 0) }}
                                </td>
                                <td style="text-align:right;font-family:monospace;">
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

