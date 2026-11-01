<?php

namespace App\Http\Controllers;

use App\Exceptions\StoreException;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function create(Request $request, Product $product): View|RedirectResponse
    {
        abort_unless($product->is_active, 404);

        if (! $product->inStock()) {
            return redirect()->route('products.show', $product)->with('error', 'This product is out of stock right now.');
        }

        $user = $request->user();
        $unitPrice = $product->priceFor($user);
        $quantity = max(1, min((int) $request->query('qty', 1), $product->max_per_order));
        $methods = PaymentMethod::query()
            ->where('is_active', true)
            ->when(! $user || (float) $user->wallet_balance <= 0, fn ($q) => $q->where('code', '!=', 'wallet'))
            ->orderBy('sort')
            ->get();

        return view('store.checkout', compact('product', 'quantity', 'methods', 'unitPrice'));
    }

    public function store(Request $request, Product $product, OrderService $orders, PaymentService $payments): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $user = $request->user();
        $payWithWallet = $request->boolean('pay_with_wallet');

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.$product->max_per_order],
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'payment_method_id' => [$payWithWallet ? 'nullable' : 'required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'agree' => ['accepted'],
        ], [
            'agree.accepted' => 'Please accept the terms & refund policy.',
        ]);

        $normalizedPhone = null;
        if (filled($data['phone'] ?? null)) {
            $normalizedPhone = format_whatsapp((string) $data['phone']);
            if (! preg_match('/^923\d{9}$/', $normalizedPhone) && ! preg_match('/^\d{10,15}$/', $normalizedPhone)) {
                throw ValidationException::withMessages([
                    'phone' => 'Enter a valid WhatsApp number in exact format (e.g. 923001234567 or 03001234567).',
                ]);
            }
        } elseif ($user?->phone) {
            $normalizedPhone = format_whatsapp($user->phone);
        }

        try {
            if ($payWithWallet) {
                if (! $user) {
                    throw new StoreException('Please log in to pay with your Account Wallet Balance.');
                }
                $method = PaymentMethod::query()->firstOrCreate(
                    ['code' => 'wallet'],
                    [
                        'name' => 'Account Wallet Balance (Instant Auto Delivery)',
                        'type' => 'auto',
                        'currency' => 'PKR',
                        'is_active' => true,
                        'sort' => 0,
                    ]
                );
            } else {
                $method = PaymentMethod::findOrFail($data['payment_method_id']);
            }

            $order = $orders->create(
                $product,
                (int) $data['quantity'],
                [
                    'email' => $data['email'],
                    'name' => $data['name'] ?? null,
                    'phone' => $normalizedPhone,
                    'ip' => $request->ip(),
                ],
                $method,
                $user,
            );

            // If user selected Wallet payment (or payment method is 'wallet'), deduct wallet & auto-deliver immediately!
            if (($payWithWallet || $method->code === 'wallet') && $user) {
                $order = $payments->payWithWallet($order, $user);
            }
        } catch (StoreException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Remember guest orders in the session so the customer can find them again.
        $request->session()->push('guest_orders', $order->number);

        return redirect()->to($order->customerUrl());
    }
}
