<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case PaymentReview = 'payment_review';
    case Processing = 'processing';
    case Completed = 'completed';
    case NeedsAttention = 'needs_attention';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::PaymentReview => 'Verifying payment',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::NeedsAttention => 'Needs attention',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Refunded => 'Refunded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingPayment => 'gray',
            self::PaymentReview => 'warning',
            self::Processing => 'info',
            self::Completed => 'success',
            self::NeedsAttention => 'danger',
            self::Cancelled, self::Expired => 'gray',
            self::Refunded => 'danger',
        };
    }

    /** Statuses where reserved stock must remain held. */
    public static function holdingStock(): array
    {
        return [self::PendingPayment, self::PaymentReview, self::Processing];
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Expired, self::Refunded], true);
    }
}
