<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentMethod extends Model
{
    protected $fillable = [
        'name', 'code', 'type', 'currency', 'account_title', 'account_number', 'instructions',
        'logo', 'requires_sender_number', 'is_active', 'sort', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'requires_sender_number' => 'boolean',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    /** Amount the customer must send in this method's currency. */
    public function amountFor(Order $order): float
    {
        if (strtoupper($this->currency) === 'PKR') {
            return (float) $order->total;
        }

        $rate = (float) ($order->usdt_rate ?: Setting::usdtRate());

        return round((float) $order->total / $rate, 2);
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo) {
            return null;
        }

        return Str::startsWith($this->logo, ['http://', 'https://'])
            ? $this->logo
            : url('/media/'.ltrim($this->logo, '/'));
    }
}
