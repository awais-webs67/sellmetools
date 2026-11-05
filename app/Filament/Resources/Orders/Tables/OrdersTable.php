<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Filament\Support\Money;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierProduct;
use App\Services\FulfillmentService;
use App\Services\OrderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items.product.activeSources.supplier', 'items.product.sources.supplier', 'paymentMethod', 'latestPayment']))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')
                    ->label('Order #')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->color('primary')
                    ->description(fn (Order $record): string => $record->created_at?->diffForHumans(short: true) ?? ''),
                TextColumn::make('email')
                    ->label('Customer')
                    ->searchable(['email', 'customer_name', 'phone'])
                    ->copyable()
                    ->limit(22)
                    ->tooltip(fn (Order $record): string => trim($record->email.' '.($record->customer_name ? "({$record->customer_name})" : '')))
                    ->description(fn (Order $record): ?string => $record->phone ?: null),
                TextColumn::make('items_summary')
                    ->label('Product')
                    ->state(fn (Order $record): string => $record->items
                        ->map(fn (OrderItem $item): string => "{$item->quantity}× {$item->product_name}")
                        ->implode(', '))
                    ->description(fn (Order $record): ?string => filled($record->customer_input)
                        ? 'Input: '.mb_strimwidth((string) $record->customer_input, 0, 28, '…')
                        : null)
                    ->limit(30)
                    ->tooltip(fn (Order $record): string => $record->items
                        ->map(fn (OrderItem $item): string => "{$item->quantity}× {$item->product_name}")
                        ->implode(', ')),
                TextColumn::make('supplier_badge')
                    ->label('Source')
                    ->badge()
                    ->color('info')
                    ->state(fn (Order $record): string => $record->adminSupplierSummary(false)),
                TextColumn::make('total')
                    ->label('Sale / Profit')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Money::pkr($state))
                    ->description(function (Order $record): ?string {
                        $parts = [];
                        $profit = $record->profit();
                        if ($profit !== null) {
                            $parts[] = ($profit >= 0 ? '+' : '').Money::pkr($profit);
                        }
                        if (filled($record->voucher_code)) {
                            $parts[] = '🏷️ '.$record->voucher_code;
                        }

                        return $parts !== [] ? implode(' · ', $parts) : null;
                    })
                    ->sortable(),
                TextColumn::make('paymentMethod.name')
                    ->label('Gateway / TID')
                    ->placeholder('Unpaid')
                    ->weight('medium')
                    ->limit(16)
                    ->description(fn (Order $record): ?string => $record->latestPayment?->transaction_id
                        ? mb_strimwidth((string) $record->latestPayment->transaction_id, 0, 16, '…')
                        : null)
                    ->tooltip(fn (Order $record): ?string => $record->latestPayment?->transaction_id
                        ? 'TID: '.$record->latestPayment->transaction_id
                        : null),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M, h:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'xl' => 4,
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label('Date Range')
                    ->options([
                        'all' => 'All Time',
                        'today' => 'Today',
                        'yesterday' => 'Yesterday',
                        'week' => 'Last 7 Days (Weekly)',
                        'month' => 'Last 30 Days (Monthly)',
                    ])
                    ->default('all')
                    ->selectablePlaceholder(false)
                    ->query(function (Builder $query, array $data): Builder {
                        $val = $data['value'] ?? 'all';

                        return match ($val) {
                            'today' => $query->whereDate('created_at', today()),
                            'yesterday' => $query->whereDate('created_at', today()->subDay()),
                            'week' => $query->where('created_at', '>=', now()->subDays(7)->startOfDay()),
                            'month' => $query->where('created_at', '>=', now()->subDays(30)->startOfDay()),
                            default => $query,
                        };
                    }),
                Filter::make('created_at')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('from')
                            ->label('Custom From')
                            ->native(false)
                            ->displayFormat('d M Y'),
                        DatePicker::make('until')
                            ->label('Custom Until')
                            ->native(false)
                            ->displayFormat('d M Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
                SelectFilter::make('payment_method_id')
                    ->label('Payment Method')
                    ->relationship('paymentMethod', 'name'),
                SelectFilter::make('status')
                    ->label('Order Status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label()])->all())
                    ->multiple(),
            ])
            ->recordActions([
                PaymentActions::approve()->button()->size('xs'),
                PaymentActions::reject()->button()->size('xs'),
                self::quickBackupSupplierAction(),
                self::quickDeliverAction(),
                PaymentActions::screenshot()->iconButton()->tooltip('View Payment Screenshot'),
                ViewAction::make()->iconButton()->tooltip('View Order Details')->color('gray'),
                ActionGroup::make([
                    self::quickWhatsappDeliveredAction(),
                    self::quickCancelAction(),
                    self::quickRefundAction(),
                ])->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
            ]);
    }

    protected static function quickBackupSupplierAction(): Action
    {
        return Action::make('quickBackupSupplier')
            ->label('Other Suppliers')
            ->icon(Heroicon::OutlinedBolt)
            ->color('warning')
            ->button()
            ->size('xs')
            ->visible(function (Order $record): bool {
                if (! in_array($record->status, [OrderStatus::NeedsAttention, OrderStatus::Processing], true)) {
                    return false;
                }
                $firstItem = $record->items->first(fn (OrderItem $i): bool => $i->remainingQty() > 0) ?? $record->items->first();

                return $firstItem && $firstItem->product && $firstItem->product->sources->count() > 1;
            })
            ->modalHeading(fn (Order $record): string => "Deliver {$record->number} via Backup Supplier")
            ->modalDescription(fn (Order $record): string => 'Customer Paid: '.Money::pkr($record->total).' · Select a supplier below to buy & deliver immediately.')
            ->schema(function (Order $record): array {
                $firstItem = $record->items->first(fn (OrderItem $i): bool => $i->remainingQty() > 0) ?? $record->items->first();
                $needQty = max(1, (int) ($firstItem?->remainingQty() ?: 1));
                $paidTotal = round((float) ($firstItem?->line_total ?? $record->total), 0);

                $sources = ($firstItem?->product?->sources ?? collect())
                    ->filter(fn (SupplierProduct $sp): bool => (bool) ($sp->supplier?->is_active ?? false))
                    ->sortBy(fn (SupplierProduct $sp): float => $sp->costPkr())
                    ->values();

                $options = [];
                foreach ($sources as $idx => $sp) {
                    $costPkr = round($sp->costPkr() * $needQty, 0);
                    $profit = $paidTotal - $costPkr;
                    $profitPct = $costPkr > 0 ? round(($profit / $costPkr) * 100, 0) : 0;
                    $stockStr = $sp->unlimited_stock ? '∞' : (string) (int) $sp->stock;
                    $priority = $idx === 0 ? '1st Priority' : ($idx === 1 ? '2nd Priority' : ($idx + 1).'th Priority');
                    $profitSign = $profit >= 0 ? "+Rs {$profit} (+{$profitPct}%)" : '-Rs '.abs($profit)." ({$profitPct}%)";

                    $options[$sp->id] = "[{$priority}] {$sp->supplier?->name} — Cost: Rs {$costPkr} (\${$sp->cost}) · Profit: {$profitSign} · Stock: {$stockStr}";
                }

                $defaultId = $sources->get(1)?->id ?? $sources->first()?->id;

                return [
                    Select::make('supplier_product_id')
                        ->label('Choose Supplier (Shows Cost & Net Profit)')
                        ->options($options)
                        ->default($defaultId)
                        ->required(),
                ];
            })
            ->action(function (Order $record, array $data): void {
                $item = $record->items()->get()->first(fn (OrderItem $i): bool => $i->remainingQty() > 0) ?? $record->items()->first();
                $source = SupplierProduct::with('supplier')->find($data['supplier_product_id'] ?? null);
                if (! $item || ! $source) {
                    Notification::make()->title('Supplier or item not found')->danger()->send();

                    return;
                }

                try {
                    $order = app(FulfillmentService::class)->fulfillFromSpecificSupplier($record, $item, $source);
                    if ($order->status === OrderStatus::Completed) {
                        Notification::make()->title("Delivered via {$source->supplier?->name}!")->success()->send();
                    } else {
                        Notification::make()
                            ->title("{$source->supplier?->name} could not deliver")
                            ->body((string) $order->admin_note)
                            ->warning()
                            ->send();
                    }
                } catch (Throwable $e) {
                    Notification::make()->title('Supplier order failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    protected static function quickDeliverAction(): Action
    {
        return Action::make('quickDeliver')
            ->label('Deliver')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->button()
            ->size('xs')
            ->visible(fn (Order $record): bool => in_array($record->status, [OrderStatus::Processing, OrderStatus::NeedsAttention], true))
            ->modalHeading(fn (Order $record): string => "Deliver Credentials for {$record->number}")
            ->modalDescription(fn (Order $record): ?string => filled($record->customer_input)
                ? "Customer Activation Data: {$record->customer_input}"
                : null)
            ->modalWidth('2xl')
            ->schema(fn (Order $record): array => [
                Select::make('order_item_id')
                    ->label('Product Item')
                    ->options(
                        $record->items
                            ->filter(fn (OrderItem $i): bool => $i->remainingQty() > 0)
                            ->mapWithKeys(fn (OrderItem $i): array => [$i->id => "{$i->product_name} ({$i->remainingQty()} remaining)"])
                            ->all()
                    )
                    ->default(
                        $record->items
                            ->first(fn (OrderItem $i): bool => $i->remainingQty() > 0)?->id
                    )
                    ->required(),
                Select::make('separator_mode')
                    ->label('How Are Multiple Units Separated?')
                    ->options([
                        'auto' => 'Auto — Full pasted text as 1 block (or split by --- for multiple units)',
                        'separator' => 'Multi-line — Credentials separated by --- or ===',
                        'line' => 'Single-line — 1 line per unit',
                    ])
                    ->default('auto')
                    ->required(),
                Textarea::make('lines')
                    ->label('Delivery Credentials')
                    ->placeholder("Email: user@gmail.com\nPass: 12345")
                    ->rows(6)
                    ->required(),
                TextInput::make('manual_cost')
                    ->label('Source Cost in PKR (If Any - Optional)')
                    ->numeric()
                    ->minValue(0)
                    ->default(function () use ($record): float {
                        $firstItem = $record->items->first(fn (OrderItem $i): bool => $i->remainingQty() > 0) ?? $record->items->first();
                        $unitCost = (float) ($firstItem?->product?->cost_price ?? 0);

                        return round($unitCost * max(1, (int) ($firstItem?->quantity ?? 1)), 2);
                    })
                    ->helperText('Enter your source/buying cost in PKR (if any) for accurate profit calculation, or leave 0.'),
            ])
            ->action(function (Order $record, array $data): void {
                /** @var OrderItem|null $item */
                $item = $record->items()->find($data['order_item_id'] ?? null);
                if (! $item) {
                    Notification::make()->title('Item not found')->danger()->send();

                    return;
                }

                $manualCost = isset($data['manual_cost']) && $data['manual_cost'] !== '' ? (float) $data['manual_cost'] : null;

                try {
                    app(FulfillmentService::class)->deliverManually(
                        $item,
                        (string) $data['lines'],
                        (string) ($data['separator_mode'] ?? 'auto'),
                        $manualCost
                    );
                    Notification::make()->title("Order {$record->number} delivered!")->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Delivery failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    protected static function quickWhatsappDeliveredAction(): Action
    {
        return Action::make('quickWhatsappDelivered')
            ->label('Mark Delivered on WhatsApp')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Order $record): bool => in_array($record->status, [OrderStatus::Processing, OrderStatus::NeedsAttention], true))
            ->requiresConfirmation()
            ->schema(fn (Order $record): array => [
                TextInput::make('note')
                    ->label('Optional Delivery Note')
                    ->placeholder('e.g. Sent credentials on WhatsApp'),
                TextInput::make('manual_cost')
                    ->label('Source Cost in PKR (If Any - Optional)')
                    ->numeric()
                    ->minValue(0)
                    ->default(function () use ($record): float {
                        return round((float) $record->items->sum(fn (OrderItem $i) => ((float) ($i->product?->cost_price ?? 0)) * $i->quantity), 2);
                    })
                    ->helperText('Enter your source/buying cost in PKR (if any) for accurate profit calculation, or leave 0.'),
            ])
            ->action(function (Order $record, array $data): void {
                $manualCost = isset($data['manual_cost']) && $data['manual_cost'] !== '' ? (float) $data['manual_cost'] : null;

                try {
                    app(FulfillmentService::class)->markDeliveredViaWhatsApp($record, $data['note'] ?? null, $manualCost);
                    Notification::make()->title("Order {$record->number} marked delivered via WhatsApp")->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    protected static function quickCancelAction(): Action
    {
        return Action::make('quickCancel')
            ->label('Cancel Order (Auto-Refund if Paid)')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Order $record): bool => ! in_array($record->status, [OrderStatus::Cancelled, OrderStatus::Refunded, OrderStatus::Expired], true))
            ->requiresConfirmation()
            ->modalDescription('Releases reserved stock. If the order was paid, the amount is automatically refunded to the customer’s Account Wallet.')
            ->action(function (Order $record): void {
                $res = app(OrderService::class)->cancelAndRefund($record, OrderStatus::Cancelled, null, auth()->id());
                $msg = $res['refunded']
                    ? "Order {$record->number} cancelled & Rs {$res['amount']} auto-refunded to Wallet!"
                    : "Order {$record->number} cancelled";
                Notification::make()->title($msg)->success()->send();
            });
    }

    protected static function quickRefundAction(): Action
    {
        return Action::make('quickRefund')
            ->label('Refund to Customer Wallet')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->visible(fn (Order $record): bool => ! in_array($record->status, [OrderStatus::Refunded, OrderStatus::Expired], true))
            ->requiresConfirmation()
            ->modalDescription('Automatically credits the order total back into the customer’s Account Wallet balance and releases reserved stock.')
            ->action(function (Order $record): void {
                $res = app(OrderService::class)->cancelAndRefund($record, OrderStatus::Refunded, null, auth()->id(), true);
                $msg = $res['refunded']
                    ? "Order {$record->number} refunded & Rs {$res['amount']} credited to Wallet!"
                    : "Order {$record->number} marked refunded";
                Notification::make()->title($msg)->success()->send();
            });
    }
}
