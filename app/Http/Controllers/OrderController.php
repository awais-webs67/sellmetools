<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\StoreException;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReceiptPdfGenerator;
use App\Services\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class OrderController extends Controller
{
    /** Only the owner (logged in), guest session owner, or someone holding the secret token may see an order. */
    protected function authorizeAccess(Request $request, Order $order): void
    {
        $token = (string) $request->input('token', $request->query('token', ''));
        $guestOrders = (array) $request->session()->get('guest_orders', []);

        $allowed = ($token !== '' && hash_equals($order->access_token, $token))
            || in_array($order->number, $guestOrders, true)
            || ($request->user() && $order->user_id === $request->user()->id)
            || ($request->user()?->is_admin);

        abort_unless($allowed, 404);
    }

    public function show(Request $request, Order $order): View
    {
        $this->authorizeAccess($request, $order);

        $order->load(['items.deliveries', 'items.product', 'paymentMethod', 'latestPayment']);

        return view('store.order', [
            'order' => $order,
            'token' => $order->access_token,
            'amountDue' => $order->paymentMethod?->amountFor($order),
        ]);
    }

    public function status(Request $request, Order $order): JsonResponse
    {
        $this->authorizeAccess($request, $order);

        return response()->json([
            'status' => $order->status->value,
            'label' => $order->status->label(),
            'expired' => $order->isExpired(),
            'review_timeout' => $order->status === OrderStatus::PaymentReview && (bool) $order->expires_at?->isPast(),
        ])->header('Cache-Control', 'no-store');
    }

    public function submitPayment(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $this->authorizeAccess($request, $order);

        $data = $request->validate([
            'transaction_id' => ['required', 'string', 'min:3', 'max:120'],
            'sender_number' => [$order->paymentMethod?->requires_sender_number ? 'required' : 'nullable', 'string', 'max:64'],
            'customer_input' => ['nullable', 'string', 'max:2000'],
            'screenshot' => ['nullable', 'image', 'max:8192'],
        ]);

        if (! empty($data['customer_input'])) {
            $order->update(['customer_input' => trim((string) $data['customer_input'])]);
        }

        try {
            $payments->submit($order, $data['transaction_id'], $data['sender_number'] ?? null, $request->file('screenshot'));
        } catch (StoreException $e) {
            return redirect()->to($order->customerUrl())->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to($order->customerUrl())->with('success', 'Payment submitted! We are verifying it, your order will be delivered automatically.');
    }

    public function submitCustomerInput(Request $request, Order $order, TelegramNotifier $telegram): RedirectResponse
    {
        $this->authorizeAccess($request, $order);

        $data = $request->validate([
            'customer_input' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $order->update([
            'customer_input' => trim((string) $data['customer_input']),
        ]);

        $notifyInput = function () use ($order, $telegram): void {
            $label = $order->requiredCustomerInputLabel() ?? 'Customer Activation Details';
            $items = $order->items()->get()->map(fn ($i) => $i->product_name.' x'.$i->quantity)->implode(', ');
            $quickUrl = route('webhooks.order.quick', ['order' => $order->number, 'do' => 'approve']);

            $telegram->notifyAdmin(
                "📝 <b>CUSTOMER ACTIVATION DETAILS SUBMITTED</b>\n\n"
                ."🧾 <b>Order:</b> <code>{$order->number}</code>\n"
                .'📦 <b>Product:</b> '.e($items)."\n"
                .'📧 <b>Customer Email:</b> '.e($order->email)."\n"
                ."🏷️ <b>{$label}:</b>\n<code>".e($order->customer_input)."</code>\n\n"
                ."👉 <a href=\"{$quickUrl}\">Open Order & Deliver Now</a>"
            );
        };

        if (app()->runningUnitTests()) {
            $notifyInput();
        } else {
            defer($notifyInput);
        }

        return redirect()->to($order->customerUrl())->with('success', 'Your activation details have been saved and sent to the admin! Your custom delivery will be completed shortly.');
    }

    public function receipt(Request $request, Order $order, ReceiptPdfGenerator $pdf): View|Response
    {
        $this->authorizeAccess($request, $order);

        $order->load(['items.deliveries', 'paymentMethod', 'latestPayment']);

        if ($request->query('download') === 'pdf') {
            $bytes = $pdf->generate($order);
            $filename = 'Receipt-'.$order->number.'.pdf';

            return response($bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Content-Length' => (string) strlen($bytes),
                'Cache-Control' => 'private, no-store, max-age=0',
            ]);
        }

        return view('store.receipt', [
            'order' => $order,
            'token' => $order->access_token,
        ]);
    }

    public function payWithWallet(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $this->authorizeAccess($request, $order);

        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

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
            $order->update(['payment_method_id' => $walletMethod->id, 'user_id' => $order->user_id ?: $user->id]);
            $payments->payWithWallet($order, $user);
        } catch (StoreException $e) {
            return redirect()->to($order->customerUrl())->with('error', $e->getMessage());
        }

        return redirect()->to($order->customerUrl())->with('success', 'Paid instantly from your Account Wallet! Your order is ready.');
    }

    public function cancel(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        $this->authorizeAccess($request, $order);

        if ($order->status === OrderStatus::PendingPayment) {
            $orders->release($order, OrderStatus::Cancelled);
        }

        return redirect()->to($order->customerUrl())->with('success', 'Order cancelled.');
    }

    public function track(): View
    {
        return view('store.track');
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email'],
        ]);

        $order = Order::query()
            ->where('number', strtoupper(trim($data['number'])))
            ->where('email', $data['email'])
            ->first();

        if (! $order) {
            return back()->withInput()->with('error', 'No order found with that number and email.');
        }

        return redirect()->to($order->customerUrl());
    }
}
