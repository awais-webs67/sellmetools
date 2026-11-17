@php
    $view = $this->getAnalyticsViewData();
    $report = $view['report'];
    $presets = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
    ];
@endphp

<x-filament-panels::page>
    <style>
        .smt-ga-wrap { display: flex; flex-direction: column; gap: 1rem; }
        .smt-ga-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1rem 1.15rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
        }
        .dark .smt-ga-card { background: #0f172a; border-color: #1e293b; }
        .smt-ga-topbar {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem;
        }
        .smt-ga-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
        .smt-ga-pill {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.28rem 0.68rem; border-radius: 999px;
            font-size: 0.74rem; font-weight: 700;
            border: 1px solid transparent;
        }
        .smt-ga-pill-green { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .dark .smt-ga-pill-green { background: rgba(16, 185, 129, 0.14); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.3); }
        .smt-ga-pill-amber { background: #fffbeb; color: #b45309; border-color: #fde68a; }
        .dark .smt-ga-pill-amber { background: rgba(245, 158, 11, 0.14); color: #fcd34d; border-color: rgba(245, 158, 11, 0.3); }
        .smt-ga-pill-slate { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .dark .smt-ga-pill-slate { background: #1e293b; color: #cbd5e1; border-color: #334155; }
        .smt-ga-dot { width: 7px; height: 7px; border-radius: 999px; background: currentColor; }

        .smt-ga-seg {
            display: inline-flex; flex-wrap: wrap; background: #f1f5f9;
            padding: 0.2rem; border-radius: 0.5rem; border: 1px solid #e2e8f0; gap: 2px;
        }
        .dark .smt-ga-seg { background: #1e293b; border-color: #334155; }
        .smt-ga-seg button {
            padding: 0.32rem 0.72rem; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 600;
            color: #475569; background: transparent; border: none; cursor: pointer;
        }
        .dark .smt-ga-seg button { color: #94a3b8; }
        .smt-ga-seg button.active {
            background: #ffffff; color: #0f172a; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
        }
        .dark .smt-ga-seg button.active { background: #0f172a; color: #f8fafc; }

        .smt-ga-kpis {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem;
        }
        .smt-ga-kpi-label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; }
        .dark .smt-ga-kpi-label { color: #94a3b8; }
        .smt-ga-kpi-val { font-size: 1.45rem; font-weight: 800; color: #0f172a; margin-top: 0.2rem; line-height: 1.2; }
        .dark .smt-ga-kpi-val { color: #f8fafc; }
        .smt-ga-kpi-sub { font-size: 0.73rem; color: #64748b; margin-top: 0.2rem; }
        .dark .smt-ga-kpi-sub { color: #94a3b8; }

        .smt-ga-grid-2 { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        @media (min-width: 960px) { .smt-ga-grid-2 { grid-template-columns: 1.3fr 1fr; } }

        .smt-ga-bars {
            display: flex; align-items: flex-end; gap: 6px; height: 155px; padding-top: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .dark .smt-ga-bars { border-color: #1e293b; }
        .smt-ga-bar-col { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 4px; height: 100%; justify-content: flex-end; }
        .smt-ga-bar {
            width: 100%; max-width: 30px; border-radius: 5px 5px 0 0;
            background: linear-gradient(180deg, #6366f1 0%, #4f46e5 100%);
            transition: height 0.2s ease;
        }
        .smt-ga-bar-lbl { font-size: 0.65rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }

        .smt-ga-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        .smt-ga-table th {
            text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;
            color: #64748b; padding: 0.5rem 0.6rem; border-bottom: 1px solid #e2e8f0;
        }
        .dark .smt-ga-table th { border-color: #1e293b; color: #94a3b8; }
        .smt-ga-table td { padding: 0.55rem 0.6rem; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
        .dark .smt-ga-table td { border-color: #1e293b; color: #e2e8f0; }
        .smt-ga-prog { height: 5px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: 4px; }
        .dark .smt-ga-prog { background: #1e293b; }
        .smt-ga-prog > span { display: block; height: 100%; background: #6366f1; border-radius: 999px; }
    </style>

    <div class="smt-ga-wrap">
        {{-- Top Status & Period Switcher --}}
        <div class="smt-ga-card smt-ga-topbar">
            <div class="smt-ga-badges">
                @if ($view['enabled'] && $view['measurement_id'] !== '')
                    <span class="smt-ga-pill smt-ga-pill-green">
                        <span class="smt-ga-dot"></span>
                        GA4 Tag Active: {{ $view['measurement_id'] }}
                    </span>
                @else
                    <span class="smt-ga-pill smt-ga-pill-amber">
                        <span class="smt-ga-dot"></span>
                        GA4 Tag Paused / Not Set
                    </span>
                @endif

                @if ($view['gtm_id'] !== '')
                    <span class="smt-ga-pill smt-ga-pill-slate">GTM: {{ $view['gtm_id'] }}</span>
                @endif

                <span class="smt-ga-pill smt-ga-pill-slate">
                    Events: page_view · view_item · begin_checkout · purchase
                </span>

                @if (! empty($report['ok']))
                    <span class="smt-ga-pill smt-ga-pill-green">
                        <span class="smt-ga-dot"></span>
                        {{ number_format((int) $report['realtime_users']) }} Live Visitor(s) Now
                    </span>
                @endif
            </div>

            <div class="smt-ga-seg">
                @foreach ($presets as $key => $label)
                    <button type="button" wire:click="setPreset('{{ $key }}')" class="{{ $this->preset === $key ? 'active' : '' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        @if (! empty($report['ok']))
            {{-- Live GA4 Data API KPIs --}}
            <div class="smt-ga-kpis">
                <div class="smt-ga-card">
                    <div class="smt-ga-kpi-label">Realtime Active (30m)</div>
                    <div class="smt-ga-kpi-val" style="color:#10b981;">{{ number_format((int) $report['realtime_users']) }}</div>
                    <div class="smt-ga-kpi-sub">Visitors on site right now</div>
                </div>
                <div class="smt-ga-card">
                    <div class="smt-ga-kpi-label">Active Visitors</div>
                    <div class="smt-ga-kpi-val">{{ number_format((int) $report['active_users']) }}</div>
                    <div class="smt-ga-kpi-sub">{{ number_format((int) $report['new_users']) }} new users</div>
                </div>
                <div class="smt-ga-card">
                    <div class="smt-ga-kpi-label">Sessions</div>
                    <div class="smt-ga-kpi-val">{{ number_format((int) $report['sessions']) }}</div>
                    <div class="smt-ga-kpi-sub">{{ $report['engagement_rate'] }}% engaged</div>
                </div>
                <div class="smt-ga-card">
                    <div class="smt-ga-kpi-label">Pageviews</div>
                    <div class="smt-ga-kpi-val">{{ number_format((int) $report['page_views']) }}</div>
                    <div class="smt-ga-kpi-sub">{{ $report['period_label'] }}</div>
                </div>
                <div class="smt-ga-card">
                    <div class="smt-ga-kpi-label">Store Paid Orders</div>
                    <div class="smt-ga-kpi-val">{{ number_format($view['store_orders_count']) }}</div>
                    <div class="smt-ga-kpi-sub">GA4 tracked: {{ number_format((int) $report['purchases']) }}</div>
                </div>
                <div class="smt-ga-card">
                    <div class="smt-ga-kpi-label">Store Revenue (PKR)</div>
                    <div class="smt-ga-kpi-val">Rs {{ number_format($view['store_revenue_pkr']) }}</div>
                    <div class="smt-ga-kpi-sub">Verified ledger sales</div>
                </div>
            </div>

            {{-- Daily Trend + Traffic Channels --}}
            <div class="smt-ga-grid-2">
                <div class="smt-ga-card">
                    <div style="font-size:0.9rem;font-weight:700;margin-bottom:0.25rem;">Daily Traffic Trend ({{ $report['period_label'] }})</div>
                    <div style="font-size:0.74rem;color:#64748b;">Daily pageviews & active visitors reported by Google Analytics 4</div>

                    @if (! empty($report['daily']))
                        <div class="smt-ga-bars">
                            @foreach ($report['daily'] as $d)
                                <div class="smt-ga-bar-col" title="{{ $d['date'] }}: {{ $d['views'] }} views · {{ $d['users'] }} users">
                                    <div class="smt-ga-bar" style="height: {{ $d['height_pct'] }}%;"></div>
                                    <div class="smt-ga-bar-lbl">{{ $d['date'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div style="padding:2rem 0;text-align:center;font-size:0.8rem;color:#64748b;">No daily data points for this range yet.</div>
                    @endif
                </div>

                <div class="smt-ga-card">
                    <div style="font-size:0.9rem;font-weight:700;margin-bottom:0.65rem;">Top Traffic Sources & Devices</div>
                    @forelse ($report['sources'] as $src)
                        <div style="margin-bottom:0.6rem;">
                            <div style="display:flex;justify-content:space-between;font-size:0.78rem;font-weight:600;">
                                <span>{{ $src['source'] }}</span>
                                <span>{{ number_format($src['sessions']) }} sess ({{ $src['share_pct'] }}%)</span>
                            </div>
                            <div class="smt-ga-prog"><span style="width: {{ min(100, $src['share_pct']) }}%;"></span></div>
                        </div>
                    @empty
                        <div style="font-size:0.8rem;color:#64748b;">No traffic source data yet.</div>
                    @endforelse

                    @if (! empty($report['devices']))
                        <div style="margin-top:1rem;padding-top:0.75rem;border-top:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:0.5rem;">
                            @foreach ($report['devices'] as $dv)
                                <span class="smt-ga-pill smt-ga-pill-slate">
                                    {{ $dv['device'] }}: {{ $dv['share_pct'] }}% ({{ number_format($dv['users']) }})
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Top Pages & Products --}}
            <div class="smt-ga-card">
                <div style="font-size:0.9rem;font-weight:700;margin-bottom:0.65rem;">Top Viewed Storefront Pages & Products</div>
                <div style="overflow-x:auto;">
                    <table class="smt-ga-table">
                        <thead>
                            <tr>
                                <th>Page Path</th>
                                <th>Page Title</th>
                                <th style="text-align:right;">Pageviews</th>
                                <th style="text-align:right;">Active Users</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['top_pages'] as $pg)
                                <tr>
                                    <td style="font-family:monospace;font-weight:600;">{{ $pg['path'] }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($pg['title'], 65) }}</td>
                                    <td style="text-align:right;font-weight:700;">{{ number_format($pg['views']) }}</td>
                                    <td style="text-align:right;">{{ number_format($pg['users']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" style="text-align:center;color:#64748b;">No pageviews recorded in this range yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            {{-- Error Banner if API configured but failed --}}
            @if (! empty($report['configured']) && ! empty($report['error']))
                <div class="smt-ga-card" style="border-color:#fca5a5;background:#fef2f2;">
                    <div style="font-size:0.88rem;font-weight:700;color:#b91c1c;">Google Analytics Data API Connection Issue</div>
                    <div style="font-size:0.8rem;color:#991b1b;margin-top:0.25rem;">{{ $report['error'] }}</div>
                    @if (! empty($report['service_account_email']))
                        <div style="font-size:0.76rem;color:#7f1d1d;margin-top:0.4rem;">
                            Make sure <strong>{{ $report['service_account_email'] }}</strong> is added as <strong>Viewer</strong> in Google Analytics → Admin → Property Access Management (Property ID: <code>{{ $report['property_id'] }}</code>).
                        </div>
                    @endif
                </div>
            @endif

            {{-- Storefront Tracking Status + Quick Setup Guide --}}
            <div class="smt-ga-kpis">
                <div class="smt-ga-card">
                    <div class="smt-ga-kpi-label">GA4 Storefront Tag</div>
                    <div class="smt-ga-kpi-val">{{ ($view['enabled'] && $view['measurement_id'] !== '') ? $view['measurement_id'] : 'Not Set' }}</div>
                    <div class="smt-ga-kpi-sub">{{ $view['enabled'] ? 'Non-blocking deferred gtag.js active' : 'Currently paused' }}</div>
                </div>
                <div class="smt-ga-card">
                    <div class="smt-ga-kpi-label">E-Commerce Funnel</div>
                    <div class="smt-ga-kpi-val">4 Events Live</div>
                    <div class="smt-ga-kpi-sub">page_view, view_item, begin_checkout, purchase</div>
                </div>
                <div class="smt-ga-card">
                    <div class="smt-ga-kpi-label">Paid Orders (Selected Range)</div>
                    <div class="smt-ga-kpi-val">{{ number_format($view['store_orders_count']) }}</div>
                    <div class="smt-ga-kpi-sub">Rs {{ number_format($view['store_revenue_pkr']) }} verified revenue</div>
                </div>
            </div>

            <div class="smt-ga-card">
                <div style="font-size:0.95rem;font-weight:800;margin-bottom:0.35rem;">
                    Connect Live Google Analytics 4 Charts Inside Admin (Optional)
                </div>
                <div style="font-size:0.82rem;color:#64748b;line-height:1.55;margin-bottom:0.85rem;">
                    Your storefront is already sending visitor & purchase events to Google Analytics (<strong>{{ $view['measurement_id'] ?: 'set Measurement ID via Configure GA4 button above' }}</strong>). To also view live visitors, traffic sources, and top pages directly on this page:
                </div>
                <ol style="font-size:0.81rem;line-height:1.75;padding-left:1.25rem;color:#334155;" class="dark:text-slate-300">
                    <li>Open <strong>Google Analytics → Admin → Property Details</strong> and copy your numeric <strong>Property ID</strong> (e.g. <code>412345678</code>).</li>
                    <li>In <strong>Google Cloud Console</strong>, enable the free <strong>Google Analytics Data API</strong>, create a <strong>Service Account</strong> JSON key, and add its <code>client_email</code> as <strong>Viewer</strong> in <strong>GA4 → Admin → Property Access Management</strong>.</li>
                    <li>Click <strong>Configure GA4</strong> in the top-right corner above (or go to <strong>Store Settings → SEO, Google & Bing</strong>) and paste your Property ID & Service Account JSON.</li>
                </ol>
            </div>
        @endif
    </div>
</x-filament-panels::page>
