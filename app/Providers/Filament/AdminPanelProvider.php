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
            ->favicon(fn (): string => asset('favicon.png').'?v=6')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => Color::Indigo,
                'warning' => Color::Blue,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'danger' => Color::Rose,
                'gray' => Color::Zinc,
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): HtmlString => new HtmlString(
                    '<a href="'.e(url('/')).'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.4rem 0.75rem;border-radius:0.55rem;font-size:0.78rem;font-weight:600;border:1px solid rgba(128,128,128,0.28);text-decoration:none;white-space:nowrap;">'
                    .'<span>🌐 Live Store</span>'
                    .'</a>'
                )
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString('<style>
                    /* Modern Responsive Admin Login Page (.fi-simple-layout) */
                    .fi-simple-layout {
                        min-height: 100dvh !important;
                        background: radial-gradient(circle at top right, #262B31 0%, #14171A 50%, #090A0C 100%) !important;
                        padding: 1rem !important;
                        display: flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                    }
                    .fi-simple-main-ctn {
                        width: 100% !important;
                        max-width: 440px !important;
                        margin: 0 auto !important;
                    }
                    .fi-simple-main {
                        width: 100% !important;
                        padding: 1.75rem 1.5rem !important;
                        border-radius: 1.25rem !important;
                        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.55) !important;
                        border: 1px solid rgba(255, 255, 255, 0.12) !important;
                    }
                    .fi-simple-header-heading {
                        font-weight: 800 !important;
                        letter-spacing: -0.02em !important;
                    }

                    /* Executive Polish for Inputs, Buttons, Cards & Tables */
                    .fi-input-wrp {
                        min-width: 0 !important;
                        border-radius: 0.6rem !important;
                    }
                    .fi-input {
                        min-width: 70px !important;
                        font-size: 0.92rem !important;
                    }
                    .fi-btn {
                        border-radius: 0.6rem !important;
                        font-weight: 600 !important;
                    }
                    .fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat {
                        border-radius: 0.85rem !important;
                    }
                    .fi-ta-content {
                        overflow-x: auto !important;
                        -webkit-overflow-scrolling: touch;
                    }

                    /* Smooth horizontally scrollable status tabs on all devices */
                    .fi-tabs {
                        overflow-x: auto !important;
                        flex-wrap: nowrap !important;
                        scrollbar-width: none !important;
                        -webkit-overflow-scrolling: touch;
                        max-width: 100% !important;
                    }
                    .fi-tabs::-webkit-scrollbar {
                        display: none !important;
                    }
                    .fi-tabs-item {
                        white-space: nowrap !important;
                        flex-shrink: 0 !important;
                    }

                    /* Responsive Header & Action Wrapping */
                    .fi-header {
                        display: flex !important;
                        flex-wrap: wrap !important;
                        gap: 0.75rem !important;
                    }
                    .fi-header-actions {
                        display: flex !important;
                        flex-wrap: wrap !important;
                        gap: 0.45rem !important;
                    }

                    /* Mobile Responsiveness for Admin Panel */
                    @media (max-width: 768px) {
                        .fi-main {
                            padding-left: 0.65rem !important;
                            padding-right: 0.65rem !important;
                        }
                        .fi-section-content, .fi-section-header {
                            padding: 0.85rem !important;
                        }
                        .fi-wi-stats-overview-stats-ctn {
                            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                            gap: 0.6rem !important;
                        }
                        .fi-wi-stats-overview-stat {
                            padding: 0.85rem !important;
                        }
                        .fi-header-heading {
                            font-size: 1.3rem !important;
                            line-height: 1.25 !important;
                        }
                        .fi-ta-cell, .fi-ta-header-cell {
                            padding-left: 0.65rem !important;
                            padding-right: 0.65rem !important;
                            padding-top: 0.55rem !important;
                            padding-bottom: 0.55rem !important;
                        }
                        .fi-simple-main {
                            padding: 1.35rem 1.15rem !important;
                            border-radius: 1rem !important;
                        }
                    }
                    @media (max-width: 420px) {
                        .fi-wi-stats-overview-stats-ctn {
                            grid-template-columns: 1fr !important;
                        }
                    }
                </style>')
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
