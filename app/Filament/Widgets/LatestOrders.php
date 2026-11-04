<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Filament\Support\Money;
use App\Models\Order;
use App\Models\OrderItem;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestOrders extends TableWidget
{
    protected static ?int $sort = 2;

    protected static ?bool $isLazyLoaded = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest Orders (Quick Review & Delivery)';

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isSalesStaff());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Order::query()->with(['items.product.activeSources.supplier', 'items.product.sources.supplier', 'paymentMethod', 'latestPayment'])->latest()->limit(12))
            ->paginated(false)
            ->emptyStateHeading('No orders yet')
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')
                    ->label('Order #')
                    ->weight('bold')
                    ->color('primary')
                    ->copyable(),
                TextColumn::make('email')
                    ->label('Customer')
                    ->description(fn (Order $record): ?string => $record->phone),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (Order $record): string => $record->items
                        ->map(fn (OrderItem $item): string => "{$item->quantity}× {$item->product_name}")
                        ->implode(', '))
                    ->limit(32),
                TextColumn::make('supplier_badge')
                    ->label('Supplier / Source')
                    ->badge()
                    ->color('info')
                    ->state(fn (Order $record): string => $record->adminSupplierSummary(false)),
                TextColumn::make('total')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Money::pkr($state)),
                TextColumn::make('paymentMethod.name')
                    ->label('Payment / TID')
                    ->placeholder('-')
                    ->description(fn (Order $record): ?string => $record->latestPayment?->transaction_id
                        ? 'TID: '.$record->latestPayment->transaction_id
                        : null),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
                TextColumn::make('created_at')
                    ->label('Placed')
                    ->since(),
            ])
            ->recordActions([
                PaymentActions::approve()->button()->size('xs'),
                PaymentActions::reject()->button()->size('xs'),
                ViewAction::make()
                    ->button()
                    ->size('xs')
                    ->color('gray')
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
