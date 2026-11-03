<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountController extends Controller
{
    /** Date presets for the order history filter (key => label). */
    public const DATE_RANGES = [
        'all' => 'All time',
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 3 months',
        'custom' => 'Custom dates',
    ];

    public function orders(Request $request): View
    {
        $user = $request->user();
        $search = trim((string) $request->query('q', ''));
        $statusFilter = strtolower((string) $request->query('status', 'all'));
        $range = (string) $request->query('range', 'all');
        $range = array_key_exists($range, self::DATE_RANGES) ? $range : 'all';
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        $query = $user->orders()->with(['items.deliveries', 'items.product', 'paymentMethod']);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_').'%';
            $query->where(function ($q) use ($like) {
                $q->where('number', 'like', $like)
                    ->orWhereHas('items', function ($iq) use ($like) {
                        $iq->where('product_name', 'like', $like)
                            ->orWhereHas('deliveries', fn ($dq) => $dq->where('content', 'like', $like));
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

        match ($range) {
            'today' => $query->where('created_at', '>=', now()->startOfDay()),
            '7d' => $query->where('created_at', '>=', now()->subDays(7)->startOfDay()),
            '30d' => $query->where('created_at', '>=', now()->subDays(30)->startOfDay()),
            '90d' => $query->where('created_at', '>=', now()->subDays(90)->startOfDay()),
            'custom' => $query
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from->copy()->startOfDay()))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to->copy()->endOfDay())),
            default => null,
        };

        $orders = $query->latest()->paginate(15)->withQueryString();

        // Group the current page by calendar day for a scannable timeline.
        $groupedOrders = $orders->getCollection()->groupBy(fn ($order) => $order->created_at->toDateString());

        $paidOrdersQuery = fn () => $user->orders()->where(function ($q) {
            $q->where('status', OrderStatus::Completed)->orWhereNotNull('paid_at');
        });

        $stats = [
            'total' => $user->orders()->count(),
            'completed' => $user->orders()->where('status', OrderStatus::Completed)->count(),
            'pending' => $user->orders()->whereIn('status', [OrderStatus::PendingPayment, OrderStatus::PaymentReview, OrderStatus::Processing, OrderStatus::NeedsAttention])->count(),
            'total_spent' => (float) $paidOrdersQuery()->sum('total'),
            'this_month_spent' => (float) $paidOrdersQuery()->where('created_at', '>=', now()->startOfMonth())->sum('total'),
            'total_items' => (int) \App\Models\OrderItem::query()
                ->whereIn('order_id', $paidOrdersQuery()->select('id'))
                ->sum('quantity'),
        ];

        $walletTransactions = $user->walletTransactions()->limit(10)->get();

        $filtersActive = $search !== '' || $statusFilter !== 'all' || $range !== 'all';
        $dateRanges = self::DATE_RANGES;

        return view('account.orders', compact(
            'orders', 'groupedOrders', 'search', 'statusFilter', 'range', 'from', 'to',
            'dateRanges', 'filtersActive', 'stats', 'walletTransactions'
        ));
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
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
