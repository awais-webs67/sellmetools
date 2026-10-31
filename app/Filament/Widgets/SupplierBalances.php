<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Suppliers\Actions\SupplierActions;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\Money;
use App\Models\Supplier;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class SupplierBalances extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Supplier balances';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Supplier::query()->where('is_active', true)->orderBy('priority'))
            ->paginated(false)
            ->emptyStateHeading('No active suppliers')
            ->recordUrl(fn (Supplier $record): string => SupplierResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->weight('bold'),
                TextColumn::make('balance')
                    ->formatStateUsing(fn (Supplier $record): string => Money::currency($record->balance, $record->currency))
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (Supplier $record): string => $record->balance !== null
                        && (float) $record->balance < (float) $record->low_balance_threshold ? 'danger' : 'success'),
                TextColumn::make('last_synced_at')
                    ->label('Synced')
                    ->since()
                    ->placeholder('Never')
                    ->color(fn (Supplier $record): ?string => $record->last_sync_error ? 'danger' : null)
                    ->tooltip(fn (Supplier $record): ?string => $record->last_sync_error),
            ])
            ->recordActions([
                SupplierActions::sync()->iconButton(),
            ]);
    }
}
