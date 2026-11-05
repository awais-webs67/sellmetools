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
                'title' => 'Buy AI Tools in Pakistan - ChatGPT Plus, Claude, Gemini',
                'description' => 'Buy genuine ChatGPT Plus, Claude Pro & API, Google Gemini Pro, HeyGen, Kling AI & ElevenLabs in Pakistan with instant delivery via Easypaisa, JazzCash & USDT.',
                'keywords' => 'buy AI tools in Pakistan, ChatGPT Plus price in Pakistan, buy ChatGPT Plus with Easypaisa JazzCash, Claude Pro price in Pakistan, Claude API key Pakistan, Gemini Advanced subscription Pakistan, HeyGen Creator, Kling AI, ElevenLabs, Midjourney Pro Pakistan, SellMeTools',
            ],
            'linkedin' => [
                'title' => 'Buy LinkedIn Premium & Sales Navigator Vouchers in Pakistan',
                'description' => 'Buy LinkedIn Career, Business & Sales Navigator official vouchers and login upgrades in Pakistan & worldwide. Pay via Easypaisa, JazzCash, NayaPay or USDT.',
                'keywords' => 'LinkedIn Premium price in Pakistan, buy LinkedIn Career voucher Pakistan, LinkedIn Business 12 months price in Pakistan, LinkedIn Sales Navigator Core Advanced voucher, buy LinkedIn Premium with Easypaisa JazzCash, SellMeTools',
            ],
            'seo' => [
                'title' => 'Buy SEO Tools in Pakistan - Ahrefs, Semrush & Moz Pro Bundle',
                'description' => 'Buy Ahrefs, Semrush, Moz Pro, Ubersuggest & 30+ premium SEO group buy tools online in Pakistan at cheap prices. Instant delivery via Easypaisa, JazzCash & USDT.',
                'keywords' => 'Ahrefs price in Pakistan, Semrush price in Pakistan, buy SEO tools in Pakistan, SEO group buy tools Pakistan, Semrush Moz Pro bundle Easypaisa JazzCash, cheap Ahrefs subscription online, SellMeTools',
            ],
            'design' => [
                'title' => 'Buy Canva Pro, Figma & Envato Subscriptions in Pakistan',
                'description' => 'Buy Canva Edu Pro 1 Year, Figma Education, Freepik Premium & Envato Elements subscriptions online in Pakistan & worldwide via Easypaisa, JazzCash & USDT.',
                'keywords' => 'Canva Pro price in Pakistan, buy Canva Pro 1 year Easypaisa JazzCash, Figma Education Pro 2 years Pakistan, Freepik Premium 1 month price in Pakistan, Envato Elements subscription Pakistan, SellMeTools',
            ],
            'video-editing' => [
                'title' => 'Buy CapCut Pro & Video Editing Subscriptions in Pakistan',
                'description' => 'Buy CapCut Pro 1 Month, 6 Months & 1 Year Full Warranty accounts, Veed Pro and Adobe Express Premium in Pakistan via Easypaisa, JazzCash or Binance USDT.',
                'keywords' => 'CapCut Pro price in Pakistan, buy CapCut Pro with Easypaisa JazzCash, CapCut Pro 1 year full warranty Pakistan, CapCut Pro 6 months private account, Veed Pro subscription Pakistan, Adobe Express 12 months, SellMeTools',
            ],
            'vpn-security' => [
                'title' => 'Buy VPN in Pakistan - Surfshark, ExpressVPN, NordVPN & HMA',
                'description' => 'Buy Surfshark VPN, ExpressVPN, NordVPN & HMA VPN accounts and license keys online at the lowest price in Pakistan via Easypaisa, JazzCash & USDT.',
                'keywords' => 'Surfshark VPN price in Pakistan, ExpressVPN price in Pakistan, NordVPN voucher Pakistan, buy VPN with Easypaisa JazzCash, HMA VPN license key Pakistan, cheap VPN subscription Pakistan, SellMeTools',
            ],
            'productivity' => [
                'title' => 'Buy Productivity Tools in Pakistan - Zoom Pro, Notion & CAD',
                'description' => 'Buy Official Zoom Pro, Notion Business, Miro Lifetime Admin Panel, Windows 11 Pro Key & Autodesk All Apps in Pakistan with instant PKR & USDT checkout.',
                'keywords' => 'Zoom Pro price in Pakistan, buy Official Zoom Pro subscription, Windows 11 Pro activation key price in Pakistan, Miro Lifetime Panel 100 invites, Notion Business 3 months, Autodesk All Apps Pakistan, SellMeTools',
            ],
            'education' => [
                'title' => 'Buy Coursera Plus, Grammarly Pro & Turnitin in Pakistan',
                'description' => 'Buy Coursera Plus, Grammarly Pro, StealthWriter AI Humanizer, Turnitin, Scribd, Udemy & Super Duolingo subscriptions in Pakistan with instant delivery.',
                'keywords' => 'Coursera Plus price in Pakistan, Grammarly Pro 1 year price in Pakistan, StealthWriter AI humanizer Pakistan, Turnitin student account Pakistan, Scribd Premium 1 month, Super Duolingo 12 months Pakistan, SellMeTools',
            ],
            'entertainment' => [
                'title' => 'Buy YouTube Premium & Amazon Prime Video in Pakistan',
                'description' => 'Buy YouTube Premium, Amazon Prime Video 1 Month & 6 Months, and Apple TV+ subscriptions in Pakistan & worldwide via Easypaisa, JazzCash & USDT.',
                'keywords' => 'YouTube Premium price in Pakistan, Amazon Prime Video price in Pakistan, buy Prime Video 6 months Easypaisa JazzCash, Apple TV+ 12 months Pakistan, cheap streaming subscriptions Pakistan, SellMeTools',
            ],
            'cloud-dev' => [
                'title' => 'Buy Cloud & Developer Tools - Cursor, Lovable, Railway, M365',
                'description' => 'Buy Cursor Pro, Lovable Pro, Railway Hobby, Microsoft 365 Family Admin, Warp Build & n8n Cloud online in Pakistan via Easypaisa, JazzCash or USDT.',
                'keywords' => 'Cursor Pro price in Pakistan, Lovable Pro price in Pakistan, Railway Hobby 1 year coupon Pakistan, Microsoft 365 Family 1 year Pakistan, Warp Build 12 months, n8n Cloud Starter 1 year, SellMeTools',
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
