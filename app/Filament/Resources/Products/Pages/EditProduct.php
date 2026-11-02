<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\StockStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Listings;
use App\Models\Product;
use App\Services\InventoryService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;
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

        $this->processQuickStockUpload($product);

        Listings::refresh($product);

        $this->reloadListing();
    }

    protected function processQuickStockUpload(Product $product): void
    {
        $rawState = $this->form->getRawState();
        $combined = '';

        $file = $rawState['quick_stock_file'] ?? null;
        if (is_array($file)) {
            $file = reset($file);
        }
        if (is_string($file) && $file !== '' && Storage::disk('local')->exists($file)) {
            $combined .= (string) Storage::disk('local')->get($file)."\n";
            Storage::disk('local')->delete($file);
        }

        $text = trim((string) ($rawState['quick_stock_text'] ?? ''));
        if ($text !== '') {
            $combined .= "\n".$text;
        }

        if (trim($combined) !== '') {
            if ($product->delivery_type === 'instant_link') {
                $product->stockItems()->where('status', StockStatus::Available)->delete();
                $product->stockItems()->create([
                    'content' => trim($combined),
                    'status' => StockStatus::Available,
                ]);
                app(InventoryService::class)->refresh($product);

                Notification::make()
                    ->title('Reusable course / resource link updated!')
                    ->success()
                    ->send();

                return;
            }

            $separator = (string) ($rawState['quick_stock_separator'] ?? 'auto');
            $added = $product->importStockItems($combined, $separator);

            Notification::make()
                ->title("{$added} stock unit(s) imported into vault!")
                ->success()
                ->send();
        }
    }

    /** Fired by the relation managers after sources / stock change so computed values stay current. */
    #[On('product-refreshed')]
    public function reloadListing(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['is_active', 'quick_stock_file', 'quick_stock_text']);
    }
}
