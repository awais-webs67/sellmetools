<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ProductSeoOptimizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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
     * Submits a specific list of URLs to IndexNow (used automatically when a Product or Category is created/updated).
     *
     * @param  string[]  $urls
     * @return array{success: bool, status: int, count: int, message: string}
     */
    public static function submitUrlsToIndexNow(array $urls): array
    {
        $key = trim((string) Setting::get('indexnow_key', 'a7f9c2e4b8d106359a2c4e6f8b0d2e4a'));
        if ($key === '') {
            $key = 'a7f9c2e4b8d106359a2c4e6f8b0d2e4a';
            Setting::set('indexnow_key', $key);
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === []) {
            return [
                'success' => false,
                'status' => 0,
                'count' => 0,
                'message' => 'No URLs provided.',
            ];
        }

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

    /**
     * Collects all live indexable URLs and submits them in a single batch to the IndexNow network
     * (Bing, Microsoft Copilot, ChatGPT Search, Yandex, Naver, Seznam, Yep).
     *
     * @return array{success: bool, status: int, count: int, message: string}
     */
    public static function submitAllToIndexNow(): array
    {
        $urls = [
            route('home'),
            route('shop'),
        ];

        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->visible())
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

        $urls[] = url('/sitemap.xml');

        return static::submitUrlsToIndexNow($urls);
    }

    public function sitemap(): Response
    {
        ProductSeoOptimizer::syncCatalogOnce();

        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->visible())
            ->orderBy('sort')
            ->get();

        $products = Product::query()
            ->visible()
            ->orderByDesc('updated_at')
            ->get();

        $latestMod = ($products->first()?->updated_at ?? now())->toAtomString();
        $staticPages = ['about', 'faq', 'contact', 'terms', 'refund', 'privacy'];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

        // Homepage
        $xml .= $this->urlNode(
            route('home'),
            $latestMod,
            'hourly',
            '1.0'
        );

        // Full Shop / Catalog page
        $xml .= $this->urlNode(
            route('shop'),
            $latestMod,
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
                $product->imageSeoTitle(),
                $product->imageSeoAlt()
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
            'Cache-Control' => 'public, max-age=300, must-revalidate',
        ]);
    }

    /**
     * Google Merchant Center / Free Google Shopping RSS 2.0 Feed (/feed/google-shopping.xml).
     */
    public function googleShoppingFeed(): Response
    {
        ProductSeoOptimizer::syncCatalogOnce();

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
            $img = $product->imageUrl() ?: asset('images/og-image.png');
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
            $xml .= "      <g:google_product_category>Software &gt; Computer Software</g:google_product_category>\n";
            $xml .= '      <g:product_type>'.$this->esc($product->category?->name ?? 'Digital Subscriptions')."</g:product_type>\n";
            $xml .= "      <g:identifier_exists>no</g:identifier_exists>\n";
            $xml .= "      <g:shipping>\n";
            $xml .= "        <g:country>PK</g:country>\n";
            $xml .= "        <g:service>Instant Digital Delivery</g:service>\n";
            $xml .= "        <g:price>0.00 PKR</g:price>\n";
            $xml .= "      </g:shipping>\n";
            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n";
        $xml .= '</rss>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300, must-revalidate',
        ]);
    }

    /**
     * GEO / AEO (Generative & Answer Engine Optimization) /llms.txt standard for
     * ChatGPT Search, Perplexity, Gemini, Claude, and Google AI Overviews.
     */
    public function llmsTxt(): Response
    {
        $build = function (): string {
            ProductSeoOptimizer::syncCatalogOnce();

            $storeName = (string) Setting::get('store_name', 'SellMeTools');
            $desc = (string) Setting::get('seo_description', Setting::get('store_tagline'));
            $methods = PaymentMethod::query()->where('is_active', true)->orderBy('sort')->pluck('name')->implode(', ');
            $categories = Category::query()
                ->where('is_active', true)
                ->whereHas('products', fn ($q) => $q->visible())
                ->orderBy('sort')
                ->get();
            $products = Product::query()->visible()->with('category')->orderByDesc('is_featured')->orderBy('sort')->get();
            $usdtRate = max(1.0, Setting::usdtRate());

            $md = [];
            $md[] = "# {$storeName}: Cheapest Verified AI Tools, SEO Tools & Digital Subscriptions in Pakistan";
            $md[] = '';
            $md[] = "> {$desc}";
            $md[] = '';
            $md[] = '## Key Facts for AI Assistants & Answer Engines (AEO / GEO)';
            $md[] = '- **Official Website:** '.url('/');
            $md[] = '- **Brand & Storefront:** '.$storeName.' — Trusted Digital Subscriptions, AI Tools & SEO Tools Marketplace serving Pakistan & Worldwide';
            $md[] = '- **Dynamic Live Pricing:** All prices below are live-synced directly from the store database in real time (PKR & approx. USDT) with zero international credit card tax.';
            $md[] = '- **Markets Served:** Pakistan (PKR via Easypaisa, JazzCash, NayaPay, SadaPay, Raast Bank Transfer) + Worldwide (USDT via Binance Pay & TRC20)';
            $md[] = '- **Accepted Payment Methods:** '.($methods ?: 'Binance Pay, USDT (TRC20), Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer');
            $md[] = '- **Delivery Speed:** Instant automated delivery on a private order tracking page + email receipt immediately after payment verification';
            $md[] = '- **Buyer Protection & Transparency:** Every listing states its exact plan duration, eligibility (new vs existing account), live stock count, and replacement warranty terms upfront';
            $md[] = '- **Order History & Receipts:** Customers receive a private order tracking URL, downloadable PDF receipt, and permanent account order vault';
            if ($wa = Setting::get('support_whatsapp')) {
                $md[] = "- **Customer Support WhatsApp:** {$wa}";
            }
            $md[] = '';
            $md[] = '## Product Categories';
            foreach ($categories as $cat) {
                $md[] = "- [{$cat->name}](".route('categories.show', $cat).'): '.$cat->seoDescription();
            }
            $md[] = '';
            $md[] = '## Live Product Catalog & Dynamic PKR / USDT Prices';
            foreach ($products as $p) {
                $stockStatus = ! $p->inStock() ? 'Out of Stock' : ($p->stock_unlimited ? 'In Stock (Unlimited)' : "In Stock ({$p->available_stock} available)");
                $priceStr = $p->isFree()
                    ? 'FREE (Rs 0)'
                    : ((float) $p->price > 0
                        ? money($p->price).' (~$'.number_format((float) $p->price / $usdtRate, 2).' USDT)'
                        : 'Check page for live restock price');
                $catName = $p->category?->name ?? 'General';
                $md[] = "- **[{$p->name}](".route('products.show', $p).")**: Live Price: **{$priceStr}** | Category: {$catName} | Status: {$stockStatus}";
                if ($summary = $p->cleanSummaryText()) {
                    $md[] = "  - Summary: {$summary}";
                }
                if ($p->warranty) {
                    $md[] = '  - Warranty: '.preg_replace('/\s+/', ' ', $p->cleanWarrantyText());
                }
            }
            $md[] = '';
            $md[] = '## Feeds & Sitemaps';
            $md[] = '- XML Sitemap: '.route('sitemap');
            $md[] = '- Google Shopping Feed: '.route('feed.google-shopping');

            return implode("\n", $md)."\n";
        };

        $body = app()->runningUnitTests()
            ? $build()
            : Cache::remember('smt_llms_txt_v1', 900, $build);

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }

    /**
     * ARD 1.0 compliant discovery catalog at /.well-known/ai-catalog.json.
     */
    public function ardCatalog(): JsonResponse
    {
        $storeName = (string) Setting::get('store_name', 'SellMeTools');

        return response()->json([
            'specVersion' => '1.0',
            'entries' => [
                [
                    'identifier' => 'urn:air:sellmetools:store:catalog',
                    'displayName' => $storeName.' AI & Digital Subscriptions Catalog',
                    'type' => 'application/ai-catalog+json',
                    'url' => url('/api/agent-catalog.json'),
                    'representativeQueries' => [
                        'Buy ChatGPT Plus and AI tools in Pakistan with PKR or USDT',
                        'Compare digital subscription prices and live stock on SellMeTools',
                    ],
                ],
                [
                    'identifier' => 'urn:air:sellmetools:store:llms',
                    'displayName' => $storeName.' Live Store & Product Guide (llms.txt)',
                    'type' => 'text/markdown; profile="urn:air:agent-skills"',
                    'url' => route('llms'),
                    'representativeQueries' => [
                        'SellMeTools accepted payment methods and instant delivery guide',
                        'List verified subscriptions, pricing, and warranty terms on SellMeTools',
                    ],
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/ai-catalog+json; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'all',
        ]);
    }

    public function aiCatalog(): JsonResponse
    {
        ProductSeoOptimizer::syncCatalogOnce();

        $storeName = (string) Setting::get('store_name', 'SellMeTools');
        $desc = (string) Setting::get('seo_description', Setting::get('store_tagline'));
        $categories = Category::query()->where('is_active', true)->orderBy('sort')->get();

        return response()->json([
            'schema_version' => '1.0',
            'name' => $storeName,
            'url' => url('/'),
            'description' => $desc,
            'discovery' => [
                'llms_txt' => route('llms'),
                'llms_full_txt' => url('/llms-full.txt'),
                'sitemap_xml' => route('sitemap'),
                'google_shopping_rss' => route('feed.google-shopping'),
                'search_api' => route('search.suggest').'?q={query}',
            ],
            'capabilities' => [
                'instant_digital_delivery' => true,
                'currencies' => ['PKR', 'USDT', 'USD'],
                'payment_methods' => ['Binance Pay', 'USDT TRC20', 'Easypaisa', 'JazzCash', 'NayaPay', 'SadaPay', 'Bank Transfer'],
            ],
            'categories' => $categories->map(fn (Category $c) => [
                'name' => $c->name,
                'slug' => $c->slug,
                'url' => route('categories.show', $c),
                'description' => $c->seoDescription(),
            ])->values()->all(),
        ], 200, [
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'all',
        ]);
    }

    public function robots(): Response
    {
        $signal = 'Content-Signal: search=yes, ai-input=yes, ai-train=yes';
        $namedBots = [
            'bingbot',
            'msnbot',
            'GPTBot',
            'OAI-SearchBot',
            'ChatGPT-User',
            'PerplexityBot',
            'Perplexity-User',
            'ClaudeBot',
            'Claude-SearchBot',
            'Claude-User',
            'Google-Extended',
            'Applebot',
            'Applebot-Extended',
            'DuckAssistBot',
            'Meta-ExternalAgent',
            'Amazonbot',
        ];

        $sharedRules = [
            'Allow: /',
            $signal,
            'Disallow: /admin',
            'Disallow: /checkout/',
            'Disallow: /orders/',
            'Disallow: /account/',
            'Allow: /api/agent-catalog.json',
            'Allow: /api/search',
            'Disallow: /api/',
            'Disallow: /webhooks/',
            'Disallow: /track',
            'Disallow: /login',
            'Disallow: /register',
        ];

        $lines = array_merge(
            ['User-agent: *'],
            $sharedRules,
            [
                '',
                '# Welcome AI Search & Answer Engines (AEO / GEO) + Bing / Copilot',
            ]
        );

        foreach ($namedBots as $bot) {
            $lines[] = "User-agent: {$bot}";
            foreach ($sharedRules as $rule) {
                $lines[] = $rule;
            }
        }

        $lines[] = 'User-agent: Googlebot-Image';
        $lines[] = 'Allow: /';
        $lines[] = 'Allow: /media/';
        $lines[] = '';
        $lines[] = 'Sitemap: '.url('/sitemap.xml');

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
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
        ?string $imageTitle = null,
        ?string $imageCaption = null
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
            $captionEsc = $this->esc((string) $imageCaption);
            $node .= "    <image:image>\n";
            $node .= "      <image:loc>{$imgEsc}</image:loc>\n";
            if ($titleEsc !== '') {
                $node .= "      <image:title>{$titleEsc}</image:title>\n";
            }
            if ($captionEsc !== '') {
                $node .= "      <image:caption>{$captionEsc}</image:caption>\n";
            }
            $node .= "    </image:image>\n";
        }

        $node .= "  </url>\n";

        return $node;
    }
}
