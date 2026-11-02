<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        $counts = Order::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $countFor = function (array $statuses) use ($counts): int {
            $sum = 0;
            foreach ($statuses as $status) {
                $val = $status instanceof OrderStatus ? $status->value : (string) $status;
                $sum += (int) ($counts[$val] ?? 0);
            }

            return $sum;
        };

        $tab = function (string $label, array $statuses, ?string $badgeColor = null) use ($countFor): Tab {
            $values = array_map(fn (OrderStatus $s): string => $s->value, $statuses);
            $count = $countFor($statuses);

            return Tab::make($label)
                ->badge($count > 0 ? (string) $count : null)
                ->badgeColor($badgeColor)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', $values));
        };

        $totalAll = array_sum(array_map('intval', $counts));

        return [
            'all' => Tab::make('All Orders')
                ->badge($totalAll > 0 ? (string) $totalAll : null)
                ->badgeColor('gray'),
            'review' => $tab('Payment Review', [OrderStatus::PaymentReview], 'warning'),
            'attention' => $tab('Needs Attention', [OrderStatus::NeedsAttention], 'danger'),
            'processing' => $tab('Processing', [OrderStatus::Processing], 'info'),
            'completed' => $tab('Completed', [OrderStatus::Completed], 'success'),
            'pending' => $tab('Unpaid', [OrderStatus::PendingPayment], 'gray'),
            'closed' => $tab('Cancelled / Refunded', [OrderStatus::Cancelled, OrderStatus::Refunded, OrderStatus::Expired], 'gray'),
        ];
    }
}
