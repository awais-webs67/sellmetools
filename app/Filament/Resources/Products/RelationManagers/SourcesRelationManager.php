<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\Listings;
use App\Filament\Support\Money;
use App\Models\Product;
use App\Models\SupplierProduct;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Supplier products this listing can be fulfilled from (cheapest active source wins). */
class SourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'sources';

    protected static ?string $title = 'Supplier sources';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (SupplierProduct $record): string => sprintf(
                '%s: %s (%s)',
                $record->supplier?->name,
                $record->name,
                Money::currency($record->cost, $record->supplier?->currency),
            ))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('supplier'))
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Supplier'),
                TextColumn::make('name')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('cost')
                    ->formatStateUsing(fn (SupplierProduct $record): string => Money::currency($record->cost, $record->supplier->currency))
                    ->description(fn (SupplierProduct $record): string => Money::pkr(round($record->costPkr(), 2))),
                TextColumn::make('stock')
                    ->state(fn (SupplierProduct $record): string => $record->unlimited_stock ? '∞' : (string) (int) $record->stock),
                IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean(),
                IconColumn::make('source_active')
                    ->label('Source enabled')
                    ->state(fn (SupplierProduct $record): bool => (bool) $record->pivot?->is_active)
                    ->boolean(),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Attach supplier product')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'external_id'])
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query->with('supplier')->orderBy('name'))
                    ->after(fn () => $this->refreshOwner()),
            ])
            ->recordActions([
                Action::make('toggleSource')
                    ->label(fn (SupplierProduct $record): string => $record->pivot?->is_active ? 'Disable' : 'Enable')
                    ->icon(fn (SupplierProduct $record): Heroicon => $record->pivot?->is_active ? Heroicon::OutlinedPause : Heroicon::OutlinedPlay)
                    ->color(fn (SupplierProduct $record): string => $record->pivot?->is_active ? 'warning' : 'success')
                    ->action(function (SupplierProduct $record): void {
                        $this->owner()->sources()->updateExistingPivot($record->getKey(), [
                            'is_active' => ! $record->pivot?->is_active,
                        ]);
                        $this->refreshOwner();
                    }),
                DetachAction::make()
                    ->after(fn () => $this->refreshOwner()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->after(fn () => $this->refreshOwner()),
                ]),
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
