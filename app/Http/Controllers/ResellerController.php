<?php

namespace App\Http\Controllers;

use App\Exceptions\StoreException;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ResellerController extends Controller
{
    public function portal(Request $request, string $slug): View
    {
        $reseller = User::query()
            ->where('is_reseller', true)
            ->where('reseller_slug', strtolower(trim($slug)))
            ->with('resellerProductPrices')
            ->firstOrFail();

        abort_unless($reseller->is_active ?? true, 403, 'This reseller portal is currently inactive.');

        $currentUser = $request->user();
        $isAuthorized = $currentUser && ($currentUser->id === $reseller->id || $currentUser->is_admin);

        if (! $isAuthorized) {
            return view('store.reseller', [
                'reseller' => $reseller,
                'authorized' => false,
                'products' => collect(),
                'categories' => collect(),
                'orders' => collect(),
                'search' => '',
                'categorySlug' => '',
            ]);
        }

        $search = trim((string) $request->query('q', ''));
        $categorySlug = trim((string) $request->query('category', ''));

        $products = Product::query()
            ->visible()
            ->with('category')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($categorySlug !== '', fn ($q) => $q->whereHas('category', fn ($cq) => $cq->where('slug', $categorySlug)))
            ->orderByRaw('CASE WHEN available_stock > 0 OR stock_unlimited = 1 THEN 0 ELSE 1 END')
            ->inRandomOrder()
            ->get();

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();

        $orders = $reseller->orders()
            ->with(['items.deliveries', 'paymentMethod'])
            ->latest()
            ->limit(15)
            ->get();

        return view('store.reseller', [
            'reseller' => $reseller,
            'authorized' => true,
            'products' => $products,
            'categories' => $categories,
            'orders' => $orders,
            'search' => $search,
            'categorySlug' => $categorySlug,
        ]);
    }

    public function login(Request $request, string $slug): RedirectResponse
    {
        $reseller = User::query()
            ->where('is_reseller', true)
            ->where('reseller_slug', strtolower(trim($slug)))
            ->firstOrFail();

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, true)) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Invalid reseller email or password.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();
        if (! ($user->is_active ?? true) || ($user->id !== $reseller->id && ! $user->is_admin)) {
            Auth::logout();

            return back()->withErrors([
                'email' => 'This account does not have access to the @'.$reseller->reseller_slug.' reseller portal.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->route('reseller.portal', $reseller->reseller_slug)
            ->with('success', 'Welcome to your VIP Reseller Portal, '.$reseller->name.'!');
    }

    public function quickWalletBuy(
        Request $request,
        string $slug,
        Product $product,
        OrderService $orders,
        PaymentService $payments
    ): RedirectResponse {
        $reseller = User::query()
            ->where('is_reseller', true)
            ->where('reseller_slug', strtolower(trim($slug)))
            ->with('resellerProductPrices')
            ->firstOrFail();

        $currentUser = $request->user();
        abort_unless($currentUser && ($currentUser->id === $reseller->id || $currentUser->is_admin), 403);

        $quantity = max(1, min((int) $request->input('quantity', 1), $product->max_per_order));

        try {
            $walletMethod = PaymentMethod::query()->firstOrCreate(
                ['code' => 'wallet'],
                [
                    'name' => 'Account Wallet Balance (Instant Auto Delivery)',
                    'type' => 'auto',
                    'currency' => 'PKR',
                    'is_active' => true,
                    'sort' => 0,
                ]
            );

            $order = $orders->create(
                $product,
                $quantity,
                [
                    'email' => $reseller->email,
                    'name' => $reseller->name,
                    'phone' => $reseller->phone,
                    'ip' => $request->ip(),
                ],
                $walletMethod,
                $reseller
            );

            $order = $payments->payWithWallet($order, $reseller);

            return redirect()->to($order->customerUrl())
                ->with('success', "Instant Reseller Order #{$order->number} paid via Wallet & delivered!");
        } catch (StoreException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
