<?php

namespace App\Models;

use App\Enums\PricingMode;
use App\Enums\StockStatus;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'name', 'slug', 'short_description', 'description', 'warranty', 'image',
        'meta_title', 'meta_description', 'meta_keywords',
        'pricing_mode', 'delivery_type', 'fixed_price', 'markup_value', 'compare_at_price', 'price', 'cost_price',
        'available_stock', 'stock_unlimited',
        'is_active', 'is_featured', 'disabled_reason', 'max_per_order', 'sort', 'sold_count',
    ];

    /**
     * @return array<string, string>
     */
    public static function deliveryTypeOptions(): array
    {
        return [
            'auto' => '⚡ Auto Delivery: From .TXT Stock Vault or Linked Supplier API',
            'manual_admin' => '👨‍💻 Admin Manual Delivery: Admin Delivers Credentials After Payment Confirmed',
            'whatsapp_handover' => '📲 Direct WhatsApp Delivery: Show WhatsApp Claim Button After Payment Confirmed',
        ];
    }

    /**
     * Parses raw text (from a .txt file or textarea) into individual credential blocks (1 block = 1 unit/pc).
     *
     * Supports:
     *  - 'auto': If text contains `---` or `===` separator lines, splits by separator (preserving multi-line credentials per unit); otherwise 1 line = 1 unit.
     *  - 'separator': Splits by `---` or `===` line between each credential unit.
     *  - 'blank_line': Splits by empty blank line between each credential unit.
     *  - 'line': 1 line = 1 unit (e.g. email:password).
     *  - 'custom': Splits by a custom separator string (e.g. `###` or `***`).
     *
     * @return string[]
     */
    public static function parseStockText(string $rawText, string $separatorMode = 'auto', string $customSeparator = '---'): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($rawText));
        if ($normalized === '') {
            return [];
        }

        $hasDashOrEqualSeparator = (bool) preg_match('/^\s*(?:-{3,}|={3,})\s*$/m', $normalized);

        if ($separatorMode === 'auto') {
            $separatorMode = $hasDashOrEqualSeparator ? 'separator' : 'line';
        }

        $chunks = match ($separatorMode) {
            'separator' => preg_split('/^\s*(?:-{3,}|={3,})\s*$/m', $normalized) ?: [],
            'blank_line' => preg_split('/\n\s*\n+/', $normalized) ?: [],
            'custom' => trim($customSeparator) !== ''
                ? explode(trim($customSeparator), $normalized)
                : (preg_split('/^\s*(?:-{3,}|={3,})\s*$/m', $normalized) ?: []),
            default => explode("\n", $normalized),
        };

        return array_values(array_filter(
            array_map(fn (string $chunk): string => trim($chunk), $chunks),
            fn (string $chunk): bool => $chunk !== '' && ! preg_match('/^(?:-{3,}|={3,})$/', $chunk)
        ));
    }

    /**
     * Imports parsed stock units into `stock_items` as Available, skipping already-available duplicates if requested.
     */
    public function importStockItems(
        string $rawText,
        string $separatorMode = 'auto',
        string $customSeparator = '---',
        bool $skipDuplicates = true
    ): int {
        $items = static::parseStockText($rawText, $separatorMode, $customSeparator);
        if ($items === []) {
            return 0;
        }

        $existingAvailable = [];
        if ($skipDuplicates) {
            $existingAvailable = $this->stockItems()
                ->where('status', StockStatus::Available)
                ->get()
                ->map(fn (StockItem $s): string => trim((string) $s->content))
                ->flip()
                ->all();
        }

        $added = 0;
        DB::transaction(function () use ($items, $skipDuplicates, &$existingAvailable, &$added): void {
            foreach ($items as $unitContent) {
                if ($skipDuplicates && isset($existingAvailable[$unitContent])) {
                    continue;
                }

                $this->stockItems()->create([
                    'content' => $unitContent,
                    'status' => StockStatus::Available,
                ]);

                if ($skipDuplicates) {
                    $existingAvailable[$unitContent] = true;
                }
                $added++;
            }
        });

        app(InventoryService::class)->refresh($this);

        return $added;
    }

    protected function casts(): array
    {
        return [
            'pricing_mode' => PricingMode::class,
            'fixed_price' => 'decimal:2',
            'markup_value' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'available_stock' => 'integer',
            'stock_unlimited' => 'boolean',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if (blank($product->slug)) {
                $product->slug = static::uniqueSlug($product->name);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $i = 2;
        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(SupplierProduct::class, 'product_sources')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    public function activeSources(): BelongsToMany
    {
        return $this->sources()->wherePivot('is_active', true);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('is_approved', true)->latest();
    }

    public function resellerPrices(): HasMany
    {
        return $this->hasMany(ResellerProductPrice::class);
    }

    /**
     * Resolves the effective unit price for a given user (applying secret reseller custom price or discount if applicable).
     */
    public function priceFor(?User $user = null): float
    {
        $retail = (float) $this->price;

        if (! $user || ! $user->is_reseller || ! ($user->is_active ?? true)) {
            return $retail;
        }

        $override = $user->relationLoaded('resellerProductPrices')
            ? $user->resellerProductPrices->firstWhere('product_id', $this->id)
            : ResellerProductPrice::query()
                ->where('user_id', $user->id)
                ->where('product_id', $this->id)
                ->first();

        if ($override) {
            if ($override->custom_price !== null && (float) $override->custom_price > 0) {
                return round((float) $override->custom_price, 2);
            }
            if ($override->discount_percent !== null && (float) $override->discount_percent > 0) {
                $pct = min(99.0, max(0.0, (float) $override->discount_percent));

                return max(1.0, round($retail * (1 - ($pct / 100)), 0));
            }
        }

        $globalDiscount = (float) ($user->reseller_discount_percent ?? 0);
        if ($globalDiscount > 0) {
            $pct = min(99.0, max(0.0, $globalDiscount));

            return max(1.0, round($retail * (1 - ($pct / 100)), 0));
        }

        return $retail;
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function inStock(): bool
    {
        return $this->stock_unlimited || $this->available_stock > 0;
    }

    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return Str::startsWith($this->image, ['http://', 'https://'])
            ? $this->image
            : url('/media/'.ltrim($this->image, '/'));
    }

    public function ownAvailableCount(): int
    {
        return $this->stockItems()->where('status', StockStatus::Available)->count();
    }

    public function seoTitle(): string
    {
        if (filled($this->meta_title)) {
            return (string) $this->meta_title;
        }

        return "{$this->name} Price in Pakistan | Buy Online";
    }

    public function seoDescription(): string
    {
        if (filled($this->meta_description)) {
            return (string) $this->meta_description;
        }

        $base = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($this->short_description ?: $this->description))));
        if ($base !== '') {
            return Str::limit("{$this->name} price in Pakistan: ".money($this->price).". {$base}", 160);
        }

        return Str::limit("Buy {$this->name} in Pakistan for ".money($this->price).'. Genuine access delivered to your order page. Pay with Easypaisa, JazzCash, NayaPay, SadaPay or Binance.', 160);
    }

    public function seoKeywords(): string
    {
        if (filled($this->meta_keywords)) {
            return (string) $this->meta_keywords;
        }

        return implode(', ', array_filter([
            "{$this->name} price in Pakistan",
            "buy {$this->name} in Pakistan",
            "{$this->name} subscription Pakistan",
            "{$this->name} Easypaisa JazzCash",
            $this->category ? "{$this->category->name} price in Pakistan" : null,
            (string) Setting::get('store_name', 'SellMeTools'),
        ]));
    }
}
