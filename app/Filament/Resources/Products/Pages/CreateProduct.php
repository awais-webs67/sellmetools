<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\StockStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Listings;
use App\Models\Product;
use App\Services\InventoryService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        /** @var Product $product */
        $product = $this->record;

        $this->processQuickStockUpload($product);

        Listings::refresh($product);
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
                    ->title('Reusable course / resource link saved!')
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

    protected function getRedirectUrl(): string
    {
        // Land on the edit page so sources / own stock can be added straight away.
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
