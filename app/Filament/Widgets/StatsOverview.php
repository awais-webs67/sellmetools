<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\Money;
use App\Models\Order;
use App\Models\Payment;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '10s';

    /** Paid orders that were not reversed. */
    protected const PAID_STATUSES = [OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention];

    protected function getStats(): array
    {
        $today = Order::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->where('paid_at', '>=', now()->startOfDay());

        $sales = (float) (clone $today)->sum('total');
        $orders = (clone $today)->count();
        $profit = (float) (clone $today)
            ->whereNotNull('total_cost')
            ->selectRaw('COALESCE(SUM(total - total_cost), 0) as profit')
            ->value('profit');

        $pendingPayments = Payment::query()->where('status', PaymentStatus::Pending)->count();
        $attention = Order::query()->where('status', OrderStatus::NeedsAttention)->count();

        return [
            Stat::make('Sales today', Money::pkr($sales, Money::pkr(0)))
                ->description($orders.' paid order(s)')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('primary'),
            Stat::make('Profit today', Money::pkr($profit, Money::pkr(0)))
                ->description('Delivered orders with known cost')
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color($profit < 0 ? 'danger' : 'success'),
            Stat::make('Pending payments', (string) $pendingPayments)
                ->description($pendingPayments > 0 ? 'Awaiting verification' : 'All caught up')
                ->icon(Heroicon::OutlinedClock)
                ->color($pendingPayments > 0 ? 'warning' : 'gray')
                ->url(PaymentResource::getUrl('index')),
            Stat::make('Needs attention', (string) $attention)
                ->description($attention > 0 ? 'Orders that failed auto-delivery' : 'No problems')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($attention > 0 ? 'danger' : 'gray')
                ->url(OrderResource::getUrl('index', ['activeTab' => 'attention'])),
        ];
    }
}
