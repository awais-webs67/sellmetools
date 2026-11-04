<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\Listings;
use App\Filament\Support\Money;
use App\Models\Product;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'activeSources.supplier', 'sources.supplier']))
            ->defaultSort('sort')
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                ImageColumn::make('image')
                    ->state(fn (Product $record): ?string => $record->imageUrl())
                    ->square()
                    ->imageSize(32)
                    ->label(''),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(34)
                    ->tooltip(fn (Product $record): string => $record->name)
                    ->description(fn (Product $record): ?string => $record->category?->name),
                TextColumn::make('supplier_source')
                    ->label('#1 Source')
                    ->visible(fn (): bool => ! auth()->user()?->isSeoStaff())
                    ->badge()
                    ->color('info')
                    ->state(fn (Product $record): string => $record->adminSupplierSummary(false)),
                TextColumn::make('cost_price')
                    ->label('Cost')
                    ->visible(fn (): bool => ! auth()->user()?->isSeoStaff())
                    ->formatStateUsing(fn ($state): string => Money::pkr($state))
                    ->description(function (Product $record): ?string {
                        if ($record->cost_price === null) {
                            return null;
                        }
                        $usd = (float) $record->cost_price / max(1.0, Setting::usdtRate());
                        $formatted = preg_replace('/(\.\d{2}\d*?)0+$/', '$1', number_format($usd, 4));

                        return '$'.$formatted;
                    })
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Sell Price')
                    ->weight('bold')
                    ->color('primary')
                    ->formatStateUsing(fn ($state): string => Money::pkr($state))
                    ->sortable(),
                TextColumn::make('profit')
                    ->label('Profit')
                    ->visible(fn (): bool => ! auth()->user()?->isSeoStaff())
                    ->weight('bold')
                    ->state(fn (Product $record): ?float => $record->cost_price === null
                        ? null
                        : (float) $record->price - (float) $record->cost_price)
                    ->formatStateUsing(fn ($state): string => ($state !== null && $state >= 0 ? '+' : '').Money::pkr($state))
                    ->color(fn ($state): ?string => $state !== null && $state < 0 ? 'danger' : 'success')
                    ->placeholder('-'),
                TextColumn::make('available_stock')
                    ->label('Stock')
                    ->state(fn (Product $record): string => $record->stock_unlimited ? '∞' : (string) $record->available_stock)
                    ->badge()
                    ->color(fn (Product $record): string => match (true) {
                        $record->stock_unlimited => 'success',
                        $record->available_stock <= 0 => 'danger',
                        $record->available_stock < 5 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Live')
                    ->afterStateUpdated(function (Product $record, bool $state): void {
                        if ($state && $record->disabled_reason) {
                            $record->forceFill(['disabled_reason' => null])->save();
                        }
                    }),
                IconColumn::make('is_featured')
                    ->label('Hot')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sold_count')
                    ->label('Sold')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('disabled_reason')
                    ->label('Auto-disabled')
                    ->badge()
                    ->color('danger')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sort')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->preload(),
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('is_featured')->label('Featured'),
                TernaryFilter::make('in_stock')
                    ->label('In stock')
                    ->queries(
                        true: fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('stock_unlimited', true)->orWhere('available_stock', '>', 0)),
                        false: fn (Builder $q) => $q->where('stock_unlimited', false)->where('available_stock', '<=', 0),
                    ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('refresh')
                        ->label('Recalculate price & stock')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(function (Product $record): void {
                            Listings::refresh($record);
                            Notification::make()->title('Product refreshed')->success()->send();
                        }),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('refresh')
                        ->label('Recalculate price & stock')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(function (Collection $records): void {
                            Listings::refreshMany($records);
                            Notification::make()->title($records->count().' product(s) refreshed')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
