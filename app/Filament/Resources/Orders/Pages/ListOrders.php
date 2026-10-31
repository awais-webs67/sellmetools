<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        $tab = fn (string $label, array $statuses): Tab => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses));

        return [
            'all' => Tab::make('All'),
            'review' => $tab('Payment review', [OrderStatus::PaymentReview]),
            'attention' => $tab('Needs attention', [OrderStatus::NeedsAttention]),
            'processing' => $tab('Processing', [OrderStatus::Processing]),
            'completed' => $tab('Completed', [OrderStatus::Completed]),
        ];
    }
}
