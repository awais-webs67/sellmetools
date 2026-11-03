<?php

namespace App\Models;

use App\Enums\SupplierOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_name', 'unit_price', 'quantity', 'line_total',
        'reserved_supplier_qty', 'delivered_qty', 'cost', 'status',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function supplierOrders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function remainingQty(): int
    {
        return max(0, $this->quantity - $this->delivered_qty);
    }

    /**
     * Returns the actual fulfillment source for this item once delivered,
     * or the expected product supplier/source before delivery.
     */
    public function adminSourceSummary(bool $withCost = false): string
    {
        $this->loadMissing('deliveries');

        if ($this->deliveries->isNotEmpty()) {
            $sources = $this->deliveries->pluck('source')->filter()->unique()->values()->all();
            $primarySource = $sources[0] ?? 'stock';

            if ($primarySource === 'supplier') {
                $attempt = SupplierOrder::with('supplier')
                    ->where('order_item_id', $this->id)
                    ->where('status', SupplierOrderStatus::Success)
                    ->latest('id')
                    ->first();

                if ($attempt?->supplier) {
                    $supName = $attempt->supplier->name;
                    if (! $withCost) {
                        return $supName;
                    }
                    $pkrCost = number_format((float) $this->cost > 0 ? (float) $this->cost : $attempt->supplier->toPkr((float) $attempt->cost), 0);
                    $usdCost = number_format((float) $attempt->cost, 2);

                    return "{$supName} (Cost: Rs {$pkrCost} / \${$usdCost})";
                }
            }

            if ($primarySource === 'manual') {
                $pkrCost = number_format((float) $this->cost, 0);

                return $withCost ? "Admin Manual (Cost: Rs {$pkrCost})" : 'Admin Manual';
            }

            if ($primarySource === 'whatsapp') {
                $pkrCost = number_format((float) $this->cost, 0);

                return $withCost ? "WhatsApp Handover (Cost: Rs {$pkrCost})" : 'WhatsApp Handover';
            }

            if ($primarySource === 'stock') {
                $pkrCost = number_format((float) $this->cost, 0);

                return $withCost ? "Own Stock (.TXT) (Cost: Rs {$pkrCost})" : 'Own Stock (.TXT)';
            }
        }

        // Before delivery: check if there is already a successful SupplierOrder
        $successAttempt = SupplierOrder::with('supplier')
            ->where('order_item_id', $this->id)
            ->where('status', SupplierOrderStatus::Success)
            ->latest('id')
            ->first();

        if ($successAttempt?->supplier) {
            $supName = $successAttempt->supplier->name;
            if (! $withCost) {
                return $supName;
            }
            $pkrCost = number_format((float) $this->cost > 0 ? (float) $this->cost : $successAttempt->supplier->toPkr((float) $successAttempt->cost), 0);
            $usdCost = number_format((float) $successAttempt->cost, 2);

            return "{$supName} (Cost: Rs {$pkrCost} / \${$usdCost})";
        }

        if ($this->product) {
            return $this->product->adminSupplierSummary($withCost);
        }

        return 'Own Stock (.TXT)';
    }
}
