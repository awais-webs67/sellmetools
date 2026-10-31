<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\StoreException;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    /** Only the owner (logged in) or someone holding the secret token may see an order. */
    protected function authorizeAccess(Request $request, Order $order): void
    {
        $token = (string) $request->input('token', $request->query('token', ''));

        $allowed = ($token !== '' && hash_equals($order->access_token, $token))
            || ($request->user() && $order->user_id === $request->user()->id)
            || ($request->user()?->is_admin);

        abort_unless($allowed, 404);
    }

    public function show(Request $request, Order $order): View
    {
        $this->authorizeAccess($request, $order);

        $order->load(['items.deliveries', 'paymentMethod', 'latestPayment']);

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
        ])->header('Cache-Control', 'no-store');
    }

    public function submitPayment(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $this->authorizeAccess($request, $order);

        $data = $request->validate([
            'transaction_id' => ['required', 'string', 'min:4', 'max:100', 'regex:/^[A-Za-z0-9\-_.]+$/'],
            'sender_number' => [$order->paymentMethod?->requires_sender_number ? 'required' : 'nullable', 'string', 'max:64'],
            'screenshot' => ['nullable', 'image', 'max:4096'],
        ], [
            'transaction_id.regex' => 'Transaction ID may only contain letters, numbers and dashes.',
        ]);

        try {
            $payments->submit($order, $data['transaction_id'], $data['sender_number'] ?? null, $request->file('screenshot'));
        } catch (StoreException $e) {
            throw ValidationException::withMessages(['transaction_id' => $e->getMessage()]);
        }

        return redirect()->to($order->customerUrl())->with('success', 'Payment submitted! We are verifying it – your order will be delivered automatically.');
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
