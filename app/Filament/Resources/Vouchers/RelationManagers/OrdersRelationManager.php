<?php

namespace App\Filament\Resources\Vouchers\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Money;
use App\Models\Order;
use App\Models\OrderItem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Redemption History & Orders Using This Voucher';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items', 'paymentMethod']))
            ->recordTitleAttribute('number')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Order #')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->color('primary')
                    ->searchable()
                    ->copyable()
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),

                TextColumn::make('email')
                    ->label('Customer')
                    ->searchable(['email', 'customer_name', 'phone'])
                    ->description(fn (Order $record): ?string => $record->customer_name ?: $record->phone),

                TextColumn::make('items_summary')
                    ->label('Product(s)')
                    ->state(fn (Order $record): string => $record->items
                        ->map(fn (OrderItem $item): string => "{$item->quantity}× {$item->product_name}")
                        ->implode(', '))
                    ->limit(36),

                TextColumn::make('discount_amount')
                    ->label('Discount Saved')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn ($state): string => '-'.Money::pkr($state)),

                TextColumn::make('total')
                    ->label('Final Paid')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Money::pkr($state)),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),

                TextColumn::make('created_at')
                    ->label('Redeemed At')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('openOrder')
                    ->label('View Order')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
