<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ResellerController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\StockController;
use App\Models\Order;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Public uploaded media (stateless, no session lock)
Route::get('/media/{path}', MediaController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->where('path', '.*')
    ->name('media');
Route::get('/storage/{path}', MediaController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->where('path', '.*');

// SEO, AEO & GEO Sitemaps / Feeds (stateless)
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('sitemap');
Route::get('/feed/google-shopping.xml', [SeoController::class, 'googleShoppingFeed'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('feed.google-shopping');
Route::get('/llms.txt', [SeoController::class, 'llmsTxt'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('llms');
Route::get('/llms-full.txt', [SeoController::class, 'llmsTxt'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
Route::get('/robots.txt', [SeoController::class, 'robots'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('robots');
Route::get('/{key}.txt', [SeoController::class, 'indexNowKey'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->where('key', '[a-zA-Z0-9\-]{8,64}')
    ->name('indexnow.key');

// Catalog
Route::get('/', [ProductController::class, 'index'])->name('home');
Route::get('/shop', [ProductController::class, 'shop'])->name('shop');
Route::get('/category/{category:slug}', [ProductController::class, 'category'])->name('categories.show');
Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('products.show');
Route::post('/products/{product:slug}/reviews', [ProductController::class, 'storeReview'])->middleware('throttle:15,1')->name('products.reviews.store');

// Instant search suggestions (stateless, cached)
Route::get('/api/search', SearchController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:120,1')
    ->name('search.suggest');

// Stateless live-stock & background supplier sync (no session lock -> never slows down user clicks!)
Route::get('/api/stock', StockController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:300,1')
    ->name('stock');
Route::post('/api/sync', [StockController::class, 'sync'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:30,1')
    ->name('stock.sync');

// Auto-Payment Webhooks (SMS/Notification forwarder + 1-click Telegram & WhatsApp approve/decline)
Route::match(['get', 'post'], '/api/webhooks/payment-sms', [PaymentWebhookController::class, 'sms'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:120,1')
    ->name('webhooks.payment.sms');
Route::post('/api/webhooks/telegram', [PaymentWebhookController::class, 'telegram'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:120,1')
    ->name('webhooks.telegram');
Route::match(['get', 'post'], '/webhooks/payment/{payment}/{action}', [PaymentWebhookController::class, 'quickAction'])
    ->name('webhooks.payment.quick-action');
Route::match(['get', 'post'], '/quick-order/{order:number}', [PaymentWebhookController::class, 'quickOrder'])
    ->middleware('throttle:60,1')
    ->name('webhooks.order.quick');

// Checkout
Route::get('/checkout/{product:slug}', [CheckoutController::class, 'create'])->name('checkout.create');
Route::post('/checkout/{product:slug}', [CheckoutController::class, 'store'])->middleware('throttle:60,1')->name('checkout.store');

// Orders (guest access via secret token, or owner when logged in)
Route::get('/orders/{order:number}', [OrderController::class, 'show'])->name('orders.show');
Route::get('/orders/{order:number}/status', [OrderController::class, 'status'])->middleware('throttle:300,1')->name('orders.status');
Route::get('/orders/{order:number}/payment', fn (Order $order) => redirect()->to($order->customerUrl()));
Route::post('/orders/{order:number}/payment', [OrderController::class, 'submitPayment'])->middleware('throttle:60,1')->name('orders.payment');
Route::post('/orders/{order:number}/pay-wallet', [OrderController::class, 'payWithWallet'])->middleware(['auth', 'throttle:30,1'])->name('orders.pay-wallet');
Route::post('/orders/{order:number}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:60,1')->name('orders.cancel');
Route::get('/track', [OrderController::class, 'track'])->name('orders.track');
Route::post('/track', [OrderController::class, 'lookup'])->middleware('throttle:60,1')->name('orders.lookup');

// Customer auth
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:30,1');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:10,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:15,1')->name('password.update');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/account/orders', [AccountController::class, 'orders'])->middleware('auth')->name('account.orders');
Route::post('/account/profile', [AccountController::class, 'updateProfile'])->middleware('auth')->name('account.profile.update');

// Static pages (required by payment gateways & SEO)
Route::get('/about', fn () => redirect()->route('pages.show', 'about', 301));
Route::get('/contact', fn () => redirect()->route('pages.show', 'contact', 301));
Route::get('/pages/{page}', PageController::class)
    ->whereIn('page', ['about', 'terms', 'privacy', 'refund', 'contact', 'faq'])
    ->name('pages.show');
Route::post('/pages/contact', [PageController::class, 'submitContact'])
    ->middleware('throttle:10,1')
    ->name('pages.contact.submit');

// Secret VIP Reseller Portals (e.g. mydomain.com/ali or mydomain.com/r/ali)
Route::get('/r/{slug}', [ResellerController::class, 'portal'])
    ->where('slug', '[a-z0-9\-]+');
Route::post('/{slug}/login', [ResellerController::class, 'login'])
    ->where('slug', '[a-z0-9\-]+')
    ->middleware('throttle:30,1')
    ->name('reseller.login');
Route::post('/{slug}/wallet-buy/{product:slug}', [ResellerController::class, 'quickWalletBuy'])
    ->where('slug', '[a-z0-9\-]+')
    ->middleware(['auth', 'throttle:30,1'])
    ->name('reseller.wallet-buy');
Route::get('/{slug}', [ResellerController::class, 'portal'])
    ->where('slug', '^(?!admin|livewire|filament|api|media|storage|feed|shop|about|contact|faq|category|products|checkout|orders|track|login|register|logout|account|pages|webhooks|quick-order|up)[a-z0-9\-]+$')
    ->name('reseller.portal');
