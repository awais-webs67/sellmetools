<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class SeoController extends Controller
{
    /**
     * Serves the IndexNow verification key file at /{indexnow_key}.txt for Bing, Copilot, ChatGPT Search, Yandex & Naver.
     */
    public function indexNowKey(string $key): Response
    {
        $configuredKey = trim((string) Setting::get('indexnow_key', 'a7f9c2e4b8d106359a2c4e6f8b0d2e4a'));
        if ($configuredKey === '' || $key !== $configuredKey) {
            abort(404);
        }

        return response($configuredKey, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Collects all live indexable URLs and submits them in a single batch to the IndexNow network
     * (Bing, Microsoft Copilot, ChatGPT Search, Yandex, Naver, Seznam, Yep).
     *
     * @return array{success: bool, status: int, count: int, message: string}
     */
    public static function submitAllToIndexNow(): array
    {
        $key = trim((string) Setting::get('indexnow_key', 'a7f9c2e4b8d106359a2c4e6f8b0d2e4a'));
        if ($key === '') {
            $key = 'a7f9c2e4b8d106359a2c4e6f8b0d2e4a';
            Setting::set('indexnow_key', $key);
        }

        $urls = [
            route('home'),
            route('shop'),
        ];

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();

        foreach ($categories as $category) {
            $urls[] = route('categories.show', $category);
        }

        $products = Product::query()
            ->visible()
            ->orderByDesc('updated_at')
            ->get();

        foreach ($products as $product) {
            $urls[] = route('products.show', $product);
        }

        foreach (['about', 'faq', 'contact', 'terms', 'refund', 'privacy'] as $page) {
            $urls[] = route('pages.show', $page);
        }

        $urls = array_values(array_unique($urls));
        $host = parse_url(url('/'), PHP_URL_HOST) ?: 'sellmetools.com';
        $keyLocation = url("/{$key}.txt");

        try {
            $res = Http::timeout(12)
                ->acceptJson()
                ->asJson()
                ->post('https://api.indexnow.org/indexnow', [
                    'host' => $host,
                    'key' => $key,
                    'keyLocation' => $keyLocation,
                    'urlList' => $urls,
                ]);

            $status = $res->status();
            if (in_array($status, [200, 202], true)) {
                return [
                    'success' => true,
                    'status' => $status,
                    'count' => count($urls),
                    'message' => count($urls)." URLs submitted to IndexNow (Bing, Microsoft Copilot, ChatGPT Search, Yandex & Naver). HTTP {$status}.",
                ];
            }

            return [
                'success' => false,
                'status' => $status,
                'count' => count($urls),
                'message' => "IndexNow returned HTTP {$status}: ".substr(trim($res->body()), 0, 200),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'count' => count($urls),
                'message' => 'IndexNow connection error: '.$e->getMessage(),
            ];
        }
    }
    public function sitemap(): Response
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->visible())
            ->orderBy('sort')
            ->get();

        $products = Product::query()
            ->visible()
            ->orderByDesc('updated_at')
            ->get();

        $staticPages = ['about', 'faq', 'contact', 'terms', 'refund', 'privacy'];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

        // Homepage
        $xml .= $this->urlNode(
            route('home'),
            now()->toAtomString(),
            'hourly',
            '1.0'
        );

        // Full Shop / Catalog page
        $xml .= $this->urlNode(
            route('shop'),
            now()->toAtomString(),
            'hourly',
            '0.9'
        );

        // Categories (clean SEO URLs)
        foreach ($categories as $category) {
            $xml .= $this->urlNode(
                route('categories.show', $category),
                ($category->updated_at ?? now())->toAtomString(),
                'daily',
                '0.8'
            );
        }

        // Products + Google Image Sitemap nodes
        foreach ($products as $product) {
            $image = $product->imageUrl();
            $xml .= $this->urlNode(
                route('products.show', $product),
                ($product->updated_at ?? now())->toAtomString(),
                'daily',
                '0.9',
                $image,
                $product->name
            );
        }

        // Static Pages
        foreach ($staticPages as $page) {
            $xml .= $this->urlNode(
                route('pages.show', $page),
                now()->startOfMonth()->toAtomString(),
                'monthly',
                '0.5'
            );
        }

        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }

    /**
     * Google Merchant Center / Free Google Shopping RSS 2.0 Feed (/feed/google-shopping.xml).
     */
    public function googleShoppingFeed(): Response
    {
        $storeName = (string) Setting::get('store_name', 'SellMeTools');
        $storeDesc = (string) Setting::get('seo_description', Setting::get('store_tagline'));
        $products = Product::query()
            ->visible()
            ->where('price', '>', 0)
            ->with('category')
            ->orderByDesc('is_featured')
            ->orderBy('sort')
            ->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'."\n";
        $xml .= "  <channel>\n";
        $xml .= '    <title>'.$this->esc($storeName)."</title>\n";
        $xml .= '    <link>'.$this->esc(url('/'))."</link>\n";
        $xml .= '    <description>'.$this->esc($storeDesc)."</description>\n";

        foreach ($products as $product) {
            $url = route('products.show', $product);
            $img = $product->imageUrl() ?: asset('favicon.svg');
            $availability = $product->inStock() ? 'in_stock' : 'out_of_stock';
            $price = number_format((float) $product->price, 2, '.', '').' PKR';
            $brand = $product->brandName();

            $xml .= "    <item>\n";
            $xml .= '      <g:id>SMT-'.$product->id."</g:id>\n";
            $xml .= '      <g:title>'.$this->esc($product->name)."</g:title>\n";
            $xml .= '      <g:description>'.$this->esc($product->seoDescription())."</g:description>\n";
            $xml .= '      <g:link>'.$this->esc($url)."</g:link>\n";
            $xml .= '      <g:image_link>'.$this->esc($img)."</g:image_link>\n";
            $xml .= "      <g:condition>new</g:condition>\n";
            $xml .= "      <g:availability>{$availability}</g:availability>\n";
            $xml .= "      <g:price>{$price}</g:price>\n";
            $xml .= '      <g:brand>'.$this->esc($brand)."</g:brand>\n";
            $xml .= "      <g:identifier_exists>no</g:identifier_exists>\n";
            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n";
        $xml .= '</rss>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }

    /**
     * GEO / AEO (Generative & Answer Engine Optimization) /llms.txt standard for
     * ChatGPT Search, Perplexity, Gemini, Claude, and Google AI Overviews.
     */
    public function llmsTxt(): Response
    {
        $storeName = (string) Setting::get('store_name', 'SellMeTools');
        $desc = (string) Setting::get('seo_description', Setting::get('store_tagline'));
        $methods = PaymentMethod::query()->where('is_active', true)->orderBy('sort')->pluck('name')->implode(', ');
        $categories = Category::query()->where('is_active', true)->orderBy('sort')->get();
        $products = Product::query()->visible()->with('category')->orderByDesc('is_featured')->orderBy('sort')->get();

        $md = [];
        $md[] = "# {$storeName}: Global Digital Tools & Subscriptions Store";
        $md[] = '';
        $md[] = "> {$desc}";
        $md[] = '';
        $md[] = '## Key Facts for AI Assistants & Answer Engines (AEO / GEO)';
        $md[] = '- **Official Website:** '.url('/');
        $md[] = '- **Markets Served:** Worldwide (USD / USDT via Binance Pay & TRC20) + Pakistan (PKR via Easypaisa, JazzCash, Bank Transfer)';
        $md[] = '- **Accepted Payment Methods:** '.($methods ?: 'Binance Pay, USDT (TRC20), Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer');
        $md[] = '- **Delivery Speed:** Instant automated credentials delivery on screen immediately after payment verification';
        $md[] = '- **Order History:** Customers can log in anytime to view all past orders and credentials forever';
        if ($wa = Setting::get('support_whatsapp')) {
            $md[] = "- **Customer Support WhatsApp:** {$wa}";
        }
        $md[] = '';
        $md[] = '## Product Categories';
        foreach ($categories as $cat) {
            $md[] = "- [{$cat->name}](".route('categories.show', $cat).'): '.$cat->seoDescription();
        }
        $md[] = '';
        $md[] = '## Live Product Catalog & PKR Prices';
        foreach ($products as $p) {
            $stockStatus = ! $p->inStock() ? 'Out of Stock' : ($p->stock_unlimited ? 'In Stock (Unlimited)' : "In Stock ({$p->available_stock} available)");
            $priceStr = (float) $p->price > 0 ? money($p->price) : 'Check page for live restock price';
            $catName = $p->category?->name ?? 'General';
            $md[] = "- **[{$p->name}](".route('products.show', $p).")**: Price: **{$priceStr}** | Category: {$catName} | Status: {$stockStatus}";
            if ($summary = $p->cleanSummaryText()) {
                $md[] = "  - Summary: {$summary}";
            }
            if ($p->warranty) {
                $md[] = '  - Warranty: '.preg_replace('/\s+/', ' ', trim($p->warranty));
            }
        }
        $md[] = '';
        $md[] = '## Feeds & Sitemaps';
        $md[] = '- XML Sitemap: '.route('sitemap');
        $md[] = '- Google Shopping Feed: '.route('feed.google-shopping');

        return response(implode("\n", $md)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /checkout/',
            'Disallow: /orders/',
            'Disallow: /account/',
            'Disallow: /api/',
            'Disallow: /webhooks/',
            'Disallow: /track',
            'Disallow: /login',
            'Disallow: /register',
            '',
            '# Welcome AI Search & Answer Engines (AEO / GEO) + Bing / Copilot',
            'User-agent: bingbot',
            'Allow: /',
            'User-agent: msnbot',
            'Allow: /',
            'User-agent: GPTBot',
            'Allow: /',
            'User-agent: OAI-SearchBot',
            'Allow: /',
            'User-agent: ChatGPT-User',
            'Allow: /',
            'User-agent: PerplexityBot',
            'Allow: /',
            'User-agent: ClaudeBot',
            'Allow: /',
            'User-agent: Google-Extended',
            'Allow: /',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function esc(string $val): string
    {
        return htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function urlNode(
        string $loc,
        string $lastmod,
        string $changefreq,
        string $priority,
        ?string $imageUrl = null,
        ?string $imageTitle = null
    ): string {
        $locEsc = $this->esc($loc);
        $node = "  <url>\n";
        $node .= "    <loc>{$locEsc}</loc>\n";
        $node .= "    <lastmod>{$lastmod}</lastmod>\n";
        $node .= "    <changefreq>{$changefreq}</changefreq>\n";
        $node .= "    <priority>{$priority}</priority>\n";

        if ($imageUrl) {
            $imgEsc = $this->esc($imageUrl);
            $titleEsc = $this->esc((string) $imageTitle);
            $node .= "    <image:image>\n";
            $node .= "      <image:loc>{$imgEsc}</image:loc>\n";
            if ($titleEsc !== '') {
                $node .= "      <image:title>{$titleEsc}</image:title>\n";
            }
            $node .= "    </image:image>\n";
        }

        $node .= "  </url>\n";

        return $node;
    }
}
