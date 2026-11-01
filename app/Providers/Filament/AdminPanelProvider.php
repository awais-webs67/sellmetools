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
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => Color::hex('#9B1B30'),
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString('<style>
                    /* Modern Responsive Admin Login Page (.fi-simple-layout) */
                    .fi-simple-layout {
                        min-height: 100dvh !important;
                        background: radial-gradient(circle at top right, #5E1021 0%, #2B0912 45%, #120408 100%) !important;
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
                        border: 1px solid rgba(155, 27, 48, 0.28) !important;
                    }
                    .fi-simple-header-heading {
                        font-weight: 800 !important;
                        letter-spacing: -0.02em !important;
                    }
                    /* Clean, responsive inputs, buttons & tables across Admin Panel */
                    .fi-input-wrp {
                        min-width: 0 !important;
                        border-radius: 0.65rem !important;
                    }
                    .fi-input {
                        min-width: 80px !important;
                        font-size: 0.95rem !important;
                        padding-top: 0.55rem !important;
                        padding-bottom: 0.55rem !important;
                    }
                    .fi-btn {
                        border-radius: 0.65rem !important;
                        font-weight: 600 !important;
                    }
                    .fi-ta-content {
                        overflow-x: auto !important;
                        -webkit-overflow-scrolling: touch;
                    }
                    .fi-section {
                        border-radius: 0.9rem !important;
                    }
                    /* Mobile Responsiveness for Admin Panel */
                    @media (max-width: 768px) {
                        .fi-main {
                            padding-left: 0.75rem !important;
                            padding-right: 0.75rem !important;
                        }
                        .fi-section-content, .fi-section-header {
                            padding: 0.9rem !important;
                        }
                        .fi-wi-stats-overview-stats-ctn {
                            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                            gap: 0.65rem !important;
                        }
                        .fi-wi-stats-overview-stat {
                            padding: 0.85rem !important;
                        }
                        .fi-header {
                            gap: 0.5rem !important;
                        }
                        .fi-header-heading {
                            font-size: 1.35rem !important;
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
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): HtmlString => new HtmlString('<script>
                    (function () {
                        const syncUrl = '.json_encode(url('/api/sync')).';
                        const triggerSync = () => {
                            if (document.hidden) return;
                            fetch(syncUrl, { method: "POST", keepalive: true }).catch(() => {});
                        };
                        triggerSync();
                        setInterval(triggerSync, 10000);
                        document.addEventListener("visibilitychange", () => {
                            if (!document.hidden) triggerSync();
                        });
                    })();
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
