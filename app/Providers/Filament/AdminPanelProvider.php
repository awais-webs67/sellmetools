<?php

namespace App\Providers\Filament;

use App\Models\Setting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Throwable;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(fn (): string => $this->storeName())
            ->brandLogo(fn (): HtmlString => new HtmlString(
                '<div style="display:inline-flex;align-items:center;gap:0.5rem;">'
                .'<img src="'.e(asset('images/logo.png').'?v=6').'" alt="'.e($this->storeName()).'" style="height:28px;width:auto;object-fit:contain;" class="dark:brightness-0 dark:invert" />'
                .'</div>'
            ))
            ->brandLogoHeight('28px')
            ->favicon(fn (): string => asset('favicon.png').'?v=8')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => Color::Indigo,
                'warning' => Color::Amber,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): HtmlString => new HtmlString(
                    '<a href="'.e(url('/')).'" target="_blank" rel="noopener" class="fi-live-store-btn">'
                    .'<svg style="width:14px;height:14px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>'
                    .'<span>Live Store</span>'
                    .'</a>'
                )
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString('<style>
                    /* ==========================================================================
                       SellMeTools Executive Admin UI/UX Design System (Filament 5)
                       ========================================================================== */

                    /* 1. Live Store Header Button */
                    .fi-live-store-btn {
                        display: inline-flex;
                        align-items: center;
                        gap: 0.4rem;
                        padding: 0.4rem 0.8rem;
                        border-radius: 0.5rem;
                        font-size: 0.78rem;
                        font-weight: 600;
                        border: 1px solid rgba(148, 163, 184, 0.35);
                        background: rgba(248, 250, 252, 0.8);
                        color: #0f172a;
                        text-decoration: none;
                        white-space: nowrap;
                        transition: all 0.15s ease;
                    }
                    .fi-live-store-btn:hover {
                        background: #f1f5f9;
                        border-color: rgba(100, 116, 139, 0.5);
                    }
                    .dark .fi-live-store-btn {
                        background: rgba(30, 41, 59, 0.7);
                        color: #f8fafc;
                        border-color: rgba(148, 163, 184, 0.25);
                    }

                    /* 2. Modern Responsive Admin Login Page (.fi-simple-layout) */
                    .fi-simple-layout {
                        min-height: 100dvh !important;
                        background: radial-gradient(circle at top right, #1e293b 0%, #0f172a 55%, #020617 100%) !important;
                        padding: 1rem !important;
                        display: flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                    }
                    .fi-simple-main-ctn {
                        width: 100% !important;
                        max-width: 430px !important;
                        margin: 0 auto !important;
                    }
                    .fi-simple-main {
                        width: 100% !important;
                        padding: 2rem 1.75rem !important;
                        border-radius: 1rem !important;
                        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
                        border: 1px solid rgba(148, 163, 184, 0.18) !important;
                    }
                    .fi-simple-header-heading {
                        font-weight: 800 !important;
                        letter-spacing: -0.02em !important;
                    }

                    /* 3. Main Content Container & Page Header Hierarchy */
                    .fi-main {
                        padding-top: 1.25rem !important;
                        padding-bottom: 2.5rem !important;
                    }
                    .fi-header {
                        display: flex !important;
                        flex-wrap: wrap !important;
                        align-items: center !important;
                        justify-content: space-between !important;
                        gap: 0.85rem !important;
                        margin-bottom: 0.25rem !important;
                    }
                    .fi-header-heading {
                        font-size: 1.5rem !important;
                        font-weight: 800 !important;
                        letter-spacing: -0.025em !important;
                        line-height: 1.25 !important;
                    }
                    .fi-header-actions {
                        display: flex !important;
                        flex-wrap: wrap !important;
                        align-items: center !important;
                        gap: 0.5rem !important;
                    }

                    /* 4. Section Cards, Boxes & Containers — Anti-Overflow & Clean Elevation */
                    .fi-section,
                    .fi-ta-ctn,
                    .fi-wi-stats-overview-stat {
                        border-radius: 0.875rem !important;
                        border: 1px solid rgba(226, 232, 240, 0.95) !important;
                        box-shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 1px 2px -1px rgba(15, 23, 42, 0.03) !important;
                        overflow: hidden !important;
                        min-width: 0 !important;
                    }
                    .dark .fi-section,
                    .dark .fi-ta-ctn,
                    .dark .fi-wi-stats-overview-stat {
                        border-color: rgba(51, 65, 85, 0.8) !important;
                        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.25) !important;
                    }
                    .fi-section-header {
                        padding: 1rem 1.25rem !important;
                        border-bottom: 1px solid rgba(226, 232, 240, 0.7) !important;
                        background: rgba(248, 250, 252, 0.6) !important;
                    }
                    .dark .fi-section-header {
                        border-bottom-color: rgba(51, 65, 85, 0.6) !important;
                        background: rgba(15, 23, 42, 0.35) !important;
                    }
                    .fi-section-header-heading {
                        font-size: 0.95rem !important;
                        font-weight: 700 !important;
                        letter-spacing: -0.01em !important;
                    }
                    .fi-section-header-description {
                        font-size: 0.8rem !important;
                        line-height: 1.45 !important;
                    }
                    .fi-section-content {
                        padding: 1.25rem !important;
                        min-width: 0 !important;
                    }

                    /* 5. Prevent Box & Grid Overflow on Infolists, Repeatable Entries & Forms */
                    .fi-sc-grid,
                    .fi-in-entry,
                    .fi-fo-field-wrp,
                    .fi-in-repeatable-item {
                        min-width: 0 !important;
                        max-width: 100% !important;
                    }
                    .fi-in-text,
                    .fi-in-text-item,
                    .fi-in-affixes,
                    .fi-fo-field-wrp-helper-text,
                    .fi-fo-field-wrp-label,
                    .fi-section-header-description {
                        min-width: 0 !important;
                        max-width: 100% !important;
                        overflow-wrap: anywhere !important;
                        word-break: break-word !important;
                    }
                    .fi-sc-actions {
                        display: flex !important;
                        flex-wrap: wrap !important;
                        gap: 0.5rem !important;
                    }
                    .fi-in-entry .fi-badge {
                        max-width: 100% !important;
                        white-space: normal !important;
                        text-align: left !important;
                        height: auto !important;
                        line-height: 1.35 !important;
                        padding-top: 0.25rem !important;
                        padding-bottom: 0.25rem !important;
                    }
                    .fi-in-repeatable-item {
                        border-radius: 0.75rem !important;
                        border: 1px solid rgba(226, 232, 240, 0.9) !important;
                        background: rgba(248, 250, 252, 0.55) !important;
                        padding: 1rem !important;
                    }
                    .dark .fi-in-repeatable-item {
                        border-color: rgba(51, 65, 85, 0.75) !important;
                        background: rgba(15, 23, 42, 0.4) !important;
                    }

                    /* 6. Dashboard Stats Overview Cards — Fluid Auto-Fit Grid */
                    .fi-wi-stats-overview-stats-ctn {
                        display: grid !important;
                        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) !important;
                        gap: 1rem !important;
                    }
                    .fi-wi-stats-overview-stat {
                        padding: 1.15rem 1.25rem !important;
                        transition: transform 0.15s ease, box-shadow 0.15s ease !important;
                    }
                    a.fi-wi-stats-overview-stat:hover {
                        transform: translateY(-1px) !important;
                        box-shadow: 0 8px 20px -6px rgba(15, 23, 42, 0.08) !important;
                    }
                    .fi-wi-stats-overview-stat-value {
                        font-size: 1.55rem !important;
                        font-weight: 800 !important;
                        letter-spacing: -0.025em !important;
                    }

                    /* 7. Inputs, Selects & Buttons */
                    .fi-input-wrp {
                        min-width: 0 !important;
                        border-radius: 0.55rem !important;
                    }
                    .fi-input {
                        min-width: 0 !important;
                        font-size: 0.875rem !important;
                    }
                    .fi-btn {
                        border-radius: 0.55rem !important;
                        font-weight: 600 !important;
                    }

                    /* 8. Tables — Clean Density & Smooth Horizontal Scroll */
                    .fi-ta-content-ctn,
                    .fi-ta-content {
                        overflow-x: auto !important;
                        -webkit-overflow-scrolling: touch;
                        max-width: 100% !important;
                    }
                    .fi-ta-header-cell {
                        font-size: 0.75rem !important;
                        text-transform: uppercase !important;
                        letter-spacing: 0.04em !important;
                        padding-top: 0.7rem !important;
                        padding-bottom: 0.7rem !important;
                        white-space: nowrap !important;
                    }
                    .fi-ta-cell {
                        padding-top: 0.65rem !important;
                        padding-bottom: 0.65rem !important;
                        vertical-align: middle !important;
                    }

                    /* 9. Scrollable Navigation & Filter Tabs */
                    .fi-tabs {
                        overflow-x: auto !important;
                        flex-wrap: nowrap !important;
                        scrollbar-width: none !important;
                        -webkit-overflow-scrolling: touch;
                        max-width: 100% !important;
                        border-radius: 0.75rem !important;
                        padding: 0.25rem !important;
                    }
                    .fi-tabs::-webkit-scrollbar {
                        display: none !important;
                    }
                    .fi-tabs-item {
                        white-space: nowrap !important;
                        flex-shrink: 0 !important;
                        border-radius: 0.5rem !important;
                    }

                    /* 10. Tablet & Mobile Breakpoint Refinements */
                    @media (max-width: 1024px) {
                        .fi-wi-stats-overview-stats-ctn {
                            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                            gap: 0.75rem !important;
                        }
                    }
                    @media (max-width: 768px) {
                        .fi-main {
                            padding-left: 0.75rem !important;
                            padding-right: 0.75rem !important;
                            padding-top: 0.85rem !important;
                        }
                        .fi-section-header {
                            padding: 0.85rem 1rem !important;
                        }
                        .fi-section-content {
                            padding: 0.95rem !important;
                        }
                        .fi-wi-stats-overview-stat {
                            padding: 0.9rem 1rem !important;
                        }
                        .fi-wi-stats-overview-stat-value {
                            font-size: 1.25rem !important;
                        }
                        .fi-header-heading {
                            font-size: 1.25rem !important;
                        }
                        .fi-ta-cell, .fi-ta-header-cell {
                            padding-left: 0.65rem !important;
                            padding-right: 0.65rem !important;
                        }
                        .fi-simple-main {
                            padding: 1.5rem 1.25rem !important;
                        }
                    }
                    @media (max-width: 480px) {
                        .fi-wi-stats-overview-stats-ctn {
                            grid-template-columns: 1fr !important;
                        }
                        .fi-header-actions {
                            width: 100% !important;
                        }
                    }
                </style>
                <script>
                    document.addEventListener("livewire:init", function () {
                        if (window.Livewire && typeof window.Livewire.interceptRequest === "function") {
                            window.Livewire.interceptRequest(({ onRedirect, onError }) => {
                                onRedirect(({ url, preventDefault }) => {
                                    if (url && url.includes("livewire") && url.includes("/update")) {
                                        preventDefault();
                                        window.location.reload();
                                    }
                                });
                                onError(({ response, preventDefault }) => {
                                    if (response && (response.status === 404 || response.status === 405)) {
                                        preventDefault();
                                        window.location.reload();
                                    }
                                });
                            });
                        }
                    });
                </script>')
            )
            ->navigationGroups([
                NavigationGroup::make('Sales'),
                NavigationGroup::make('Catalog'),
                NavigationGroup::make('Suppliers'),
                NavigationGroup::make('Settings'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    protected function storeName(): string
    {
        try {
            return (string) Setting::get('store_name', 'SellMeTools');
        } catch (Throwable) {
            return 'SellMeTools';
        }
    }
}
