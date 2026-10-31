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
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => Color::hex('#9B1B30'),
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString('<style>
                    /* Clean, responsive inputs & tables in Filament */
                    .fi-input-wrp { min-width: 0 !important; }
                    .fi-input { min-width: 80px !important; font-size: 0.95rem !important; }
                    .fi-ta-content { overflow-x: auto !important; -webkit-overflow-scrolling: touch; }
                    @media (max-width: 640px) {
                        .fi-main { padding-left: 0.75rem !important; padding-right: 0.75rem !important; }
                        .fi-section-content { padding: 1rem !important; }
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
