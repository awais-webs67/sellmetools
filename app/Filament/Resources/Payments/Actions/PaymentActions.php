<?php

namespace App\Filament\Resources\Payments\Actions;

use App\Enums\PaymentStatus;
use App\Filament\Support\Money;
use App\Filament\Support\Screenshots;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Payment review actions. Works seamlessly on Payment records (PaymentsTable),
 * Order records (OrdersTable / LatestOrders), and ViewOrder page header actions.
 */
final class PaymentActions
{
    private static function isPendingReview(?Payment $payment): bool
    {
        if (! $payment || $payment->status !== PaymentStatus::Pending) {
            return false;
        }

        $orderStatus = $payment->order?->status;
        if ($orderStatus && in_array($orderStatus, [
            \App\Enums\OrderStatus::Completed,
            \App\Enums\OrderStatus::Processing,
            \App\Enums\OrderStatus::NeedsAttention,
            \App\Enums\OrderStatus::Cancelled,
            \App\Enums\OrderStatus::Refunded,
        ], true)) {
            return false;
        }

        return true;
    }

    public static function approve(?Closure $resolve = null): Action
    {
        return Action::make('approvePayment')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (?Model $record = null, mixed $livewire = null): bool => self::isPendingReview(self::resolvePayment($record, $livewire, $resolve)))
            ->requiresConfirmation()
            ->modalHeading('Approve Payment & Deliver Order')
            ->modalDescription(fn (?Model $record = null, mixed $livewire = null): string => self::describe(self::resolvePayment($record, $livewire, $resolve)).' Delivery starts immediately after approval.')
            ->action(function (?Model $record = null, mixed $livewire = null) use ($resolve): void {
                $payment = self::resolvePayment($record, $livewire, $resolve);

                if (! $payment) {
                    Notification::make()->title('No pending payment found')->warning()->send();

                    return;
                }

                try {
                    /** @var User|null $admin */
                    $admin = auth()->user();
                    $order = app(PaymentService::class)->approve($payment, $admin);
                } catch (Throwable $e) {
                    Notification::make()->title('Could not approve payment')->body($e->getMessage())->danger()->send();

                    return;
                }

                self::notifyResult($order);
            });
    }

    public static function reject(?Closure $resolve = null): Action
    {
        return Action::make('rejectPayment')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (?Model $record = null, mixed $livewire = null): bool => self::isPendingReview(self::resolvePayment($record, $livewire, $resolve)))
            ->modalHeading('Reject Payment')
            ->modalDescription(fn (?Model $record = null, mixed $livewire = null): string => self::describe(self::resolvePayment($record, $livewire, $resolve)).' The customer can submit a new transaction ID.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason (shown to the customer)')
                    ->default('Payment not received. Please check the transaction ID and try again.')
                    ->required()
                    ->maxLength(255)
                    ->rows(3),
            ])
            ->action(function (array $data, ?Model $record = null, mixed $livewire = null) use ($resolve): void {
                $payment = self::resolvePayment($record, $livewire, $resolve);

                if (! $payment) {
                    Notification::make()->title('No pending payment found')->warning()->send();

                    return;
                }

                try {
                    /** @var User|null $admin */
                    $admin = auth()->user();
                    app(PaymentService::class)->reject($payment, (string) ($data['reason'] ?? 'Payment not verified.'), $admin);
                } catch (Throwable $e) {
                    Notification::make()->title('Could not reject payment')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Payment rejected')->warning()->send();
            });
    }

    public static function screenshot(?Closure $resolve = null): Action
    {
        return Action::make('viewScreenshot')
            ->label('Screenshot')
            ->icon(Heroicon::OutlinedPhoto)
            ->color('gray')
            ->visible(fn (?Model $record = null, mixed $livewire = null): bool => filled(self::resolvePayment($record, $livewire, $resolve)?->screenshot))
            ->modalHeading(fn (?Model $record = null, mixed $livewire = null): string => 'Screenshot: TID '.(self::resolvePayment($record, $livewire, $resolve)?->transaction_id ?? '-'))
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (?Model $record = null, mixed $livewire = null): HtmlString => new HtmlString(Screenshots::html(self::resolvePayment($record, $livewire, $resolve)?->screenshot)));
    }

    private static function resolvePayment(?Model $record, mixed $livewire, ?Closure $resolve): ?Payment
    {
        $target = $record;
        if (! $target && is_object($livewire) && method_exists($livewire, 'getRecord')) {
            try {
                $target = $livewire->getRecord();
            } catch (Throwable) {
                $target = null;
            }
        }

        if (! $target) {
            return null;
        }

        if ($resolve) {
            return $resolve($target);
        }

        if ($target instanceof Payment) {
            return $target;
        }

        if ($target instanceof Order) {
            return $target->relationLoaded('latestPayment')
                ? $target->latestPayment
                : $target->latestPayment()->first();
        }

        return null;
    }

    private static function describe(?Payment $payment): string
    {
        if (! $payment) {
            return '';
        }

        return sprintf(
            'Order %s · %s via %s · TID %s.',
            $payment->order?->number,
            Money::currency($payment->amount, $payment->currency),
            $payment->paymentMethod?->name ?? '-',
            $payment->transaction_id,
        );
    }

    private static function notifyResult(Order $order): void
    {
        $notification = Notification::make()
            ->title('Payment approved')
            ->body("Order {$order->number} is now: {$order->status->label()}.");

        match ($order->status->color()) {
            'success' => $notification->success(),
            'danger' => $notification->danger(),
            default => $notification->info(),
        };

        $notification->send();
    }
}
