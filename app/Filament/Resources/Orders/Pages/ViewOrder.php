<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\FulfillmentService;
use App\Services\OrderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * @property Order $record
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        $latestPayment = fn (?Model $record = null): ?Payment => ($record instanceof Order ? $record : $this->record)->latestPayment;

        return [
            PaymentActions::approve($latestPayment)
                ->record(fn (): Model => $this->record)
                ->label('Approve Payment')
                ->after(fn () => $this->refreshRecord()),
            PaymentActions::reject($latestPayment)
                ->record(fn (): Model => $this->record)
                ->label('Reject Payment')
                ->after(fn () => $this->refreshRecord()),
            $this->retryFulfillmentAction(),
            $this->deliverManuallyAction(),
            $this->markWhatsappDeliveredAction(),
            $this->chatCustomerWhatsappAction(),
            ActionGroup::make([
                PaymentActions::screenshot($latestPayment)
                    ->record(fn (): Model => $this->record)
                    ->label('Payment Screenshot'),
                Action::make('openCustomerPage')
                    ->label('Open Customer Order Page')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (): string => $this->record->customerUrl())
                    ->openUrlInNewTab(),
                $this->adminNoteAction(),
                $this->cancelAction(),
                $this->refundAction(),
            ])->label('More Actions')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    protected function chatCustomerWhatsappAction(): Action
    {
        return Action::make('chatCustomerWhatsapp')
            ->label('WhatsApp Customer')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('gray')
            ->visible(fn (): bool => filled($this->record->phone) && format_whatsapp((string) $this->record->phone) !== '')
            ->url(function (): string {
                $phone = format_whatsapp((string) $this->record->phone);
                $text = urlencode("Assalam o Alaikum! Regarding your order {$this->record->number} on ".setting('store_name', 'SellMeTools').': ');

                return "https://wa.me/{$phone}?text={$text}";
            })
            ->openUrlInNewTab();
    }

    protected function retryFulfillmentAction(): Action
    {
        return Action::make('retryFulfillment')
            ->label('Retry Supplier / Stock')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->visible(fn (): bool => in_array($this->record->status, [OrderStatus::Processing, OrderStatus::NeedsAttention], true))
            ->requiresConfirmation()
            ->modalDescription('Tries own .TXT stock vault first, then calls Supplier APIs (cheapest first) for every undelivered unit.')
            ->action(function (): void {
                $order = $this->record;

                try {
                    if ($order->status === OrderStatus::NeedsAttention) {
                        $order->update(['status' => OrderStatus::Processing]);
                    }

                    $order = app(FulfillmentService::class)->fulfill($order);
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title('Fulfillment failed')->body($e->getMessage())->danger()->send();
                    $this->refreshRecord();

                    return;
                }

                $this->notifyStatus($order, 'Fulfillment finished');
                $this->refreshRecord();
            });
    }

    protected function deliverManuallyAction(): Action
    {
        return Action::make('deliverManually')
            ->label('Deliver Credentials Manually')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->visible(fn (): bool => in_array($this->record->status, [OrderStatus::Processing, OrderStatus::NeedsAttention], true)
                && $this->undeliveredItems() !== [])
            ->modalWidth('2xl')
            ->schema([
                Select::make('order_item_id')
                    ->label('Item')
                    ->options(fn (): array => $this->undeliveredItems())
                    ->default(fn (): ?int => array_key_first($this->undeliveredItems()))
                    ->required(),
                Select::make('separator_mode')
                    ->label('How Are Multiple Units Separated?')
                    ->options([
                        'auto' => '✨ Deliver Full Pasted Text as 1 Block (or split by --- for multiple pcs)',
                        'separator' => '✂️ Multi-Line Credentials Separated by --- or ===',
                        'line' => '📏 Single-Line Credentials (1 Line = 1 PC)',
                    ])
                    ->default('auto')
                    ->required(),
                Textarea::make('lines')
                    ->label('Delivery Credentials')
                    ->helperText('For multi-line credentials, put --- on a line between Unit 1 and Unit 2.')
                    ->placeholder("Email: user1@gmail.com\nPass: 12345\n---\nEmail: user2@gmail.com\nPass: 67890")
                    ->rows(8)
                    ->required(),
                TextInput::make('manual_cost')
                    ->label('Source Cost in PKR (If Any - Optional)')
                    ->numeric()
                    ->minValue(0)
                    ->default(function (): float {
                        $firstItem = $this->record->items()->with('product')->get()->first(fn (OrderItem $i): bool => $i->remainingQty() > 0)
                            ?? $this->record->items()->with('product')->first();
                        $unitCost = (float) ($firstItem?->product?->cost_price ?? 0);

                        return round($unitCost * max(1, (int) ($firstItem?->quantity ?? 1)), 2);
                    })
                    ->helperText('Enter your source/buying cost in PKR (if any) for accurate profit calculation, or leave 0.'),
            ])
            ->action(function (array $data): void {
                /** @var OrderItem $item */
                $item = $this->record->items()->findOrFail($data['order_item_id']);
                $manualCost = isset($data['manual_cost']) && $data['manual_cost'] !== '' ? (float) $data['manual_cost'] : null;

                try {
                    $order = app(FulfillmentService::class)->deliverManually(
                        $item,
                        (string) $data['lines'],
                        (string) ($data['separator_mode'] ?? 'auto'),
                        $manualCost
                    );
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title('Manual delivery failed')->body($e->getMessage())->danger()->send();

                    return;
                }

                $this->notifyStatus($order, 'Delivered');
                $this->refreshRecord();
            });
    }

    protected function markWhatsappDeliveredAction(): Action
    {
        return Action::make('markWhatsappDelivered')
            ->label('Mark Delivered on WhatsApp')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('gray')
            ->visible(fn (): bool => in_array($this->record->status, [OrderStatus::Processing, OrderStatus::NeedsAttention], true)
                && $this->undeliveredItems() !== [])
            ->requiresConfirmation()
            ->modalHeading('Mark Order Delivered via WhatsApp')
            ->modalDescription('Use this when you have already handed over the product/activation to the customer directly on WhatsApp.')
            ->schema([
                TextInput::make('note')
                    ->label('Optional Delivery Note (shown to customer)')
                    ->placeholder('e.g. Activated on customer email / Sent on WhatsApp'),
                TextInput::make('manual_cost')
                    ->label('Source Cost in PKR (If Any - Optional)')
                    ->numeric()
                    ->minValue(0)
                    ->default(function (): float {
                        return round((float) $this->record->items()->with('product')->get()->sum(fn (OrderItem $i) => ((float) ($i->product?->cost_price ?? 0)) * $i->quantity), 2);
                    })
                    ->helperText('Enter your source/buying cost in PKR (if any) for accurate profit calculation, or leave 0.'),
            ])
            ->action(function (array $data): void {
                $manualCost = isset($data['manual_cost']) && $data['manual_cost'] !== '' ? (float) $data['manual_cost'] : null;
                try {
                    $order = app(FulfillmentService::class)->markDeliveredViaWhatsApp(
                        $this->record,
                        $data['note'] ?? null,
                        $manualCost
                    );
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title('Failed to mark delivered')->body($e->getMessage())->danger()->send();

                    return;
                }

                $this->notifyStatus($order, 'Marked Delivered via WhatsApp');
                $this->refreshRecord();
            });
    }

    protected function cancelAction(): Action
    {
        return Action::make('cancelOrder')
            ->label('Cancel Order (Auto-Refund if Paid)')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (): bool => ! in_array($this->record->status, [OrderStatus::Cancelled, OrderStatus::Refunded, OrderStatus::Expired], true))
            ->requiresConfirmation()
            ->modalHeading('Cancel Order')
            ->modalDescription('Releases reserved stock. If this order was already paid, the full amount is automatically refunded to the customer’s Account Wallet balance.')
            ->schema([
                Textarea::make('note')
                    ->label('Cancellation reason / note (optional)')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $res = app(OrderService::class)->cancelAndRefund(
                    $this->record,
                    OrderStatus::Cancelled,
                    $data['note'] ?? null,
                    auth()->id()
                );

                $title = $res['refunded']
                    ? "Order cancelled & Rs {$res['amount']} auto-refunded to Wallet!"
                    : 'Order cancelled & reserved stock released';

                Notification::make()->title($title)->success()->send();
                $this->refreshRecord();
            });
    }

    protected function refundAction(): Action
    {
        return Action::make('markRefunded')
            ->label('Refund to Customer Wallet')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->visible(fn (): bool => ! in_array($this->record->status, [OrderStatus::Refunded, OrderStatus::Expired], true))
            ->requiresConfirmation()
            ->modalHeading('Refund Order to Customer Wallet')
            ->modalDescription('Automatically credits the order total back into the customer’s Account Wallet balance and releases any reserved stock.')
            ->schema([
                Textarea::make('note')
                    ->label('Refund note (optional)')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $res = app(OrderService::class)->cancelAndRefund(
                    $this->record,
                    OrderStatus::Refunded,
                    $data['note'] ?? null,
                    auth()->id(),
                    true
                );

                $title = $res['refunded']
                    ? "Order refunded & Rs {$res['amount']} credited to customer Wallet ({$res['user_email']})!"
                    : 'Order marked as refunded';

                Notification::make()->title($title)->success()->send();
                $this->refreshRecord();
            });
    }

    protected function adminNoteAction(): Action
    {
        return Action::make('adminNote')
            ->label('Edit admin note')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->fillForm(fn (): array => ['admin_note' => $this->record->admin_note])
            ->schema([
                Textarea::make('admin_note')
                    ->label('Admin note (internal)')
                    ->rows(4),
            ])
            ->action(function (array $data): void {
                $this->record->update(['admin_note' => $data['admin_note']]);
                Notification::make()->title('Note saved')->success()->send();
                $this->refreshRecord();
            });
    }

    /** @return array<int, string> */
    protected function undeliveredItems(): array
    {
        return $this->record->items
            ->filter(fn (OrderItem $item): bool => $item->remainingQty() > 0)
            ->mapWithKeys(fn (OrderItem $item): array => [
                $item->id => "{$item->product_name} ({$item->remainingQty()} remaining)",
            ])
            ->all();
    }

    protected function notifyStatus(Order $order, string $title): void
    {
        $notification = Notification::make()
            ->title($title)
            ->body("Order status: {$order->status->label()}".($order->admin_note ? " ({$order->admin_note})" : ''));

        match ($order->status) {
            OrderStatus::Completed => $notification->success(),
            OrderStatus::NeedsAttention => $notification->warning(),
            default => $notification->info(),
        };

        $notification->send();
    }

    protected function refreshRecord(): void
    {
        $this->record->refresh();
        $this->record->unsetRelations();
    }
}
