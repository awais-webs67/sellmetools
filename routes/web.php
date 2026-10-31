<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockController;
use App\Models\Order;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Public uploaded media (stateless, no session lock)
Route::get('/media/{path}', MediaController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->where('path', '.*')
    ->name('media');
Route::get('/storage/{path}', MediaController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->where('path', '.*');

// Catalog
Route::get('/', [ProductController::class, 'index'])->name('home');
Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('products.show');
Route::post('/products/{product:slug}/reviews', [ProductController::class, 'storeReview'])->middleware('throttle:15,1')->name('products.reviews.store');

// Stateless live-stock & background supplier sync (no session lock -> never slows down user clicks!)
Route::get('/api/stock', StockController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->middleware('throttle:300,1')
    ->name('stock');
Route::post('/api/sync', [StockController::class, 'sync'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('stock.sync');

// Checkout
Route::get('/checkout/{product:slug}', [CheckoutController::class, 'create'])->name('checkout.create');
Route::post('/checkout/{product:slug}', [CheckoutController::class, 'store'])->middleware('throttle:60,1')->name('checkout.store');

// Orders (guest access via secret token, or owner when logged in)
Route::get('/orders/{order:number}', [OrderController::class, 'show'])->name('orders.show');
Route::get('/orders/{order:number}/status', [OrderController::class, 'status'])->middleware('throttle:300,1')->name('orders.status');
Route::get('/orders/{order:number}/payment', fn (Order $order) => redirect()->to($order->customerUrl()));
Route::post('/orders/{order:number}/payment', [OrderController::class, 'submitPayment'])->middleware('throttle:60,1')->name('orders.payment');
Route::post('/orders/{order:number}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:60,1')->name('orders.cancel');
Route::get('/track', [OrderController::class, 'track'])->name('orders.track');
Route::post('/track', [OrderController::class, 'lookup'])->middleware('throttle:60,1')->name('orders.lookup');

// Customer auth
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:30,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/account/orders', [AccountController::class, 'orders'])->middleware('auth')->name('account.orders');

// Static pages (required by payment gateways)
Route::get('/pages/{page}', PageController::class)
    ->whereIn('page', ['terms', 'privacy', 'refund', 'contact', 'faq'])
    ->name('pages.show');
