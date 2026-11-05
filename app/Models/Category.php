<?php

namespace App\Models;

use App\Http\Controllers\SeoController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'icon', 'meta_title', 'meta_description', 'sort', 'is_active'];

    /**
     * Curated high-ranking SEO metadata for store categories (zero em dashes).
     *
     * @return array<string, array{title: string, description: string, keywords: string}>
     */
    public static function curatedSeoMap(): array
    {
        return [
            'ai-tools' => [
                'title' => 'Buy AI Tools & Subscriptions - ChatGPT, Claude, Gemini',
                'description' => 'Buy genuine ChatGPT Plus, Claude API, Google Gemini Pro, HeyGen, Kling AI & ElevenLabs subscriptions online with instant delivery via PKR or USDT.',
                'keywords' => 'buy AI tools Pakistan, ChatGPT Plus price in Pakistan, Claude API key Pakistan, Gemini Pro subscription, HeyGen Creator, Kling AI, SellMeTools',
            ],
            'linkedin' => [
                'title' => 'Buy LinkedIn Premium & Sales Navigator Vouchers Online',
                'description' => 'Buy LinkedIn Career, Business & Sales Navigator vouchers and login upgrades in Pakistan & worldwide. Instant checkout via Easypaisa, JazzCash & USDT.',
                'keywords' => 'LinkedIn Premium price in Pakistan, buy LinkedIn Career voucher, LinkedIn Business 12 months, LinkedIn Sales Navigator voucher Pakistan, SellMeTools',
            ],
            'seo' => [
                'title' => 'Buy SEO Tools in Pakistan - Ahrefs, Semrush & Moz Pro',
                'description' => 'Buy Ahrefs, Semrush, Moz Pro, Ubersuggest & 30+ premium SEO tools online in Pakistan at cheap prices. Instant delivery via Easypaisa, JazzCash & USDT.',
                'keywords' => 'Ahrefs price in Pakistan, buy SEO tools Pakistan, Semrush Moz Pro bundle Pakistan, cheap Ahrefs subscription online, SellMeTools',
            ],
            'design' => [
                'title' => 'Buy Design Software Subscriptions - Canva Pro & Figma',
                'description' => 'Buy Canva Edu Pro, Figma Education & creative design software subscriptions online in Pakistan & worldwide. Instant delivery via Easypaisa & USDT.',
                'keywords' => 'Canva Pro price in Pakistan, buy Figma Pro subscription, graphic design tools Pakistan, cheap Canva Edu Pro, SellMeTools',
            ],
            'video-editing' => [
                'title' => 'Buy Video Editing Subscriptions - CapCut Pro & Adobe',
                'description' => 'Buy CapCut Pro 1 Month, 6 Months & 1 Year Full Warranty accounts and Adobe Express Premium online. Pay in PKR via Easypaisa, JazzCash or Binance USDT.',
                'keywords' => 'CapCut Pro price in Pakistan, buy CapCut Pro 1 year full warranty, Adobe Express 12 months Pakistan, video editing subscription, SellMeTools',
            ],
            'vpn-security' => [
                'title' => 'Buy VPN Subscriptions Online - NordVPN, ExpressVPN, HMA',
                'description' => 'Buy ExpressVPN, NordVPN, Surfshark VPN & HMA VPN keys and accounts online at the lowest price in Pakistan. Instant delivery via PKR wallets or USDT.',
                'keywords' => 'ExpressVPN price in Pakistan, NordVPN voucher Pakistan, buy Surfshark VPN cheap, HMA VPN license key Pakistan, SellMeTools',
            ],
            'productivity' => [
                'title' => 'Buy Productivity Tools - Zoom Pro, Notion, Miro & CAD',
                'description' => 'Buy Official Zoom Pro, Notion Business, Miro Lifetime Admin Panel & Autodesk All Apps subscriptions online with instant PKR & USDT checkout.',
                'keywords' => 'Zoom Pro price in Pakistan, buy Official Zoom Pro subscription, Miro Lifetime Panel 100 invites, Notion Business 3 months, Autodesk All Apps Pakistan',
            ],
            'education' => [
                'title' => 'Buy Education Subscriptions - Coursera, Grammarly, Udemy',
                'description' => 'Buy Coursera Plus, Grammarly Pro, StealthWriter AI Humanizer, Scribd, Udemy & Super Duolingo subscriptions online in Pakistan with instant delivery.',
                'keywords' => 'Coursera Plus price in Pakistan, Grammarly Pro 1 year Pakistan, StealthWriter AI humanizer price, Scribd Premium 1 month, Super Duolingo Pakistan',
            ],
            'entertainment' => [
                'title' => 'Buy Streaming Subscriptions - YouTube Premium & Prime',
                'description' => 'Buy YouTube Premium, Amazon Prime Video & Apple TV+ subscriptions online in Pakistan & worldwide. Instant delivery via Easypaisa, JazzCash & USDT.',
                'keywords' => 'YouTube Premium price in Pakistan, buy Amazon Prime Video Pakistan, Apple TV+ 12 months link, cheap streaming subscriptions Pakistan',
            ],
            'cloud-dev' => [
                'title' => 'Buy Cloud & Developer Tools - Railway, Office 365, Warp',
                'description' => 'Buy Railway Hobby, Microsoft 365 Family Admin, Lovable Pro, Warp Build & Outlook accounts online with instant automated delivery via PKR or USDT.',
                'keywords' => 'Railway Hobby 1 year coupon price in Pakistan, Microsoft 365 Family 1 year Pakistan, Lovable Lite 12 months, Warp Build coupon, SellMeTools',
            ],
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $category->name = preg_replace('/\s+/u', ' ', trim(str_replace(['—', '–'], '-', (string) $category->name))) ?: $category->name;

            if (blank($category->slug)) {
                $category->slug = Str::slug((string) $category->name) ?: 'category';
            } else {
                $category->slug = trim((string) $category->slug);
            }

            $curated = static::curatedSeoMap()[$category->slug] ?? null;
            if (blank($category->meta_title)) {
                $category->meta_title = $curated['title']
                    ?? Str::limit("Buy {$category->name} Subscriptions Online | SellMeTools", 58, '');
            } else {
                $category->meta_title = trim(str_replace(['—', '–'], '-', (string) $category->meta_title));
            }

            if (blank($category->meta_description)) {
                $category->meta_description = $curated['description']
                    ?? Str::limit("Buy genuine {$category->name} subscriptions and accounts online in Pakistan & worldwide. Instant delivery via Easypaisa, JazzCash & Binance USDT.", 154, '');
            } else {
                $category->meta_description = trim(str_replace(['—', '–'], '-', (string) $category->meta_description));
            }
        });

        static::saved(function (Category $category): void {
            Cache::forget('smt_search_catalog_v1');

            if (! app()->runningUnitTests() && $category->is_active && ($category->wasRecentlyCreated || $category->wasChanged(['name', 'slug', 'is_active', 'meta_title', 'meta_description']))) {
                defer(function () use ($category): void {
                    SeoController::submitUrlsToIndexNow([
                        route('categories.show', $category),
                        route('shop'),
                        route('home'),
                        url('/sitemap.xml'),
                    ]);
                });
            }
        });

        static::deleted(fn () => Cache::forget('smt_search_catalog_v1'));
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if ($field !== null) {
            return $query->where($this->qualifyColumn($field), $value);
        }

        return $query->where(function ($q) use ($value) {
            $q->where($this->qualifyColumn('slug'), $value);
            if (is_numeric($value)) {
                $q->orWhere($this->qualifyColumn('id'), (int) $value);
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function seoTitle(): string
    {
        $curated = static::curatedSeoMap()[(string) $this->slug] ?? null;

        if (filled($this->meta_title)) {
            return str_replace(['—', '–'], '-', (string) $this->meta_title);
        }

        if ($curated) {
            return $curated['title'];
        }

        $cleanName = trim((string) $this->name);

        return "{$cleanName} Subscriptions | Buy Online";
    }

    public function seoDescription(): string
    {
        $curated = static::curatedSeoMap()[(string) $this->slug] ?? null;

        if (filled($this->meta_description)) {
            return str_replace(['—', '–'], '-', (string) $this->meta_description);
        }

        if ($curated) {
            return $curated['description'];
        }

        $cleanName = trim((string) $this->name);

        return "Buy genuine {$cleanName} subscriptions online with instant delivery. Pay worldwide via Binance USDT or locally with PKR wallets (Easypaisa, JazzCash).";
    }

    public function seoKeywords(): string
    {
        $curated = static::curatedSeoMap()[(string) $this->slug] ?? null;
        if ($curated) {
            return $curated['keywords'];
        }

        $cleanName = trim((string) $this->name);

        return "buy {$cleanName} subscriptions, {$cleanName} price in Pakistan, cheap {$cleanName} accounts, {$cleanName} Easypaisa JazzCash USDT, SellMeTools";
    }
}
