<?php

namespace App\Filament\Resources\SupplierOrders\Pages;

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
            'direct' => Tab::make('⚡ Direct Admin Buys')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->whereNull('order_item_id')),
            'customer' => Tab::make('🛒 Customer Orders')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->whereNotNull('order_item_id')),
            'failed' => Tab::make('⚠️ Failed / Timeout')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->whereIn('status', [
                    SupplierOrderStatus::Failed,
                    SupplierOrderStatus::Unknown,
                ])),
        ];
    }
}
