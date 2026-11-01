<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Money;
use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestOrders extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest orders';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Order::query()->with('paymentMethod')->latest()->limit(10))
            ->paginated(false)
            ->emptyStateHeading('No orders yet')
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label('Customer'),
                TextColumn::make('total')
                    ->formatStateUsing(fn ($state): string => Money::pkr($state)),
                TextColumn::make('paymentMethod.name')
                    ->label('Payment')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
                TextColumn::make('created_at')
                    ->label('Placed')
                    ->since(),
            ]);
    }
}
