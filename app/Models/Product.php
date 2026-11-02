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

    /**
     * Detects the software/service brand from the product name for Schema.org & Google Shopping.
     */
    public function brandName(): string
    {
        $haystack = strtolower((string) $this->name);
        $brands = [
            'capcut' => 'CapCut',
            'gemini' => 'Google',
            'google' => 'Google',
            'youtube' => 'Google',
            'outlook' => 'Microsoft',
            'hotmail' => 'Microsoft',
            'microsoft' => 'Microsoft',
            'office 365' => 'Microsoft',
            'copilot' => 'Microsoft',
            'github' => 'GitHub',
            'chatgpt' => 'OpenAI',
            'openai' => 'OpenAI',
            'claude' => 'Anthropic',
            'canva' => 'Canva',
            'adobe' => 'Adobe',
            'photoshop' => 'Adobe',
            'premiere' => 'Adobe',
            'netflix' => 'Netflix',
            'spotify' => 'Spotify',
            'prime video' => 'Amazon',
            'aws' => 'Amazon',
            'cursor' => 'Cursor',
            'perplexity' => 'Perplexity',
            'midjourney' => 'Midjourney',
            'grammarly' => 'Grammarly',
            'quillbot' => 'QuillBot',
            'turnitin' => 'Turnitin',
            'semrush' => 'Semrush',
            'ahrefs' => 'Ahrefs',
            'nordvpn' => 'NordVPN',
            'expressvpn' => 'ExpressVPN',
            'surfshark' => 'Surfshark',
            'zoom' => 'Zoom',
            'figma' => 'Figma',
            'envato' => 'Envato',
            'freepik' => 'Freepik',
            'coursera' => 'Coursera',
            'udemy' => 'Udemy',
            'linkedin' => 'LinkedIn',
            'discord' => 'Discord',
            'telegram' => 'Telegram',
        ];

        foreach ($brands as $needle => $brand) {
            if (str_contains($haystack, $needle)) {
                return $brand;
            }
        }

        return (string) Setting::get('store_name', 'SellMeTools');
    }

    /**
     * Returns a clean, human-readable summary sentence (converting ALL-CAPS supplier notes into readable text).
     */
    public function cleanSummaryText(): string
    {
        $raw = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($this->short_description ?: $this->description))));
        if ($raw === '') {
            return '';
        }

        $lettersOnly = preg_replace('/[^a-zA-Z]/', '', $raw);
        if ($lettersOnly !== '' && strlen($lettersOnly) > 8 && strtoupper($lettersOnly) === $lettersOnly) {
            $lower = strtolower($raw);
            $sentences = preg_split('/([.!?]+\s*)/', $lower, -1, PREG_SPLIT_DELIM_CAPTURE);
            $rebuilt = '';
            foreach ($sentences as $idx => $part) {
                $rebuilt .= ($idx % 2 === 0) ? ucfirst($part) : $part;
            }
            $acronyms = ['/\bai\b/i' => 'AI', '/\bpkr\b/i' => 'PKR', '/\busdt\b/i' => 'USDT', '/\bvpn\b/i' => 'VPN', '/\bfw\b/i' => 'FW', '/\bvip\b/i' => 'VIP', '/\bapi\b/i' => 'API', '/\bpc\b/i' => 'PC', '/\b4k\b/i' => '4K', '/\bhd\b/i' => 'HD', '/\botp\b/i' => 'OTP', '/\b(\d+)m\b/i' => '$1M', '/\b(\d+)y\b/i' => '$1Y'];
            $raw = preg_replace(array_keys($acronyms), array_values($acronyms), $rebuilt) ?? $rebuilt;
        }

        return $raw;
    }

    public function seoDescription(): string
    {
        if (filled($this->meta_description)) {
            return (string) $this->meta_description;
        }

        $base = $this->cleanSummaryText();
        $hasValidPrice = (float) $this->price > 0;

        if ($base !== '') {
            if ($hasValidPrice) {
                return Str::limit("{$this->name} price in Pakistan: ".money($this->price).". {$base}", 160);
            }

            return Str::limit("Buy {$this->name} in Pakistan. {$base} Pay via Easypaisa, JazzCash, NayaPay or Binance.", 160);
        }

        if ($hasValidPrice) {
            return Str::limit("Buy {$this->name} in Pakistan for ".money($this->price).'. Genuine access delivered to your order page. Pay with Easypaisa, JazzCash, NayaPay, SadaPay or Binance.', 160);
        }

        return Str::limit("Buy {$this->name} in Pakistan with instant automated delivery on your order page. Pay locally via Easypaisa, JazzCash, NayaPay, SadaPay or Binance USDT.", 160);
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
