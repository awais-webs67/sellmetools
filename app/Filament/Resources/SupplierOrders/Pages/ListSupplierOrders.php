<?php

namespace App\Filament\Resources\SupplierOrders\Pages;

use App\Filament\Resources\SupplierOrders\SupplierOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListSupplierOrders extends ListRecords
{
    protected static string $resource = SupplierOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SupplierOrderResource::directBuyAction(),
        ];
    }
}
