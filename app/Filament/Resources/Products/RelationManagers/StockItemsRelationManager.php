<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\StockStatus;
use App\Filament\Support\Listings;
use App\Models\Product;
use App\Models\StockItem;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Own inventory (accounts, keys, codes) delivered before falling back to suppliers. */
class StockItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockItems';

    protected static ?string $title = 'Own stock';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (StockItem $record): string => "Stock item #{$record->id}")
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (StockStatus $state): string => ucfirst($state->value))
                    ->color(fn (StockStatus $state): string => match ($state) {
                        StockStatus::Available => 'success',
                        StockStatus::Reserved => 'warning',
                        StockStatus::Sold => 'gray',
                    }),
                TextColumn::make('orderItem.order.number')
                    ->label('Order')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(StockStatus::cases())->mapWithKeys(fn (StockStatus $s) => [$s->value => ucfirst($s->value)])->all())
                    ->default(StockStatus::Available->value),
            ])
            ->headerActions([
                Action::make('bulkAdd')
                    ->label('Bulk add stock')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalWidth('2xl')
                    ->schema([
                        Textarea::make('items')
                            ->label('Items')
                            ->helperText('One item per line (e.g. email:password or a licence key). Empty lines are ignored.')
                            ->rows(12)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $lines = collect(preg_split('/\r\n|\r|\n/', (string) $data['items']))
                            ->map(fn (string $line): string => trim($line))
                            ->filter()
                            ->values();

                        DB::transaction(function () use ($lines): void {
                            foreach ($lines as $line) {
                                $this->owner()->stockItems()->create([
                                    'content' => $line,
                                    'status' => StockStatus::Available,
                                ]);
                            }
                        });

                        $this->refreshOwner();

                        Notification::make()
                            ->title($lines->count().' stock item(s) added')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('reveal')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (StockItem $record): string => "Stock item #{$record->id}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (StockItem $record): array => [
                        TextEntry::make('content')
                            ->state($record->content)
                            ->copyable()
                            ->fontFamily('mono'),
                    ]),
                DeleteAction::make()
                    ->visible(fn (StockItem $record): bool => $record->status === StockStatus::Available)
                    ->after(fn () => $this->refreshOwner()),
            ])
            ->toolbarActions([
                BulkAction::make('deleteAvailable')
                    ->label('Delete selected (available only)')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $deleted = StockItem::query()
                            ->whereKey($records->modelKeys())
                            ->where('product_id', $this->owner()->getKey())
                            ->where('status', StockStatus::Available)
                            ->delete();

                        $this->refreshOwner();

                        Notification::make()->title("{$deleted} stock item(s) deleted")->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    protected function owner(): Product
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $product;
    }

    protected function refreshOwner(): void
    {
        Listings::refresh($this->owner()->refresh());
        $this->dispatch('product-refreshed');
    }
}
