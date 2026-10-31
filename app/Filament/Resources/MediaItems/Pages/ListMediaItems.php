<?php

namespace App\Filament\Resources\MediaItems\Pages;

use App\Filament\Resources\MediaItems\MediaItemResource;
use App\Models\MediaItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ListMediaItems extends ListRecords
{
    protected static string $resource = MediaItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Upload Image')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->mutateDataUsing(function (array $data): array {
                    $path = (string) ($data['path'] ?? '');
                    if ($path !== '' && Storage::disk('public')->exists($path)) {
                        $data['size'] = Storage::disk('public')->size($path);
                    }

                    return $data;
                }),
            Action::make('syncExisting')
                ->label('Import existing product images')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    $added = 0;

                    Product::query()->whereNotNull('image')->each(function (Product $p) use (&$added): void {
                        $item = MediaItem::firstOrCreate(
                            ['path' => $p->image],
                            ['name' => $p->name]
                        );
                        if ($item->wasRecentlyCreated) {
                            $added++;
                        }
                    });

                    PaymentMethod::query()->whereNotNull('logo')->each(function (PaymentMethod $m) use (&$added): void {
                        $item = MediaItem::firstOrCreate(
                            ['path' => $m->logo],
                            ['name' => $m->name.' Logo']
                        );
                        if ($item->wasRecentlyCreated) {
                            $added++;
                        }
                    });

                    Notification::make()
                        ->title("{$added} image(s) added to gallery")
                        ->success()
                        ->send();
                }),
        ];
    }
}
