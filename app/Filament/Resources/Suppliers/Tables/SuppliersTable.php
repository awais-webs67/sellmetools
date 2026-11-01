<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Filament\Resources\Suppliers\Actions\SupplierActions;
use App\Filament\Support\Money;
use App\Models\Supplier;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Supplier $record): string => $record->base_url),
                ToggleColumn::make('is_active')
                    ->label('Active'),
                TextColumn::make('balance')
                    ->formatStateUsing(fn (Supplier $record): string => Money::currency($record->balance, $record->currency))
                    ->placeholder('-')
                    ->color(fn (Supplier $record): ?string => $record->balance !== null
                        && (float) $record->balance < (float) $record->low_balance_threshold ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products')
                    ->badge()
                    ->sortable(),
                TextColumn::make('priority')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_synced_at')
                    ->label('Last sync')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('last_sync_error')
                    ->label('Sync error')
                    ->color('danger')
                    ->limit(60)
                    ->tooltip(fn (Supplier $record): ?string => $record->last_sync_error)
                    ->placeholder('-')
                    ->wrap(),
            ])
            ->headerActions([
                SupplierActions::syncAll(),
            ])
            ->recordActions([
                SupplierActions::sync(),
                ActionGroup::make([
                    SupplierActions::testConnection(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
