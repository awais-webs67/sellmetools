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
 * Payment review actions. By default they act on the table/page record (a Payment);
 * pass a resolver (Closure(Model): ?Payment) to use them from another record, e.g. an Order's latest payment.
 */
final class PaymentActions
{
    public static function approve(?Closure $resolve = null): Action
    {
        $resolve ??= self::self();

        return Action::make('approvePayment')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Model $record): bool => $resolve($record)?->status === PaymentStatus::Pending)
            ->requiresConfirmation()
            ->modalHeading('Approve payment')
            ->modalDescription(fn (Model $record): string => self::describe($resolve($record)).' Delivery starts immediately after approval.')
            ->action(function (Model $record) use ($resolve): void {
                $payment = $resolve($record);

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
        $resolve ??= self::self();

        return Action::make('rejectPayment')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Model $record): bool => $resolve($record)?->status === PaymentStatus::Pending)
            ->modalHeading('Reject payment')
            ->modalDescription(fn (Model $record): string => self::describe($resolve($record)).' The customer can submit a new transaction ID.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason (shown to the customer)')
                    ->default('Payment not received. Please check the transaction ID and try again.')
                    ->required()
                    ->maxLength(255)
                    ->rows(3),
            ])
            ->action(function (Model $record, array $data) use ($resolve): void {
                try {
                    /** @var User|null $admin */
                    $admin = auth()->user();
                    app(PaymentService::class)->reject($resolve($record), $data['reason'], $admin);
                } catch (Throwable $e) {
                    Notification::make()->title('Could not reject payment')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Payment rejected')->warning()->send();
            });
    }

    public static function screenshot(?Closure $resolve = null): Action
    {
        $resolve ??= self::self();

        return Action::make('viewScreenshot')
            ->label('Screenshot')
            ->icon(Heroicon::OutlinedPhoto)
            ->color('gray')
            ->visible(fn (Model $record): bool => filled($resolve($record)?->screenshot))
            ->modalHeading(fn (Model $record): string => 'Screenshot — TID '.$resolve($record)?->transaction_id)
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (Model $record): HtmlString => new HtmlString(Screenshots::html($resolve($record)?->screenshot)));
    }

    private static function self(): Closure
    {
        return fn (Model $record): ?Payment => $record instanceof Payment ? $record : null;
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
            $payment->paymentMethod?->name ?? '—',
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
