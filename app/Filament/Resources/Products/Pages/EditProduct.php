<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Listings;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Manually re-activating a product clears the auto-disable flag.
        if (($data['is_active'] ?? false) && ! $this->record->is_active) {
            $data['disabled_reason'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Product $product */
        $product = $this->record;

        Listings::refresh($product);

        $this->reloadListing();
    }

    /** Fired by the relation managers after sources / stock change so computed values stay current. */
    #[On('product-refreshed')]
    public function reloadListing(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['is_active']);
    }
}
