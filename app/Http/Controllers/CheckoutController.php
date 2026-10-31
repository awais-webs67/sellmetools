<?php

namespace App\Http\Controllers;

use App\Exceptions\StoreException;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function create(Request $request, Product $product): View|RedirectResponse
    {
        abort_unless($product->is_active, 404);

        if (! $product->inStock()) {
            return redirect()->route('products.show', $product)->with('error', 'This product is out of stock right now.');
        }

        $quantity = max(1, min((int) $request->query('qty', 1), $product->max_per_order));
        $methods = PaymentMethod::query()->where('is_active', true)->orderBy('sort')->get();

        return view('store.checkout', compact('product', 'quantity', 'methods'));
    }

    public function store(Request $request, Product $product, OrderService $orders): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.$product->max_per_order],
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'agree' => ['accepted'],
        ], [
            'agree.accepted' => 'Please accept the terms & refund policy.',
        ]);

        try {
            $order = $orders->create(
                $product,
                (int) $data['quantity'],
                [
                    'email' => $data['email'],
                    'name' => $data['name'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'ip' => $request->ip(),
                ],
                PaymentMethod::findOrFail($data['payment_method_id']),
                $request->user(),
            );
        } catch (StoreException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Remember guest orders in the session so the customer can find them again.
        $request->session()->push('guest_orders', $order->number);

        return redirect()->to($order->customerUrl());
    }
}
