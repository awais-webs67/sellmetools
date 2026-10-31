<?php

namespace App\Models;

use App\Enums\SupplierOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOrder extends Model
{
    protected $fillable = [
        'supplier_id', 'order_item_id', 'supplier_product_id', 'request_id', 'quantity', 'status',
        'cost', 'external_order_id', 'response', 'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupplierOrderStatus::class,
            'cost' => 'decimal:4',
            'response' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }
}
