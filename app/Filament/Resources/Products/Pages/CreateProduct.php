<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Listings;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        /** @var Product $product */
        $product = $this->record;

        Listings::refresh($product);
    }

    protected function getRedirectUrl(): string
    {
        // Land on the edit page so sources / own stock can be added straight away.
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
