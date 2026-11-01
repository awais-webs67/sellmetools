<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerProductPrice extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'custom_price',
        'discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'custom_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
