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
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Order Summary')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(['default' => 2, 'sm' => 3])
                    ->schema([
                        TextEntry::make('number')
                            ->label('Order #')
                            ->copyable()
                            ->weight('bold'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                            ->color(fn (OrderStatus $state): string => $state->color()),
                        TextEntry::make('paymentMethod.name')
                            ->label('Payment Method')
                            ->placeholder('-'),
                        TextEntry::make('total')
                            ->label('Total Payable')
                            ->weight('bold')
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
                            ->label('Placed At')
                            ->dateTime('d M Y, h:i A'),
                        TextEntry::make('paid_at')
                            ->label('Paid At')
                            ->dateTime('d M Y, h:i A')
                            ->placeholder('-'),
                        TextEntry::make('completed_at')
                            ->label('Completed At')
                            ->dateTime('d M Y, h:i A')
                            ->placeholder('-'),
                        TextEntry::make('expires_at')
                            ->label('Payment Deadline')
                            ->dateTime('d M Y, h:i A')
                            ->placeholder('-'),
                        TextEntry::make('usdt_rate')
                            ->label('USDT Rate')
                            ->placeholder('-'),
                        TextEntry::make('customer_input')
                            ->label('📝 Customer Submitted Activation Data')
                            ->copyable()
                            ->weight('bold')
                            ->color('primary')
                            ->visible(fn (Order $record): bool => filled($record->customer_input))
                            ->columnSpanFull(),
                        TextEntry::make('admin_note')
                            ->label('Admin Note / Status Log')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Customer Details')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 1])
                    ->schema([
                        TextEntry::make('customer_name')
                            ->label('Name')
                            ->weight('bold')
                            ->placeholder('-'),
                        TextEntry::make('email')
                            ->label('Email')
                            ->copyable(),
                        TextEntry::make('phone')
                            ->label('WhatsApp / Phone')
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('user.email')
                            ->label('Registered Account')
                            ->placeholder('Guest Checkout'),
                        TextEntry::make('ip')
                            ->label('IP Address')
                            ->placeholder('-'),
                    ]),
                Section::make('Items & Delivered Credentials')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(['default' => 2, 'sm' => 5])->schema([
                                    TextEntry::make('product_name')
                                        ->label('Product')
                                        ->weight('bold')
                                        ->columnSpan(['default' => 2, 'sm' => 2]),
                                    TextEntry::make('unit_price')
                                        ->label('Unit Price')
                                        ->formatStateUsing(fn ($state): string => Money::pkr($state)),
                                    TextEntry::make('delivered')
                                        ->label('Delivered')
                                        ->state(fn (OrderItem $record): string => "{$record->delivered_qty} / {$record->quantity}")
                                        ->badge()
                                        ->color(fn (OrderItem $record): string => $record->delivered_qty >= $record->quantity ? 'success' : 'warning'),
                                    TextEntry::make('cost')
                                        ->label('Cost')
                                        ->formatStateUsing(fn ($state): string => Money::pkr($state))
                                        ->placeholder('-'),
                                ]),
                                RepeatableEntry::make('deliveries')
                                    ->label('Delivered Content')
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
                                            ->dateTime('d M Y, h:i A'),
                                    ]),
                            ]),
                    ]),
                Section::make('Submitted Payments & Receipts')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('payments')
                            ->hiddenLabel()
                            ->placeholder('No payment submitted yet.')
                            ->schema([
                                Grid::make(['default' => 2, 'sm' => 3, 'lg' => 6])->schema([
                                    TextEntry::make('transaction_id')
                                        ->label('TID / Reference')
                                        ->copyable()
                                        ->weight('bold')
                                        ->fontFamily('mono')
                                        ->columnSpan(['default' => 2, 'sm' => 2]),
                                    TextEntry::make('sender_number')
                                        ->label('Sender Phone / Acc')
                                        ->copyable()
                                        ->placeholder('-'),
                                    TextEntry::make('amount')
                                        ->label('Amount')
                                        ->weight('bold')
                                        ->state(fn (Payment $record): string => Money::currency($record->amount, $record->currency)),
                                    TextEntry::make('paymentMethod.name')
                                        ->label('Gateway')
                                        ->placeholder('-'),
                                    TextEntry::make('status')
                                        ->badge()
                                        ->formatStateUsing(fn (PaymentStatus $state): string => ucfirst($state->value))
                                        ->color(fn (PaymentStatus $state): string => $state->color()),
                                    TextEntry::make('created_at')
                                        ->label('Submitted At')
                                        ->dateTime('d M Y, h:i A')
                                        ->columnSpan(['default' => 1, 'sm' => 2]),
                                    TextEntry::make('reviewer.name')
                                        ->label('Reviewed By')
                                        ->placeholder('-')
                                        ->columnSpan(['default' => 1, 'sm' => 2]),
                                    TextEntry::make('reject_reason')
                                        ->label('Reject Reason')
                                        ->placeholder('-')
                                        ->columnSpan(['default' => 2, 'sm' => 2]),
                                    TextEntry::make('screenshot')
                                        ->label('Receipt Screenshot')
                                        ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(Screenshots::html($state, '320px')))
                                        ->placeholder('No screenshot uploaded.')
                                        ->columnSpanFull(),
                                ]),
                            ]),
                    ]),
                Section::make('Supplier API Attempts')
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
