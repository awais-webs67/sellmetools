<?php

namespace App\Providers\Filament;

use App\Http\Controllers\StockController;
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
            ->spa()
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
                    '<div class="smt-topbar-actions">'
                    .'<a href="'.e(url('/admin/sales-statement')).'" class="fi-live-store-btn smt-hide-mobile" title="Sales & Profit Reports">'
                    .'<svg style="width:14px;height:14px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z"/></svg>'
                    .'<span class="smt-btn-text">Reports</span>'
                    .'</a>'
                    .'<a href="'.e(url('/admin/vendor-orders')).'" class="fi-live-store-btn smt-hide-mobile" title="Direct & Vendor Orders">'
                    .'<svg style="width:14px;height:14px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>'
                    .'<span class="smt-btn-text">Vendor</span>'
                    .'</a>'
                    .'<a href="'.e(url('/')).'" target="_blank" rel="noopener" class="fi-live-store-btn smt-hide-mobile" title="Open Live Storefront">'
                    .'<svg style="width:14px;height:14px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>'
                    .'<span class="smt-btn-text">Store</span>'
                    .'</a>'
                    .'<div class="smt-bell-container" id="smt-order-bell-wrap">'
                    .'<button type="button" id="smt-order-bell-btn" class="smt-bell-btn" aria-label="Order Notifications" title="Live Order Notifications">'
                    .'<svg style="width:18px;height:18px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'
                    .'<span id="smt-bell-badge" class="smt-bell-badge" style="display:none;">0</span>'
                    .'</button>'
                    .'<div id="smt-bell-dropdown" class="smt-bell-dropdown" style="display:none;">'
                    .'<div class="smt-bell-header">'
                    .'<div>'
                    .'<div class="smt-bell-title">Live Order Notifications</div>'
                    .'<div class="smt-bell-subtitle" id="smt-bell-summary">Checking live queue...</div>'
                    .'</div>'
                    .'<div style="display:flex;align-items:center;gap:0.35rem;">'
                    .'<button type="button" id="smt-bell-sound-btn" class="smt-bell-mini-btn" title="Toggle Sound & Browser Alerts">Alerts: ON</button>'
                    .'<button type="button" id="smt-bell-mark-read" class="smt-bell-mini-btn" title="Mark current notifications as seen">Clear Badge</button>'
                    .'</div>'
                    .'</div>'
                    .'<div class="smt-bell-queues">'
                    .'<a href="'.e(url('/admin/orders?activeTab=review')).'" class="smt-queue-pill smt-queue-amber">'
                    .'<span>Payment Review</span>'
                    .'<strong id="smt-q-review">0</strong>'
                    .'</a>'
                    .'<a href="'.e(url('/admin/orders?activeTab=attention')).'" class="smt-queue-pill smt-queue-rose">'
                    .'<span>Needs Attention</span>'
                    .'<strong id="smt-q-attention">0</strong>'
                    .'</a>'
                    .'</div>'
                    .'<div id="smt-bell-list" class="smt-bell-list">'
                    .'<div class="smt-bell-empty">Loading recent orders...</div>'
                    .'</div>'
                    .'<div class="smt-bell-footer">'
                    .'<a href="'.e(url('/admin/orders')).'" class="smt-bell-view-all">View All Orders →</a>'
                    .'</div>'
                    .'</div>'
                    .'</div>'
                    .'</div>'
                )
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString('
                <link rel="manifest" href="/admin-manifest.json">
                <meta name="theme-color" content="#0f172a">
                <meta name="mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
                <meta name="apple-mobile-web-app-title" content="SMT Admin">
                <link rel="apple-touch-icon" href="/images/pwa-icon-192.png">
                <style>
                    /* ==========================================================================
                       SellMeTools Executive E-Commerce Admin UI/UX Design System (Filament 5)
                       ========================================================================== */

                    /* 0. Clean Slate Workspace Canvas so Cards & Sections Pop Out Clearly */
                    .fi-body {
                        background-color: #f1f5f9 !important;
                    }
                    .dark .fi-body {
                        background-color: #020617 !important;
                    }

                    /* Topbar Actions, Install App Button & Live Notification Bell */
                    .smt-topbar-actions {
                        display: inline-flex;
                        align-items: center;
                        gap: 0.38rem;
                    }
                    .smt-install-app-btn {
                        background: #0f172a !important;
                        color: #ffffff !important;
                        border-color: #0f172a !important;
                        cursor: pointer;
                    }
                    .smt-install-app-btn:hover {
                        background: #1e293b !important;
                    }
                    .dark .smt-install-app-btn {
                        background: #4f46e5 !important;
                        border-color: #6366f1 !important;
                    }
                    .smt-bell-container {
                        position: relative;
                        display: inline-flex;
                        align-items: center;
                    }
                    .smt-bell-btn {
                        position: relative;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        width: 34px;
                        height: 34px;
                        border-radius: 0.55rem;
                        border: 1px solid #cbd5e1;
                        background: #ffffff;
                        color: #334155;
                        cursor: pointer;
                        transition: all 0.15s ease;
                    }
                    .smt-bell-btn:hover {
                        background: #f8fafc;
                        border-color: #94a3b8;
                        color: #0f172a;
                    }
                    .dark .smt-bell-btn {
                        background: #1e293b;
                        border-color: #334155;
                        color: #e2e8f0;
                    }
                    .smt-bell-badge {
                        position: absolute;
                        top: -5px;
                        right: -5px;
                        min-width: 18px;
                        height: 18px;
                        padding: 0 5px;
                        border-radius: 9999px;
                        background: #e11d48;
                        color: #ffffff;
                        font-size: 0.65rem;
                        font-weight: 800;
                        line-height: 18px;
                        text-align: center;
                        box-shadow: 0 0 0 2px #ffffff;
                    }
                    .dark .smt-bell-badge {
                        box-shadow: 0 0 0 2px #0f172a;
                    }
                    .smt-bell-dropdown {
                        position: absolute;
                        top: calc(100% + 8px);
                        right: 0;
                        width: 360px;
                        max-width: calc(100vw - 1.25rem);
                        background: #ffffff;
                        border: 1px solid #cbd5e1;
                        border-radius: 0.85rem;
                        box-shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.14), 0 8px 10px -6px rgba(15, 23, 42, 0.08);
                        z-index: 9999;
                        overflow: hidden;
                    }
                    .dark .smt-bell-dropdown {
                        background: #0f172a;
                        border-color: #334155;
                        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
                    }
                    .smt-bell-header {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 0.5rem;
                        padding: 0.75rem 0.9rem;
                        border-bottom: 1px solid #e2e8f0;
                        background: #f8fafc;
                    }
                    .dark .smt-bell-header {
                        background: #1e293b;
                        border-bottom-color: #334155;
                    }
                    .smt-bell-title {
                        font-size: 0.8rem;
                        font-weight: 700;
                        color: #0f172a;
                    }
                    .dark .smt-bell-title {
                        color: #f8fafc;
                    }
                    .smt-bell-subtitle {
                        font-size: 0.68rem;
                        color: #64748b;
                    }
                    .smt-bell-mini-btn {
                        padding: 0.22rem 0.48rem;
                        font-size: 0.65rem;
                        font-weight: 600;
                        border-radius: 0.38rem;
                        border: 1px solid #cbd5e1;
                        background: #ffffff;
                        color: #475569;
                        cursor: pointer;
                    }
                    .smt-bell-mini-btn:hover {
                        background: #f1f5f9;
                        color: #0f172a;
                    }
                    .dark .smt-bell-mini-btn {
                        background: #0f172a;
                        border-color: #334155;
                        color: #cbd5e1;
                    }
                    .smt-bell-queues {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 0.45rem;
                        padding: 0.55rem 0.9rem;
                        border-bottom: 1px solid #f1f5f9;
                        background: #ffffff;
                    }
                    .dark .smt-bell-queues {
                        background: #0f172a;
                        border-bottom-color: #1e293b;
                    }
                    .smt-queue-pill {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 0.35rem 0.6rem;
                        border-radius: 0.5rem;
                        font-size: 0.7rem;
                        font-weight: 600;
                        text-decoration: none;
                    }
                    .smt-queue-amber {
                        background: #fffbeb;
                        color: #92400e;
                        border: 1px solid #fde68a;
                    }
                    .smt-queue-rose {
                        background: #fff1f2;
                        color: #9f1239;
                        border: 1px solid #fecdd3;
                    }
                    .smt-bell-list {
                        max-height: 330px;
                        overflow-y: auto;
                    }
                    .smt-bell-item {
                        display: block;
                        padding: 0.65rem 0.9rem;
                        border-bottom: 1px solid #f1f5f9;
                        text-decoration: none;
                        transition: background 0.12s ease;
                    }
                    .smt-bell-item:hover {
                        background: #f8fafc;
                    }
                    .dark .smt-bell-item {
                        border-bottom-color: #1e293b;
                    }
                    .dark .smt-bell-item:hover {
                        background: #1e293b;
                    }
                    .smt-bell-item.is-actionable {
                        background: rgba(254, 243, 199, 0.25);
                    }
                    .smt-bell-empty {
                        padding: 1.5rem 1rem;
                        text-align: center;
                        font-size: 0.75rem;
                        color: #64748b;
                    }
                    .smt-bell-footer {
                        padding: 0.55rem 0.9rem;
                        text-align: center;
                        background: #f8fafc;
                        border-top: 1px solid #e2e8f0;
                    }
                    .dark .smt-bell-footer {
                        background: #1e293b;
                        border-top-color: #334155;
                    }
                    .smt-bell-view-all {
                        font-size: 0.74rem;
                        font-weight: 700;
                        color: #4f46e5;
                        text-decoration: none;
                    }
                    @media (max-width: 640px) {
                        .smt-btn-text {
                            display: none;
                        }
                        .smt-bell-dropdown {
                            position: fixed;
                            top: 58px;
                            left: 0.5rem;
                            right: 0.5rem;
                            width: auto;
                        }
                    }

                    /* 1. Executive Dark Sidebar & Sleek Topbar */
                    .fi-sidebar {
                        background: linear-gradient(180deg, #0b0f19 0%, #0f172a 100%) !important;
                        border-right: 1px solid rgba(51, 65, 85, 0.45) !important;
                    }
                    .fi-sidebar-header {
                        background: rgba(15, 23, 42, 0.65) !important;
                        border-bottom: 1px solid rgba(51, 65, 85, 0.45) !important;
                    }
                    .fi-sidebar-header img {
                        filter: brightness(0) invert(1) !important;
                    }
                    .fi-sidebar-group-label {
                        color: #94a3b8 !important;
                        font-size: 0.68rem !important;
                        font-weight: 800 !important;
                        text-transform: uppercase !important;
                        letter-spacing: 0.08em !important;
                    }
                    .fi-sidebar-item-btn {
                        border-radius: 0.6rem !important;
                        padding-top: 0.5rem !important;
                        padding-bottom: 0.5rem !important;
                        transition: all 0.15s ease !important;
                    }
                    .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-label {
                        color: #cbd5e1 !important;
                        font-weight: 500 !important;
                    }
                    .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-icon {
                        color: #64748b !important;
                    }
                    .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-btn:hover {
                        background: rgba(255, 255, 255, 0.06) !important;
                    }
                    .fi-sidebar-item.fi-active .fi-sidebar-item-btn {
                        background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%) !important;
                        box-shadow: 0 4px 12px -2px rgba(79, 70, 229, 0.45) !important;
                    }
                    .fi-sidebar-item.fi-active .fi-sidebar-item-label,
                    .fi-sidebar-item.fi-active .fi-sidebar-item-icon {
                        color: #ffffff !important;
                        font-weight: 700 !important;
                    }
                    .fi-topbar-ctn {
                        overflow: visible !important;
                    }
                    .fi-topbar {
                        background: rgba(255, 255, 255, 0.92) !important;
                        backdrop-filter: blur(12px) !important;
                        border-bottom: 1px solid #cbd5e1 !important;
                        gap: 0.5rem !important;
                    }
                    .dark .fi-topbar {
                        background: rgba(15, 23, 42, 0.92) !important;
                        border-bottom-color: rgba(51, 65, 85, 0.65) !important;
                    }
                    .fi-topbar-end {
                        flex: 1 1 auto !important;
                        min-width: 0 !important;
                        justify-content: flex-end !important;
                        column-gap: 0.5rem !important;
                    }
                    .fi-global-search-ctn {
                        flex: 1 1 auto !important;
                        min-width: 130px !important;
                        max-width: 320px !important;
                    }
                    .fi-global-search,
                    .fi-global-search-field,
                    .fi-global-search-field .fi-input-wrp,
                    .fi-global-search-field .fi-input-wrp-content-ctn {
                        width: 100% !important;
                        min-width: 0 !important;
                    }
                    .fi-global-search-field .fi-input-wrp,
                    .fi-ta-search-field .fi-input-wrp {
                        cursor: text !important;
                    }
                    .fi-global-search-field .fi-input-wrp-prefix,
                    .fi-ta-search-field .fi-input-wrp-prefix {
                        pointer-events: none !important;
                    }
                    .fi-global-search-field .fi-input {
                        width: 100% !important;
                        min-width: 85px !important;
                        cursor: text !important;
                        pointer-events: auto !important;
                    }
                    .fi-ta-search-field {
                        flex: 1 1 180px !important;
                        min-width: 140px !important;
                    }
                    .fi-ta-search-field .fi-input {
                        width: 100% !important;
                        min-width: 95px !important;
                        cursor: text !important;
                        pointer-events: auto !important;
                    }

                    /* 1b. Live Store & Quick Action Header Buttons */
                    .fi-live-store-btn {
                        display: inline-flex;
                        align-items: center;
                        gap: 0.35rem;
                        padding: 0.38rem 0.72rem;
                        border-radius: 0.55rem;
                        font-size: 0.74rem;
                        font-weight: 700;
                        border: 1px solid rgba(79, 70, 229, 0.25);
                        background: rgba(238, 242, 255, 0.9);
                        color: #3730a3;
                        text-decoration: none;
                        white-space: nowrap;
                        transition: all 0.15s ease;
                    }
                    .fi-live-store-btn:hover {
                        transform: translateY(-1px);
                        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
                    }
                    .dark .fi-live-store-btn {
                        background: rgba(49, 46, 129, 0.4) !important;
                        color: #e0e7ff !important;
                        border-color: rgba(99, 102, 241, 0.35) !important;
                    }
                    @media (max-width: 640px) {
                        .fi-live-store-btn {
                            padding: 0.32rem 0.5rem;
                            font-size: 0.68rem;
                        }
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
                        padding-top: 1.15rem !important;
                        padding-bottom: 2.5rem !important;
                    }
                    .fi-header {
                        display: flex !important;
                        flex-wrap: wrap !important;
                        align-items: center !important;
                        justify-content: space-between !important;
                        gap: 0.75rem !important;
                        margin-bottom: 0.35rem !important;
                        padding: 0.85rem 1.15rem !important;
                        background: #ffffff !important;
                        border: 1px solid #cbd5e1 !important;
                        border-radius: 0.875rem !important;
                        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04) !important;
                    }
                    .dark .fi-header {
                        background: #0f172a !important;
                        border-color: #1e293b !important;
                    }
                    .fi-header-heading {
                        font-size: 1.35rem !important;
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

                    /* 4. Section Cards, Boxes & Containers — Distinct Separation & Elevation */
                    .fi-section,
                    .fi-ta-ctn,
                    .fi-wi-stats-overview-stat {
                        background: #ffffff !important;
                        border-radius: 0.875rem !important;
                        border: 1px solid #cbd5e1 !important;
                        box-shadow: 0 2px 5px -1px rgba(15, 23, 42, 0.05), 0 1px 2px -1px rgba(15, 23, 42, 0.03) !important;
                        overflow: hidden !important;
                        min-width: 0 !important;
                    }
                    .dark .fi-section,
                    .dark .fi-ta-ctn,
                    .dark .fi-wi-stats-overview-stat {
                        background: #0f172a !important;
                        border-color: rgba(51, 65, 85, 0.85) !important;
                        box-shadow: 0 2px 6px 0 rgba(0, 0, 0, 0.3) !important;
                    }
                    .fi-section-header {
                        padding: 0.85rem 1.2rem !important;
                        border-bottom: 1px solid #e2e8f0 !important;
                        background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%) !important;
                    }
                    .dark .fi-section-header {
                        border-bottom-color: rgba(51, 65, 85, 0.65) !important;
                        background: rgba(30, 41, 59, 0.55) !important;
                    }
                    .fi-section-header-heading {
                        font-size: 0.92rem !important;
                        font-weight: 800 !important;
                        letter-spacing: -0.01em !important;
                    }
                    .fi-section-header-description {
                        font-size: 0.78rem !important;
                        line-height: 1.4 !important;
                    }
                    .fi-section-content {
                        padding: 1.15rem !important;
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
                        border-radius: 0.55rem !important;
                    }
                    .fi-fo-field-wrp .fi-input-wrp,
                    .fi-fo-field-wrp .fi-input {
                        min-width: 0 !important;
                    }
                    .fi-input {
                        font-size: 0.875rem !important;
                    }
                    .fi-btn {
                        border-radius: 0.55rem !important;
                        font-weight: 600 !important;
                    }

                    /* 8. Tables — Ultra-Compact High-Density Rows & Smooth Scroll */
                    .fi-ta-content-ctn,
                    .fi-ta-content {
                        overflow-x: auto !important;
                        -webkit-overflow-scrolling: touch;
                        max-width: 100% !important;
                    }
                    .fi-ta-header-cell {
                        font-size: 0.69rem !important;
                        font-weight: 800 !important;
                        text-transform: uppercase !important;
                        letter-spacing: 0.045em !important;
                        padding: 0.5rem 0.65rem !important;
                        white-space: nowrap !important;
                        background: #f8fafc !important;
                        border-bottom: 1px solid #cbd5e1 !important;
                    }
                    .dark .fi-ta-header-cell {
                        background: #1e293b !important;
                        border-bottom-color: #334155 !important;
                    }
                    .fi-ta-row:hover {
                        background-color: rgba(79, 70, 229, 0.03) !important;
                    }
                    .fi-ta-cell {
                        padding: 0.34rem 0.65rem !important;
                        vertical-align: middle !important;
                    }
                    .fi-ta-text {
                        padding: 0.15rem 0.25rem !important;
                        gap: 0.1rem !important;
                    }
                    .fi-ta-text-item-label {
                        font-size: 0.8rem !important;
                        line-height: 1.25 !important;
                    }
                    .fi-ta-text-item-description {
                        font-size: 0.7rem !important;
                        line-height: 1.15 !important;
                        margin-top: 1px !important;
                    }
                    .fi-ta-cell .fi-badge {
                        font-size: 0.68rem !important;
                        padding: 0.12rem 0.42rem !important;
                    }
                    .fi-ta-actions {
                        gap: 0.25rem !important;
                        flex-wrap: nowrap !important;
                    }
                    .fi-ta-actions .fi-btn {
                        padding: 0.22rem 0.48rem !important;
                        font-size: 0.72rem !important;
                        line-height: 1.15 !important;
                    }
                    .fi-ta-header-toolbar {
                        padding: 0.55rem 0.85rem !important;
                        background: #ffffff !important;
                        flex-wrap: wrap !important;
                        gap: 0.5rem !important;
                    }
                    .dark .fi-ta-header-toolbar {
                        background: #0f172a !important;
                    }
                    .fi-ta-header {
                        flex-wrap: wrap !important;
                        gap: 0.5rem !important;
                    }
                    .fi-ta-header-actions {
                        flex-wrap: wrap !important;
                        gap: 0.45rem !important;
                    }

                    /* 8b. Live Supplier Priority Green Row & Green Tab Highlight */
                    .smt-live-supplier-row {
                        background-color: rgba(16, 185, 129, 0.08) !important;
                        box-shadow: inset 3px 0 0 #059669 !important;
                    }
                    .smt-live-supplier-row:hover {
                        background-color: rgba(16, 185, 129, 0.13) !important;
                    }
                    .dark .smt-live-supplier-row {
                        background-color: rgba(16, 185, 129, 0.14) !important;
                        box-shadow: inset 3px 0 0 #10b981 !important;
                    }
                    .smt-live-supplier-row .fi-color-success.fi-badge {
                        background-color: #059669 !important;
                        color: #ffffff !important;
                        border-color: #047857 !important;
                        font-weight: 800 !important;
                        padding: 0.22rem 0.6rem !important;
                        box-shadow: 0 1px 2px rgba(5, 150, 105, 0.25) !important;
                    }
                    .smt-live-supplier-row .fi-color-success.fi-badge span {
                        color: #ffffff !important;
                    }

                    /* 8c. Out-of-Stock Product Red Row Highlight in Admin Products Table */
                    .smt-out-of-stock-row {
                        background-color: rgba(244, 63, 94, 0.08) !important;
                        box-shadow: inset 4px 0 0 #e11d48 !important;
                    }
                    .smt-out-of-stock-row > td {
                        background-color: rgba(244, 63, 94, 0.08) !important;
                    }
                    .smt-out-of-stock-row:hover,
                    .smt-out-of-stock-row:hover > td {
                        background-color: rgba(244, 63, 94, 0.14) !important;
                    }
                    .dark .smt-out-of-stock-row,
                    .dark .smt-out-of-stock-row > td {
                        background-color: rgba(244, 63, 94, 0.16) !important;
                    }
                    .dark .smt-out-of-stock-row {
                        box-shadow: inset 4px 0 0 #fb7185 !important;
                    }
                    .dark .smt-out-of-stock-row:hover,
                    .dark .smt-out-of-stock-row:hover > td {
                        background-color: rgba(244, 63, 94, 0.22) !important;
                    }
                    .smt-out-of-stock-row .fi-color-danger.fi-badge {
                        background-color: #e11d48 !important;
                        color: #ffffff !important;
                        border-color: #be123c !important;
                        font-weight: 800 !important;
                        box-shadow: 0 1px 2px rgba(225, 29, 72, 0.25) !important;
                    }
                    .smt-out-of-stock-row .fi-color-danger.fi-badge span {
                        color: #ffffff !important;
                    }

                    /* 9. Scrollable Navigation & Filter Tabs */
                    .fi-tabs {
                        overflow-x: auto !important;
                        flex-wrap: nowrap !important;
                        scrollbar-width: none !important;
                        -webkit-overflow-scrolling: touch;
                        max-width: 100% !important;
                        border-radius: 0.75rem !important;
                        padding: 0.3rem !important;
                        background: #ffffff !important;
                        border: 1px solid #cbd5e1 !important;
                        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04) !important;
                    }
                    .dark .fi-tabs {
                        background: #0f172a !important;
                        border-color: #334155 !important;
                    }
                    .fi-tabs::-webkit-scrollbar {
                        display: none !important;
                    }
                    .fi-tabs-item {
                        white-space: nowrap !important;
                        flex-shrink: 0 !important;
                        border-radius: 0.5rem !important;
                    }

                    /* 10. Desktop Sticky Sidebar & Full Mobile/Tablet Responsive Flow */
                    .fi-main-ctn {
                        max-width: 100% !important;
                        min-width: 0 !important;
                        overflow-x: clip !important;
                    }
                    .fi-main,
                    .fi-page,
                    .fi-resource-edit-record-page,
                    .fi-resource-create-record-page,
                    .fi-resource-list-records-page,
                    .fi-resource-view-record-page {
                        max-width: 100% !important;
                        min-width: 0 !important;
                    }
                    @media (min-width: 1024px) {
                        .fi-sidebar {
                            position: sticky !important;
                            top: 0 !important;
                            height: 100dvh !important;
                            max-height: 100dvh !important;
                            overflow-y: auto !important;
                            align-self: flex-start !important;
                        }
                        .fi-sidebar-nav {
                            overflow-y: auto !important;
                        }
                    }
                    @media (max-width: 1024px) {
                        .fi-wi-stats-overview-stats-ctn {
                            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                            gap: 0.75rem !important;
                        }
                    }
                    @media (max-width: 768px) {
                        .smt-hide-mobile {
                            display: none !important;
                        }
                        .fi-topbar {
                            width: 100% !important;
                            max-width: 100vw !important;
                            box-sizing: border-box !important;
                            padding-inline: 0.45rem !important;
                            gap: 0.3rem !important;
                            min-height: 3.25rem !important;
                        }
                        .fi-topbar-open-sidebar-btn,
                        .fi-topbar-close-sidebar-btn {
                            margin-inline: 0 !important;
                            width: 32px !important;
                            height: 32px !important;
                            flex-shrink: 0 !important;
                        }
                        .fi-topbar-end {
                            flex: 1 1 0% !important;
                            min-width: 0 !important;
                            max-width: 100% !important;
                            margin-inline-start: 0 !important;
                            column-gap: 0.3rem !important;
                            justify-content: space-between !important;
                        }
                        .fi-global-search-ctn {
                            flex: 1 1 0% !important;
                            width: 0 !important;
                            min-width: 60px !important;
                            max-width: 100% !important;
                        }
                        .fi-global-search,
                        .fi-global-search-field,
                        .fi-global-search-field .fi-input-wrp,
                        .fi-global-search-field .fi-input-wrp-content-ctn {
                            width: 100% !important;
                            min-width: 0 !important;
                            max-width: 100% !important;
                        }
                        .fi-global-search-field .fi-input,
                        .fi-ta-search-field .fi-input {
                            width: 100% !important;
                            min-width: 0 !important;
                            font-size: 16px !important;
                            padding-top: 0.3rem !important;
                            padding-bottom: 0.3rem !important;
                            padding-inline-start: 0.25rem !important;
                            padding-inline-end: 0.4rem !important;
                        }
                        .fi-topbar .fi-global-search-results-ctn {
                            position: fixed !important;
                            top: 56px !important;
                            left: 0.5rem !important;
                            right: 0.5rem !important;
                            width: auto !important;
                            max-width: none !important;
                            z-index: 9999 !important;
                        }
                        .smt-topbar-actions {
                            gap: 0.25rem !important;
                            flex-shrink: 0 !important;
                        }
                        .fi-live-store-btn,
                        .smt-bell-btn {
                            width: 32px !important;
                            height: 32px !important;
                            padding: 0 !important;
                            justify-content: center !important;
                            border-radius: 0.5rem !important;
                            flex-shrink: 0 !important;
                        }
                        .fi-topbar .fi-user-menu,
                        .fi-topbar .fi-user-menu-trigger {
                            flex-shrink: 0 !important;
                        }
                        .fi-main {
                            padding-left: 0.5rem !important;
                            padding-right: 0.5rem !important;
                            padding-top: 0.65rem !important;
                        }
                        .fi-header {
                            padding: 0.65rem 0.8rem !important;
                            gap: 0.5rem !important;
                        }
                        .fi-header-heading {
                            font-size: 1.08rem !important;
                            word-break: break-word !important;
                        }
                        .fi-breadcrumbs {
                            display: none !important;
                        }
                        .fi-section-header {
                            padding: 0.65rem 0.8rem !important;
                            flex-wrap: wrap !important;
                            gap: 0.45rem !important;
                        }
                        .fi-section-content {
                            padding: 0.75rem !important;
                        }
                        .fi-ta-header {
                            padding: 0.65rem 0.8rem !important;
                            flex-direction: column !important;
                            align-items: stretch !important;
                        }
                        .fi-ta-header-actions {
                            width: 100% !important;
                            justify-content: flex-start !important;
                        }
                        .fi-ta-header-actions .fi-btn {
                            font-size: 0.75rem !important;
                            padding: 0.35rem 0.6rem !important;
                            max-width: 100% !important;
                            white-space: normal !important;
                            text-align: left !important;
                        }
                        .fi-ta-header-toolbar {
                            padding: 0.5rem 0.65rem !important;
                        }
                        .fi-wi-stats-overview-stat {
                            padding: 0.7rem 0.85rem !important;
                        }
                        .fi-wi-stats-overview-stat-value {
                            font-size: 1.15rem !important;
                        }
                        .fi-ta-cell, .fi-ta-header-cell {
                            padding: 0.28rem 0.42rem !important;
                        }
                        .fi-ta-text {
                            padding: 0.1rem 0.15rem !important;
                        }
                        .fi-ta-text-item-label {
                            font-size: 0.75rem !important;
                        }
                        .fi-ta-text-item-description {
                            font-size: 0.66rem !important;
                        }
                        .fi-simple-main {
                            padding: 1.5rem 1.25rem !important;
                        }
                        .fi-modal-window {
                            max-width: calc(100vw - 1rem) !important;
                            margin: 0.5rem !important;
                        }
                        .fi-fo-actions, .fi-ac {
                            flex-wrap: wrap !important;
                            gap: 0.45rem !important;
                        }
                    }
                    @media (max-width: 480px) {
                        .fi-wi-stats-overview-stats-ctn {
                            grid-template-columns: 1fr !important;
                        }
                        .fi-header-actions {
                            width: 100% !important;
                        }
                        .fi-header-actions .fi-btn {
                            flex: 1 1 auto !important;
                            justify-content: center !important;
                            font-size: 0.76rem !important;
                        }
                        .fi-live-store-btn,
                        .smt-bell-btn {
                            width: 32px !important;
                            height: 32px !important;
                            padding: 0 !important;
                        }
                    }
                </style>
                <script>
                    document.addEventListener("click", function (e) {
                        const wrp = e.target.closest(".fi-global-search-field .fi-input-wrp, .fi-ta-search-field .fi-input-wrp");
                        if (wrp && e.target.tagName !== "INPUT" && !e.target.closest("button")) {
                            const input = wrp.querySelector("input");
                            if (input) {
                                input.focus();
                            }
                        }
                    });
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
            ->renderHook(
                PanelsRenderHook::BODY_END,
                function (): HtmlString {
                    if (! auth()->check() || ! auth()->user()?->is_admin) {
                        return new HtmlString('');
                    }
                    $token = StockController::bridgeToken();
                    $syncUrl = route('stock.sync', [], false);

                    return new HtmlString('
                    <div id="smt-admin-chrome-install-popup" style="display:none;position:fixed;top:0.75rem;left:50%;transform:translateX(-50%);z-index:99999;max-width:410px;width:calc(100vw - 1.5rem);background:#ffffff;border:1px solid #cbd5e1;border-radius:1rem;padding:0.75rem 1rem;box-shadow:0 20px 25px -5px rgba(15,23,42,0.18),0 8px 10px -6px rgba(15,23,42,0.08);">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;">
                            <div style="display:flex;align-items:center;gap:0.65rem;min-width:0;">
                                <img src="/images/pwa-icon-192.png" alt="SMT Admin" style="width:38px;height:38px;border-radius:0.65rem;border:1px solid #e2e8f0;flex-shrink:0;" />
                                <div style="min-width:0;">
                                    <div style="font-size:0.84rem;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Install SMT Admin App</div>
                                    <div style="font-size:0.7rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Add dashboard to your Home screen</div>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:0.4rem;flex-shrink:0;">
                                <button type="button" id="smt-admin-pwa-accept" style="border:none;background:#0f172a;color:#ffffff;font-size:0.74rem;font-weight:700;padding:0.4rem 0.85rem;border-radius:0.5rem;cursor:pointer;">Install</button>
                                <button type="button" id="smt-admin-pwa-dismiss" style="border:none;background:#f1f5f9;color:#64748b;width:28px;height:28px;border-radius:0.45rem;cursor:pointer;font-size:0.8rem;font-weight:700;">✕</button>
                            </div>
                        </div>
                    </div>
                    <div id="smt-live-toast" style="display:none;position:fixed;bottom:1.25rem;right:1.25rem;z-index:99998;max-width:340px;width:calc(100vw - 2.5rem);background:#0f172a;color:#ffffff;border:1px solid #334155;border-radius:0.85rem;padding:0.85rem 1rem;box-shadow:0 20px 25px -5px rgba(15,23,42,0.35);">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.6rem;">
                            <div>
                                <div id="smt-toast-title" style="font-size:0.8rem;font-weight:800;color:#f8fafc;">New Order Alert</div>
                                <div id="smt-toast-body" style="margin-top:0.2rem;font-size:0.72rem;color:#cbd5e1;line-height:1.4;"></div>
                                <a id="smt-toast-link" href="/admin/orders" style="margin-top:0.5rem;display:inline-flex;align-items:center;gap:0.3rem;padding:0.28rem 0.65rem;border-radius:0.4rem;background:#4f46e5;color:#ffffff;font-size:0.7rem;font-weight:700;text-decoration:none;">Open Order →</a>
                            </div>
                            <button type="button" onclick="document.getElementById(\'smt-live-toast\').style.display=\'none\'" style="background:transparent;border:none;color:#94a3b8;cursor:pointer;font-size:0.85rem;">✕</button>
                        </div>
                    </div>
                    <script>
                        (function () {
                            if (window.__smtSupplierBridgeInit) return;
                            window.__smtSupplierBridgeInit = true;
                            const BRIDGE_TOKEN = '.json_encode($token).';
                            const SYNC_URL = '.json_encode($syncUrl).';
                            const ADMIN_PWA_DISMISS_KEY = "smt_admin_pwa_dismissed";
                            let running = false;
                            let deferredPwaPrompt = null;

                            // Register Service Worker for Admin PWA & Notifications
                            if ("serviceWorker" in navigator) {
                                navigator.serviceWorker.register("/sw.js").catch(() => {});
                            }

                            function isStandaloneApp() {
                                return (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches) || window.navigator.standalone === true;
                            }

                            function syncInstallButton() {
                                const popup = document.getElementById("smt-admin-chrome-install-popup");
                                const acceptBtn = document.getElementById("smt-admin-pwa-accept");
                                const dismissBtn = document.getElementById("smt-admin-pwa-dismiss");
                                if (!popup) return;
                                if (isStandaloneApp() || localStorage.getItem(ADMIN_PWA_DISMISS_KEY) === "1" || !deferredPwaPrompt) {
                                    popup.style.display = "none";
                                    return;
                                }
                                popup.style.display = "block";
                                if (acceptBtn && !acceptBtn.__bound) {
                                    acceptBtn.__bound = true;
                                    acceptBtn.addEventListener("click", async () => {
                                        if (!deferredPwaPrompt) return;
                                        deferredPwaPrompt.prompt();
                                        const choice = await deferredPwaPrompt.userChoice.catch(() => null);
                                        deferredPwaPrompt = null;
                                        popup.style.display = "none";
                                        if (choice && choice.outcome === "dismissed") {
                                            localStorage.setItem(ADMIN_PWA_DISMISS_KEY, "1");
                                        }
                                    });
                                }
                                if (dismissBtn && !dismissBtn.__bound) {
                                    dismissBtn.__bound = true;
                                    dismissBtn.addEventListener("click", () => {
                                        localStorage.setItem(ADMIN_PWA_DISMISS_KEY, "1");
                                        popup.style.display = "none";
                                    });
                                }
                            }

                            window.addEventListener("beforeinstallprompt", (e) => {
                                deferredPwaPrompt = e;
                                syncInstallButton();
                            });
                            window.addEventListener("appinstalled", () => {
                                deferredPwaPrompt = null;
                                syncInstallButton();
                            });

                            function isSoundEnabled() {
                                return localStorage.getItem("smt_admin_alerts_muted") !== "1";
                            }

                            function playOrderChime() {
                                if (!isSoundEnabled()) return;
                                try {
                                    const AudioCtx = window.AudioContext || window.webkitAudioContext;
                                    if (!AudioCtx) return;
                                    const ctx = new AudioCtx();
                                    const now = ctx.currentTime;
                                    [587.33, 880].forEach((freq, idx) => {
                                        const osc = ctx.createOscillator();
                                        const gain = ctx.createGain();
                                        osc.type = "sine";
                                        osc.frequency.setValueAtTime(freq, now + idx * 0.14);
                                        gain.gain.setValueAtTime(0.001, now + idx * 0.14);
                                        gain.gain.exponentialRampToValueAtTime(0.16, now + idx * 0.14 + 0.02);
                                        gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.14 + 0.26);
                                        osc.connect(gain);
                                        gain.connect(ctx.destination);
                                        osc.start(now + idx * 0.14);
                                        osc.stop(now + idx * 0.14 + 0.28);
                                    });
                                } catch (e) {}
                            }

                            function showLiveOrderAlert(title, body, url) {
                                playOrderChime();
                                const toast = document.getElementById("smt-live-toast");
                                const tTitle = document.getElementById("smt-toast-title");
                                const tBody = document.getElementById("smt-toast-body");
                                const tLink = document.getElementById("smt-toast-link");
                                if (toast && tTitle && tBody && tLink) {
                                    tTitle.textContent = title;
                                    tBody.textContent = body;
                                    tLink.href = url || "/admin/orders";
                                    toast.style.display = "block";
                                }
                                if (isSoundEnabled() && "Notification" in window && Notification.permission === "granted") {
                                    try {
                                        new Notification(title, { body: body, icon: "/images/pwa-icon-192.png", data: { url: url || "/admin/orders" } });
                                    } catch (e) {}
                                }
                            }

                            function escapeHtml(str) {
                                return String(str || "").replace(/[&<>"\']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#39;" }[c]));
                            }

                            function renderNotifications(notif) {
                                if (!notif) return;
                                const badge = document.getElementById("smt-bell-badge");
                                const summary = document.getElementById("smt-bell-summary");
                                const qReview = document.getElementById("smt-q-review");
                                const qAttention = document.getElementById("smt-q-attention");
                                const list = document.getElementById("smt-bell-list");

                                const prevOrderId = parseInt(localStorage.getItem("smt_seen_order_id") || "0", 10);
                                const prevPaymentId = parseInt(localStorage.getItem("smt_seen_payment_id") || "0", 10);
                                const latestOrderId = parseInt(notif.latest_order_id || 0, 10);
                                const latestPaymentId = parseInt(notif.latest_payment_id || 0, 10);

                                if (prevOrderId === 0 && latestOrderId > 0) {
                                    localStorage.setItem("smt_seen_order_id", String(latestOrderId));
                                } else if (latestOrderId > prevOrderId) {
                                    localStorage.setItem("smt_seen_order_id", String(latestOrderId));
                                    const newest = Array.isArray(notif.items) && notif.items.length > 0 ? notif.items[0] : null;
                                    if (newest) {
                                        showLiveOrderAlert(
                                            "New Order " + newest.number + " (" + newest.total + ")",
                                            newest.product + " · " + newest.customer,
                                            newest.url
                                        );
                                    }
                                }

                                if (prevPaymentId === 0 && latestPaymentId > 0) {
                                    localStorage.setItem("smt_seen_payment_id", String(latestPaymentId));
                                } else if (latestPaymentId > prevPaymentId) {
                                    localStorage.setItem("smt_seen_payment_id", String(latestPaymentId));
                                    const reviewItem = Array.isArray(notif.items) ? notif.items.find(i => i.status === "payment_review") || notif.items[0] : null;
                                    if (reviewItem && latestOrderId <= prevOrderId) {
                                        showLiveOrderAlert(
                                            "Payment Submitted: " + reviewItem.number,
                                            reviewItem.total + " via " + reviewItem.gateway + (reviewItem.tid ? " (TID: " + reviewItem.tid + ")" : ""),
                                            reviewItem.url
                                        );
                                    }
                                }

                                const dismissedOrder = parseInt(localStorage.getItem("smt_cleared_order_id") || "0", 10);
                                const unreadNew = latestOrderId > dismissedOrder ? 1 : 0;
                                const badgeCount = Math.max(parseInt(notif.actionable_count || 0, 10), unreadNew);

                                if (badge) {
                                    if (badgeCount > 0) {
                                        badge.textContent = badgeCount > 99 ? "99+" : String(badgeCount);
                                        badge.style.display = "inline-block";
                                    } else {
                                        badge.style.display = "none";
                                    }
                                }
                                if (qReview) qReview.textContent = String(notif.review_count || 0);
                                if (qAttention) qAttention.textContent = String(notif.attention_count || 0);
                                if (summary) {
                                    summary.textContent = (notif.actionable_count || 0) > 0
                                        ? notif.actionable_count + " order(s) awaiting action"
                                        : "All orders verified & up to date";
                                }

                                if (list && Array.isArray(notif.items)) {
                                    if (notif.items.length === 0) {
                                        list.innerHTML = "<div class=\"smt-bell-empty\">No recent orders yet.</div>";
                                    } else {
                                        list.innerHTML = notif.items.map((item) => {
                                            const badgeBg = item.status === "completed" ? "#ecfdf5" : (item.status === "payment_review" ? "#fffbeb" : (item.status === "needs_attention" ? "#fff1f2" : "#f1f5f9"));
                                            const badgeCol = item.status === "completed" ? "#047857" : (item.status === "payment_review" ? "#b45309" : (item.status === "needs_attention" ? "#be123c" : "#475569"));
                                            return `<a href="${escapeHtml(item.url)}" class="smt-bell-item ${item.is_actionable ? "is-actionable" : ""}">
                                                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.4rem;">
                                                    <span style="font-family:monospace;font-size:0.75rem;font-weight:800;color:#4f46e5;">${escapeHtml(item.number)}</span>
                                                    <span style="padding:0.1rem 0.42rem;border-radius:9999px;font-size:0.63rem;font-weight:700;background:${badgeBg};color:${badgeCol};">${escapeHtml(item.status_label)}</span>
                                                </div>
                                                <div style="margin-top:0.2rem;font-size:0.74rem;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(item.product)}</div>
                                                <div style="margin-top:0.18rem;display:flex;align-items:center;justify-content:space-between;font-size:0.68rem;color:#64748b;">
                                                    <span>${escapeHtml(item.customer)} · ${escapeHtml(item.gateway)}</span>
                                                    <strong style="color:#0f172a;font-family:monospace;">${escapeHtml(item.total)} · ${escapeHtml(item.time_ago)}</strong>
                                                </div>
                                            </a>`;
                                        }).join("");
                                    }
                                }
                            }

                            function bindBellEvents() {
                                syncInstallButton();
                                const bellBtn = document.getElementById("smt-order-bell-btn");
                                const dropdown = document.getElementById("smt-bell-dropdown");
                                const soundBtn = document.getElementById("smt-bell-sound-btn");
                                const markReadBtn = document.getElementById("smt-bell-mark-read");

                                if (soundBtn) {
                                    soundBtn.textContent = isSoundEnabled() ? "Alerts: ON" : "Alerts: Muted";
                                    if (!soundBtn.__bound) {
                                        soundBtn.__bound = true;
                                        soundBtn.addEventListener("click", (e) => {
                                            e.stopPropagation();
                                            const muted = localStorage.getItem("smt_admin_alerts_muted") === "1";
                                            localStorage.setItem("smt_admin_alerts_muted", muted ? "0" : "1");
                                            soundBtn.textContent = isSoundEnabled() ? "Alerts: ON" : "Alerts: Muted";
                                            if (isSoundEnabled()) {
                                                playOrderChime();
                                                if ("Notification" in window && Notification.permission === "default") {
                                                    Notification.requestPermission().catch(() => {});
                                                }
                                            }
                                        });
                                    }
                                }

                                if (markReadBtn && !markReadBtn.__bound) {
                                    markReadBtn.__bound = true;
                                    markReadBtn.addEventListener("click", (e) => {
                                        e.stopPropagation();
                                        const latest = localStorage.getItem("smt_seen_order_id") || "0";
                                        localStorage.setItem("smt_cleared_order_id", latest);
                                        const badge = document.getElementById("smt-bell-badge");
                                        if (badge) badge.style.display = "none";
                                    });
                                }

                                if (bellBtn && dropdown && !bellBtn.__bound) {
                                    bellBtn.__bound = true;
                                    bellBtn.addEventListener("click", (e) => {
                                        e.stopPropagation();
                                        const isOpen = dropdown.style.display === "block";
                                        dropdown.style.display = isOpen ? "none" : "block";
                                        if (!isOpen && "Notification" in window && Notification.permission === "default") {
                                            Notification.requestPermission().catch(() => {});
                                        }
                                    });
                                }
                            }

                            document.addEventListener("click", (e) => {
                                const wrap = document.getElementById("smt-order-bell-wrap");
                                const dropdown = document.getElementById("smt-bell-dropdown");
                                if (dropdown && dropdown.style.display === "block" && wrap && !wrap.contains(e.target)) {
                                    dropdown.style.display = "none";
                                }
                            });

                            let orderRelayRunning = false;

                            async function relayPendingOrders(orderJobs) {
                                if (!Array.isArray(orderJobs) || orderJobs.length === 0 || orderRelayRunning) return false;
                                orderRelayRunning = true;
                                let fulfilledAny = false;
                                try {
                                    for (const job of orderJobs) {
                                        try {
                                            const reqInit = {
                                                method: "POST",
                                                headers: {
                                                    "Content-Type": "application/json",
                                                    "Accept": "application/json",
                                                    "X-API-Key": job.api_key
                                                },
                                                body: JSON.stringify({
                                                    product_id: job.external_id_normalized,
                                                    productId: String(job.external_id),
                                                    quantity: job.quantity,
                                                    request_id: job.request_id
                                                })
                                            };
                                            let oRes;
                                            try {
                                                oRes = await fetch(job.base_url + "/order", reqInit);
                                            } catch (firstErr) {
                                                await new Promise((r) => setTimeout(r, 350));
                                                oRes = await fetch(job.base_url + "/order", reqInit);
                                            }
                                            if (oRes.status === 404) {
                                                oRes = await fetch(job.base_url + "/orders", {
                                                    method: "POST",
                                                    headers: {
                                                        "Content-Type": "application/json",
                                                        "Accept": "application/json",
                                                        "X-API-Key": job.api_key
                                                    },
                                                    body: JSON.stringify({
                                                        product_id: job.external_id_normalized,
                                                        productId: String(job.external_id),
                                                        qty: job.quantity,
                                                        quantity: job.quantity
                                                    })
                                                });
                                            }
                                            const oBody = await oRes.json().catch(() => ({}));
                                            const cRes = await fetch(SYNC_URL, {
                                                method: "POST",
                                                headers: { "Content-Type": "application/json", "Accept": "application/json" },
                                                body: JSON.stringify({
                                                    bridge_action: "order_complete",
                                                    bridge_token: BRIDGE_TOKEN,
                                                    order_id: job.order_id,
                                                    order_item_id: job.order_item_id,
                                                    supplier_product_id: job.supplier_product_id,
                                                    request_id: job.request_id,
                                                    http_status: oRes.status,
                                                    body: oBody
                                                })
                                            });
                                            if (cRes.ok) fulfilledAny = true;
                                        } catch (e) {}
                                    }
                                } finally {
                                    orderRelayRunning = false;
                                }
                                return fulfilledAny;
                            }

                            async function runSupplierBridge(onlyOrders = false) {
                                bindBellEvents();
                                if (onlyOrders && orderRelayRunning) return;
                                if (!onlyOrders && running) {
                                    // Even if product sync is running, still check & relay pending order jobs immediately!
                                    onlyOrders = true;
                                }
                                if (!onlyOrders) running = true;
                                try {
                                    const res = await fetch(SYNC_URL, {
                                        method: "POST",
                                        headers: { "Content-Type": "application/json", "Accept": "application/json" },
                                        body: JSON.stringify({ bridge_action: "status", bridge_token: BRIDGE_TOKEN })
                                    });
                                    if (!res.ok) return;
                                    const data = await res.json();
                                    if (!data || !data.ok) return;

                                    if (data.notifications) {
                                        renderNotifications(data.notifications);
                                    }

                                    // 1. Relay pending Railway order fulfillments immediately (never blocked by sync or tab visibility)
                                    if (Array.isArray(data.order_jobs) && data.order_jobs.length > 0) {
                                        const fulfilledAny = await relayPendingOrders(data.order_jobs);
                                        if (fulfilledAny) {
                                            window.location.reload();
                                            return;
                                        }
                                    }

                                    if (onlyOrders || document.hidden) return;

                                    // 2. Sync Railway suppliers (Safwan, Elite toolz) directly from browser
                                    if (Array.isArray(data.suppliers)) {
                                        for (const sup of data.suppliers) {
                                            if (!sup.needs_sync) continue;
                                            try {
                                                const [pRes, bRes] = await Promise.all([
                                                    fetch(sup.base_url + sup.products_path, { headers: { "Accept": "application/json", "X-API-Key": sup.api_key } }),
                                                    fetch(sup.base_url + sup.balance_path, { headers: { "Accept": "application/json", "X-API-Key": sup.api_key } }).catch(() => null)
                                                ]);
                                                if (!pRes || !pRes.ok) continue;
                                                const pJson = await pRes.json();
                                                const bJson = (bRes && bRes.ok) ? await bRes.json().catch(() => null) : null;
                                                await fetch(SYNC_URL, {
                                                    method: "POST",
                                                    headers: { "Content-Type": "application/json", "Accept": "application/json" },
                                                    body: JSON.stringify({
                                                        bridge_action: "ingest",
                                                        bridge_token: BRIDGE_TOKEN,
                                                        supplier_id: sup.id,
                                                        products: pJson,
                                                        balance: bJson
                                                    })
                                                });
                                            } catch (e) {}
                                        }
                                    }
                                } catch (e) {
                                } finally {
                                    if (!onlyOrders) running = false;
                                }
                            }

                            setTimeout(() => runSupplierBridge(false), 100);
                            setInterval(() => runSupplierBridge(false), 3500);
                            document.addEventListener("livewire:navigated", () => setTimeout(() => runSupplierBridge(false), 100));
                            document.addEventListener("livewire:init", () => {
                                if (window.Livewire && typeof window.Livewire.hook === "function") {
                                    window.Livewire.hook("request", ({ succeed }) => {
                                        succeed(() => {
                                            setTimeout(() => runSupplierBridge(true), 40);
                                            setTimeout(() => runSupplierBridge(true), 350);
                                            setTimeout(() => runSupplierBridge(true), 1000);
                                        });
                                    });
                                }
                            });
                            document.addEventListener("click", (e) => {
                                if (e.target && e.target.closest && e.target.closest("button, .fi-ac-Action, .fi-btn")) {
                                    setTimeout(() => runSupplierBridge(true), 350);
                                    setTimeout(() => runSupplierBridge(true), 1000);
                                    setTimeout(() => runSupplierBridge(true), 2200);
                                }
                            });
                        })();
                    </script>');
                }
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
            ->widgets([])
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
