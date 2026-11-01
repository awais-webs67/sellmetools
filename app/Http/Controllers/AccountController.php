<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function orders(Request $request): View
    {
        $user = $request->user();
        $search = trim((string) $request->query('q', ''));
        $statusFilter = strtolower((string) $request->query('status', 'all'));

        $query = $user->orders()->with(['items.deliveries', 'items.product', 'paymentMethod']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('items', function ($iq) use ($search) {
                        $iq->where('product_name', 'like', "%{$search}%")
                            ->orWhereHas('deliveries', fn ($dq) => $dq->where('content', 'like', "%{$search}%"));
                    });
            });
        }

        if ($statusFilter === 'completed') {
            $query->where('status', OrderStatus::Completed);
        } elseif ($statusFilter === 'pending') {
            $query->whereIn('status', [OrderStatus::PendingPayment, OrderStatus::PaymentReview, OrderStatus::Processing, OrderStatus::NeedsAttention]);
        } elseif ($statusFilter === 'cancelled') {
            $query->whereIn('status', [OrderStatus::Cancelled, OrderStatus::Expired, OrderStatus::Refunded]);
        }

        $orders = $query->latest()->paginate(15)->withQueryString();

        $stats = [
            'total' => $user->orders()->count(),
            'completed' => $user->orders()->where('status', OrderStatus::Completed)->count(),
            'pending' => $user->orders()->whereIn('status', [OrderStatus::PendingPayment, OrderStatus::PaymentReview, OrderStatus::Processing])->count(),
        ];

        $walletTransactions = $user->walletTransactions()->limit(10)->get();

        return view('account.orders', compact('orders', 'search', 'statusFilter', 'stats', 'walletTransactions'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $normalizedPhone = format_whatsapp($data['phone']);
        if (! preg_match('/^923\d{9}$/', $normalizedPhone) && ! preg_match('/^\d{10,15}$/', $normalizedPhone)) {
            throw ValidationException::withMessages([
                'phone' => 'Please enter a valid WhatsApp number in exact format (e.g. 923001234567 or 03001234567).',
            ]);
        }

        $request->user()->update([
            'name' => $data['name'],
            'phone' => $normalizedPhone,
        ]);

        return back()->with('success', 'Profile & WhatsApp number updated.');
    }
}
