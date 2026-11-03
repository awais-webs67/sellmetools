<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'number', 'access_token', 'user_id', 'customer_name', 'email', 'phone', 'payment_method_id',
        'status', 'total', 'total_cost', 'currency', 'usdt_rate', 'expires_at', 'paid_at', 'completed_at',
        'ip', 'admin_note', 'customer_input',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'usdt_rate' => 'decimal:4',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            Product::ensureFreeAndStockColumnsExist();
            $order->number ??= static::generateNumber();
            $order->access_token ??= Str::random(48);
        });
    }

    /**
     * Returns the custom activation prompt configured on any product in this order, or null if none.
     */
    public function requiredCustomerInputLabel(): ?string
    {
        $this->loadMissing('items.product');
        foreach ($this->items as $item) {
            $label = trim((string) ($item->product?->customer_input_label ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return null;
    }

    /**
     * Admin-only helper: summarizes the supplier/source for all items in this order.
     * Never called on customer-facing views.
     */
    public function adminSupplierSummary(bool $withCost = false): string
    {
        $this->loadMissing(['items.deliveries', 'items.product.activeSources.supplier', 'items.product.sources.supplier']);

        $sources = $this->items
            ->map(fn (OrderItem $item): string => $item->adminSourceSummary($withCost))
            ->filter()
            ->unique()
            ->values();

        return $sources->isNotEmpty() ? $sources->implode(', ') : 'Own Stock (.TXT)';
    }

    public static function generateNumber(): string
    {
        do {
            $number = 'SMT-'.strtoupper(Str::random(8));
        } while (static::where('number', $number)->exists());

        return $number;
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function deliveries(): HasManyThrough
    {
        return $this->hasManyThrough(Delivery::class, OrderItem::class);
    }

    public function profit(): ?float
    {
        return $this->total_cost === null ? null : (float) $this->total - (float) $this->total_cost;
    }

    public function isExpired(): bool
    {
        return $this->status === OrderStatus::PendingPayment && $this->expires_at?->isPast();
    }

    public function canSubmitPayment(): bool
    {
        return $this->status === OrderStatus::PendingPayment && ! $this->isExpired();
    }

    /** Signed customer URL, works for guests without login. */
    public function customerUrl(): string
    {
        return route('orders.show', ['order' => $this->number, 'token' => $this->access_token]);
    }
}
