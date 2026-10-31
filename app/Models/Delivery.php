<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $fillable = ['order_item_id', 'content', 'source', 'stock_item_id', 'supplier_order_id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
