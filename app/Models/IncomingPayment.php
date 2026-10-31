<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingPayment extends Model
{
    protected $fillable = [
        'provider', 'transaction_id', 'amount', 'currency', 'sender',
        'raw_message', 'matched_payment_id', 'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'matched_at' => 'datetime',
        ];
    }

    public function matchedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'matched_payment_id');
    }
}
