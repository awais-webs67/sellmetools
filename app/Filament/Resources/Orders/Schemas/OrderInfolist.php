<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SupplierOrderStatus;
use App\Filament\Support\Money;
use App\Filament\Support\Screenshots;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\SupplierOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Order')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('number')
                            ->copyable()
                            ->weight('bold'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                            ->color(fn (OrderStatus $state): string => $state->color()),
                        TextEntry::make('paymentMethod.name')
                            ->label('Payment method')
                            ->placeholder('-'),
                        TextEntry::make('total')
                            ->formatStateUsing(fn ($state): string => Money::pkr($state)),
                        TextEntry::make('total_cost')
                            ->label('Cost')
                            ->formatStateUsing(fn ($state): string => Money::pkr($state))
                            ->placeholder('-'),
                        TextEntry::make('profit')
                            ->state(fn (Order $record): ?float => $record->profit())
                            ->formatStateUsing(fn ($state): string => Money::pkr($state))
                            ->color(fn ($state): ?string => $state !== null && $state < 0 ? 'danger' : 'success')
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->label('Placed')
                            ->dateTime(),
                        TextEntry::make('paid_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('expires_at')
                            ->label('Payment deadline')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('usdt_rate')
                            ->label('USDT rate (snapshot)')
                            ->placeholder('-'),
                        TextEntry::make('admin_note')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Customer')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('customer_name')
                            ->label('Name')
                            ->placeholder('-'),
                        TextEntry::make('email')
                            ->copyable(),
                        TextEntry::make('phone')
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('user.email')
                            ->label('Account')
                            ->placeholder('Guest'),
                        TextEntry::make('ip')
                            ->label('IP')
                            ->placeholder('-'),
                    ]),
                Section::make('Items & deliveries')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(5)->schema([
                                    TextEntry::make('product_name')
                                        ->label('Product')
                                        ->weight('bold')
                                        ->columnSpan(2),
                                    TextEntry::make('unit_price')
                                        ->label('Unit price')
                                        ->formatStateUsing(fn ($state): string => Money::pkr($state)),
                                    TextEntry::make('delivered')
                                        ->label('Delivered')
                                        ->state(fn (OrderItem $record): string => "{$record->delivered_qty} / {$record->quantity}")
                                        ->badge()
                                        ->color(fn (OrderItem $record): string => $record->delivered_qty >= $record->quantity ? 'success' : 'warning'),
                                    TextEntry::make('cost')
                                        ->formatStateUsing(fn ($state): string => Money::pkr($state))
                                        ->placeholder('-'),
                                ]),
                                RepeatableEntry::make('deliveries')
                                    ->label('Delivered content')
                                    ->placeholder('Nothing delivered yet.')
                                    ->table([
                                        TableColumn::make('Content'),
                                        TableColumn::make('Source'),
                                        TableColumn::make('Delivered'),
                                    ])
                                    ->schema([
                                        TextEntry::make('content')
                                            ->copyable()
                                            ->fontFamily('mono'),
                                        TextEntry::make('source')
                                            ->badge(),
                                        TextEntry::make('created_at')
                                            ->dateTime(),
                                    ]),
                            ]),
                    ]),
                Section::make('Payments')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('payments')
                            ->hiddenLabel()
                            ->placeholder('No payment submitted yet.')
                            ->schema([
                                Grid::make(6)->schema([
                                    TextEntry::make('transaction_id')
                                        ->label('TID')
                                        ->copyable()
                                        ->fontFamily('mono')
                                        ->columnSpan(2),
                                    TextEntry::make('sender_number')
                                        ->label('Sender')
                                        ->placeholder('-'),
                                    TextEntry::make('amount')
                                        ->state(fn (Payment $record): string => Money::currency($record->amount, $record->currency)),
                                    TextEntry::make('paymentMethod.name')
                                        ->label('Method')
                                        ->placeholder('-'),
                                    TextEntry::make('status')
                                        ->badge()
                                        ->formatStateUsing(fn (PaymentStatus $state): string => ucfirst($state->value))
                                        ->color(fn (PaymentStatus $state): string => $state->color()),
                                    TextEntry::make('created_at')
                                        ->label('Submitted')
                                        ->dateTime()
                                        ->columnSpan(2),
                                    TextEntry::make('reviewer.name')
                                        ->label('Reviewed by')
                                        ->placeholder('-')
                                        ->columnSpan(2),
                                    TextEntry::make('reject_reason')
                                        ->placeholder('-')
                                        ->columnSpan(2),
                                    TextEntry::make('screenshot')
                                        ->label('Screenshot')
                                        ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(Screenshots::html($state, '320px')))
                                        ->placeholder('No screenshot uploaded.')
                                        ->columnSpanFull(),
                                ]),
                            ]),
                    ]),
                Section::make('Supplier attempts')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('supplier_attempts')
                            ->hiddenLabel()
                            ->state(fn (Order $record): Collection => SupplierOrder::query()
                                ->whereIn('order_item_id', $record->items->modelKeys())
                                ->with(['supplier', 'supplierProduct'])
                                ->latest('id')
                                ->get())
                            ->placeholder('No supplier orders were placed.')
                            ->table([
                                TableColumn::make('Supplier'),
                                TableColumn::make('Product'),
                                TableColumn::make('Request ID'),
                                TableColumn::make('Qty'),
                                TableColumn::make('Status'),
                                TableColumn::make('Cost'),
                                TableColumn::make('Error'),
                            ])
                            ->schema([
                                TextEntry::make('supplier.name'),
                                TextEntry::make('supplierProduct.name')->placeholder('-'),
                                TextEntry::make('request_id')->copyable()->fontFamily('mono'),
                                TextEntry::make('quantity'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (SupplierOrderStatus $state): string => ucfirst($state->value))
                                    ->color(fn (SupplierOrderStatus $state): string => $state->color()),
                                TextEntry::make('cost')
                                    ->state(fn (SupplierOrder $record): string => Money::currency($record->cost, $record->supplier?->currency)),
                                TextEntry::make('error')->color('danger')->placeholder('-')->wrap(),
                            ]),
                    ]),
            ]);
    }
}
