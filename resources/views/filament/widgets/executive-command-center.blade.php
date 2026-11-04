@php
    $m = $this->getDashboardMetrics();
    $ranges = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 Days',
        'this_month' => 'This Month',
        'all' => 'All Time',
    ];
    $adminName = auth()->user()?->name ?: 'Admin';
@endphp

<x-filament-widgets::widget>
    <style>
        .smt-dash {
            display: flex;
            flex-direction: column;
            gap: 1.15rem;
        }

        /* 1. Executive Dark Command Banner */
        .smt-hero {
            background: radial-gradient(circle at 85% 15%, rgba(79, 70, 229, 0.35) 0%, transparent 45%),
                        linear-gradient(135deg, #0b0f19 0%, #0f172a 60%, #1e1b4b 100%);
            border: 1px solid rgba(99, 102, 241, 0.28);
            border-radius: 1rem;
            padding: 1.25rem 1.4rem;
            color: #ffffff;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .smt-hero-top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.85rem;
        }
        .smt-hero-pulse {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.22rem 0.65rem;
            border-radius: 9999px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #6ee7b7;
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .smt-hero-pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 9999px;
            background: #10b981;
            box-shadow: 0 0 8px #10b981;
        }
        .smt-hero-title {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            margin-top: 0.3rem;
            color: #ffffff;
        }
        .smt-hero-sub {
            font-size: 0.78rem;
            color: #94a3b8;
            margin-top: 0.1rem;
        }
        .smt-range-bar {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 0.3rem;
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(148, 163, 184, 0.2);
            padding: 0.28rem;
            border-radius: 0.65rem;
        }
        .smt-range-btn {
            padding: 0.35rem 0.72rem;
            border-radius: 0.45rem;
            font-size: 0.73rem;
            font-weight: 700;
            color: #cbd5e1;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .smt-range-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
        }
        .smt-range-btn.active {
            background: #4f46e5;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.45);
        }
        .smt-hero-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.65rem;
            padding-top: 0.85rem;
            border-top: 1px solid rgba(148, 163, 184, 0.14);
        }
        .smt-quick-links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }
        .smt-qbtn {
            display: inline-flex;
            align-items: center;
            gap: 0.38rem;
            padding: 0.4rem 0.78rem;
            border-radius: 0.55rem;
            font-size: 0.74rem;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(255, 255, 255, 0.07);
            color: #f8fafc;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .smt-qbtn:hover {
            background: rgba(255, 255, 255, 0.14);
            transform: translateY(-1px);
        }
        .smt-qbtn.primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: rgba(16, 185, 129, 0.5);
            color: #ffffff;
        }
        .smt-qbtn.indigo {
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            border-color: rgba(99, 102, 241, 0.5);
            color: #ffffff;
        }

        /* 2. 4-Card Primary KPI Grid */
        .smt-kpi-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.9rem;
        }
        @media (max-width: 1100px) {
            .smt-kpi-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 580px) {
            .smt-kpi-row { grid-template-columns: 1fr; }
        }
        .smt-bento-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 0.9rem;
            padding: 1.05rem 1.15rem;
            box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        a.smt-bento-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -6px rgba(15, 23, 42, 0.1);
        }
        .dark .smt-bento-card {
            background: #0f172a;
            border-color: #1e293b;
        }
        .smt-kpi-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .smt-kpi-tag {
            font-size: 0.69rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.055em;
            color: #64748b;
        }
        .smt-kpi-icon {
            width: 34px;
            height: 34px;
            border-radius: 0.6rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .smt-kpi-num {
            font-size: 1.55rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-top: 0.4rem;
            color: #0f172a;
            font-variant-numeric: tabular-nums;
            line-height: 1.15;
        }
        .dark .smt-kpi-num { color: #f8fafc; }
        .smt-kpi-foot {
            margin-top: 0.55rem;
            padding-top: 0.5rem;
            border-top: 1px dashed #e2e8f0;
            font-size: 0.72rem;
            color: #64748b;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.35rem;
        }
        .dark .smt-kpi-foot { border-top-color: #1e293b; }
        .smt-pill {
            display: inline-flex;
            align-items: center;
            padding: 0.12rem 0.48rem;
            border-radius: 9999px;
            font-size: 0.66rem;
            font-weight: 800;
        }
        .smt-pill.green { background: #dcfce7; color: #166534; }
        .smt-pill.amber { background: #fef3c7; color: #92400e; }
        .smt-pill.rose  { background: #ffe4e6; color: #9f1239; }
        .smt-pill.indigo { background: #e0e7ff; color: #3730a3; }
        .dark .smt-pill.green { background: rgba(22, 163, 74, 0.22); color: #86efac; }
        .dark .smt-pill.amber { background: rgba(217, 119, 6, 0.22); color: #fcd34d; }
        .dark .smt-pill.rose  { background: rgba(225, 29, 72, 0.22); color: #fda4af; }
        .dark .smt-pill.indigo { background: rgba(79, 70, 229, 0.25); color: #c7d2fe; }

        /* 3. Mid Bento Row: 7-Day Visual Chart + Supplier Wallets */
        .smt-mid-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 0.9rem;
        }
        @media (max-width: 1024px) {
            .smt-mid-grid { grid-template-columns: 1fr; }
        }
        .smt-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 0.85rem;
        }
        .dark .smt-section-head { border-bottom-color: #1e293b; }
        .smt-section-title {
            font-size: 0.86rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .dark .smt-section-title { color: #f8fafc; }

        /* 7-Day Bar Chart */
        .smt-chart-bars {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 0.55rem;
            align-items: end;
            height: 150px;
            padding-top: 1rem;
        }
        .smt-bar-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.35rem;
            height: 100%;
            justify-content: flex-end;
        }
        .smt-bar-val {
            font-size: 0.63rem;
            font-weight: 700;
            color: #64748b;
            white-space: nowrap;
        }
        .smt-bar-track {
            width: 100%;
            max-width: 38px;
            height: 102px;
            background: #f1f5f9;
            border-radius: 0.45rem;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        .dark .smt-bar-track { background: #1e293b; }
        .smt-bar-fill-rev {
            width: 100%;
            background: linear-gradient(180deg, #6366f1 0%, #4338ca 100%);
            border-radius: 0.4rem 0.4rem 0 0;
            position: relative;
            transition: height 0.3s ease;
        }
        .smt-bar-fill-prof {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(180deg, #10b981 0%, #059669 100%);
            border-radius: 0.3rem 0.3rem 0 0;
        }
        .smt-bar-day {
            font-size: 0.68rem;
            font-weight: 800;
            color: #334155;
        }
        .dark .smt-bar-day { color: #cbd5e1; }

        /* Supplier Wallet Mini-Cards */
        .smt-sup-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.65rem;
        }
        @media (max-width: 540px) {
            .smt-sup-grid { grid-template-columns: 1fr; }
        }
        .smt-sup-box {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 0.7rem;
            padding: 0.7rem 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .dark .smt-sup-box {
            background: #1e293b;
            border-color: #334155;
        }
        .smt-sync-mini {
            padding: 0.28rem 0.52rem;
            border-radius: 0.45rem;
            font-size: 0.68rem;
            font-weight: 700;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .smt-sync-mini:hover {
            background: #4f46e5;
            color: #ffffff;
            border-color: #4f46e5;
        }
        .dark .smt-sync-mini {
            background: #0f172a;
            border-color: #475569;
            color: #e2e8f0;
        }

        /* 4. Bottom Bento Row: Top Products + Payment Gateways & Store Health */
        .smt-bot-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 0.9rem;
        }
        @media (max-width: 1024px) {
            .smt-bot-grid { grid-template-columns: 1fr; }
        }
        .smt-list-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.65rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .smt-list-row:last-child { border-bottom: none; }
        .dark .smt-list-row { border-bottom-color: #1e293b; }
        .smt-prog-bg {
            height: 6px;
            width: 100%;
            background: #f1f5f9;
            border-radius: 9999px;
            overflow: hidden;
            margin-top: 4px;
        }
        .dark .smt-prog-bg { background: #1e293b; }
        .smt-prog-fill {
            height: 100%;
            background: linear-gradient(90deg, #4f46e5, #10b981);
            border-radius: 9999px;
        }
    </style>

    <div class="smt-dash">
        {{-- 1. Executive Command Banner --}}
        <div class="smt-hero">
            <div class="smt-hero-top">
                <div>
                    <div class="smt-hero-pulse">
                        <span class="smt-hero-pulse-dot"></span>
                        <span>SellMeTools E-Commerce Command Center · {{ $m['range_label'] }}</span>
                    </div>
                    <h2 class="smt-hero-title">Welcome back, {{ $adminName }} 👋</h2>
                    <p class="smt-hero-sub">
                        Live store overview, automated supplier wallets, and instant order fulfillment controls.
                    </p>
                </div>

                {{-- Interactive Period Filter Pills --}}
                <div class="smt-range-bar">
                    @foreach ($ranges as $key => $label)
                        <button type="button"
                                wire:click="setRange('{{ $key }}')"
                                class="smt-range-btn {{ $range === $key ? 'active' : '' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="smt-hero-actions">
                <div class="smt-quick-links">
                    <a href="{{ $m['urls']['statement'] }}" class="smt-qbtn primary">
                        <span>🏦 Full Bank Statement &amp; Reports</span>
                    </a>
                    <a href="{{ $m['urls']['vendor_orders'] }}" class="smt-qbtn indigo">
                        <span>⚡ Direct &amp; Vendor Orders</span>
                    </a>
                    <a href="{{ $m['urls']['create_product'] }}" class="smt-qbtn">
                        <span>➕ New Product</span>
                    </a>
                    <button type="button" wire:click="syncAllSuppliers" wire:loading.attr="disabled" class="smt-qbtn">
                        <span wire:loading.remove wire:target="syncAllSuppliers">🔄 Sync All Suppliers</span>
                        <span wire:loading wire:target="syncAllSuppliers">⏳ Syncing...</span>
                    </button>
                </div>

                <div style="display:flex;align-items:center;gap:0.85rem;font-size:0.74rem;color:#cbd5e1;">
                    <span>📦 Active Products: <strong style="color:#fff;">{{ $m['in_stock_products_count'] }}/{{ $m['active_products_count'] }}</strong></span>
                    <span>·</span>
                    <span>👥 Customers: <strong style="color:#fff;">{{ $m['customers_count'] }}</strong></span>
                </div>
            </div>
        </div>

        {{-- 2. Primary 4 Bento KPI Cards --}}
        <div class="smt-kpi-row">
            {{-- Revenue Card --}}
            <a href="{{ $m['urls']['statement'] }}" class="smt-bento-card" style="border-top: 3px solid #10b981;">
                <div>
                    <div class="smt-kpi-head">
                        <span class="smt-kpi-tag">Gross Revenue ({{ $m['range_label'] }})</span>
                        <span class="smt-kpi-icon" style="background:rgba(16,185,129,0.12);color:#059669;">💰</span>
                    </div>
                    <div class="smt-kpi-num" style="color:#059669;">
                        Rs {{ number_format($m['revenue_pkr'], 0) }}
                    </div>
                </div>
                <div class="smt-kpi-foot">
                    <span class="smt-pill green">{{ $m['paid_orders_count'] }} Paid Orders</span>
                    <span>Avg: <strong>Rs {{ number_format($m['avg_order_pkr'], 0) }}</strong></span>
                </div>
            </a>

            {{-- Net Profit Card --}}
            <a href="{{ $m['urls']['statement'] }}" class="smt-bento-card" style="border-top: 3px solid #4f46e5;">
                <div>
                    <div class="smt-kpi-head">
                        <span class="smt-kpi-tag">Net Profit ({{ $m['range_label'] }})</span>
                        <span class="smt-kpi-icon" style="background:rgba(79,70,229,0.12);color:#4f46e5;">📈</span>
                    </div>
                    <div class="smt-kpi-num" style="color:{{ $m['net_profit_pkr'] >= 0 ? '#4f46e5' : '#e11d48' }};">
                        {{ $m['net_profit_pkr'] >= 0 ? '+Rs ' : '-Rs ' }}{{ number_format(abs($m['net_profit_pkr']), 0) }}
                    </div>
                </div>
                <div class="smt-kpi-foot">
                    <span class="smt-pill indigo">{{ $m['margin_pct'] }}% Margin</span>
                    <span>Cost: <strong>Rs {{ number_format($m['order_cost_pkr'], 0) }}</strong></span>
                </div>
            </a>

            {{-- Payment Verification Queue --}}
            <a href="{{ $m['urls']['review_orders'] }}" class="smt-bento-card" style="border-top: 3px solid {{ $m['pending_payments_count'] > 0 ? '#f59e0b' : '#94a3b8' }};">
                <div>
                    <div class="smt-kpi-head">
                        <span class="smt-kpi-tag">Payment Review Queue</span>
                        <span class="smt-kpi-icon" style="background:rgba(245,158,11,0.14);color:#d97706;">⏳</span>
                    </div>
                    <div class="smt-kpi-num" style="color:{{ $m['pending_payments_count'] > 0 ? '#d97706' : '#64748b' }};">
                        {{ $m['pending_payments_count'] }}
                    </div>
                </div>
                <div class="smt-kpi-foot">
                    @if ($m['pending_payments_count'] > 0)
                        <span class="smt-pill amber">Action Needed: Approve TID</span>
                    @else
                        <span class="smt-pill green">✓ All Payments Verified</span>
                    @endif
                    <span>Open Queue →</span>
                </div>
            </a>

            {{-- Delivery & Vendor Alert Queue --}}
            <a href="{{ $m['unfulfilled_vendor_count'] > 0 ? $m['urls']['unfulfilled_vendor'] : $m['urls']['attention_orders'] }}"
               class="smt-bento-card"
               style="border-top: 3px solid {{ ($m['needs_attention_count'] + $m['unfulfilled_vendor_count']) > 0 ? '#e11d48' : '#10b981' }};">
                <div>
                    <div class="smt-kpi-head">
                        <span class="smt-kpi-tag">Pending Delivery / Alerts</span>
                        <span class="smt-kpi-icon" style="background:rgba(225,29,72,0.12);color:#e11d48;">⚡</span>
                    </div>
                    <div class="smt-kpi-num" style="color:{{ ($m['needs_attention_count'] + $m['unfulfilled_vendor_count']) > 0 ? '#e11d48' : '#059669' }};">
                        {{ $m['needs_attention_count'] }} <span style="font-size:0.85rem;font-weight:600;color:#64748b;">Orders</span>
                    </div>
                </div>
                <div class="smt-kpi-foot">
                    @if ($m['unfulfilled_vendor_count'] > 0)
                        <span class="smt-pill rose">{{ $m['unfulfilled_vendor_count'] }} Vendor Unfulfilled</span>
                    @elseif ($m['needs_attention_count'] > 0)
                        <span class="smt-pill amber">Awaiting Manual Delivery</span>
                    @else
                        <span class="smt-pill green">✓ Zero Delivery Issues</span>
                    @endif
                    <span>Direct Buys: <strong>{{ $m['direct_buys_count'] }}</strong></span>
                </div>
            </a>
        </div>

        {{-- 3. Middle Bento Row: 7-Day Revenue vs Profit Chart + Live Supplier Wallets --}}
        <div class="smt-mid-grid">
            {{-- 7-Day Revenue & Profit Visual Bar Chart --}}
            <div class="smt-bento-card">
                <div>
                    <div class="smt-section-head">
                        <span class="smt-section-title">📊 Last 7 Days Revenue &amp; Profit Trend</span>
                        <div style="display:flex;align-items:center;gap:0.65rem;font-size:0.68rem;font-weight:700;color:#64748b;">
                            <span style="display:inline-flex;align-items:center;gap:4px;">
                                <span style="width:8px;height:8px;border-radius:2px;background:#4f46e5;"></span> Revenue
                            </span>
                            <span style="display:inline-flex;align-items:center;gap:4px;">
                                <span style="width:8px;height:8px;border-radius:2px;background:#10b981;"></span> Net Profit
                            </span>
                        </div>
                    </div>

                    <div class="smt-chart-bars">
                        @foreach ($m['daily_chart'] as $day)
                            <div class="smt-bar-col" title="{{ $day['date_short'] }}: Sales Rs {{ number_format($day['revenue'], 0) }} | Profit Rs {{ number_format($day['profit'], 0) }} ({{ $day['orders'] }} orders)">
                                <span class="smt-bar-val">
                                    {{ $day['revenue'] >= 1000 ? round($day['revenue'] / 1000, 1).'k' : ($day['revenue'] > 0 ? number_format($day['revenue'], 0) : '0') }}
                                </span>
                                <div class="smt-bar-track">
                                    <div class="smt-bar-fill-rev" style="height: {{ $day['height_pct'] }}%;">
                                        <div class="smt-bar-fill-prof" style="height: {{ $day['revenue'] > 0 ? min(100, round(($day['profit'] / max(1, $day['revenue'])) * 100)) : 0 }}%;"></div>
                                    </div>
                                </div>
                                <span class="smt-bar-day">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Live Supplier Wallets & 1-Tap Sync --}}
            <div class="smt-bento-card">
                <div>
                    <div class="smt-section-head">
                        <span class="smt-section-title">⚡ Supplier Wallets &amp; Live API Status</span>
                        <a href="{{ $m['urls']['suppliers'] }}" style="font-size:0.72rem;font-weight:700;color:#4f46e5;text-decoration:none;">
                            Manage All →
                        </a>
                    </div>

                    <div class="smt-sup-grid">
                        @foreach ($m['suppliers'] as $sup)
                            @php
                                $bal = (float) ($sup->balance ?? 0);
                                $low = (float) ($sup->low_balance_threshold ?? 1.0);
                                $isLow = $bal < max(0.5, $low);
                                $pkrEq = $sup->toPkr($bal);
                            @endphp
                            <div class="smt-sup-box">
                                <div style="min-width:0;">
                                    <div style="display:flex;align-items:center;gap:0.35rem;">
                                        <span style="width:7px;height:7px;border-radius:9999px;background:{{ $sup->is_active ? ($isLow ? '#f59e0b' : '#10b981') : '#94a3b8' }};"></span>
                                        <span style="font-size:0.78rem;font-weight:800;color:#0f172a;" class="dark:!text-white truncate">
                                            {{ $sup->name }}
                                        </span>
                                    </div>
                                    <div style="font-family:monospace;font-size:0.9rem;font-weight:800;margin-top:2px;color:{{ $isLow ? '#e11d48' : '#059669' }};">
                                        {{ number_format($bal, 2) }} {{ $sup->currency ?: 'USDT' }}
                                    </div>
                                    <div style="font-size:0.65rem;color:#64748b;">
                                        ≈ Rs {{ number_format($pkrEq, 0) }} · {{ $sup->products_count }} items
                                    </div>
                                </div>

                                <button type="button"
                                        wire:click="syncSupplierNow({{ $sup->id }})"
                                        wire:loading.attr="disabled"
                                        class="smt-sync-mini"
                                        title="Sync {{ $sup->name }} now">
                                    🔄 Sync
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- 4. Bottom Bento Row: Top Selling Products + Payment Gateways Breakdown --}}
        <div class="smt-bot-grid">
            {{-- Top Selling Products Leaderboard --}}
            <div class="smt-bento-card">
                <div>
                    <div class="smt-section-head">
                        <span class="smt-section-title">🏆 Top Selling Products ({{ $m['range_label'] }})</span>
                        <a href="{{ $m['urls']['products'] }}" style="font-size:0.72rem;font-weight:700;color:#4f46e5;text-decoration:none;">
                            All Products →
                        </a>
                    </div>

                    @forelse ($m['top_products'] as $idx => $tp)
                        <div class="smt-list-row">
                            <div style="display:flex;align-items:center;gap:0.6rem;min-width:0;">
                                <span style="width:22px;height:22px;border-radius:6px;background:#e0e7ff;color:#3730a3;font-size:0.7rem;font-weight:800;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    #{{ $idx + 1 }}
                                </span>
                                <div style="min-width:0;">
                                    <div style="font-size:0.78rem;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" class="dark:!text-white">
                                        {{ $tp->product_name }}
                                    </div>
                                    <div style="font-size:0.67rem;color:#64748b;">
                                        {{ (int) $tp->units_sold }} unit(s) sold
                                    </div>
                                </div>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <div style="font-family:monospace;font-size:0.8rem;font-weight:800;color:#059669;">
                                    Rs {{ number_format((float) $tp->total_rev, 0) }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <div style="text-align:center;padding:1.5rem 0;font-size:0.76rem;color:#64748b;">
                            No paid product sales recorded in {{ strtolower($m['range_label']) }} yet.
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Payment Gateways Mix --}}
            <div class="smt-bento-card">
                <div>
                    <div class="smt-section-head">
                        <span class="smt-section-title">💳 Payment Gateways Mix ({{ $m['range_label'] }})</span>
                        <a href="{{ $m['urls']['statement'] }}" style="font-size:0.72rem;font-weight:700;color:#4f46e5;text-decoration:none;">
                            Full Ledger →
                        </a>
                    </div>

                    @forelse ($m['gateways'] as $gw)
                        <div style="padding:0.4rem 0;">
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.76rem;">
                                <span style="font-weight:700;color:#0f172a;" class="dark:!text-white">
                                    {{ $gw['name'] }} <span style="font-size:0.68rem;color:#64748b;font-weight:500;">({{ $gw['count'] }} orders)</span>
                                </span>
                                <span style="font-family:monospace;font-weight:800;color:#059669;">
                                    Rs {{ number_format($gw['total'], 0) }} ({{ $gw['share_pct'] }}%)
                                </span>
                            </div>
                            <div class="smt-prog-bg">
                                <div class="smt-prog-fill" style="width: {{ max(4, $gw['share_pct']) }}%;"></div>
                            </div>
                        </div>
                    @empty
                        <div style="text-align:center;padding:1.5rem 0;font-size:0.76rem;color:#64748b;">
                            No gateway payments in {{ strtolower($m['range_label']) }} yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
