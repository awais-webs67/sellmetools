<?php

namespace App\Filament\Resources\SupplierOrders\Pages;

use App\Enums\OrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Filament\Resources\SupplierOrders\SupplierOrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSupplierOrders extends ListRecords
{
    protected static string $resource = SupplierOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SupplierOrderResource::directBuyAction(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Supplier Orders'),
            'direct' => Tab::make('Direct Admin Buys')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('order_item_id')),
            'customer' => Tab::make('Customer Orders')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('order_item_id')),
            'failed' => Tab::make('Unfulfilled / Needs Action')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', [SupplierOrderStatus::Failed, SupplierOrderStatus::Unknown])
                    ->where(function (Builder $sub): void {
                        $sub->whereNull('order_item_id')
                            ->orWhereHas('orderItem.order', function (Builder $oq): void {
                                $oq->whereIn('status', [OrderStatus::Processing, OrderStatus::NeedsAttention]);
                            });
                    })),
        ];
    }
}
