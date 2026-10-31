<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\Listings;
use App\Filament\Support\Money;
use App\Models\Product;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('image')
                    ->disk('public')
                    ->square()
                    ->imageSize(40)
                    ->label(''),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Product $record): ?string => $record->category?->name),
                TextColumn::make('price')
                    ->formatStateUsing(fn ($state): string => Money::pkr($state))
                    ->sortable(),
                TextColumn::make('cost_price')
                    ->label('Cost')
                    ->formatStateUsing(fn ($state): string => Money::pkr($state))
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('profit')
                    ->state(fn (Product $record): ?float => $record->cost_price === null
                        ? null
                        : (float) $record->price - (float) $record->cost_price)
                    ->formatStateUsing(fn ($state): string => Money::pkr($state))
                    ->color(fn ($state): ?string => $state !== null && $state < 0 ? 'danger' : 'success')
                    ->placeholder('—'),
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
                    ->label('Active')
                    ->afterStateUpdated(function (Product $record, bool $state): void {
                        if ($state && $record->disabled_reason) {
                            $record->forceFill(['disabled_reason' => null])->save();
                        }
                    }),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('sold_count')
                    ->label('Sold')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('disabled_reason')
                    ->label('Auto-disabled')
                    ->badge()
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(),
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
