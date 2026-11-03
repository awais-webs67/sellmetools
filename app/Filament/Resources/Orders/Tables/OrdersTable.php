<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Filament\Support\Money;
use App\Models\Order;
use App\Models\OrderItem;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items', 'paymentMethod', 'latestPayment']))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')
                    ->label('Order #')
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    ->color('primary')
                    ->description(fn (Order $record): string => $record->created_at?->diffForHumans() ?? ''),
                TextColumn::make('email')
                    ->label('Customer')
                    ->searchable(['email', 'customer_name', 'phone'])
                    ->copyable()
                    ->description(fn (Order $record): ?string => trim(($record->customer_name ? $record->customer_name.' · ' : '').($record->phone ?? ''), ' ·') ?: null),
                TextColumn::make('items_summary')
                    ->label('Products')
                    ->state(fn (Order $record): string => $record->items
                        ->map(fn (OrderItem $item): string => "{$item->quantity}× {$item->product_name}")
                        ->implode(', '))
                    ->description(fn (Order $record): ?string => filled($record->customer_input)
                        ? '📝 Customer Data: '.mb_strimwidth((string) $record->customer_input, 0, 55, '…')
                        : null)
                    ->limit(48)
                    ->wrap(),
                TextColumn::make('total')
                    ->label('Total / Profit')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => Money::pkr($state))
                    ->description(function (Order $record): ?string {
                        $profit = $record->profit();

                        return $profit !== null ? 'Profit: '.Money::pkr($profit) : null;
                    })
                    ->sortable(),
                TextColumn::make('paymentMethod.name')
                    ->label('Payment / TID')
                    ->placeholder('Unpaid')
                    ->weight('medium')
                    ->description(fn (Order $record): ?string => $record->latestPayment?->transaction_id
                        ? 'TID: '.$record->latestPayment->transaction_id
                        : null),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y, h:i A')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'xl' => 4,
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label('Date Range')
                    ->options([
                        'today' => '📅 Today',
                        'yesterday' => '⏮️ Yesterday',
                        'week' => '📆 Last 7 Days (Weekly)',
                        'month' => '🗓️ Last 30 Days (Monthly)',
                        'all' => '🌐 All Time',
                    ])
                    ->default('today')
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
                self::quickDeliverAction(),
                PaymentActions::screenshot()->iconButton()->tooltip('View Payment Screenshot'),
                ViewAction::make()->button()->size('xs')->color('gray'),
                ActionGroup::make([
                    self::quickWhatsappDeliveredAction(),
                    self::quickCancelAction(),
                    self::quickRefundAction(),
                ])->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
            ]);
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
                ? "📝 Customer Activation Data: {$record->customer_input}"
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
                        'auto' => '✨ Smart Auto-Detect (Splits by --- or === if present, otherwise 1 Line = 1 PC)',
                        'separator' => '✂️ Multi-Line Credentials Separated by --- or ===',
                        'line' => '📏 Single-Line Credentials (1 Line = 1 PC)',
                    ])
                    ->default('auto')
                    ->required(),
                Textarea::make('lines')
                    ->label('Delivery Credentials')
                    ->placeholder("Email: user@gmail.com\nPass: 12345")
                    ->rows(6)
                    ->required(),
            ])
            ->action(function (Order $record, array $data): void {
                /** @var OrderItem|null $item */
                $item = $record->items()->find($data['order_item_id'] ?? null);
                if (! $item) {
                    Notification::make()->title('Item not found')->danger()->send();

                    return;
                }

                try {
                    app(FulfillmentService::class)->deliverManually(
                        $item,
                        (string) $data['lines'],
                        (string) ($data['separator_mode'] ?? 'auto')
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
            ->schema([
                TextInput::make('note')
                    ->label('Optional Delivery Note')
                    ->placeholder('e.g. Sent credentials on WhatsApp'),
            ])
            ->action(function (Order $record, array $data): void {
                try {
                    app(FulfillmentService::class)->markDeliveredViaWhatsApp($record, $data['note'] ?? null);
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
