<?php

namespace App\Http\Controllers;

use App\Exceptions\StoreException;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function create(Request $request, string $slug): View|RedirectResponse
    {
        Product::ensureFreeAndStockColumnsExist();

        $product = Product::query()->where('slug', $slug)->first();
        if (! $product) {
            $legacyMatch = Product::query()
                ->where('is_active', true)
                ->whereJsonContains('legacy_slugs', $slug)
                ->first();

            if ($legacyMatch) {
                return redirect()->route('checkout.create', $legacyMatch, 301);
            }

            if (Product::onlyTrashed()->where(fn ($q) => $q->where('slug', $slug)->orWhereJsonContains('legacy_slugs', $slug))->exists() || $slug === 'test-reseller-api-elite') {
                return redirect()->route('shop', [], 301);
            }

            abort(404);
        }

        abort_unless($product->is_active, 404);

        if (! $product->inStock()) {
            return redirect()->route('products.show', $product)->with('error', 'This product is out of stock right now.');
        }

        $user = $request->user();
        $unitPrice = $product->priceFor($user);
        $quantity = max(1, min((int) $request->query('qty', 1), $product->max_per_order));
        $methods = PaymentMethod::query()
            ->where('is_active', true)
            ->whereNotIn('code', ['wallet', 'free'])
            ->orderBy('sort')
            ->get();

        return view('store.checkout', compact('product', 'quantity', 'methods', 'unitPrice'));
    }

    public function store(Request $request, Product $product, OrderService $orders, PaymentService $payments): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $user = $request->user();
        $isFree = $product->isFree($user);
        $payWithWallet = ! $isFree && $request->boolean('pay_with_wallet');

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.$product->max_per_order],
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'voucher_code' => ['nullable', 'string', 'max:64'],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'agree' => ['accepted'],
        ], [
            'agree.accepted' => 'Please accept the terms & refund policy.',
        ]);

        $normalizedPhone = null;
        if (filled($data['phone'] ?? null)) {
            $normalizedPhone = format_whatsapp((string) $data['phone']);
            if (! preg_match('/^\d{8,15}$/', $normalizedPhone)) {
                throw ValidationException::withMessages([
                    'phone' => 'Enter a valid WhatsApp number with country code (e.g. +1..., +44..., +971..., or 03001234567).',
                ]);
            }
        } elseif ($user?->phone) {
            $normalizedPhone = format_whatsapp($user->phone);
        }

        try {
            if ($isFree) {
                $method = PaymentMethod::query()->firstOrCreate(
                    ['code' => 'free'],
                    [
                        'name' => 'Free Instant Access (No Payment Required)',
                        'type' => 'auto',
                        'currency' => 'PKR',
                        'is_active' => true,
                        'sort' => 998,
                    ]
                );
                if (! $method->is_active) {
                    $method->update(['is_active' => true]);
                }
            } elseif ($payWithWallet) {
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
            } elseif (filled($data['payment_method_id'] ?? null)) {
                $method = PaymentMethod::findOrFail($data['payment_method_id']);
            } else {
                $method = PaymentMethod::query()->where('is_active', true)->orderBy('sort')->first();
                if (! $method) {
                    throw new StoreException('Please select a payment method.');
                }
            }

            $order = $orders->create(
                $product,
                (int) $data['quantity'],
                [
                    'email' => $data['email'],
                    'name' => $data['name'] ?? null,
                    'phone' => $normalizedPhone,
                    'voucher_code' => $data['voucher_code'] ?? null,
                    'ip' => $request->ip(),
                ],
                $method,
                $user,
            );

            // Free product or 100% off voucher: auto-approve and fulfill immediately without payment or TID!
            if ($isFree || (float) $order->total <= 0) {
                $order = $payments->completeFreeOrder($order);
            } elseif (($payWithWallet || $method->code === 'wallet') && $user) {
                // If user selected Wallet payment (or payment method is 'wallet'), deduct wallet & auto-deliver immediately!
                $order = $payments->payWithWallet($order, $user);
            }
        } catch (StoreException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Remember guest orders in the session so the customer can find them again.
        $request->session()->push('guest_orders', $order->number);

        return redirect()->to($order->customerUrl());
    }

    public function checkVoucher(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        $code = strtoupper(trim((string) $request->input('code', '')));
        $qty = max(1, min((int) $request->input('quantity', 1), (int) $product->max_per_order));
        $email = trim((string) $request->input('email', ''));

        if ($code === '') {
            return response()->json(['ok' => false, 'message' => 'Please enter a voucher code.'], 422);
        }

        $user = $request->user();
        $unitPrice = (float) $product->priceFor($user);
        $subtotal = round($unitPrice * $qty, 2);

        try {
            Voucher::ensureSchemaExists();
            /** @var Voucher|null $voucher */
            $voucher = Voucher::query()->where('code', $code)->first();
            if (! $voucher) {
                throw new StoreException("Invalid voucher code: {$code}");
            }
            $discount = $voucher->validateAndCalculateDiscount($product, $subtotal, $email ?: ($user?->email), $user?->id);

            return response()->json([
                'ok' => true,
                'code' => $voucher->code,
                'title' => $voucher->title,
                'discount_type' => $voucher->discount_type,
                'discount_value' => (float) $voucher->discount_value,
                'max_discount_amount' => $voucher->max_discount_amount !== null ? (float) $voucher->max_discount_amount : null,
                'formatted_discount' => $voucher->formattedDiscount(),
                'discount_pkr' => $discount,
                'final_total_pkr' => max(0.0, round($subtotal - $discount, 2)),
                'message' => "Voucher {$voucher->code} ({$voucher->formattedDiscount()}) applied!",
            ]);
        } catch (StoreException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
