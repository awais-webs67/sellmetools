<?php

namespace App\Models;

use App\Enums\SupplierOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SupplierOrder extends Model
{
    protected $fillable = [
        'supplier_id', 'order_item_id', 'supplier_product_id', 'request_id', 'quantity', 'status',
        'cost', 'external_order_id', 'response', 'error',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierOrder $order): void {
            if ($order->order_item_id === null) {
                static::ensureDirectOrderSchemaReady();
            }
        });
    }

    public static function ensureDirectOrderSchemaReady(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `supplier_orders` MODIFY `order_item_id` BIGINT UNSIGNED NULL');
            } else {
                Schema::table('supplier_orders', function (Blueprint $table): void {
                    $table->unsignedBigInteger('order_item_id')->nullable()->change();
                });
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

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
