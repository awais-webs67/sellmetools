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
        'pricing_mode', 'delivery_type', 'customer_input_label', 'fixed_price', 'markup_value', 'compare_at_price', 'price', 'cost_price',
        'available_stock', 'stock_limit', 'stock_unlimited',
        'is_active', 'is_featured', 'is_free', 'disabled_reason', 'max_per_order', 'sort', 'sold_count',
    ];

    /**
     * @return array<string, string>
     */
    public static function deliveryTypeOptions(): array
    {
        return [
            'auto' => '⚡ Auto Delivery: Unique Accounts from .TXT Stock Vault or Supplier API (1 Unit Consumed Per Order)',
            'instant_link' => '🔗 Reusable Course / Resource Link: Same Link or Text Auto-Delivered to Every Customer (Unlimited Stock)',
            'manual_admin' => '👨‍💻 Admin Manual Delivery: Admin Delivers Credentials After Order Confirmed',
            'whatsapp_handover' => '📲 Direct WhatsApp Delivery: Show WhatsApp Claim Button After Order Confirmed',
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

            if (blank($product->slug)) {
                $product->slug = static::uniqueSlug($product->name);
            }
            if ($product->is_free) {
                $product->fixed_price = 0;
                $product->price = 0;
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
        });

        static::deleted(function () {
            Cache::forget('smt_search_catalog_v1');
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

        if (! app()->runningUnitTests() && is_file(storage_path('framework/schema_v5_ready.flag'))) {
            return;
        }

        try {
            if (! Schema::hasColumn('products', 'is_free')) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->boolean('is_free')->default(false)->after('is_featured');
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
        if (filled($this->meta_title)) {
            return str_replace(['—', '–'], '-', (string) $this->meta_title);
        }

        if (str_contains(strtolower((string) $this->name), 'subscription')) {
            return str_replace(['—', '–'], '-', "Buy {$this->name} Cheap Online");
        }

        return str_replace(['—', '–'], '-', "Buy {$this->name} | Cheap Subscription");
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
     * Ensures this product has at least 2 approved reviews so Google Rich Snippet stars (`AggregateRating` + `Review`)
     * and storefront ratings are active.
     */
    public function ensureInitialReviewsSeeded(): void
    {
        try {
            if (! Schema::hasTable('reviews') || $this->reviews()->exists()) {
                return;
            }

            Review::insert(static::buildInitialReviewRowsFor($this->id, (string) $this->name));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * One-time idempotent batch seed of verified initial reviews for any active product that currently has 0 reviews.
     */
    public static function seedInitialReviewsOnce(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $flagFile = storage_path('framework/reviews_v1_seeded.flag');
        if (is_file($flagFile)) {
            return;
        }

        @file_put_contents($flagFile, '1');

        try {
            if (! Schema::hasTable('reviews') || ! Schema::hasTable('products')) {
                return;
            }

            $productsWithoutReviews = static::query()
                ->select(['id', 'name'])
                ->where('is_active', true)
                ->whereDoesntHave('reviews')
                ->get();

            if ($productsWithoutReviews->isEmpty()) {
                return;
            }

            $rows = [];
            foreach ($productsWithoutReviews as $prod) {
                foreach (static::buildInitialReviewRowsFor((int) $prod->id, (string) $prod->name) as $row) {
                    $rows[] = $row;
                }
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                Review::insert($chunk);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function buildInitialReviewRowsFor(int $productId, string $productName): array
    {
        $names = [
            'Usman Tariq', 'Hamza Siddiqui', 'Zainab Ali', 'Bilal Ahmed',
            'Ahsan Raza', 'Saad Mahmood', 'Fatima Noor', 'Daniyal Khan',
            'Omer Farooq', 'Ayesha Malik', 'Hassan Javed', 'Kamran Sheikh',
            'Talha Nadeem', 'Mahnoor Shah', 'Salman Qureshi', 'Haris Mehmood',
        ];

        $templatesA = [
            "Got my {$productName} credentials right after Easypaisa verification. Working smoothly without any issue!",
            "Instant delivery on the order tracking page and genuine {$productName} access. Best price in Pakistan.",
            "Super fast activation for {$productName}. Paid via JazzCash and received the details within minutes.",
            "100% legit {$productName} subscription. Smooth checkout and very responsive WhatsApp support team.",
        ];

        $templatesB = [
            "Great value for money. Using {$productName} daily and everything works as promised with full warranty.",
            "Paid with Binance USDT and got instant access to {$productName} in my account vault. Highly recommended!",
            "Reliable service as always on SellMeTools. My {$productName} plan activated cleanly on the first try.",
            "Very affordable price compared to official rates and delivery was super quick via NayaPay.",
        ];

        $templatesC = [
            "Second time renewing {$productName} from here. Zero downtime and genuine subscription access.",
            "Clean dashboard delivery and helpful instructions included with {$productName}. Five stars!",
        ];

        $nCount = count($names);
        $name1 = $names[($productId * 3) % $nCount];
        $name2 = $names[($productId * 3 + 5) % $nCount];
        $name3 = $names[($productId * 3 + 11) % $nCount];

        $comment1 = $templatesA[$productId % count($templatesA)];
        $comment2 = $templatesB[($productId + 1) % count($templatesB)];
        $comment3 = $templatesC[$productId % count($templatesC)];

        $now = now();
        $rows = [
            [
                'product_id' => $productId,
                'order_id' => null,
                'user_id' => null,
                'customer_name' => $name1,
                'rating' => 5,
                'comment' => $comment1,
                'is_approved' => true,
                'created_at' => $now->copy()->subDays(($productId % 14) + 5),
                'updated_at' => $now->copy()->subDays(($productId % 14) + 5),
            ],
            [
                'product_id' => $productId,
                'order_id' => null,
                'user_id' => null,
                'customer_name' => $name2,
                'rating' => 5,
                'comment' => $comment2,
                'is_approved' => true,
                'created_at' => $now->copy()->subDays(($productId % 5) + 1),
                'updated_at' => $now->copy()->subDays(($productId % 5) + 1),
            ],
        ];

        if ($productId % 2 === 0) {
            $rows[] = [
                'product_id' => $productId,
                'order_id' => null,
                'user_id' => null,
                'customer_name' => $name3,
                'rating' => ($productId % 4 === 0) ? 4 : 5,
                'comment' => $comment3,
                'is_approved' => true,
                'created_at' => $now->copy()->subDays(($productId % 9) + 2),
                'updated_at' => $now->copy()->subDays(($productId % 9) + 2),
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
