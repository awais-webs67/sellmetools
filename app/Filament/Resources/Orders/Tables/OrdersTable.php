<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Filament\Support\Money;
use App\Models\Order;
use App\Models\OrderItem;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items', 'paymentMethod']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label('Customer')
                    ->searchable(['email', 'customer_name', 'phone'])
                    ->description(fn (Order $record): ?string => $record->customer_name ?: $record->phone),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (Order $record): string => $record->items
                        ->map(fn (OrderItem $item): string => "{$item->quantity}× {$item->product_name}")
                        ->implode(', '))
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('total')
                    ->formatStateUsing(fn ($state): string => Money::pkr($state))
                    ->sortable(),
                TextColumn::make('profit')
                    ->state(fn (Order $record): ?float => $record->profit())
                    ->formatStateUsing(fn ($state): string => Money::pkr($state))
                    ->color(fn ($state): ?string => $state !== null && $state < 0 ? 'danger' : 'success')
                    ->placeholder('-'),
                TextColumn::make('paymentMethod.name')
                    ->label('Payment')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
                TextColumn::make('created_at')
                    ->label('Placed')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label()])->all())
                    ->multiple(),
                SelectFilter::make('payment_method_id')
                    ->label('Payment method')
                    ->relationship('paymentMethod', 'name'),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
