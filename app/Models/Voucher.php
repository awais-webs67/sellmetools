<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Exceptions\StoreException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Voucher extends Model
{
    private static bool $schemaChecked = false;

    protected $fillable = [
        'code',
        'title',
        'discount_type',
        'discount_value',
        'scope',
        'product_ids',
        'max_uses',
        'max_uses_per_customer',
        'used_count',
        'min_order_amount',
        'max_discount_amount',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'product_ids' => 'array',
            'max_uses' => 'integer',
            'max_uses_per_customer' => 'integer',
            'used_count' => 'integer',
            'min_order_amount' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::ensureSchemaExists();

        static::saving(function (Voucher $voucher) {
            $voucher->code = strtoupper(trim((string) $voucher->code));
            if ($voucher->code === '') {
                $voucher->code = static::generateUniqueCode();
            }
            if ($voucher->scope === 'all') {
                $voucher->product_ids = [];
            } else {
                $voucher->product_ids = array_values(array_unique(array_map('intval', (array) ($voucher->product_ids ?? []))));
            }
            if ($voucher->discount_type === 'percentage') {
                $voucher->discount_value = min(100, max(0.01, (float) $voucher->discount_value));
            } else {
                $voucher->discount_value = max(1, (float) $voucher->discount_value);
            }
        });
    }

    public static function ensureSchemaExists(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        $flagFile = storage_path('framework/vouchers_v1_schema.flag');
        if (! app()->runningUnitTests() && is_file($flagFile)) {
            return;
        }

        try {
            if (! Schema::hasTable('vouchers')) {
                Schema::create('vouchers', function (Blueprint $table) {
                    $table->id();
                    $table->string('code', 64)->unique();
                    $table->string('title', 160)->nullable();
                    $table->string('discount_type', 20)->default('percentage');
                    $table->decimal('discount_value', 10, 2)->default(0);
                    $table->string('scope', 20)->default('all');
                    $table->json('product_ids')->nullable();
                    $table->unsignedInteger('max_uses')->nullable();
                    $table->unsignedInteger('max_uses_per_customer')->nullable();
                    $table->unsignedInteger('used_count')->default(0);
                    $table->decimal('min_order_amount', 10, 2)->nullable();
                    $table->decimal('max_discount_amount', 10, 2)->nullable();
                    $table->timestamp('starts_at')->nullable();
                    $table->timestamp('expires_at')->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                });
            }

            if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'voucher_code')) {
                Schema::table('orders', function (Blueprint $table) {
                    if (! Schema::hasColumn('orders', 'voucher_id')) {
                        $table->unsignedBigInteger('voucher_id')->nullable()->after('payment_method_id')->index();
                    }
                    if (! Schema::hasColumn('orders', 'voucher_code')) {
                        $table->string('voucher_code', 64)->nullable()->after('voucher_id');
                    }
                    if (! Schema::hasColumn('orders', 'discount_amount')) {
                        $table->decimal('discount_amount', 12, 2)->default(0)->after('voucher_code');
                    }
                });
            }

            if (! app()->runningUnitTests()) {
                @file_put_contents($flagFile, '1');
            }
        } catch (\Throwable) {
            // Ignore during early boot
        }
    }

    public static function generateUniqueCode(string $prefix = 'SMT-'): string
    {
        self::ensureSchemaExists();
        do {
            $code = strtoupper($prefix.Str::upper(Str::random(6)));
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'voucher_id');
    }

    public function appliesToProduct(int $productId): bool
    {
        if ($this->scope === 'all' || empty($this->product_ids)) {
            return true;
        }

        $ids = array_map('intval', (array) $this->product_ids);

        return in_array($productId, $ids, true);
    }

    public function formattedDiscount(): string
    {
        if ($this->discount_type === 'percentage') {
            $val = rtrim(rtrim(number_format((float) $this->discount_value, 2, '.', ''), '0'), '.');

            return "{$val}% OFF";
        }

        return money((float) $this->discount_value).' OFF';
    }

    public function statusLabel(): string
    {
        if (! $this->is_active) {
            return 'Disabled';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'Scheduled';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'Expired';
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'Limit Reached';
        }

        return 'Active';
    }

    public function statusColor(): string
    {
        return match ($this->statusLabel()) {
            'Active' => 'success',
            'Scheduled' => 'info',
            'Limit Reached' => 'warning',
            default => 'danger',
        };
    }

    /**
     * Validates whether this voucher can be applied to the given product, subtotal, and customer email,
     * and returns the calculated discount amount in PKR.
     */
    public function validateAndCalculateDiscount(
        Product $product,
        float $subtotalPkr,
        ?string $customerEmail = null,
        ?int $userId = null
    ): float {
        if (! $this->is_active) {
            throw new StoreException('This voucher code is currently inactive.');
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            throw new StoreException('This voucher code is not active yet.');
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            throw new StoreException('This voucher code has expired.');
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            throw new StoreException('This voucher code has reached its maximum usage limit.');
        }

        if (! $this->appliesToProduct((int) $product->id)) {
            throw new StoreException("Voucher {$this->code} is not valid for this product.");
        }

        if ($this->min_order_amount !== null && (float) $this->min_order_amount > 0 && $subtotalPkr < (float) $this->min_order_amount) {
            throw new StoreException('Minimum order amount for this voucher is '.money((float) $this->min_order_amount).'.');
        }

        if ($this->max_uses_per_customer !== null && $this->max_uses_per_customer > 0) {
            $emailClean = strtolower(trim((string) $customerEmail));
            if ($emailClean !== '' || $userId !== null) {
                $customerUsed = Order::query()
                    ->where('voucher_id', $this->id)
                    ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Expired, OrderStatus::Refunded])
                    ->where(function ($q) use ($emailClean, $userId) {
                        if ($emailClean !== '') {
                            $q->whereRaw('LOWER(email) = ?', [$emailClean]);
                        }
                        if ($userId !== null) {
                            $q->orWhere('user_id', $userId);
                        }
                    })
                    ->count();

                if ($customerUsed >= $this->max_uses_per_customer) {
                    throw new StoreException('You have already used this voucher code the maximum allowed times.');
                }
            }
        }

        if ($subtotalPkr <= 0) {
            return 0.0;
        }

        if ($this->discount_type === 'percentage') {
            $pct = min(100.0, max(0.0, (float) $this->discount_value));
            $discount = round($subtotalPkr * ($pct / 100.0), 2);
            if ($this->max_discount_amount !== null && (float) $this->max_discount_amount > 0) {
                $discount = min($discount, (float) $this->max_discount_amount);
            }
        } else {
            $discount = round((float) $this->discount_value, 2);
        }

        return min($subtotalPkr, max(0.0, $discount));
    }
}
