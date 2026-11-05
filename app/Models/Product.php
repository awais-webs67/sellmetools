<?php

namespace App\Models;

use App\Enums\PricingMode;
use App\Enums\StockStatus;
use App\Http\Controllers\SeoController;
use App\Services\InventoryService;
use App\Services\ProductSeoOptimizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'name', 'slug', 'short_description', 'description', 'warranty', 'image',
        'meta_title', 'meta_description', 'meta_keywords',
        'pricing_mode', 'delivery_type', 'customer_input_label', 'fixed_price', 'markup_value', 'use_overall_supplier_markup', 'compare_at_price', 'price', 'cost_price',
        'available_stock', 'stock_limit', 'stock_unlimited',
        'is_active', 'is_featured', 'is_free', 'disabled_reason', 'max_per_order', 'sort', 'sold_count',
    ];

    /**
     * @return array<string, string>
     */
    public static function deliveryTypeOptions(): array
    {
        return [
            'auto' => 'Auto Delivery — Unique Accounts from .TXT Stock Vault or Supplier API (1 Unit Consumed Per Order)',
            'instant_link' => 'Reusable Resource Link — Same Link or Text Auto-Delivered to Every Customer (Unlimited Stock)',
            'manual_admin' => 'Admin Manual Delivery — Admin Delivers Credentials After Order Confirmed',
            'whatsapp_handover' => 'Direct WhatsApp Delivery — Show WhatsApp Claim Button After Order Confirmed',
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
            'use_overall_supplier_markup' => 'boolean',
            'compare_at_price' => 'decimal:2',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'available_stock' => 'integer',
            'stock_limit' => 'integer',
            'stock_unlimited' => 'boolean',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_free' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            static::ensureFreeAndStockColumnsExist();

            $product->name = ProductSeoOptimizer::cleanProductTitle((string) $product->name) ?: $product->name;

            if (blank($product->slug)) {
                $product->slug = static::uniqueSlug((string) $product->name);
            }
            if ($product->is_free) {
                $product->fixed_price = 0;
                $product->price = 0;
            }

            if (blank($product->meta_title) || blank($product->meta_description) || blank($product->meta_keywords) || blank($product->short_description)) {
                $autoSeo = ProductSeoOptimizer::buildDynamicSeoFields($product);
                if (blank($product->short_description)) {
                    $product->short_description = $autoSeo['short_description'];
                }
                if (blank($product->description)) {
                    $product->description = $autoSeo['description'];
                }
                if (blank($product->warranty)) {
                    $product->warranty = $autoSeo['warranty'];
                }
                if (blank($product->meta_title)) {
                    $product->meta_title = $autoSeo['meta_title'];
                }
                if (blank($product->meta_description)) {
                    $product->meta_description = $autoSeo['meta_description'];
                }
                if (blank($product->meta_keywords)) {
                    $product->meta_keywords = $autoSeo['meta_keywords'];
                }
            }

            foreach (['short_description', 'description', 'warranty', 'meta_title', 'meta_description', 'meta_keywords'] as $seoField) {
                if (filled($product->{$seoField})) {
                    $product->{$seoField} = ProductSeoOptimizer::stripEmDashes((string) $product->{$seoField});
                }
            }
        });

        static::saved(function (Product $product) {
            if ($product->image && ! Str::startsWith($product->image, ['http://', 'https://'])) {
                try {
                    if (! MediaItem::query()->where('path', $product->image)->exists()) {
                        $item = MediaItem::query()->create([
                            'name' => Str::limit((string) ($product->name ?: 'Product Image'), 140, ''),
                            'path' => $product->image,
                        ]);
                        if ($item->path && $item->path !== $product->image) {
                            static::withoutEvents(function () use ($product, $item): void {
                                $product->forceFill(['image' => $item->path])->saveQuietly();
                            });
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            if ($product->is_active) {
                $product->ensureInitialReviewsSeeded();
            }

            Cache::forget('smt_search_catalog_v1');
            Cache::forget('store.active_categories.v2');

            if (! app()->runningUnitTests() && $product->is_active && ($product->wasRecentlyCreated || $product->wasChanged(['name', 'slug', 'is_active', 'price', 'short_description', 'description']))) {
                defer(function () use ($product): void {
                    $urls = [
                        route('products.show', $product),
                        route('shop'),
                        route('home'),
                        url('/sitemap.xml'),
                    ];
                    if ($product->category) {
                        $urls[] = route('categories.show', $product->category);
                    }
                    SeoController::submitUrlsToIndexNow($urls);
                });
            }
        });

        static::deleted(function () {
            Cache::forget('smt_search_catalog_v1');
            Cache::forget('store.active_categories.v2');
        });
    }

    /**
     * Self-healing schema check so saving a product never fails with "Unknown column 'is_free'"
     * even if the live database was imported via .sql without running migrations.
     */
    public static function ensureFreeAndStockColumnsExist(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        if (! app()->runningUnitTests() && is_file(storage_path('framework/schema_v7_ready.flag'))) {
            return;
        }

        try {
            if (! Schema::hasColumn('products', 'is_free')) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->boolean('is_free')->default(false)->after('is_featured');
                });
            }
            if (! Schema::hasColumn('products', 'use_overall_supplier_markup')) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->boolean('use_overall_supplier_markup')->default(true)->after('markup_value');
                });
            }
            if (! Schema::hasColumn('products', 'stock_limit')) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->unsignedInteger('stock_limit')->nullable()->after('available_stock');
                });
            }
            if (! Schema::hasColumn('products', 'delivery_type')) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->string('delivery_type', 40)->default('auto')->after('pricing_mode');
                });
            }
            if (! Schema::hasColumn('products', 'customer_input_label')) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->string('customer_input_label', 500)->nullable()->after('delivery_type');
                });
            }
            if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'customer_input')) {
                Schema::table('orders', function (Blueprint $table): void {
                    $table->text('customer_input')->nullable()->after('admin_note');
                });
            }
            if (Schema::hasTable('product_sources')) {
                if (! Schema::hasColumn('product_sources', 'pricing_mode')) {
                    Schema::table('product_sources', function (Blueprint $table): void {
                        $table->string('pricing_mode', 20)->nullable()->after('is_active');
                    });
                }
                if (! Schema::hasColumn('product_sources', 'markup_value')) {
                    Schema::table('product_sources', function (Blueprint $table): void {
                        $table->decimal('markup_value', 12, 2)->nullable()->after('pricing_mode');
                    });
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
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
            ->withPivot(['is_active', 'pricing_mode', 'markup_value'])
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
        if ($this->is_free) {
            return 0.0;
        }

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

    public function displayStock(): int|string
    {
        return $this->stock_unlimited ? '∞' : max(0, (int) $this->available_stock);
    }

    /**
     * Admin-only helper: returns which Supplier (or Own Stock / Manual mode) fulfills this product.
     * Never called on customer-facing views.
     */
    public function adminSupplierSummary(bool $withCost = false): string
    {
        static $matchedByName = [];

        $this->loadMissing(['activeSources.supplier', 'sources.supplier']);

        $sources = $this->activeSources->isNotEmpty() ? $this->activeSources : $this->sources;

        if ($sources->isEmpty() && filled($this->name)) {
            if (! array_key_exists($this->name, $matchedByName)) {
                $matchedByName[$this->name] = SupplierProduct::with('supplier')->where('name', $this->name)->first();
            }
            $matchedSp = $matchedByName[$this->name];
            if ($matchedSp) {
                $sources = collect([$matchedSp]);
            }
        }

        if ($sources->isNotEmpty()) {
            /** @var SupplierProduct|null $primary */
            $primary = $sources
                ->sortBy([
                    fn (SupplierProduct $a, SupplierProduct $b) => (int) $b->isSellable() <=> (int) $a->isSellable(),
                    fn (SupplierProduct $a, SupplierProduct $b) => (int) ($b->supplier?->is_active ?? false) <=> (int) ($a->supplier?->is_active ?? false),
                    fn (SupplierProduct $a, SupplierProduct $b) => $a->costPkr() <=> $b->costPkr(),
                    fn (SupplierProduct $a, SupplierProduct $b) => ($a->supplier->priority ?? 999) <=> ($b->supplier->priority ?? 999),
                ])
                ->first();

            if ($primary) {
                $supName = $primary->supplier?->name ?? 'Supplier API';
                if (! $withCost) {
                    return $supName;
                }
                $pkrCost = number_format($primary->costPkr(), 0);
                $usdCost = number_format((float) $primary->cost, 2);

                return "{$supName} (Cost: Rs {$pkrCost} / \${$usdCost})";
            }
        }

        return match ($this->delivery_type ?: 'auto') {
            'manual_admin' => 'Admin Manual Delivery',
            'whatsapp_handover' => 'WhatsApp Handover',
            'instant_link' => 'Instant Link Vault',
            default => 'Own Stock (.TXT)',
        };
    }

    /**
     * True when the product has the Free Product tag enabled (`is_free = true`).
     */
    public function isFree(?User $user = null): bool
    {
        return (bool) $this->is_free;
    }

    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        if (Str::startsWith($this->image, ['http://', 'https://'])) {
            return $this->image;
        }

        $cleanPath = ltrim((string) $this->image, '/');
        if (filled($this->slug)) {
            $ext = pathinfo($cleanPath, PATHINFO_EXTENSION) ?: 'webp';
            $withoutExt = preg_replace('/\.[^.\/]+$/', '', $cleanPath) ?: $cleanPath;
            $seoSlug = Str::slug((string) $this->slug) ?: 'digital-tool';

            return url("/media/{$withoutExt}/{$seoSlug}-subscription-pakistan.{$ext}");
        }

        return url('/media/'.$cleanPath);
    }

    public function imageSeoAlt(): string
    {
        $pricePart = (float) $this->price > 0 ? ' ('.money($this->price).')' : '';

        return str_replace(['—', '–'], '-', "Buy {$this->name} Subscription in Pakistan{$pricePart} - Instant Delivery | SellMeTools");
    }

    public function imageSeoTitle(): string
    {
        $pricePart = (float) $this->price > 0 ? ' - '.money($this->price) : '';

        return str_replace(['—', '–'], '-', "{$this->name} Price in Pakistan{$pricePart} | SellMeTools");
    }

    public function ownAvailableCount(): int
    {
        return $this->stockItems()->where('status', StockStatus::Available)->count();
    }

    public function seoTitle(): string
    {
        $cleanName = ProductSeoOptimizer::cleanProductTitle((string) $this->name);
        if (filled($this->meta_title)) {
            return ProductSeoOptimizer::cleanProductTitle((string) $this->meta_title);
        }

        if (str_contains(strtolower($cleanName), 'subscription')) {
            return "Buy {$cleanName} Cheap Online";
        }

        return "Buy {$cleanName} | Best Price in Pakistan";
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
            'antigravity' => 'Google',
            'google' => 'Google',
            'youtube' => 'Google',
            'outlook' => 'Microsoft',
            'hotmail' => 'Microsoft',
            'microsoft' => 'Microsoft',
            'windows' => 'Microsoft',
            'office 365' => 'Microsoft',
            'office365' => 'Microsoft',
            'ms365' => 'Microsoft',
            'copilot' => 'Microsoft',
            'github' => 'GitHub',
            'chatgpt' => 'OpenAI',
            'codex' => 'OpenAI',
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
            'apple tv' => 'Apple',
            'cursor' => 'Cursor',
            'grok' => 'xAI',
            'perplexity' => 'Perplexity',
            'midjourney' => 'Midjourney',
            'heygen' => 'HeyGen',
            'higgsfield' => 'Higgsfield AI',
            'kling' => 'Kling AI',
            'elevenlabs' => 'ElevenLabs',
            'wispr' => 'Wispr Flow',
            'lovable' => 'Lovable',
            'replit' => 'Replit',
            'railway' => 'Railway',
            'warp' => 'Warp',
            'framer' => 'Framer',
            'jetbrains' => 'JetBrains',
            'autodesk' => 'Autodesk',
            'miro' => 'Miro',
            'notion' => 'Notion',
            'ilovepdf' => 'iLovePDF',
            'scribd' => 'Scribd',
            'duolingo' => 'Duolingo',
            'stealth writer' => 'StealthWriter',
            'stealthwriter' => 'StealthWriter',
            'grammarly' => 'Grammarly',
            'quillbot' => 'QuillBot',
            'turnitin' => 'Turnitin',
            'semrush' => 'Semrush',
            'ahrefs' => 'Ahrefs',
            'nordvpn' => 'NordVPN',
            'nord vpn' => 'NordVPN',
            'expressvpn' => 'ExpressVPN',
            'express vpn' => 'ExpressVPN',
            'surfshark' => 'Surfshark',
            'hma' => 'HMA VPN',
            'zoom' => 'Zoom',
            'figma' => 'Figma',
            'envato' => 'Envato',
            'freepik' => 'Freepik',
            'coursera' => 'Coursera',
            'udemy' => 'Udemy',
            'apna college' => 'Apna College',
            'muse ai' => 'Muse AI',
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
     * Normalizes Mathematical Bold/Italic/Sans-Serif Unicode (U+1D400..U+1D7FF) into clean standard ASCII.
     */
    public static function normalizeUnicodeText(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($raw, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $raw = $normalized;
            }
        }

        // Fallback mathematical bold/italic/sans-serif Unicode normalization (U+1D400..U+1D7FF) if intl extension is unavailable
        return preg_replace_callback('/[\x{1D400}-\x{1D7FF}]/u', function (array $m): string {
            $cp = mb_ord($m[0], 'UTF-8');
            if ($cp >= 0x1D7CE && $cp <= 0x1D7FF) {
                return chr(ord('0') + (($cp - 0x1D7CE) % 10));
            }
            if ($cp >= 0x1D5D4 && $cp <= 0x1D5ED) {
                return chr(ord('A') + ($cp - 0x1D5D4));
            }
            if ($cp >= 0x1D5EE && $cp <= 0x1D607) {
                return chr(ord('a') + ($cp - 0x1D5EE));
            }
            if ($cp >= 0x1D400 && $cp <= 0x1D419) {
                return chr(ord('A') + ($cp - 0x1D400));
            }
            if ($cp >= 0x1D41A && $cp <= 0x1D433) {
                return chr(ord('a') + ($cp - 0x1D41A));
            }

            return ' ';
        }, $raw) ?? $raw;

        return str_replace(["\u{2014}", "\u{2013}", "\u{2015}", '—', '–', '―'], ' - ', $raw);
    }

    public function cleanShortDescription(): string
    {
        return trim(static::normalizeUnicodeText((string) $this->short_description));
    }

    public function cleanDescriptionText(): string
    {
        return trim(static::normalizeUnicodeText((string) $this->description));
    }

    public function cleanWarrantyText(): string
    {
        $clean = static::normalizeUnicodeText((string) $this->warranty);

        return trim(str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $clean));
    }

    /**
     * Returns a clean, human-readable summary sentence (converting ALL-CAPS or Mathematical Unicode supplier notes into readable text).
     */
    public function cleanSummaryText(): string
    {
        $raw = static::normalizeUnicodeText((string) ($this->short_description ?: $this->description));

        // Strip non-ASCII emoji clusters cleanly while keeping a space separator so words never stick together
        $raw = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}]+/u', ' ', $raw) ?? $raw;
        $raw = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
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

    /**
     * Ensures this product has 25+ to 40+ approved reviews so Google Rich Snippet stars (`AggregateRating` + `Review`)
     * and storefront ratings are active.
     */
    public function ensureInitialReviewsSeeded(): void
    {
        try {
            if (! Schema::hasTable('reviews') || $this->reviews()->count() >= 25) {
                return;
            }

            $this->reviews()->whereNull('order_id')->whereNull('user_id')->delete();
            Review::insert(static::buildInitialReviewRowsFor($this->id, (string) $this->name));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * One-time idempotent batch seed of 26 to 42 verified reviews per product (mostly Pakistani names,
     * some Indian names, and a few Australian names, unique per product).
     */
    public static function seedInitialReviewsOnce(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $flagFile = storage_path('framework/reviews_v6_seeded.flag');
        if (is_file($flagFile)) {
            return;
        }

        @file_put_contents($flagFile, '1');

        try {
            if (! Schema::hasTable('reviews') || ! Schema::hasTable('products')) {
                return;
            }

            $allProducts = static::withTrashed()
                ->select(['id', 'name'])
                ->get();

            if ($allProducts->isEmpty()) {
                return;
            }

            Review::query()
                ->whereNull('order_id')
                ->whereNull('user_id')
                ->delete();

            $rows = [];
            foreach ($allProducts as $prod) {
                foreach (static::buildInitialReviewRowsFor((int) $prod->id, (string) $prod->name) as $row) {
                    $rows[] = $row;
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                Review::insert($chunk);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Builds 26 to 42 unique, realistic reviews for a product (~73% Pakistani, ~18% Indian, ~9% Australian names).
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function buildInitialReviewRowsFor(int $productId, string $productName): array
    {
        $pkFirst = [
            'Usman', 'Hamza', 'Zainab', 'Bilal', 'Ahsan', 'Saad', 'Fatima', 'Daniyal',
            'Omer', 'Ayesha', 'Hassan', 'Kamran', 'Talha', 'Mahnoor', 'Salman', 'Haris',
            'Zohaib', 'Waqas', 'Hira', 'Shahzaib', 'Fahad', 'Adeel', 'Sana', 'Mubashir',
            'Arslan', 'Noman', 'Kashif', 'Maryam', 'Taimoor', 'Awais', 'Shoaib', 'Yasir',
            'Nida', 'Junaid', 'Huzaifa', 'Ammar', 'Ubaid', 'Rizwan', 'Faisal', 'Zeeshan',
            'Iqra', 'Areeba', 'Komal', 'Rabia', 'Sufyan', 'Furqan', 'Moiz', 'Waleed',
            'Shehryar', 'Umair', 'Asad', 'Babar', 'Haider', 'Sikandar', 'Subhan', 'Rehan',
            'Abdullah', 'Atif', 'Imran', 'Sohail', 'Irfan', 'Tahir', 'Naved', 'Khurram',
        ];
        $pkLast = [
            'Tariq', 'Siddiqui', 'Ali', 'Ahmed', 'Raza', 'Mahmood', 'Noor', 'Khan',
            'Farooq', 'Malik', 'Javed', 'Sheikh', 'Nadeem', 'Shah', 'Qureshi', 'Mehmood',
            'Butt', 'Chaudhry', 'Baig', 'Mirza', 'Abbasi', 'Gillani', 'Lodhi', 'Iqbal',
            'Bajwa', 'Afridi', 'Khattak', 'Pasha', 'Hashmi', 'Gondal', 'Leghari', 'Niaz',
            'Kazmi', 'Durrani', 'Warraich', 'Cheema', 'Randhawa', 'Ansari', 'Syed', 'Bukhari',
            'Chishti', 'Mughal', 'Rajput', 'Awan', 'Satti', 'Kiani', 'Jadoon', 'Marwat',
            'Bangash', 'Mengal', 'Jamali', 'Soomro', 'Kakar',
        ];

        $inFirst = [
            'Rohan', 'Priya', 'Arjun', 'Ananya', 'Vikram', 'Neha', 'Karan', 'Siddharth',
            'Pooja', 'Rahul', 'Aakash', 'Divya', 'Aditya', 'Sneha', 'Varun', 'Kritika',
            'Nikhil', 'Shreya', 'Gaurav', 'Tanvi', 'Harsh', 'Meera', 'Pranav', 'Riya',
            'Abhishek', 'Ishita', 'Manish', 'Kavya', 'Vivek', 'Nandini', 'Deepak', 'Swati',
        ];
        $inLast = [
            'Sharma', 'Patel', 'Verma', 'Nair', 'Singh', 'Gupta', 'Malhotra', 'Joshi',
            'Iyer', 'Deshmukh', 'Mehta', 'Reddy', 'Rao', 'Kulkarni', 'Chatterjee', 'Bhatia',
            'Kapoor', 'Agarwal', 'Banerjee', 'Tiwari', 'Pandey', 'Sinha', 'Chawla', 'Chopra',
            'Ahuja', 'Pillai', 'Menon', 'Hegde', 'Saxena',
        ];

        $auFirst = [
            'Liam', 'Chloe', 'Lachlan', 'Jack', 'Olivia', 'Ethan', 'Cooper', 'Zoe',
            'Noah', 'Charlotte', 'Lucas', 'Mia', 'Mason', 'Ruby', 'Hunter', 'Sienna',
            'Harrison', 'Matilda',
        ];
        $auLast = [
            'Mitchell', 'Thompson', 'Anderson', 'Campbell', 'Kelly', 'Williams', 'Robinson',
            'Wilson', 'Taylor', 'Martin', 'Walker', 'Roberts', 'Turner', 'Stewart',
            'Edwards', 'Collins', 'Morris', 'Murphy', 'Cook',
        ];

        // Pure English pool (mix of short half-line & 1-line comments)
        $englishReviews = [
            'Great deal',
            'Best price I found',
            'Very fast service',
            'Working fine, thanks.',
            '100% legit.',
            'Super quick delivery!',
            'Highly recommended.',
            'Good value for money.',
            'Works perfectly.',
            'Instant delivery, thanks!',
            'Legit and fast.',
            'Top notch service!',
            'No issues at all.',
            'Smooth experience.',
            'Totally worth it.',
            'Best rates online.',
            'Worked right away.',
            'Affordable and reliable.',
            'Quick and easy.',
            'Very satisfied!',
            'Good deal, working fine.',
            'Everything works as described.',
            'Got my login in 2 minutes, working smoothly.',
            'Way cheaper than the official rate and works great.',
            'Activated on the first try.',
            'Solid service, will renew next month.',
            'Received the details right after verification.',
            'Legit seller, account is working properly.',
            'Fast response from support when I had a question.',
            'Second time buying from here, always reliable.',
            'Logged in without any problem.',
            'Working fine since last week.',
            'Trusted store, highly recommended.',
            'Got instant access as promised.',
            'Simple checkout and fast delivery.',
            'Been using it for a few days now, zero issues.',
            'Genuine access, happy with the deal.',
            'Delivered in under 3 minutes!',
            'Great service as always.',
            'Third order from this site, never disappointed.',
            'Thank you! Working 100%.',
            'Saved a lot compared to paying in dollars.',
            'All features are unlocked and working.',
            'Zero downtime so far.',
            'Logged in easily, instructions were clear.',
            'Very reasonable price.',
            'Quick delivery, no drama.',
            'Works great on both mobile and PC.',
            'Got the credentials on the order page immediately.',
            'Good deal, will buy again.',
            'Genuine subscription, tested and verified.',
            'Best place to buy digital tools.',
            'Impressed by the speed.',
            'Fair pricing and genuine account.',
            'Delivered right on time.',
            'Fastest delivery I have experienced.',
            'Used it for my project today, works great.',
            '10/10 would recommend.',
            'Price is unbeatable.',
            'Already recommended to my friends.',
            'Renewed my plan here again, still the best.',
            'Instant access, no waiting around.',
        ];

        // Combined pool for Pakistani/Indian reviewers (mix of English + Hinglish / Roman Urdu, half-line & 1-line)
        $mixedReviews = array_merge($englishReviews, [
            'Bohat achi service ha',
            'Foran mil gya thanks',
            'Bilkul sahi chal rha ha',
            'Kamaal service ha yar',
            'Bohat zabardast deal ha',
            '100% original aur fast',
            '2 min ma mil gya',
            'Sasta aur genuine!',
            'Bohat tez delivery ha',
            'Bilkul trusted site ha',
            'Sab kuch sahi chal rha ha',
            'Paisa vasool deal ha',
            'Bohat fit chal rha ha',
            'Achi service ha bohat',
            'Time pa deliver ho gya',
            'Full working ha yar',
            'Bohat achi service ha, login foran mil gya.',
            'Pehli dafa order kia tha, bilkul genuine nikla.',
            'Market sa kafi kam rate ha aur account b sahi chal rha.',
            'Order karty hi 2 minute ma details mil gyin, thanks.',
            'Bilkul sahi kaam kar rha ha, koi masla nahi aya.',
            'Bohat achi deal ha, dobara b yahi sa lu ga.',
            'WhatsApp support b bohat fast ha, recommended!',
            'Pehle thora doubt tha par service waqai bohat achi ha.',
            'Dost na recommend kia tha, waqai best price ha.',
            'Login foran ho gya tha, sab features chal rahy hain.',
            'Bohat munasib rate ha aur delivery b fast ha.',
            'Abhi tak bilkul smooth chal rha ha, shukriya.',
            '2nd time buy kia ha, hamesha ki tarah fast service.',
            'Bina kisi tension k foran account active ho gya.',
            'Students k lia best jagah ha, rates bohat achy hain.',
            'Bohat jaldi verify ho gya aur login b sahi ha.',
            'Kafi sasta mil gya official price sa, working fine.',
        ]);

        // Between 26 and 42 reviews per product (deterministic per productId)
        $targetCount = 26 + (($productId * 7) % 17);
        $usedNames = [];
        $usedComments = [];
        $rows = [];
        $now = now();

        $pkFCount = count($pkFirst);
        $pkLCount = count($pkLast);
        $inFCount = count($inFirst);
        $inLCount = count($inLast);
        $auFCount = count($auFirst);
        $auLCount = count($auLast);
        $enPoolCount = count($englishReviews);
        $mixedPoolCount = count($mixedReviews);

        for ($i = 0; $i < $targetCount; $i++) {
            $mod = ($i + ($productId % 3)) % 11;

            $attempt = 0;
            do {
                $seedA = $productId * 37 + $i * 13 + $attempt * 7;
                $seedB = $productId * 59 + $i * 19 + $attempt * 11;

                if ($mod === 10) {
                    // Australian (~9%)
                    $name = $auFirst[$seedA % $auFCount].' '.$auLast[$seedB % $auLCount];
                } elseif ($mod === 5 || $mod === 8) {
                    // Indian (~18%)
                    $name = $inFirst[$seedA % $inFCount].' '.$inLast[$seedB % $inLCount];
                } else {
                    // Pakistani (~73%)
                    $name = $pkFirst[$seedA % $pkFCount].' '.$pkLast[$seedB % $pkLCount];
                }
                $attempt++;
            } while (isset($usedNames[$name]) && $attempt < 30);

            $usedNames[$name] = true;

            $cAttempt = 0;
            do {
                $cIndex = ($productId * 37) + ($i * 29) + $cAttempt;
                if ($mod === 10) {
                    // Australian reviewers always get pure English comments
                    $comment = $englishReviews[$cIndex % $enPoolCount];
                } else {
                    // Pakistani & Indian reviewers get a natural mix of English and Hinglish/Roman Urdu
                    $comment = $mixedReviews[$cIndex % $mixedPoolCount];
                }
                $cAttempt++;
            } while (isset($usedComments[$comment]) && $cAttempt < 120);

            $usedComments[$comment] = true;

            // ~86% 5-star, ~14% 4-star -> realistic 4.8 - 4.9 average rating
            $rating = (($productId + $i * 3) % 7 === 4) ? 4 : 5;
            $daysAgo = (($productId * 3 + $i * 2) % 45) + 1;
            $ts = $now->copy()->subDays($daysAgo)->subHours(($productId + $i) % 23);

            $rows[] = [
                'product_id' => $productId,
                'order_id' => null,
                'user_id' => null,
                'customer_name' => $name,
                'rating' => $rating,
                'comment' => $comment,
                'is_approved' => true,
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }

        return $rows;
    }

    public function seoDescription(): string
    {
        if (filled($this->meta_description)) {
            return (string) $this->meta_description;
        }

        $base = $this->cleanSummaryText();
        $hasValidPrice = (float) $this->price > 0;

        if ($this->isFree()) {
            if ($base !== '') {
                return Str::limit("Get {$this->name} for FREE with instant online access. {$base}", 152);
            }

            return Str::limit("Get {$this->name} for FREE with instant automated delivery on your order page, no payment required.", 152);
        }

        if ($base !== '') {
            if ($hasValidPrice) {
                return Str::limit("Buy {$this->name} cheap subscription online (".money($this->price)."). {$base}", 152);
            }

            return Str::limit("Buy {$this->name} cheap subscription online with instant delivery. {$base} Pay via Binance USDT, Easypaisa or JazzCash.", 152);
        }

        if ($hasValidPrice) {
            return Str::limit("Buy {$this->name} cheap subscription online for ".money($this->price).'. Instant worldwide delivery via Binance USDT or local PKR wallets (Easypaisa, JazzCash).', 152);
        }

        return Str::limit("Buy {$this->name} cheap subscription online with instant automated delivery worldwide. Pay via Binance Pay / USDT or local PKR wallets.", 152);
    }

    public function seoKeywords(): string
    {
        if (filled($this->meta_keywords)) {
            return (string) $this->meta_keywords;
        }

        return implode(', ', array_filter([
            "{$this->name} cheap subscription",
            "buy {$this->name} cheap subscription",
            "buy {$this->name} online",
            "{$this->name} subscription",
            "{$this->name} cheap price",
            "{$this->name} price in Pakistan",
            "{$this->name} instant delivery",
            "{$this->name} USDT Binance",
            $this->category ? $this->category->name : null,
            (string) Setting::get('store_name', 'SellMeTools'),
        ]));
    }
}
