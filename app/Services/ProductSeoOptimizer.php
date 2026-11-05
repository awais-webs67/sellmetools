<?php

namespace App\Services;

use App\Http\Controllers\SeoController;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductSeoOptimizer
{
    /**
     * Runs once on production/local to upgrade all catalog products and categories with clean, high-ranking SEO descriptions,
     * short summaries, structured HTML feature lists, and clean warranty terms (with zero em dashes).
     */
    public static function syncCatalogOnce(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $flagFile = storage_path('framework/product_seo_v9_synced.flag');
        if (is_file($flagFile)) {
            return;
        }

        @file_put_contents($flagFile, '1');

        try {
            if (! Schema::hasTable('products')) {
                return;
            }

            $categoryMap = [];
            if (Schema::hasTable('categories')) {
                $curatedCatSeo = Category::curatedSeoMap();
                foreach (Category::query()->get() as $cat) {
                    $cleanCatName = preg_replace('/\s+/u', ' ', trim(static::stripEmDashes((string) $cat->name))) ?: $cat->name;
                    $slug = trim((string) $cat->slug);
                    $catSeo = $curatedCatSeo[$slug] ?? null;

                    $cat->forceFill([
                        'name' => $cleanCatName,
                        'meta_title' => $catSeo['title'] ?? ($cat->meta_title ?: Str::limit("Buy {$cleanCatName} Subscriptions Online | SellMeTools", 58, '')),
                        'meta_description' => $catSeo['description'] ?? ($cat->meta_description ?: Str::limit("Buy genuine {$cleanCatName} subscriptions and accounts online in Pakistan & worldwide. Instant delivery via Easypaisa, JazzCash & Binance USDT.", 154, '')),
                    ])->saveQuietly();

                    $categoryMap[$slug] = (int) $cat->id;
                }
            }

            $categoryReassignments = [
                'chatgpt-plus-1m-1hw' => 'ai-tools',
                'grok-cursor-premium-1month-fw' => 'ai-tools',
                'warp-build-12m' => 'cloud-dev',
                'official-zoom-pro-3-months' => 'productivity',
            ];
            $generalCatId = $categoryMap['general'] ?? null;

            $catalog = static::curatedCatalog();
            $products = Product::withTrashed()->get();

            foreach ($products as $product) {
                $slug = (string) $product->slug;
                $cleanName = static::cleanProductTitle((string) $product->name);
                $product->name = $cleanName;
                $updates = $catalog[$slug] ?? static::buildDynamicSeoFields($product);

                $customMetaTitle = "Buy {$cleanName} | Best Price in Pakistan";
                if (mb_strlen($customMetaTitle) > 60) {
                    $customMetaTitle = "Buy {$cleanName} | Instant Delivery";
                }
                if (mb_strlen($customMetaTitle) > 60) {
                    $customMetaTitle = Str::limit("Buy {$cleanName}", 60, '');
                }

                $fill = [
                    'name' => $cleanName,
                    'short_description' => static::stripBotCodesFromCopy(static::stripEmDashes($updates['short_description'])),
                    'description' => static::stripEmDashes($updates['description']),
                    'warranty' => static::stripEmDashes($updates['warranty']),
                    'meta_title' => static::stripEmDashes($customMetaTitle),
                    'meta_description' => Str::limit(static::stripBotCodesFromCopy(static::stripEmDashes($updates['meta_description'])), 152, '...'),
                    'meta_keywords' => static::stripEmDashes($updates['meta_keywords']),
                ];

                if (isset($categoryReassignments[$slug])) {
                    $targetSlug = $categoryReassignments[$slug];
                    if (isset($categoryMap[$targetSlug]) && (! $product->category_id || $product->category_id === $generalCatId)) {
                        $fill['category_id'] = $categoryMap[$targetSlug];
                    }
                }

                $product->forceFill($fill)->saveQuietly();
            }

            Cache::forget('smt_search_catalog_v1');

            defer(function (): void {
                try {
                    SeoController::submitAllToIndexNow();
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Strips internal supplier bot abbreviations (FW, 1HW, 5DW, 1DW, 20DW, 1MW, NW, W1D, W25D, etc.)
     * and expands shorthand durations (1M -> 1 Month, 2M -> 2 Months, 6Months -> 6 Months, 3D -> 3 Days, 1Y -> 1 Year)
     * so product titles look 100% professional and rank for real Google search queries.
     */
    public static function cleanProductTitle(string $rawTitle): string
    {
        $title = Product::normalizeUnicodeText($rawTitle);
        $title = static::stripEmDashes($title);

        // 1. Separate concatenated supplier warranty/duration codes attached to words (e.g., CreditsW1D, 100Million)
        $title = preg_replace('/([a-z])(w\d+[dhmyw]|\d+[dhmy]w)\b/iu', '$1 $2', $title) ?? $title;
        $title = preg_replace('/\b(\d+)(Million|Billion)\b/iu', '$1 $2', $title) ?? $title;

        // 2. Remove parenthesized or bracketed supplier warranty codes like (FW), (1HW), (5DW), (20DW), (W25D), (NW)
        $title = preg_replace('/[\(\[\{]\s*(?:FW|NW|\d+\s*[HDMWY]W|W\d+\s*[HDMWY]|Full\s+Warranty|Non?\s+Warranty)\s*[\)\]\}]/iu', ' ', $title) ?? $title;

        // 3. Remove standalone supplier bot codes (FW, NW, 1HW, 24HW, 1DW, 5DW, 7DW, 20DW, 1MW, 3MW, 6MW, 12MW, W1D, W25D)
        $title = preg_replace('/\b(?:FW|NW|\d+[HDMWY]W|W\d+[HDMWY])\b/iu', ' ', $title) ?? $title;

        // 4. Expand abbreviated duration tokens into clean, search-friendly English keywords
        $durationPatterns = [
            '/\b1\s*Months?\b/iu' => '1 Month',
            '/\b([2-9]|[12]\d|3[06])\s*Months?\b/iu' => '$1 Months',
            '/\b1\s*M\b(?!\s*(?:Tokens?|Billion|Million)\b)/iu' => '1 Month',
            '/\b(2|3|4|5|6|9|12|18|24|36)\s*M\b(?!\s*(?:Tokens?|Billion|Million)\b)/iu' => '$1 Months',
            '/\b1\s*Years?\b/iu' => '1 Year',
            '/\b([2-9]|10)\s*Years?\b/iu' => '$1 Years',
            '/\b1\s*Y(?:rs?)?\b/iu' => '1 Year',
            '/\b([2-9]|10)\s*Y(?:rs?)?\b/iu' => '$1 Years',
            '/\b1\s*Days?\b/iu' => '1 Day',
            '/\b([2-9]|[1-9]\d|[1-3]\d{2})\s*Days?\b/iu' => '$1 Days',
            '/\b1\s*D\b/iu' => '1 Day',
            '/\b(2|3|5|7|10|14|15|20|25|28|30|45|60|90|180|365)\s*D\b(?!\s*(?:Model|Render|Design|Animation|Print|Studio|Max|Object|Asset|Graphics))/iu' => '$1 Days',
        ];
        foreach ($durationPatterns as $pattern => $replacement) {
            $title = preg_replace($pattern, $replacement, $title) ?? $title;
        }

        // 5. Expand supplier shorthand words and normalize brand/acronym casing
        $brandNormalizations = [
            '/\bAcc\b/iu' => 'Account',
            '/\bChatgpt\b/iu' => 'ChatGPT',
            '/\bGpt\b/iu' => 'GPT',
            '/\bOpenai\b/iu' => 'OpenAI',
            '/\bVpn\b/iu' => 'VPN',
            '/\bApi\b/iu' => 'API',
            '/\bAi\b/iu' => 'AI',
            '/\bIdm\b/iu' => 'IDM',
            '/\bRdp\b/iu' => 'RDP',
            '/\bSeo\b/iu' => 'SEO',
            '/\bYoutube\b/iu' => 'YouTube',
            '/\bLinkedin\b/iu' => 'LinkedIn',
            '/\bCapcut\b/iu' => 'CapCut',
            '/\bElevenlabs\b/iu' => 'ElevenLabs',
            '/\bHeygen\b/iu' => 'HeyGen',
            '/\bQuillbot\b/iu' => 'QuillBot',
            '/\bN8n\b/iu' => 'n8n',
        ];
        foreach ($brandNormalizations as $pattern => $replacement) {
            $title = preg_replace($pattern, $replacement, $title) ?? $title;
        }

        // 6. Clean up empty parentheses, trailing dashes/pipes, and multiple spaces
        $title = preg_replace('/\(\s*\)|\[\s*\]/', ' ', $title) ?? $title;
        $title = preg_replace('/\s+[-|\/]\s*$/u', '', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);

        return $title !== '' ? $title : trim($rawTitle);
    }

    /**
     * Removes parenthesized bot codes like "(FW)", "(1HW)", "(5DW)", "(20DW)", "(NW)" from public SEO snippets.
     */
    public static function stripBotCodesFromCopy(string $text): string
    {
        $clean = preg_replace('/\s*\((?:FW|NW|\d+[HDMWY]W|W\d+[HDMWY])\)/iu', '', $text) ?? $text;
        $clean = preg_replace('/\b(?:1HW|24HW|48HW|1DW|3DW|5DW|7DW|14DW|20DW|25DW|30DW|1MW|3MW|6MW|12MW|NW|W\d+[HDMWY])\b/iu', '', $clean) ?? $clean;

        return preg_replace('/\s+/u', ' ', trim($clean)) ?: trim($text);
    }

    public static function stripEmDashes(string $text): string
    {
        $clean = str_replace(
            ["\u{2014}", "\u{2013}", "\u{2015}", '—', '–', '―', '═══════════════════', "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"],
            [' - ', ' - ', ' - ', ' - ', ' - ', ' - ', '', '', '', '', ''],
            $text
        );

        return trim(preg_replace('/\s+-\s+-\s+/', ' - ', $clean) ?? $clean);
    }

    /**
     * Dynamic fallback optimizer for any new product added in the future via Admin Panel.
     *
     * @return array{short_description: string, description: string, warranty: string, meta_title: string, meta_description: string, meta_keywords: string}
     */
    public static function buildDynamicSeoFields(Product $product): array
    {
        $name = static::cleanProductTitle((string) $product->name) ?: 'Digital Tool';
        $brand = $product->brandName();
        $categoryName = trim((string) ($product->category?->name ?: 'Digital Subscription'));

        $rawSummary = $product->cleanSummaryText();
        $rawSummary = static::stripBotCodesFromCopy(static::cleanRawSupplierText($rawSummary));

        $shortDesc = $rawSummary !== ''
            ? Str::limit($rawSummary, 165, '.')
            : "Buy genuine {$name} subscription online with instant automated delivery. Pay securely via Easypaisa, JazzCash, NayaPay, SadaPay, or Binance USDT.";

        $rawWarranty = Product::normalizeUnicodeText((string) $product->warranty);
        $cleanWarranty = static::cleanRawSupplierText($rawWarranty);
        if ($cleanWarranty === '' || strtolower($cleanWarranty) === 'no' || strtolower($cleanWarranty) === 'non') {
            $cleanWarranty = 'Activation & login verification support included. Please check your credentials immediately upon delivery.';
        }

        $hasHtml = str_contains((string) $product->description, '<h3>') || str_contains((string) $product->description, '<ul>');
        if ($hasHtml) {
            $descriptionHtml = static::stripEmDashes(Product::normalizeUnicodeText((string) $product->description));
        } else {
            $bodyText = static::cleanRawSupplierText(Product::normalizeUnicodeText((string) ($product->description ?: $shortDesc)));
            $safeName = e($name);
            $safeBody = e($bodyText);
            $safeWarranty = e($cleanWarranty);
            $descriptionHtml = <<<HTML
<h3>Buy {$safeName} Online at the Best Price</h3>
<p>{$safeBody}</p>
<h3>Key Features &amp; Delivery Highlights</h3>
<ul>
<li><strong>Authentic Access:</strong> Verified {$safeName} digital subscription or activation credentials ready for immediate use.</li>
<li><strong>Instant Order Vault Delivery:</strong> Receive your login details, redeem link, or license key directly on your private order tracking page right after payment confirmation.</li>
<li><strong>Flexible Local &amp; Global Checkout:</strong> Pay in PKR via Easypaisa, JazzCash, NayaPay, SadaPay, and Bank Transfer (Raast), or globally via Binance Pay and USDT.</li>
<li><strong>Warranty &amp; Support:</strong> {$safeWarranty}</li>
</ul>
HTML;
        }

        $metaTitle = "Buy {$name} | Best Price in Pakistan";
        if (mb_strlen($metaTitle) > 58) {
            $metaTitle = "Buy {$name} | Instant Delivery";
        }
        if (mb_strlen($metaTitle) > 58) {
            $metaTitle = Str::limit("Buy {$name}", 58, '');
        }

        $metaDesc = Str::limit(
            "Buy {$name} online at the lowest price in Pakistan and worldwide. {$shortDesc} Instant delivery via Easypaisa, JazzCash & USDT.",
            152,
            '...'
        );

        $metaKeywords = implode(', ', array_unique(array_filter([
            "buy {$name}",
            "buy {$name} online",
            "{$name} price in Pakistan",
            "cheap {$name} subscription",
            "genuine {$name} account",
            "{$name} instant delivery",
            "{$brand} subscription price in Pakistan",
            "buy {$brand} subscription online",
            "buy {$name} with Easypaisa JazzCash",
            "buy {$name} USDT Binance",
            "{$categoryName} Pakistan",
            'SellMeTools',
        ])));

        return [
            'short_description' => $shortDesc,
            'description' => $descriptionHtml,
            'warranty' => $cleanWarranty,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDesc,
            'meta_keywords' => $metaKeywords,
        ];
    }

    public static function cleanRawSupplierText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = static::stripEmDashes($text);
        $text = preg_replace('/[\*\_\~`]+/', ' ', $text) ?? $text;
        $text = str_ireplace(
            ['Fotmat:', 'Warrenty :', 'Warrenty:', 'Fulll Warranty', 'FW HOLS WARRANTY:', '24-HOURS-WARRANT', 'coderedeem', 'monthget', 'accessgrok', 'includedduration', 'dayswarranty', 'daysformat:'],
            ['Format:', 'Warranty:', 'Warranty:', 'Full Warranty', 'Full Hold Warranty:', '24-Hour Warranty.', 'code. Redeem', 'month. Get', 'access. Grok', 'included. Duration:', 'days. Warranty:', 'days. Format:'],
            $text
        );
        $text = preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text;

        return trim($text);
    }

    /**
     * Hand-crafted, keyword-rich SEO descriptions and clean warranty terms for all 76+ store products.
     * Zero em dashes or en dashes are used anywhere.
     *
     * @return array<string, array{short_description: string, description: string, warranty: string, meta_title: string, meta_description: string, meta_keywords: string}>
     */
    public static function curatedCatalog(): array
    {
        $items = [
            'amazon-prime-video-6-months' => [
                'short' => 'Buy Amazon Prime Video 6 Months private account with full email access. Stream 4K UHD movies, series, and Prime Originals on your favorite devices.',
                'overview' => 'Enjoy 6 months of uninterrupted streaming with a private Amazon Prime Video 6 Months subscription at the best price in Pakistan and worldwide. You receive a ready-made private account with full email access so you can watch Prime Originals, blockbuster movies, and live sports on Smart TV, mobile, laptop, or tablet.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 6 Months active Amazon Prime Video private account.',
                    '<strong>Delivery Format:</strong> Ready-made account with full email access included.',
                    '<strong>Streaming Quality:</strong> Supports Full HD and 4K Ultra HD streaming on compatible devices.',
                    '<strong>Instant Delivery:</strong> Login credentials are delivered automatically to your order page after payment verification.',
                ],
                'warranty' => '5-Day Replacement Warranty. Format: Full Mail Access included. Please follow the account usage instructions to maintain warranty coverage.',
                'kw' => 'Amazon Prime Video 6 months price in Pakistan, buy Amazon Prime Video cheap subscription, Prime Video private account Pakistan, Amazon Prime Easypaisa JazzCash',
            ],
            'amazon-prime-video-1month' => [
                'short' => 'Get a private Amazon Prime Video 1 Month subscription at a budget-friendly price. Watch movies, TV shows, and exclusive originals anytime.',
                'overview' => 'Stream your favorite movies, web series, and exclusive Amazon Originals with this private Amazon Prime Video 1 Month subscription. Designed for viewers who want affordable monthly entertainment with instant digital delivery via local PKR wallets or USDT.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 1 Month (30 Days) private Amazon Prime Video access.',
                    '<strong>Multi-Device Support:</strong> Watch on Smart TVs, Android, iOS, Fire Stick, and web browsers.',
                    '<strong>Instant Vault Delivery:</strong> Receive your login details directly on your order page.',
                ],
                'warranty' => '24-Hour (1-Day) Replacement Warranty from delivery.',
                'kw' => 'Amazon Prime Video 1 month price in Pakistan, buy Prime Video 1 month online, cheap Amazon Prime subscription Pakistan',
            ],
            'capcut-pro-6months-fw' => [
                'short' => 'Buy CapCut Pro 6 Months subscription with Full Warranty (FW) and complete email access. Unlock 4K exports, AI video editing tools, and Pro templates.',
                'overview' => 'Upgrade your content creation workflow with a genuine CapCut Pro 6 Months individual subscription backed by Full Warranty (FW). You receive a ready-made CapCut Pro account with full email access, allowing you to update account details and enjoy watermark-free 4K 60fps exports, AI auto-captions, background removal, and all premium effects.',
                'bullets' => [
                    '<strong>Plan &amp; Duration:</strong> 6 Months active CapCut Pro subscription with Full Warranty (FW).',
                    '<strong>Full Email Access:</strong> Complete email and CapCut login credentials provided (you can update account details after delivery).',
                    '<strong>Pro Creator Features:</strong> Unlimited Pro templates, keyframes, smooth slow-mo, AI cutout, and 4K 60fps exports without watermarks.',
                    '<strong>Cross-Platform Use:</strong> Works seamlessly on CapCut Desktop (Windows &amp; macOS) and Mobile (iOS &amp; Android).',
                ],
                'warranty' => 'Full 6-Month Subscription Warranty (FW). Full email access is included.',
                'kw' => 'CapCut Pro 6 months price in Pakistan, buy CapCut Pro subscription online, CapCut Pro full warranty account, CapCut Pro Easypaisa JazzCash',
            ],
            'capcut-pro-1-year-fw' => [
                'short' => 'Get CapCut Pro 1 Year (12 Months) individual subscription with Full Warranty (FW) and changeable email access. Unlock all Pro AI tools and 4K exports.',
                'overview' => 'Save big on annual video editing with the CapCut Pro 1 Year (12 Months) Full Warranty subscription. Ideal for YouTubers, TikTok creators, and video editors who need 12 full months of uninterrupted access to CapCut Pro templates, AI effects, cloud storage, and 4K 60fps watermark-free rendering.',
                'bullets' => [
                    '<strong>Plan &amp; Duration:</strong> 12 Months (1 Year) individual CapCut Pro access.',
                    '<strong>Changeable Email:</strong> Full access provided so you can change the email address after purchase.',
                    '<strong>All Pro Features Unlocked:</strong> Premium transitions, AI captions, vocal isolation, color grading, and 4K exports.',
                    '<strong>Full Warranty Protection:</strong> Covered by our 12-month Full Warranty (FW) guarantee.',
                ],
                'warranty' => 'Full 12-Month Subscription Warranty (FW). Email can be changed after delivery.',
                'kw' => 'CapCut Pro 1 year price in Pakistan, buy CapCut Pro yearly subscription, CapCut Pro 12 months full warranty, cheap CapCut Pro Pakistan',
            ],
            'capcut-pro-1month-fw' => [
                'short' => 'Buy CapCut Pro 1 Month individual subscription with Full Warranty (FW), 650 AI credits, and full email access for 1 Mobile + 1 PC.',
                'overview' => 'Level up your short-form and long-form video editing with a CapCut Pro 1 Month Individual Subscription. This package includes 29 to 30 days of guaranteed Pro access, 650 AI credits for generative tools, and full email login credentials supporting up to 2 devices simultaneously (1 Mobile + 1 Desktop/PC).',
                'bullets' => [
                    '<strong>Duration &amp; Credits:</strong> 29 to 30 Days guaranteed validity with 650 AI Credits included.',
                    '<strong>Device Limit:</strong> Supports up to 2 devices simultaneously (1 Mobile + 1 PC/Mac).',
                    '<strong>Delivery Format:</strong> Email | CapCut Password | Email Password (full email access included).',
                    '<strong>Pro Editing Suite:</strong> 4K 60fps watermark-free exports, Auto Captions, Smart Cutout, Background Removal, and Noise Reduction.',
                ],
                'warranty' => 'Full 1-Month Subscription Warranty (FW) for up to 2 devices (1 Mobile + 1 PC).',
                'kw' => 'CapCut Pro 1 month price in Pakistan, buy CapCut Pro monthly subscription, CapCut Pro 650 AI credits, CapCut Pro Pakistan Easypaisa',
            ],
            'capcut-pro-7d-500-credit' => [
                'short' => 'Get CapCut Pro 7 Days subscription loaded with 500 AI Credits and Full Warranty. Ideal for quick video projects, AI generations, and 4K exports.',
                'overview' => 'Need CapCut Pro and AI generation credits for a week-long project? The CapCut Pro 7 Days + 500 Credits plan gives you immediate ready-made account access (`Email | Password`) with full 6 to 7 days warranty coverage.',
                'bullets' => [
                    '<strong>Duration &amp; Credits:</strong> 6 to 7 Days active CapCut Pro plan with 500 AI Credits included.',
                    '<strong>Delivery Format:</strong> Ready-made account (`Email | Password`) delivered instantly.',
                    '<strong>Full Pro Access:</strong> Export 4K watermark-free videos and use premium AI editing features.',
                ],
                'warranty' => 'Full 7-Day Subscription Warranty.',
                'kw' => 'CapCut Pro 7 days price in Pakistan, CapCut Pro 500 AI credits, buy CapCut Pro weekly trial Pakistan',
            ],
            'chatgpt-plus-1m-1hw' => [
                'short' => 'Buy ChatGPT Plus 1 Month ready-made account (Email | Password | 2FA) with 1-Hour Login Warranty. Access GPT-4o, o1/o3 reasoning, and DALL-E.',
                'overview' => 'Get instant access to OpenAI ChatGPT Plus 1 Month with a private ready-made account (`Email | Password | 2FA`). Unlock advanced AI reasoning models, voice mode, image generation, file analysis, and faster response speeds at a fraction of the official cost.',
                'bullets' => [
                    '<strong>Plan Details:</strong> ChatGPT Plus 1 Month ready-made subscription account.',
                    '<strong>Delivery Format:</strong> `Email | Password | 2FA` credentials delivered to your order page.',
                    '<strong>Important Login Rule:</strong> This is a 1-Hour Login Warranty (1HW) product. Please log in and verify your account within 1 hour of delivery.',
                ],
                'warranty' => '1-Hour Login Warranty (1HW) only. Format: Email | Password | 2FA. You must verify login and report any login issue within 1 hour of delivery. No replacement or refund applies after the 1-hour login window.',
                'kw' => 'ChatGPT Plus 1 month price in Pakistan, buy ChatGPT Plus cheap account, ChatGPT Plus Pakistan Easypaisa JazzCash, OpenAI Plus subscription',
            ],
            'chatgpt-plus-1month-5dw' => [
                'short' => 'Buy ChatGPT Plus 1 Month ready-made account with 5-Day Warranty (5DW). Includes Email, Password, and 2FA access for ChatGPT web and app.',
                'overview' => 'Unlock OpenAI flagship models with a ChatGPT Plus 1 Month (5-Day Warranty) ready-made account. You receive `Email | ChatGPT Password | 2FA` credentials for direct ChatGPT login (iCloud email login is not included, and you can update the email to your own).',
                'bullets' => [
                    '<strong>Plan &amp; Coverage:</strong> ChatGPT Plus 1 Month account backed by a 5-Day Warranty (5DW).',
                    '<strong>Delivery Format:</strong> `Email | ChatGPT Password | 2FA` (ChatGPT login only; no iCloud mail inbox access).',
                    '<strong>Usage Policy:</strong> Standard ChatGPT Plus usage is covered for 5 days. Codex API/heavy automated abuse is not covered under warranty.',
                ],
                'warranty' => '5-Day Warranty (5DW). Format: Email | ChatGPT Password | 2FA (no mail inbox access; you may change the email to your own). If an issue occurs within 5 days and replacement stock is unavailable, a pro-rata refund based on remaining warranty days is provided.',
                'kw' => 'ChatGPT Plus 5 days warranty price in Pakistan, buy ChatGPT Plus 1 month online, cheap ChatGPT Plus subscription Pakistan',
            ],
            'coursera-premium-12month-1dw' => [
                'short' => 'Buy Coursera Premium 12 Months (Org+ Premium) account with 24-Hour Warranty. Access thousands of accredited courses and certificates.',
                'overview' => 'Advance your career with 12 months of Coursera Premium (Organization+ Plan) access. You receive full Coursera login credentials (`Email | Password | Coursera 2FA Key`) to enroll in top university and company courses, specializations, and professional certificates.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 12 Months Coursera Org+ Premium access.',
                    '<strong>Delivery Format:</strong> `Email | Coursera Password | 2FA` (Coursera platform access only; Gmail inbox access is not included).',
                    '<strong>Easy Setup:</strong> Log in to Coursera using the provided credentials and link your profile. Do not change the primary account email.',
                ],
                'warranty' => '24-Hour (1-Day) Login & Activation Warranty. Format: Email | Coursera Password | 2FA (no Gmail inbox access). Do not change the primary email address.',
                'kw' => 'Coursera Plus 12 months price in Pakistan, buy Coursera Premium 1 year cheap, Coursera Org+ subscription Pakistan',
            ],
            'elevenlabs-creator-3m-nw' => [
                'short' => 'Get ElevenLabs Creator Plan 3 Months ready-made account with full email access and 131,000 monthly AI voice generation credits.',
                'overview' => 'Produce studio-quality AI voiceovers, dubbing, and voice clones with an ElevenLabs Creator 3-Month ready-made account. Each month includes 131,000 character credits and full email access so you can start generating ultra-realistic speech immediately without self-signup hassle.',
                'bullets' => [
                    '<strong>Plan &amp; Duration:</strong> 3-Month ElevenLabs Creator Plan (New Ready-Made Account).',
                    '<strong>Monthly Credits:</strong> 131,000 (131K) credits per month for high-bitrate AI speech and voice cloning.',
                    '<strong>Full Mail Access:</strong> Ready-made login credentials with complete email inbox access included.',
                ],
                'warranty' => 'Login Verification Warranty (NW - Non Warranty after first successful login). Format: Ready-made account with full email access and 131K monthly credits.',
                'kw' => 'ElevenLabs Creator plan price in Pakistan, buy ElevenLabs 3 months subscription, AI voice generator subscription Pakistan',
            ],
            'figma-edu-2year-1mw' => [
                'short' => 'Buy Figma Education Plan 2 Years ready-made account with 1-Month Warranty. Unlock unlimited Figma files, Dev Mode, and team libraries.',
                'overview' => 'Design and prototype without limits using a pre-activated Figma Education Plan valid for 2 Years (24 Months). You receive a ready-made account with all professional Figma Education features, unlimited projects, and Dev Mode unlocked from day one.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 2 Years (24 Months) pre-activated Figma Education Pro features.',
                    '<strong>Ready-to-Use Account:</strong> No student ID verification needed on your end; log in and start designing right away.',
                    '<strong>Warranty Coverage:</strong> Backed by a 1-Month (30-Day) replacement warranty.',
                ],
                'warranty' => '1-Month (30-Day) Replacement Warranty. Format: Pre-activated ready-made Figma Education account.',
                'kw' => 'Figma Pro price in Pakistan, buy Figma Education 2 years account, cheap Figma subscription Pakistan',
            ],
            'gemini-pro-3m-link' => [
                'short' => 'Buy Google Gemini AI Pro 3-Month official redeem link with 5TB Google One cloud storage and Gemini Advanced + Antigravity access.',
                'overview' => 'Activate Google Gemini AI Pro for 3 Months on your own Google account using an official redemption link. Includes 5TB of Google One cloud storage, Gemini Advanced models, and Antigravity (Argon) support.',
                'bullets' => [
                    '<strong>Official Redeem Link:</strong> 3-Month Google Gemini AI Pro + 5TB Cloud Storage redemption link.',
                    '<strong>Card Required for Mandate:</strong> A valid payment card is required by Google during activation verification.',
                    '<strong>Single-Attempt Rule:</strong> One link equals one redemption attempt. Only open the link on the exact Google account you intend to upgrade.',
                ],
                'warranty' => '3-Day Link Hold & Validity Warranty. One link is valid for one redemption attempt only; do not test the link on random or ineligible emails.',
                'kw' => 'Gemini Advanced 3 months price in Pakistan, buy Google Gemini Pro redeem link, Gemini 5TB storage subscription Pakistan',
            ],
            'gemini-plus-12months-links' => [
                'short' => 'Get a 12-Month Google Gemini Plus official redemption link with 400GB cloud storage, Family Sharing (up to 5 users), and 250 monthly Flow credits.',
                'overview' => 'Upgrade your personal Google account with a 12-Month Gemini Plus official redemption link. Enjoy 1 full year of Gemini AI access, 400GB of Google Cloud Storage, 250 monthly Google Flow credits, and Family Sharing support for up to 5 members.',
                'bullets' => [
                    '<strong>What is Included:</strong> 12-Month Gemini Plus subscription, 400GB Google storage, 250 monthly Flow credits, and Family Sharing (up to 5 accounts).',
                    '<strong>Activation Steps:</strong> Connect to an Indian VPN/proxy, set your Google Payments profile country to India, add a valid debit/credit card, and claim the offer.',
                    '<strong>Important Single-Use Policy:</strong> Each link is valid for one redemption attempt only. If you see "This account is not eligible", stop immediately and use a fresh Google account before clicking subscribe.',
                ],
                'warranty' => '12-Month Subscription Validity after activation. Note: Single-attempt redemption link; links consumed on ineligible test accounts are not eligible for free replacement.',
                'kw' => 'Gemini Plus 12 months link price in Pakistan, buy Google Gemini 1 year subscription, Gemini Plus 400GB storage Pakistan',
            ],
            'muse-ai-31b-token-account' => [
                'short' => 'Buy Muse AI 31 Billion Token ready-made account with email access. High-capacity AI token balance for text, coding, and creative workflows.',
                'overview' => 'Power your heavy AI generation and automation tasks with a Muse AI 31 Billion (31B) Token ready-made account. Delivered with email login access so you can start utilizing your 31B token balance right after checkout.',
                'bullets' => [
                    '<strong>Token Balance:</strong> 31 Billion (31B) tokens pre-loaded.',
                    '<strong>Delivery Format:</strong> Ready-made account with mail/email login credentials.',
                    '<strong>Instant Delivery:</strong> Dispatched automatically to your order page after payment verification.',
                ],
                'warranty' => 'Login Verification Warranty (Non-Warranty after initial login verification). Format: Ready-made 31B token account.',
                'kw' => 'Muse AI 31B token account price in Pakistan, buy Muse AI tokens online, cheap AI token account Pakistan',
            ],
            'muse-ai-account-1-billion-token' => [
                'short' => 'Get a ready-made Muse AI 1 Billion Token account at a pocket-friendly price with instant automated delivery.',
                'overview' => 'Start testing and running AI prompts immediately with a pre-loaded Muse AI 1 Billion (1B) Token ready-made account. Simply log in with the delivered account credentials and use your 1 billion token balance right away.',
                'bullets' => [
                    '<strong>Token Balance:</strong> 1 Billion (1B) tokens included.',
                    '<strong>Delivery Format:</strong> Ready-made login credentials delivered on screen.',
                    '<strong>Unbeatable Value:</strong> Ultra-affordable entry price with instant PKR and USDT checkout.',
                ],
                'warranty' => 'Login Verification Warranty (Non-Warranty after first login). Format: Ready-made 1 Billion Token account.',
                'kw' => 'Muse AI 1 billion token price in Pakistan, buy cheap AI token account, Muse AI login Pakistan',
            ],
            'notion-business-3m' => [
                'short' => 'Buy Notion Business 3 Months official activation link and upgrade your personal Notion workspace with Notion AI and advanced team features.',
                'overview' => 'Unlock the full productivity power of Notion Business for 3 Months on your own Notion account. You receive an official activation link that upgrades your workspace with unlimited file uploads, SAML SSO, private teamspaces, and advanced page analytics.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 3 Months Notion Business workspace upgrade.',
                    '<strong>Self-Activation Link:</strong> Activate directly on your own Notion account (a valid payment card is required by Notion during activation).',
                    '<strong>Instant Delivery:</strong> Activation link delivered immediately after payment.',
                ],
                'warranty' => 'Activation Link Validity Warranty (Non-Warranty after successful activation on your workspace).',
                'kw' => 'Notion Business 3 months price in Pakistan, buy Notion Pro activation link, Notion subscription Pakistan',
            ],
            'outlook-ready-mails' => [
                'short' => 'Buy stable, ready-made Microsoft Outlook email accounts (Mail | Password | Inbox Link) with instant automated delivery.',
                'overview' => 'Need clean, reliable Microsoft Outlook email accounts for registrations, verifications, or business workflows? Each unit includes a stable ready-made Outlook address, password, and direct web inbox access link.',
                'bullets' => [
                    '<strong>Delivery Format:</strong> `Email | Password | Inbox Access Link` delivered automatically.',
                    '<strong>High Stability:</strong> Verified, stable Outlook accounts ready for immediate login.',
                    '<strong>Bulk Friendly:</strong> Order single or multiple units with instant order vault delivery.',
                ],
                'warranty' => 'Initial Login Replacement Guarantee for unused accounts.',
                'kw' => 'buy Outlook mail accounts Pakistan, ready made Outlook emails price, Hotmail Outlook accounts instant delivery',
            ],
            'super-duolingo-12m-redeem-link' => [
                'short' => 'Buy Super Duolingo 12 Months official redeem link and upgrade your own Duolingo account for 1 year with no payment card required.',
                'overview' => 'Learn languages faster with a 12-Month Super Duolingo (formerly Duolingo Plus) official promo redeem link. Activate directly on your personal Duolingo account for 1 full year of ad-free learning, unlimited hearts, personalized practice, and mastery quizzes without needing a credit or debit card.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 12 Months (1 Year) Super Duolingo membership on your own account.',
                    '<strong>No Card Required:</strong> Redeem directly via link without entering any bank card.',
                    '<strong>Activation Tip:</strong> Connect to an Indian VPN (India IP) while opening and redeeming the activation link.',
                ],
                'warranty' => 'Redeem Link Validity Warranty (Non-Warranty after activation). Connect to an Indian VPN during activation.',
                'kw' => 'Super Duolingo 12 months price in Pakistan, buy Duolingo Plus 1 year redeem link, cheap Super Duolingo Pakistan',
            ],
            'surfshark-2m-account-fw' => [
                'short' => 'Buy Surfshark VPN 2 Months ready-made account with Full Warranty (FW), temp mail access, and unlimited simultaneous device connections.',
                'overview' => 'Protect your privacy and unblock global streaming libraries with a Surfshark VPN 2 Months ready-made account backed by Full Warranty (FW). Includes temporary mail access for convenient login verification and supports unlimited device connections during the subscription period.',
                'bullets' => [
                    '<strong>Plan &amp; Warranty:</strong> 2 Months active Surfshark VPN account with Full Subscription Warranty.',
                    '<strong>Temp Mail Access Included:</strong> Manage login approvals and device invites easily.',
                    '<strong>High-Speed Global Servers:</strong> 3,200+ RAM-only servers across 100 countries with CleanWeb ad-blocking.',
                ],
                'warranty' => 'Full 2-Month Subscription Warranty (FW) with Temp Mail access included.',
                'kw' => 'Surfshark VPN 2 months price in Pakistan, buy Surfshark VPN account online, cheap VPN subscription Pakistan',
            ],
            'surfshark-vpn-2m-coupon' => [
                'short' => 'Get an official Surfshark VPN 2 Months coupon code with Full Warranty and redeem it directly on your own email account.',
                'overview' => 'Activate 2 months of Surfshark VPN on your own personal email account using this official Surfshark 2-Month Coupon Code. Enjoy military-grade encryption, NoBorders mode, and unlimited devices on your private account.',
                'bullets' => [
                    '<strong>Official Coupon Code:</strong> Redeemable on your own email address at Surfshark checkout (0.00 checkout verification required).',
                    '<strong>Unlimited Devices:</strong> Use on Windows, macOS, Android, iOS, Smart TVs, and browser extensions.',
                    '<strong>Full Warranty:</strong> Covered by our Full Warranty guarantee.',
                ],
                'warranty' => 'Full 2-Month Subscription Warranty.',
                'kw' => 'Surfshark VPN coupon code price in Pakistan, buy Surfshark 2 months voucher, Surfshark VPN Pakistan Easypaisa',
            ],
            'warp-build-12m' => [
                'short' => 'Buy Warp Build 12 Months official coupon code for new and existing accounts. Unlock Warp AI terminal and pro developer workflows for 1 year.',
                'overview' => 'Supercharge your command-line productivity with a Warp Build 12 Months official coupon code. Works on both new and existing Warp accounts to unlock Warp AI agent mode, shared workflows, and Warp Drive team features for a full year.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 12 Months (1 Year) Warp Build plan via official coupon code.',
                    '<strong>Account Compatibility:</strong> Valid for both new and existing personal Warp accounts (payment method verification required at Warp activation).',
                    '<strong>Built for Developers:</strong> Modern Rust-based AI terminal for macOS, Linux, and Windows.',
                ],
                'warranty' => 'Coupon Validity Warranty up to redemption (Non-Warranty after successful activation on your Warp account).',
                'kw' => 'Warp Build 12 months price in Pakistan, buy Warp AI terminal coupon, Warp Pro subscription Pakistan',
            ],
            'wispr-flow-pro-12m-nw' => [
                'short' => 'Buy Wispr Flow Pro 12 Months official coupon code for new or existing accounts. Unlock unlimited AI voice dictation on Mac, Windows, and iOS.',
                'overview' => 'Type 4x faster across every application with a Wispr Flow Pro 12 Months official coupon code. Redeem directly on your own new or existing Wispr Flow account to unlock unlimited AI-powered voice-to-text dictation, auto-edits, and custom vocabulary for 1 full year.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 12 Months (1 Year) Wispr Flow Pro subscription.',
                    '<strong>Official Coupon Code:</strong> Redeem on your own email (works on both new and existing accounts).',
                    '<strong>All Pro Features Unlocked:</strong> Unlimited dictation words, AI command mode, and multi-language support.',
                ],
                'warranty' => 'Coupon Code Redemption Guarantee (Non-Warranty after activation on your personal account).',
                'kw' => 'Wispr Flow Pro 12 months price in Pakistan, buy Wispr Flow yearly coupon, AI voice dictation software subscription',
            ],
            'youtube-premium-admin-1m-fw' => [
                'short' => 'Buy YouTube Premium 1 Month Family Admin account with Full Gmail Access and Full Warranty. Invite up to 5 family members for ad-free YouTube & Music.',
                'overview' => 'Enjoy ad-free YouTube, background play, offline downloads, and YouTube Music Premium with a YouTube Premium 1 Month Family Admin Account. You receive full Gmail login access and can invite up to 5 additional family members to share the Premium benefits.',
                'bullets' => [
                    '<strong>Family Admin Plan:</strong> 1 Month YouTube Premium Admin account supporting up to 5 family group members.',
                    '<strong>Full Gmail Access:</strong> Complete Google/Gmail credentials provided.',
                    '<strong>Full Warranty (FW):</strong> Covered for the entire 1-month subscription period.',
                ],
                'warranty' => 'Full 1-Month Subscription Warranty (FW). Plan: Family Admin (up to 5 users) with Full Gmail Access.',
                'kw' => 'YouTube Premium 1 month family admin price in Pakistan, buy YouTube Premium Pakistan, YouTube Music Premium cheap subscription',
            ],
            'ai-ml-by-apna-college' => [
                'short' => 'Get the complete Apna College Prime AI & Machine Learning Course (40GB, Urdu/Hindi) with lifetime direct access and Full Warranty.',
                'overview' => 'Master Artificial Intelligence and Machine Learning from scratch with the complete Apna College Prime AI/ML Course in Urdu/Hindi. Covering Python, data preparation, supervised and unsupervised learning, deep learning, and real-world portfolio projects across 40GB of HD video lectures and resources.',
                'bullets' => [
                    '<strong>Course Content:</strong> Complete 40GB Apna College Prime AI &amp; Machine Learning curriculum.',
                    '<strong>Language:</strong> Easy-to-follow Urdu / Hindi instruction with practical coding projects.',
                    '<strong>Delivery:</strong> Direct cloud access link delivered on your order tracking page.',
                ],
                'warranty' => 'Full Lifetime Access Warranty.',
                'kw' => 'Apna College AI ML course price in Pakistan, buy Apna College Prime AI Machine Learning course Urdu Hindi',
            ],
            'office365-lifetime-plan' => [
                'short' => 'Buy Microsoft Office 365 Organization Account compatible with Windows, macOS, iOS, Android, and Web apps at a one-time price.',
                'overview' => 'Get access to authentic Microsoft Office 365 apps including Word, Excel, PowerPoint, Outlook, and OneNote with a pre-made Organization Account. Works across both web browsers and official desktop/mobile Office applications.',
                'bullets' => [
                    '<strong>Supported Platforms:</strong> Official Desktop apps (Windows &amp; Mac), Mobile apps (iOS &amp; Android), and Office Web.',
                    '<strong>Delivery Format:</strong> Pre-made organization login (`Email | Password`) delivered instantly.',
                    '<strong>One-Time Checkout:</strong> No recurring monthly billing required.',
                ],
                'warranty' => 'Login & Initial Setup Verification Warranty (Non-Warranty after activation).',
                'kw' => 'Microsoft Office 365 lifetime price in Pakistan, buy Office 365 account cheap, MS Office desktop license Pakistan',
            ],
            'microsoft-office-365-plus-1-year' => [
                'short' => 'Buy Microsoft Office 365 Plus 1 Year (1 Month + 11 Months Gift) subscription for up to 5 Windows PCs with Word, Excel, PowerPoint & OneNote.',
                'overview' => 'Equip your computers with genuine Microsoft Office 365 Plus for 1 Year (1 Month + 11 Months Gift Subscription). Includes full access to Microsoft Word, Excel, PowerPoint, OneNote, and Forms for up to 5 Windows PCs with instant digital delivery.',
                'bullets' => [
                    '<strong>Included Apps:</strong> Microsoft Word, Excel, PowerPoint, OneNote, and Forms.',
                    '<strong>Device Support:</strong> Up to 5 Windows PCs supported.',
                    '<strong>Delivery Format:</strong> `Email : Password` delivered immediately after payment confirmation.',
                ],
                'warranty' => '1-Month Replacement Warranty.',
                'kw' => 'Microsoft Office 365 1 year price in Pakistan, buy MS Office 365 Plus 5 PCs, cheap Office 365 subscription Pakistan',
            ],
            'ms365-admin-1-year-family-fw' => [
                'short' => 'Buy Microsoft 365 Family Admin 1 Year Account with Full Warranty (FW). Full admin access allowing you to invite up to 5 personal emails.',
                'overview' => 'Get a complete Microsoft 365 Family Admin 1 Year subscription backed by a Full 1-Year Warranty (FW). With full admin account control, you can invite up to 5 of your own personal email addresses so each user enjoys premium Office apps and cloud features on their own account.',
                'bullets' => [
                    '<strong>Plan &amp; Duration:</strong> 1 Year (12 Months) Microsoft 365 Admin Family Account.',
                    '<strong>Invite 5 Users:</strong> Add up to 5 personal email addresses to receive full Microsoft 365 benefits.',
                    '<strong>Full 1-Year Warranty:</strong> Complete 365-day replacement protection included.',
                ],
                'warranty' => 'Full 1-Year (12-Month) Subscription Warranty (FW).',
                'kw' => 'Microsoft 365 Family 1 year admin price in Pakistan, buy MS 365 Family 5 users Pakistan, Office 365 1 year full warranty',
            ],
            'key-hma-androidpc-30days-w25d' => [
                'short' => 'Buy HMA (HideMyAss) VPN 30-Day License Key for Android & Windows PC with 25-Day Warranty and instant key delivery.',
                'overview' => 'Secure your internet connection and access geo-restricted content across 290+ global locations with a 30-Day HMA (HideMyAss) VPN License Key. Works directly inside the official HMA VPN app on Windows PC and Android devices.',
                'bullets' => [
                    '<strong>Delivery Format:</strong> Official HMA VPN activation license key.',
                    '<strong>Supported Devices:</strong> Android smartphones/tablets and Windows PCs.',
                    '<strong>Duration &amp; Warranty:</strong> 30 Days validity backed by a 25-Day Full Warranty.',
                ],
                'warranty' => '25-Day Full Replacement Warranty. Format: HMA Activation License Key.',
                'kw' => 'HMA VPN 30 days key price in Pakistan, buy HideMyAss VPN license key, cheap Android PC VPN Pakistan',
            ],
            'nordvpn-3-months-voucher-link' => [
                'short' => 'Buy NordVPN 3 Months official voucher code/link with Full 3-Month Warranty. Redeem on your own account for up to 10 devices with no card required.',
                'overview' => 'Enjoy industry-leading online privacy and blazing-fast NordLynx speeds with a NordVPN 3-Month Official Voucher. Redeem directly at `my.nordaccount.com/activate` on your personal account without needing any payment card. Multiple codes are stackable on the same account.',
                'bullets' => [
                    '<strong>100% Private &amp; Stackable:</strong> Activate on your own NordVPN account (multiple codes can be stacked to extend duration).',
                    '<strong>No Card Required:</strong> Activate cleanly at `https://my.nordaccount.com/activate` within 5 days of purchase.',
                    '<strong>Up to 10 Devices:</strong> Protect Windows, macOS, Android, iOS, Linux, and routers simultaneously.',
                ],
                'warranty' => 'Full 3-Month Warranty after activation (must be redeemed within 5 days of purchase).',
                'kw' => 'NordVPN 3 months voucher price in Pakistan, buy NordVPN activation code online, NordVPN 10 devices Pakistan',
            ],
            'nord-vpn-3months-ready-made-accounts' => [
                'short' => 'Buy a ready-made NordVPN 3 Months premium account for fast global streaming, strong encryption, and multi-device privacy.',
                'overview' => 'Connect to 6,000+ ultra-fast VPN servers worldwide with a pre-activated NordVPN 3 Months ready-made account. Ideal for streaming, secure browsing, and bypassing ISP throttling across all major devices.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 3 Months active NordVPN premium subscription.',
                    '<strong>Ready-to-Use Login:</strong> No setup or card required; sign in with the delivered credentials.',
                    '<strong>Global Server Network:</strong> High-speed servers optimized for streaming and low-latency gaming.',
                ],
                'warranty' => '24-Hour (1-Day) Login Replacement Warranty.',
                'kw' => 'NordVPN 3 months account price in Pakistan, buy NordVPN ready made account, cheap NordVPN subscription Pakistan',
            ],
            'kling-standard-1month-1000-creditsw1d' => [
                'short' => 'Buy Kling AI Standard 1 Month subscription loaded with 1,100 AI video & image generation credits and full email access.',
                'overview' => 'Create cinematic AI videos and photorealistic visuals with a Kling AI Standard 1-Month Individual Subscription pre-loaded with 1,100 (1.1K) AI Credits. Includes priority queue generations, advanced camera motion controls, and full account + email login credentials.',
                'bullets' => [
                    '<strong>Plan &amp; Credits:</strong> Kling AI Standard 1 Month with 1,100 (1.1K) AI generation credits.',
                    '<strong>Delivery Format:</strong> `Email | Password` (full account access provided).',
                    '<strong>Pro Video Generation:</strong> Priority fast-queue video rendering, extended video length, and camera movement controls.',
                ],
                'warranty' => '24-Hour (1-Day) Replacement Guarantee. Format: Email | Password with 1,100 AI credits.',
                'kw' => 'Kling AI subscription price in Pakistan, buy Kling AI 1000 credits account, AI video generator subscription Pakistan',
            ],
            'codex-api-10million-token-1day' => [
                'short' => 'Buy OpenAI Codex API 10 Million Token redeem code with 1-Day validity and Full Warranty. Ideal for intensive AI coding and API tasks.',
                'overview' => 'Get 10 Million tokens of high-speed Codex API access with an instant redeem code valid for 24 hours (1 Day) after activation. Built for developers running automated code generation, refactoring, or batch LLM workflows.',
                'bullets' => [
                    '<strong>Token Quota:</strong> 10 Million (10M) Codex API tokens.',
                    '<strong>Validity:</strong> 1 Day (24 Hours) from activation.',
                    '<strong>Instant Delivery:</strong> Redeem code delivered immediately on your order page.',
                ],
                'warranty' => 'Full 1-Day Validity Warranty.',
                'kw' => 'Codex API 10M tokens price in Pakistan, buy OpenAI Codex API key cheap, AI coding API tokens Pakistan',
            ],
            'codex-api-100million-token-3days' => [
                'short' => 'Buy Codex API 100 Million Token redeem code with Full Warranty for high-volume AI coding, agent workflows, and batch API processing.',
                'overview' => 'Scale your AI engineering sprints with a massive 100 Million (100M) Token Codex API redeem code. Designed for developers using Cursor, Cline, Roo Code, or custom API pipelines who need huge token throughput at a fraction of standard API billing.',
                'bullets' => [
                    '<strong>Token Quota:</strong> 100 Million (100M) Codex API tokens.',
                    '<strong>Validity:</strong> 2 to 3 Days high-throughput validity after activation.',
                    '<strong>Full Warranty:</strong> Backed by our Full Warranty replacement guarantee.',
                ],
                'warranty' => 'Full Validity Period Warranty.',
                'kw' => 'Codex API 100M tokens price in Pakistan, buy bulk OpenAI Codex tokens, cheap AI API key Pakistan',
            ],
            'claude-500-api-30day-fw' => [
                'short' => 'Buy Anthropic Claude $500 API Key valid for 30 Days with Full Warranty (FW). Plug directly into Cursor, Cline, or your own apps.',
                'overview' => 'Get a ready-to-use Anthropic Claude $500 credit API Key valid for 30 Days and backed by Full Warranty (FW). Connect it immediately to your favorite AI coding IDEs, agent frameworks, or custom applications.',
                'bullets' => [
                    '<strong>API Credit Value:</strong> $500 USD Claude API quota valid for 30 Days.',
                    '<strong>Ready to Use:</strong> Delivered as an active API key ready for immediate integration.',
                    '<strong>Full Warranty (FW):</strong> 30-Day Full Warranty coverage included.',
                ],
                'warranty' => 'Full 30-Day API Warranty (FW).',
                'kw' => 'Claude $500 API key price in Pakistan, buy Anthropic Claude API key cheap, Claude 3.7 Sonnet API Pakistan',
            ],
            'claude-100-api-30day-fw' => [
                'short' => 'Buy Anthropic Claude $100 API CDK/Key valid for 30 Days with Full Warranty (FW). Affordable high-speed Claude API access for developers.',
                'overview' => 'Integrate Anthropic Claude models into your coding tools and apps with a Claude $100 API CDK (convertible into an active API key) valid for 30 Days. Backed by Full Warranty (FW) and instant digital delivery.',
                'bullets' => [
                    '<strong>API Credit Value:</strong> $100 USD Claude API balance valid for 30 Days.',
                    '<strong>Delivery Format:</strong> CDK / API key ready for instant conversion and use in your projects.',
                    '<strong>Full Warranty (FW):</strong> Protected for the full 30-day validity window.',
                ],
                'warranty' => 'Full 30-Day Warranty (FW).',
                'kw' => 'Claude $100 API key price in Pakistan, buy Claude API credits online, cheap Anthropic API key Pakistan',
            ],
            'claude-api-100million-token-3d' => [
                'short' => 'Buy Claude API 100 Million Token redeem code with 3-Day validity and Full Warranty for heavy AI coding and text processing.',
                'overview' => 'Run massive AI coding and text-processing workloads with a Claude API 100 Million (100M) Token redeem code valid for 3 Days after activation. Ideal for intensive development sprints and batch inference.',
                'bullets' => [
                    '<strong>Token Quota:</strong> 100 Million (100M) Claude API tokens.',
                    '<strong>Validity:</strong> 3 Days active validity after redemption.',
                    '<strong>Full Warranty:</strong> Covered by our Full Warranty guarantee.',
                ],
                'warranty' => 'Full 3-Day Validity Warranty.',
                'kw' => 'Claude API 100M tokens price in Pakistan, buy Claude token redeem code, Anthropic API Pakistan',
            ],
            'grok-cursor-premium-1month-fw' => [
                'short' => 'Buy Grok Premium + Cursor Premium 1 Month combo account with Full Warranty (FW), Grok Bot access, and full email login credentials.',
                'overview' => 'Get two powerhouse AI tools in one bundle: Grok Premium + Cursor Pro Premium access with Grok Bot included. You receive a ready-made account (`Email | Account Password | Email Password`) with 23 to 28 days of active validity and Full Warranty protection.',
                'bullets' => [
                    '<strong>2-in-1 AI Bundle:</strong> Includes both Grok Premium (with Grok Bot) and Cursor Premium IDE access.',
                    '<strong>Delivery Format:</strong> `Email | Account Password | Mail Password` (use the provided account directly).',
                    '<strong>Duration &amp; Warranty:</strong> 23 to 28 Days active duration covered by Full Warranty (FW).',
                ],
                'warranty' => '23 to 28 Days Full Subscription Warranty (FW). Format: Email | Account Password | Mail Password (includes Grok Bot).',
                'kw' => 'Cursor Pro price in Pakistan, buy Grok Premium Cursor Premium bundle, Cursor AI IDE subscription Pakistan',
            ],
            'lovable-lite-12month-link' => [
                'short' => 'Buy Lovable Pro Lite 12 Months official gift activation link and upgrade your own Lovable AI full-stack app builder workspace for 1 year.',
                'overview' => 'Build and deploy full-stack web apps with AI using a Lovable Pro Lite 12 Months official gift activation link. Redeem the link directly on your own Lovable account and workspace for 12 months of upgraded limits.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 12 Months (1 Year) Lovable Lite Pro plan.',
                    '<strong>Self-Activation Gift Link:</strong> Activate directly on your own Lovable account/workspace.',
                    '<strong>Redeem Promptly:</strong> Do not hold the link; redeem it within 1 hour of delivery.',
                ],
                'warranty' => '1-Hour Link Hold Warranty. Please redeem the gift link on your Lovable workspace within 1 hour of delivery.',
                'kw' => 'Lovable AI Pro price in Pakistan, buy Lovable Lite 12 months gift link, AI web app builder subscription',
            ],
            'replit-core-1-year' => [
                'short' => 'Buy Replit Core 1 Year (12 Months) official coupon code. Redeem on your own new or existing Replit account to unlock Replit Agent and cloud compute.',
                'overview' => 'Upgrade your personal Replit account for 12 full months with an official Replit Core 1 Year Coupon Code. Works on both new and existing Replit accounts, unlocking Replit AI Agent, boosted cloud development machines, private deployments, and monthly compute credits.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 12 Months (1 Year) Replit Core membership.',
                    '<strong>Official Coupon Code:</strong> Redeem directly on your own email account (new or existing Replit users).',
                    '<strong>All Core Features Unlocked:</strong> Full access to Replit Agent, Ghostwriter AI, and private repls.',
                ],
                'warranty' => 'Coupon Code Redemption Guarantee (Non-Warranty after activation on your personal Replit account).',
                'kw' => 'Replit Core 1 year price in Pakistan, buy Replit Core coupon code, Replit AI Agent subscription Pakistan',
            ],
            'replit-core-1month-3dw' => [
                'short' => 'Buy Replit Core 1 Month ready-made account (Email | Password) with 3-Day Warranty (3DW). Build and deploy apps with Replit AI Agent.',
                'overview' => 'Test-drive Replit Core and Replit AI Agent for 1 month at a fraction of the official price. You receive a pre-activated Replit Core account with email and password access backed by a 3-Day Replacement Warranty (3DW).',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 1 Month Replit Core ready-made account.',
                    '<strong>Delivery Format:</strong> `Email | Password` login credentials delivered instantly.',
                    '<strong>Warranty Coverage:</strong> 3-Day Replacement Warranty (3DW) from delivery.',
                ],
                'warranty' => '3-Day Replacement Warranty (3DW). Format: Email & Password access.',
                'kw' => 'Replit Core 1 month price in Pakistan, buy Replit Core monthly account, cheap Replit AI subscription',
            ],
            'adobe-express-12months' => [
                'short' => 'Buy Adobe Express Premium 12 Months official redeem link. Upgrade your own email instantly with no payment card required.',
                'overview' => 'Unlock 1 full year of Adobe Express Premium on your own email account with an instant redeem link. No credit card or VISA payment is required. Enjoy thousands of professional Adobe templates, Adobe Stock assets, Firefly generative AI features, and one-click background removal.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 12 Months (1 Year) Adobe Express Premium on your own account.',
                    '<strong>Zero Card Needed:</strong> Open the redeem link and sign in with your email to upgrade immediately.',
                    '<strong>Region Tip:</strong> If you see a region notice during redemption, connect to an Indian VPN and open the link again.',
                ],
                'warranty' => 'Activation Link Redemption Warranty. Connect to an Indian VPN if prompted by a regional restriction during activation.',
                'kw' => 'Adobe Express Premium 12 months price in Pakistan, buy Adobe Express 1 year redeem link, cheap Adobe subscription Pakistan',
            ],
            'antigravity-slot-x20-ultra-1-month-0-credit' => [
                'short' => 'Buy Google AI Ultra x20 Antigravity Family Slot (1 Month, 0 Credit plan) with 25-Day Warranty. High-tier x20 rate limit access.',
                'overview' => 'Join a Google AI Ultra x20 Family Slot for 1 Month and experience 20x higher usage limits in Antigravity. After placing your order, share your email/details with admin for family slot invitation.',
                'bullets' => [
                    '<strong>Plan Tier:</strong> Google AI Ultra x20 Family Slot (1 Month validity).',
                    '<strong>Mandatory Setting:</strong> You must keep AI Credit Overages turned OFF in Antigravity settings (0 Credit plan).',
                    '<strong>Fair Usage Policy:</strong> Consuming shared family credits will result in immediate slot removal without refund.',
                ],
                'warranty' => '25-Day Ultra Slot Warranty (strictly keep AI Credit Overages OFF in settings).',
                'kw' => 'Google AI Ultra x20 slot price in Pakistan, Antigravity Ultra 1 month subscription, buy Google AI Ultra family slot',
            ],
            'apple-tv-12-months-link' => [
                'short' => 'Buy Apple TV+ 12 Months activation link with 48-Hour redemption warranty. No payment card required to activate.',
                'overview' => 'Stream award-winning Apple Originals for a full year with an Apple TV+ 12 Months Activation Link. No credit card or payment method is required during activation.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 12 Months (1 Year) Apple TV+ subscription.',
                    '<strong>Easy Activation:</strong> Connect to a USA VPN, create/sign in to an Apple TV account on the web, and open the redemption link.',
                    '<strong>No VPN Needed After Setup:</strong> Once activated, stream normally without a VPN.',
                ],
                'warranty' => '48-Hour Link Redemption Warranty (Non-Warranty after activation). Requires USA VPN during initial web activation.',
                'kw' => 'Apple TV Plus 12 months price in Pakistan, buy Apple TV 1 year redeem link, cheap Apple TV subscription Pakistan',
            ],
            'autodesk-admin-3000-invite' => [
                'short' => 'Buy Autodesk Education Admin Panel (3 Years validity) with 3,000 user invitations and full Gmail + Autodesk login credentials.',
                'overview' => 'Get a full Autodesk Education Admin Panel valid for 3 Years with capacity for up to 3,000 user seat invitations. Assign AutoCAD, Revit, 3ds Max, Maya, Fusion 360, and Civil 3D licenses to your team members or customers directly from `manage.autodesk.com`.',
                'bullets' => [
                    '<strong>Admin Capacity:</strong> Up to 3,000 user invitations across 3 Years of educational license validity.',
                    '<strong>Delivery Format:</strong> `Gmail | Gmail Password | Autodesk Password` (full admin control).',
                    '<strong>Security Tip:</strong> Sign in at `manage.autodesk.com` and update your primary email and security password at `profile.autodesk.com/security` upon first login.',
                ],
                'warranty' => 'Login & Admin Panel Verification Warranty. Please update your security email and password immediately upon first login.',
                'kw' => 'Autodesk Admin Panel 3000 invites price in Pakistan, buy Autodesk reseller panel, AutoCAD Revit 3ds Max bulk license',
            ],
            'autodesk-all-apps-1-year' => [
                'short' => 'Buy Autodesk All Apps 1 Year official subscription activated via invitation on your own email with Full 1-Year Warranty.',
                'overview' => 'Unlock official 1-Year access to the complete Autodesk suite including AutoCAD, 3ds Max, Maya, Revit, Fusion 360, and Inventor directly on your personal email address. Backed by a Full 1-Year Warranty.',
                'bullets' => [
                    '<strong>All Major Apps Included:</strong> AutoCAD, 3ds Max, Revit, Maya, Fusion 360, Civil 3D, and more.',
                    '<strong>Activated on Your Email:</strong> Official educational/student license invitation sent directly to your email.',
                    '<strong>Full 1-Year Warranty:</strong> Complete 12-month warranty and support included.',
                ],
                'warranty' => 'Full 1-Year (12-Month) Subscription Warranty.',
                'kw' => 'Autodesk All Apps 1 year price in Pakistan, buy AutoCAD 3ds Max Revit genuine license Pakistan',
            ],
            'canva-edu-pro-1-year' => [
                'short' => 'Buy Canva Edu Pro 1 Year subscription at the lowest price in Pakistan. Unlock premium templates, background remover, and Magic Studio AI.',
                'overview' => 'Design professional social media graphics, presentations, and videos with Canva Education Pro for 1 Year (12 Months). Get access to millions of premium stock photos, fonts, Brand Kit tools, one-click Background Remover, and Magic Studio AI features.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 1 Year (12 Months) Canva Education Pro access.',
                    '<strong>Pro Design Tools:</strong> Magic Resize, Background Remover, premium elements, and 100M+ stock assets.',
                    '<strong>Multi-Device Access:</strong> Works on Web, Windows, Mac, iOS, and Android.',
                ],
                'warranty' => 'Activation & Login Verification Warranty.',
                'kw' => 'Canva Pro 1 year price in Pakistan, buy Canva Edu Pro cheap subscription, Canva Pro Easypaisa JazzCash',
            ],
            'framer-ai-1-year' => [
                'short' => 'Buy Framer AI Basic Plan 1 Year official promo code. Upgrade your Framer website project for 12 months with instant code delivery.',
                'overview' => 'Publish your custom domain website and unlock professional CMS and hosting features with an official Framer Basic Plan 1-Year Subscription Code. Receive your promotion code immediately after payment and apply it directly inside your Framer project settings.',
                'bullets' => [
                    '<strong>100% Official Yearly Code:</strong> Full 12-month upgrade for the Framer Basic Yearly Plan.',
                    '<strong>Easy Activation:</strong> Open your Framer project -> Settings -> Plans -> Subscribe under Basic (Yearly) -> Add Promotion Code.',
                    '<strong>Instant Delivery:</strong> Promo code delivered right on your order page.',
                ],
                'warranty' => 'Official Promo Code Redemption Guarantee.',
                'kw' => 'Framer Basic plan 1 year price in Pakistan, buy Framer AI promo code online, Framer yearly subscription discount',
            ],
            'grammarly-pro-1-year' => [
                'short' => 'Buy Grammarly Pro 1 Year (365 Days) subscription with Full 1-Year Warranty. Upgrade your writing with AI rewrite, tone, and plagiarism checks.',
                'overview' => 'Write clear, mistake-free English across every browser, document, and email app with a Grammarly Pro 1 Year subscription. Backed by a full 365-day warranty and activated for your account by our team.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 1 Year (365 Days) Grammarly Pro access.',
                    '<strong>Advanced Writing &amp; AI Features:</strong> Full-sentence rewrites, tone adjustments, vocabulary enhancement, and plagiarism detection.',
                    '<strong>Full 365-Day Warranty:</strong> Complete 1-year replacement and activation support.',
                ],
                'warranty' => 'Full 365-Day (1-Year) Subscription Warranty.',
                'kw' => 'Grammarly Pro 1 year price in Pakistan, buy Grammarly Premium yearly subscription, Grammarly Pakistan Easypaisa',
            ],
            'ilovepdf-premium-1year' => [
                'short' => 'Buy iLovePDF Premium 1 Year private account (Email : Password) with 2,000 AI credits, OCR, digital signatures, and unlimited PDF tools.',
                'overview' => 'Handle all your document workflows effortlessly with an iLovePDF Premium 1 Year private account. Includes unlimited PDF merging, compression, OCR scanning, Word/Excel conversions, e-signatures, and 2,000 AI Credits across Web, Desktop, and Mobile.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 1 Year (12 Months) iLovePDF Premium + 2,000 AI Credits.',
                    '<strong>Private Account:</strong> Delivered as `Email : Password` (you can change the account password after logging in).',
                    '<strong>Cross-Platform Access:</strong> Works on iLovePDF Web, Windows/Mac Desktop app, and iOS/Android mobile app.',
                ],
                'warranty' => 'Full 1-Year Account Warranty. You may change the password after first login.',
                'kw' => 'iLovePDF Premium 1 year price in Pakistan, buy iLovePDF Pro subscription online, PDF editor subscription Pakistan',
            ],
            'jetbrains-edu-pack-12-months' => [
                'short' => 'Buy JetBrains Student/Education Pack 12 Months private account with full Outlook mail access. Unlock all 18 JetBrains IDEs (IntelliJ, PyCharm, PhpStorm).',
                'overview' => 'Code like a professional with an original JetBrains Education Pack 1-Year Private Account (extendable validity through August 2027). Includes full Outlook email access and unlocks all 18 flagship JetBrains IDEs on Windows, macOS, and Linux.',
                'bullets' => [
                    '<strong>18 Premium IDEs Included:</strong> IntelliJ IDEA Ultimate, PyCharm Pro, PhpStorm, WebStorm, GoLand, CLion, Rider, DataGrip, and more.',
                    '<strong>Delivery Format:</strong> `Outlook Email | Outlook Password | JetBrains Password` (100% private account with full mail access).',
                    '<strong>Cross-OS Support:</strong> Works on Windows, macOS, and Linux.',
                ],
                'warranty' => 'Full 12-Month License Warranty with full Outlook email access.',
                'kw' => 'JetBrains All Products Pack price in Pakistan, buy PhpStorm PyCharm IntelliJ Ultimate license Pakistan',
            ],
            'udemy-personal-plan-1month' => [
                'short' => 'Buy Udemy Personal Plan 1 Month subscription with full email access and 1-Month Warranty. Stream thousands of top-rated Udemy courses.',
                'overview' => 'Get unlimited access to thousands of top-rated courses in web development, AI, data science, business, and design with a Udemy Personal Plan 1 Month subscription. Delivered with full email access and a 1-Month Warranty.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 1 Month Udemy Personal Plan subscription.',
                    '<strong>Full Mail Access:</strong> Private account login with complete email access.',
                    '<strong>Full 1-Month Warranty:</strong> 30-day warranty coverage included.',
                ],
                'warranty' => 'Full 1-Month (30-Day) Subscription Warranty with full mail access.',
                'kw' => 'Udemy Personal Plan 1 month price in Pakistan, buy Udemy subscription online, cheap Udemy courses Pakistan',
            ],
            'google-flow-omni-need-extension-20-days' => [
                'short' => 'Buy Google Flow + Omni Flash 1.1 (20 Days) subscription with Unlimited Video generations and 20-Day Full Warranty.',
                'overview' => 'Generate unlimited AI videos on desktop using Google Flow + Omni Flash 1.1 for 20 Days. Designed for AI filmmakers and content creators who want high-speed unlimited video generation with full 20-day warranty support.',
                'bullets' => [
                    '<strong>Plan &amp; Duration:</strong> 20 Days Google Flow + Omni Flash 1.1 access.',
                    '<strong>Unlimited Videos:</strong> Generate unlimited AI videos (recommended for desktop browser use with extension).',
                    '<strong>Full Warranty:</strong> Covered by a 20-Day Full Warranty.',
                ],
                'warranty' => 'Full 20-Day Subscription Warranty (recommended for desktop use with extension).',
                'kw' => 'Google Flow Omni AI video price in Pakistan, buy Google Flow unlimited video subscription, AI video tool Pakistan',
            ],

            // ================= 27 NEWLY ADDED PRODUCTS =================
            'heygen-creator-1-month-20dw' => [
                'short' => 'Buy HeyGen Creator Plan 1 Month ready-made account with 20-Day Warranty (20DW). Generate studio-quality AI avatar videos, voice clones, and translations.',
                'overview' => 'Create professional AI spokesperson videos, lip-synced translations, and custom talking avatars with a ready-made HeyGen Creator Plan 1 Month account. You receive direct login credentials right after purchase so you can sign in and use the Creator Plan immediately without needing any credit card.',
                'bullets' => [
                    '<strong>Plan &amp; Duration:</strong> HeyGen Creator Plan with 1 Month access.',
                    '<strong>Ready-to-Use Account:</strong> Login details are provided immediately after purchase; simply sign in and use the given account directly.',
                    '<strong>Creator Plan Features:</strong> Premium AI avatars, voice cloning, video translation, fast rendering queue, and 1080p watermark-free video exports.',
                    '<strong>20-Day Warranty (20DW):</strong> Backed by a 20-Day Replacement Warranty.',
                ],
                'warranty' => '20-Day Replacement Warranty (20DW). Format: Ready-made HeyGen Creator account (use the provided account directly).',
                'kw' => 'HeyGen Creator 1 month price in Pakistan, buy HeyGen subscription online, HeyGen AI video generator account Pakistan, HeyGen Easypaisa JazzCash',
            ],
            'railway-hobby-1-year' => [
                'short' => 'Buy Railway Hobby Plan 12 Months (1 Year) official coupon code. Redeem on your own new Railway account to unlock all Hobby cloud hosting features.',
                'overview' => 'Deploy web apps, APIs, bots, and databases effortlessly with an official Railway Hobby 12 Months (1 Year) Coupon Code. Redeem the promo code directly on your own new Railway account to unlock all Railway Hobby plan features for a full year.',
                'bullets' => [
                    '<strong>Official 12-Month Coupon Code:</strong> Unlocks 1 Year (12 Months) of the Railway Hobby Plan.',
                    '<strong>Redeem on Your Own Account:</strong> Activate directly on your personal Railway account (works on new Railway accounts only).',
                    '<strong>Full Hobby Features Unlocked:</strong> Deploy GitHub repos, Docker containers, PostgreSQL/MySQL/Redis databases, and custom domains with Hobby resource limits.',
                ],
                'warranty' => 'Coupon Code Redemption Guarantee (Non-Warranty after activation on your Railway account; valid for new Railway accounts only).',
                'kw' => 'Railway Hobby 1 year price in Pakistan, buy Railway app coupon code, Railway cloud hosting subscription Pakistan, cheap Railway Hobby plan',
            ],
            'expressvpn-1-month-5-devices' => [
                'short' => 'Buy ExpressVPN 1 Month 100% private account supporting 5 devices (Format: Email | Password | Activation Key) with instant delivery.',
                'overview' => 'Browse privately and unblock global streaming platforms with a 100% private ExpressVPN 1 Month account supporting up to 5 simultaneous devices. You receive the full login and desktop activation key so you can connect across PC and mobile right away.',
                'bullets' => [
                    '<strong>100% Private Account:</strong> Dedicated 1-Month ExpressVPN subscription supporting up to 5 devices.',
                    '<strong>Delivery Format (`email | password | key`):</strong> Here `key` is the activation key/password used to sign in and open the ExpressVPN desktop app.',
                    '<strong>Ultra-Fast Lightway Protocol:</strong> High-speed servers across 105 countries for 4K streaming, gaming, and secure browsing.',
                ],
                'warranty' => 'Activation Warranty only. Format: email | password | key (use the key to activate the ExpressVPN desktop application).',
                'kw' => 'ExpressVPN 1 month price in Pakistan, buy ExpressVPN 5 devices private account, ExpressVPN activation key Pakistan, cheap ExpressVPN Easypaisa',
            ],
            'express-vpn-2-years' => [
                'short' => 'Buy ExpressVPN 2 Years (24 Months) subscription for both Mobile and PC with a Full 2-Year Warranty and instant delivery.',
                'overview' => 'Get 24 months of non-stop online privacy and unrestricted streaming with an ExpressVPN 2 Years subscription that works on both Mobile (Android & iOS) and PC (Windows & macOS). Backed by a Full 2-Year Warranty for complete peace of mind.',
                'bullets' => [
                    '<strong>Plan Duration:</strong> 2 Years (24 Months) ExpressVPN premium access.',
                    '<strong>Mobile &amp; PC Compatible:</strong> Works seamlessly on both Mobile devices and Desktop PCs.',
                    '<strong>Full 2-Year Warranty:</strong> Complete 24-month warranty coverage included.',
                ],
                'warranty' => 'Full 2-Year (24-Month) Subscription Warranty for both Mobile and PC.',
                'kw' => 'ExpressVPN 2 years price in Pakistan, buy ExpressVPN 24 months mobile and PC, ExpressVPN full warranty Pakistan, cheap ExpressVPN subscription',
            ],
            'miro-lifetime-panel-100-invite' => [
                'short' => 'Buy Miro Lifetime Admin & Owner Panel with 100 member invitations. Full team owner control over settings, permissions, and project spaces.',
                'overview' => 'Take complete command of your visual collaboration workspace with a Miro Lifetime Team Owner & Admin Panel supporting up to 100 member invitations. Unlike regular shared seats, you are the official Owner and Admin of the team, giving you full control over team settings, member permissions, and project spaces.',
                'bullets' => [
                    '<strong>Absolute Admin &amp; Owner Control:</strong> You are the "Owner" and "Admin" of the Miro team, controlling all team settings, member permissions, and project spaces (not just a regular member of a shared account).',
                    '<strong>100-Member Team Scale:</strong> Easily create, invite, and manage a formal team of up to 100 members, ideal for startup teams, agencies, class projects, and student organizations.',
                    '<strong>Unlimited Collaboration Boards:</strong> Create unlimited whiteboards, mind maps, wireframes, and agile workflows.',
                ],
                'warranty' => 'Admin Panel Login & Activation Verification Warranty. Includes full Owner/Admin control for up to 100 team members.',
                'kw' => 'Miro Lifetime Panel 100 invites price in Pakistan, buy Miro Admin Owner account, Miro team subscription Pakistan, Miro whiteboard pro',
            ],
            'chatgpt-plus-1-month-full-warranty' => [
                'short' => 'Buy ChatGPT Plus 1 Month super-stable Apple-billed Gmail account (Mail | Pass | 2FA) with Full 30-Day Warranty.',
                'overview' => 'Get our most stable ChatGPT Plus 1 Month subscription: a 100% Gmail-registered, Apple-billed dedicated account where each account is paid with its own individual card (Vietnamese billing address) with zero IP conflicts. Accounts are updated daily and pre-checked with 2FA configured so you can log in and use GPT-4o, o1/o3 reasoning, DALL-E, and voice mode confidently without changing any settings.',
                'bullets' => [
                    '<strong>Super-Stable Dedicated Billing:</strong> 100% Gmail Apple-billed accounts, updated daily, paid with one dedicated card per account and zero IP conflicts.',
                    '<strong>Delivery Format (`Mail | Pass | 2FA`):</strong> Pre-checked accounts with 2FA ready. Get your 6-digit login code anytime at <code>https://2fa.live/</code>.',
                    '<strong>Phone Verification Tip (If Prompted):</strong> If Codex/SMS verification ever occurs, you can verify via <code>https://viotp.com/</code> (select ChatGPT service + Vina number prefix).',
                    '<strong>Mandatory Security &amp; Warranty Policy:</strong> Accounts are registered via Gmail OTP, so password or 2FA recovery is not supported. <strong>Do NOT change the Password, 2FA, or Email</strong> (if changing 2FA, only do so immediately after first login). We never reclaim accounts, and warranty is NOT provided for security lockouts caused by changing password, 2FA, or email. Warranty applies whenever the account can be logged in to verify the Plus package.',
                ],
                'warranty' => 'Full 30-Day Subscription Warranty (Format: Mail | Pass | 2FA; get 2FA code at https://2fa.live/). Strictly do NOT change Password, 2FA, or Email, as security lockouts caused by changing login details void warranty.',
                'kw' => 'ChatGPT Plus 1 month full warranty price in Pakistan, buy stable ChatGPT Plus account Pakistan, OpenAI ChatGPT Plus 30 days warranty Easypaisa JazzCash',
            ],
            'higgsfield-genjutsu-30-day-limit-24h-warranty' => [
                'short' => 'Buy Higgsfield AI Genjutsu Video Package (720p, 30s video, Motion/Swap Genjutsu) with account, password & OTP mailbox link and 24-Hour Warranty.',
                'overview' => 'Create viral AI motion and character-swap videos with the Higgsfield Genjutsu package. This account lets you generate 1 AI video using Motion / Swap Genjutsu in 720p HD quality (up to 30 seconds per video) directly on Higgsfield AI.',
                'bullets' => [
                    '<strong>Package Output:</strong> Create 1 video using Motion / Swap Genjutsu in <strong>720p HD quality (30 seconds per video)</strong>.',
                    '<strong>Account Format:</strong> <code>Higgsfield Account | Higgsfield Password | OTP Link</code>.',
                    '<strong>Login &amp; Video Creation Links:</strong> Open <code>https://higgsfield.ai/ai/video?model=genjutsu</code> and sign in with the provided account and password. If an OTP code is requested, click the OTP mailbox link at <code>https://dongvanfb.net/read_mail_box/</code>.',
                    '<strong>Render Time &amp; Usage Rule:</strong> Render time is approximately 20 minutes per video (or longer depending on server queue). Generate your video immediately after purchase, wait during rendering (reload the page if it takes long), and do NOT turn off/on multiple times while generating to avoid losing your slot.',
                ],
                'warranty' => '24-Hour (1-Day) Warranty. Generate your video immediately after purchase and do not toggle generation on/off repeatedly while rendering.',
                'kw' => 'Higgsfield AI Genjutsu price in Pakistan, buy Higgsfield AI video account, Motion Swap Genjutsu AI video Pakistan, SellMeTools',
            ],
            'zoom-pro-1-month' => [
                'short' => 'Buy Zoom Pro 1 Month pre-provided account (100 participants, 30 hours per meeting, Format: Email | Pass | Web Mail) with Full 30-Day Warranty.',
                'overview' => 'Host long, uninterrupted video conferences, classes, and webinars with a pre-provided Zoom Pro 1 Month account supporting up to 100 participants and up to 30 hours per meeting. Delivered in the format `Email | Pass | Web Mail` with a Full 30-Day Warranty.',
                'bullets' => [
                    '<strong>Plan Specs:</strong> Zoom Pro 1 Month supporting <strong>100 members</strong> and up to <strong>30 hours per meeting</strong>.',
                    '<strong>Delivery Format:</strong> <code>Email | Pass | Web Mail</code>.',
                    '<strong>Account Password Rule:</strong> Please do NOT change the password during the subscription period so our shop can assist you immediately if any issue arises (if you do change it, please inform support of the new password).',
                    '<strong>2FA Prompt Rule:</strong> During login, if the screen prompts for two-factor authentication (2FA), simply click <strong>"Skip"</strong>.',
                    '<strong>Important Webmail OTP Code Note (`333333`):</strong> When checking webmail for your login OTP, if you see the code <code>333333</code>, <strong>click on `333333` to open the email content</strong> containing the actual 6-digit verification code. Do NOT enter `333333` as the OTP (it is incorrect and will lock the account for 30 minutes).',
                ],
                'warranty' => 'Full 30-Day Subscription Warranty. Do not change the password, click "Skip" if prompted for 2FA, and click on 333333 in webmail to reveal the real 6-digit OTP code.',
                'kw' => 'Zoom Pro 1 month price in Pakistan, buy Zoom Pro account 100 participants, cheap Zoom Pro subscription Pakistan, Zoom Pro Easypaisa JazzCash',
            ],
            'zoom-pro-14-days-full-warranty' => [
                'short' => 'Buy Zoom Pro 14 Days pre-provided account (100 participants, 30 hours/meeting, unlimited meetings, Host & Co-Host) with Full 14-Day Warranty.',
                'overview' => 'Need Zoom Pro for a two-week conference, training batch, or online event? Get a pre-provided Zoom Pro 14-Day account that removes the 40-minute limit and supports up to 100 participants, 30 hours per meeting, unlimited meetings, and Host/Co-Host assignment.',
                'bullets' => [
                    '<strong>Plan Duration &amp; Capacity:</strong> 14 Days active Zoom Pro account for up to <strong>100 members</strong>.',
                    '<strong>Pro Meeting Controls:</strong> Removes the 40-minute limit (up to 30 hours per meeting), unlimited number of meetings, and full Host &amp; Co-Host assignment.',
                    '<strong>Delivery Format:</strong> Pre-provided account in <code>Email | Pass | Web Mail</code> format.',
                ],
                'warranty' => 'Full 14-Day Subscription Warranty. Format: Email | Pass | Web Mail.',
                'kw' => 'Zoom Pro 14 days price in Pakistan, buy 2 weeks Zoom Pro account, Zoom Pro 100 members subscription Pakistan',
            ],
            'official-zoom-pro-3-months' => [
                'short' => 'Buy Official Zoom Pro 3 Months subscription (100 participants, 30 hours/meeting) activated on your own account or pre-provided with Full Warranty.',
                'overview' => 'Upgrade to Official Zoom Pro for 3 Months with support for 100 participants and up to 30 hours per meeting. Can be activated directly on your own Zoom account (`Mail | Pass | 2FA`) with automatic 14-day renewal, or you can request a pre-provided ready-made account.',
                'bullets' => [
                    '<strong>Plan Specs:</strong> Official Zoom Pro for 3 Months (100 members, up to 30 hours per meeting).',
                    '<strong>Upgrade on Your Account (`Mail | Pass | 2FA`):</strong> Provide your Zoom credentials in the format <code>Mail | Pass | 2FA</code>. Add 2FA at <code>https://zoom.us/profile</code> under Two-Step Verification by adding the <strong>Authenticator App</strong> with a secret key.',
                    '<strong>Auto-Renewal Requirement:</strong> Do not change your login password or 2FA information during use so our renewal tool can automatically renew your Pro status every 14 days.',
                    '<strong>Pre-Provided Account Option:</strong> If you prefer a ready-made pre-provided Zoom account instead, simply mention it in your order note.',
                ],
                'warranty' => 'Full 3-Month Subscription Warranty. Keep login & 2FA unchanged during use so automatic 14-day renewal works seamlessly.',
                'kw' => 'Official Zoom Pro 3 months price in Pakistan, buy Zoom Pro 3 months upgrade, Zoom Pro 100 participants Pakistan',
            ],
            'official-zoom-pro-6-months' => [
                'short' => 'Buy Official Zoom Pro 6 Months subscription (100 participants, 30 hours/meeting) activated on your account or pre-provided with Full 6-Month Warranty.',
                'overview' => 'Enjoy half a year of uninterrupted meetings with Official Zoom Pro for 6 Months (100 participants, 30 hours per meeting). Activated directly on your own Zoom account (`Mail | Pass | 2FA`) with automated 14-day renewal, or request a pre-provided account from our shop.',
                'bullets' => [
                    '<strong>Plan Specs:</strong> Official Zoom Pro for 6 Months (100 members, up to 30 hours per meeting).',
                    '<strong>Upgrade Format (`Mail | Pass | 2FA`):</strong> Submit your account in <code>Mail | Pass | 2FA</code> format after enabling Two-Step Verification (Authenticator App secret key) at <code>https://zoom.us/profile</code>.',
                    '<strong>Seamless Auto-Renewal:</strong> Do not change your password or 2FA secret key during the 6-month period so the system can automatically renew every 14 days (or request a pre-provided account if preferred).',
                ],
                'warranty' => 'Full 6-Month Subscription Warranty. Keep account credentials and 2FA secret key unchanged for automatic 14-day renewal.',
                'kw' => 'Official Zoom Pro 6 months price in Pakistan, buy Zoom Pro 6 months subscription, Zoom Pro Pakistan Easypaisa USDT',
            ],
            'zoom-pro-1-year' => [
                'short' => 'Buy Official Zoom Pro 1 Year (12 Months) subscription (100 participants, 30 hours/meeting) with Full 1-Year Warranty.',
                'overview' => 'Get 12 full months of Official Zoom Pro supporting up to 100 members and 30 hours per meeting. Upgrade your own Zoom account (`Mail | Pass | 2FA`) with automated 14-day renewal throughout the year, or request a pre-provided account.',
                'bullets' => [
                    '<strong>Plan Specs:</strong> Official Zoom Pro for 1 Year / 12 Months (100 members, up to 30 hours per meeting).',
                    '<strong>Account Upgrade Format (`Mail | Pass | 2FA`):</strong> Provide your account in <code>Mail | Pass | 2FA</code> format by adding the Authenticator App secret key under Two-Step Verification at <code>https://zoom.us/profile</code>.',
                    '<strong>Auto-Renewal Rule:</strong> Keep your login and 2FA details unchanged during use so our tool automatically renews the license every 14 days (or ask our shop for a pre-provided account).',
                ],
                'warranty' => 'Full 1-Year (12-Month) Subscription Warranty. Do not change account info during use to maintain automatic 14-day renewal.',
                'kw' => 'Zoom Pro 1 year price in Pakistan, buy Official Zoom Pro 12 months subscription, Zoom Pro yearly plan Pakistan',
            ],
            'scribd-premium-1month' => [
                'short' => 'Buy Scribd Premium 1 Month full private account with complete Outlook mail access (Email | Password) and Full 1-Month Warranty.',
                'overview' => 'Read unlimited ebooks, audiobooks, magazines, sheet music, and research documents with a 100% private Scribd (Everand) Premium 1 Month account. Delivered with full Outlook email and password login access.',
                'bullets' => [
                    '<strong>100% Private Monthly Plan:</strong> Dedicated Scribd Premium 1 Month subscription.',
                    '<strong>Full Outlook Mail Access:</strong> Includes complete <code>Email | Password</code> login for both Scribd and the linked Outlook mail inbox.',
                    '<strong>Unlimited Digital Library:</strong> Access millions of books, audiobooks, and PDF documents on Web, iOS, and Android.',
                ],
                'warranty' => 'Full 1-Month (30-Day) Subscription Warranty with full private Outlook mail access.',
                'kw' => 'Scribd Premium 1 month price in Pakistan, buy Scribd account online Pakistan, Everand Scribd subscription Easypaisa JazzCash',
            ],
            'youtube-premium-account-1month' => [
                'short' => 'Buy YouTube Premium 1 Month personal account with full Gmail + password access and Full 1-Month Warranty. Ad-free YouTube & Music.',
                'overview' => 'Watch YouTube 100% ad-free with background play, offline downloads, and YouTube Music Premium using a private personal YouTube Premium 1 Month account. You receive full Gmail + password access so you can secure the account with your own password and recovery details.',
                'bullets' => [
                    '<strong>Full Gmail Access (`Gmail + Password`):</strong> Personal Google/Gmail account with active 1-Month YouTube Premium + YouTube Music.',
                    '<strong>Custom Security Allowed:</strong> You can change the account password after delivery and add your primary phone number and email as recovery options.',
                    '<strong>Important Billing Rule:</strong> Do NOT change, remove, or tamper with the linked payment method so your Premium membership stays active for the full month.',
                ],
                'warranty' => 'Full 1-Month (30-Day) Subscription Warranty. You may change the password and add recovery info, but do NOT tamper with the payment method.',
                'kw' => 'YouTube Premium 1 month personal account price in Pakistan, buy YouTube Premium Gmail account, cheap YouTube Premium Pakistan Easypaisa',
            ],
            'linkedin-career-2-months' => [
                'short' => 'Buy LinkedIn Premium Career 2 Months official voucher link for new users ($0 checkout for 2 months) with Full Warranty.',
                'overview' => 'Stand out to recruiters and land your dream job faster with an official LinkedIn Premium Career 2 Months Voucher Link. Designed for new users whose LinkedIn accounts have never used a LinkedIn Premium trial before, opening the official checkout at $0 for 2 months.',
                'bullets' => [
                    '<strong>Official 2-Month Career Voucher:</strong> Upgrades your LinkedIn profile to LinkedIn Premium Career for 2 full months.',
                    '<strong>Eligibility (New Trial Users):</strong> Works on LinkedIn accounts that have never used any LinkedIn trial before. Opening the voucher link takes you to the official LinkedIn checkout page showing <strong>$0 for 2 Months</strong>.',
                    '<strong>Career Benefits:</strong> 5 InMail credits/month, see Who Viewed Your Profile, applicant insights, AI resume/profile tools, and full LinkedIn Learning access.',
                ],
                'warranty' => 'Full 2-Month Subscription Warranty (valid for fresh LinkedIn accounts that have never used a LinkedIn trial before).',
                'kw' => 'LinkedIn Career 2 months voucher price in Pakistan, buy LinkedIn Premium Career Pakistan, LinkedIn 2 months redeem link Easypaisa',
            ],
            'linkedin-career-3-months' => [
                'short' => 'Buy LinkedIn Premium Career 3 Months official voucher link for fresh accounts ($0 checkout for 3 months) with Full Warranty.',
                'overview' => 'Boost your job search for a full quarter with an official LinkedIn Premium Career 3 Months Voucher for new users. When opened on a fresh LinkedIn account that has never used a 3-month subscription or trial before, it sets the official LinkedIn checkout to $0 for 3 months.',
                'bullets' => [
                    '<strong>Official 3-Month Career Voucher:</strong> Unlocks 3 months of LinkedIn Premium Career + LinkedIn Learning.',
                    '<strong>Eligibility (Fresh Accounts Only):</strong> Works on fresh LinkedIn accounts that have never taken any LinkedIn trial or 3-month voucher before, making checkout <strong>$0 for 3 Months</strong>.',
                    '<strong>Get Hired Faster:</strong> Direct recruiter InMails, Top Applicant recommendations, salary insights, and 21,000+ LinkedIn Learning courses.',
                ],
                'warranty' => 'Full 3-Month Subscription Warranty (valid for fresh accounts with no prior LinkedIn trial or 3-month voucher).',
                'kw' => 'LinkedIn Career 3 months voucher price in Pakistan, buy LinkedIn Premium 3 months link, cheap LinkedIn Premium Pakistan',
            ],
            'linkedin-career-3-months-via-login-all-users' => [
                'short' => 'Buy LinkedIn Premium Career 3 Months via Login Method (works on ALL accounts, old & new). Official subscription activated in 30 to 60 minutes.',
                'overview' => 'Already used a LinkedIn trial in the past? Upgrade ANY LinkedIn profile with our Official LinkedIn Premium Career 3 Months via Login service. Works on all accounts (both old and new), activated directly by our team within 30 minutes to 1 hour.',
                'bullets' => [
                    '<strong>Works on ALL Accounts:</strong> Compatible with both new and existing LinkedIn accounts, even if you have already used previous trials or vouchers.',
                    '<strong>Activation Method (Via Login):</strong> Customer submits their LinkedIn login credentials after ordering so our team can activate the official 3-month Career subscription.',
                    '<strong>Fast Turnaround:</strong> Activated within <strong>30 Minutes to 1 Hour</strong>.',
                ],
                'warranty' => 'Official Activation Warranty. Works on all LinkedIn accounts (delivery time: 30 minutes to 1 hour).',
                'kw' => 'LinkedIn Career 3 months via login price in Pakistan, upgrade existing LinkedIn account to Premium, buy LinkedIn Career all users Pakistan',
            ],
            'linkedin-career-12-months-via-login' => [
                'short' => 'Buy LinkedIn Premium Career 12 Months (1 Year) via Login Method for ALL accounts. Official 1-year upgrade completed in 30 to 60 minutes.',
                'overview' => 'Get a full year of LinkedIn Premium Career and LinkedIn Learning on any LinkedIn profile (old or new) via our direct login activation service. Ideal for professionals and job seekers who want 12 months of official Career access activated within 30 to 60 minutes.',
                'bullets' => [
                    '<strong>12 Months Official Career Plan:</strong> Full 1-year LinkedIn Premium Career + LinkedIn Learning subscription.',
                    '<strong>Works on All Accounts:</strong> Valid for both old and new LinkedIn accounts regardless of previous trial history.',
                    '<strong>Activation via Login (30 Mins to 1 Hour):</strong> Share your LinkedIn credentials after checkout for rapid official activation.',
                ],
                'warranty' => 'Official Activation Warranty. Works on all LinkedIn accounts (delivery time: 30 minutes to 1 hour).',
                'kw' => 'LinkedIn Career 12 months price in Pakistan, buy LinkedIn Premium 1 year Pakistan, LinkedIn Career yearly upgrade via login',
            ],
            'linkedin-career-duo-12-months-voucher' => [
                'short' => 'Buy LinkedIn Career Duo 12 Months (1 Year) official voucher activated via your LinkedIn email (no password needed) with 10-Month Warranty.',
                'overview' => 'Upgrade your personal profile without sharing your password using the LinkedIn Career Duo 12 Months Voucher activation. Simply provide your LinkedIn email address, and your 12-month official Career subscription is activated within 6 to 30 hours, backed by a 10-Month Warranty.',
                'bullets' => [
                    '<strong>Email-Only Activation:</strong> Activated directly via the email address of your LinkedIn account (no login password required).',
                    '<strong>Delivery Time:</strong> Completed within <strong>6 Hours to 30 Hours</strong>.',
                    '<strong>10-Month Warranty Included:</strong> Long-term 10-month warranty protection included.',
                ],
                'warranty' => '10-Month Subscription Warranty. Activated via your LinkedIn email address within 6 to 30 hours.',
                'kw' => 'LinkedIn Career Duo 12 months voucher price in Pakistan, buy LinkedIn 1 year email activation, LinkedIn Premium 12 months warranty Pakistan',
            ],
            'linkedin-business-2-months' => [
                'short' => 'Buy LinkedIn Premium Business 2 Months official voucher link for new users ($0 checkout for 2 months) with Activation Warranty.',
                'overview' => 'Expand your network and unlock unlimited people browsing with an official LinkedIn Premium Business 2 Months Voucher Link. Works on LinkedIn accounts that have never used any LinkedIn trial before, showing $0 for 2 months on the official checkout page.',
                'bullets' => [
                    '<strong>Official 2-Month Business Voucher:</strong> Unlocks LinkedIn Premium Business for 2 months.',
                    '<strong>Eligibility (New Users):</strong> Works on accounts that have never used a LinkedIn trial before; opens the official LinkedIn checkout page showing <strong>$0 for 2 Months</strong>.',
                    '<strong>Business Plan Features:</strong> Unlimited people browsing up to 3rd degree, 15 InMail credits/month, Company Insights, and 365-day Who Viewed Your Profile.',
                ],
                'warranty' => 'Official Activation Warranty (valid for LinkedIn accounts that have never used a trial before).',
                'kw' => 'LinkedIn Business 2 months voucher price in Pakistan, buy LinkedIn Premium Business link, cheap LinkedIn Business Pakistan',
            ],
            'linkedin-business-3-months-via-login-all-users' => [
                'short' => 'Buy LinkedIn Premium Business 3 Months via Login Method (works on ALL accounts, old & new). Official activation in 30 to 60 minutes.',
                'overview' => 'Upgrade any new or existing LinkedIn account to LinkedIn Premium Business for 3 Months via our direct login method. Works on all profiles even if you have previously redeemed trials or subscriptions, with fast 30-to-60-minute activation.',
                'bullets' => [
                    '<strong>Works on ALL LinkedIn Accounts:</strong> Compatible with both old and new accounts regardless of prior trials.',
                    '<strong>Activation via Login:</strong> Customer provides LinkedIn login credentials after payment for official 3-month Business activation.',
                    '<strong>Fast Delivery Time:</strong> Completed in <strong>30 Minutes to 1 Hour</strong>.',
                ],
                'warranty' => 'Official Activation Warranty. Works on all accounts with 30-minute to 1-hour delivery.',
                'kw' => 'LinkedIn Business 3 months price in Pakistan, buy LinkedIn Business via login all users, LinkedIn Premium Business Pakistan',
            ],
            'linkedin-business-12-months-via-login' => [
                'short' => 'Buy LinkedIn Premium Business 12 Months (1 Year) via Login Method for ALL accounts. Official 1-year Business upgrade in 30 to 60 minutes.',
                'overview' => 'Unlock 12 full months of unlimited people search, 15 monthly InMails, and business intelligence on any LinkedIn account with our Official LinkedIn Business 12 Months via Login service. Works on all accounts (old and new) within 30 minutes to 1 hour.',
                'bullets' => [
                    '<strong>12 Months Official Business Plan:</strong> Full 1-Year LinkedIn Premium Business upgrade.',
                    '<strong>All Accounts Eligible:</strong> Works on both new and old LinkedIn profiles.',
                    '<strong>Via Login Activation (30 Mins to 1 Hour):</strong> Submit your LinkedIn credentials after checkout for fast official activation.',
                ],
                'warranty' => 'Official Activation Warranty. Works on all LinkedIn accounts (delivery time: 30 minutes to 1 hour).',
                'kw' => 'LinkedIn Business 12 months price in Pakistan, buy LinkedIn Business 1 year Pakistan, LinkedIn Premium Business yearly upgrade',
            ],
            'linkedin-business-duo-12-months-voucher' => [
                'short' => 'Buy LinkedIn Business Duo 12 Months (1 Year) official voucher activated via your LinkedIn email address (6 to 30 hours delivery).',
                'overview' => 'Upgrade to LinkedIn Premium Business for 12 Months without sharing your account password. With the LinkedIn Business Duo 12 Months Voucher, activation is processed directly via your LinkedIn email address within 6 to 30 hours.',
                'bullets' => [
                    '<strong>12-Month Business Duo Plan:</strong> Full 1-Year LinkedIn Premium Business membership.',
                    '<strong>Email-Only Activation:</strong> Activated directly via your LinkedIn email address (no password required).',
                    '<strong>Delivery Time:</strong> <strong>6 Hours to 30 Hours</strong>.',
                ],
                'warranty' => 'Official Activation Warranty. Activated via your LinkedIn email within 6 to 30 hours.',
                'kw' => 'LinkedIn Business Duo 12 months voucher price in Pakistan, buy LinkedIn Business 1 year email voucher, SellMeTools',
            ],
            'linkedin-sales-navigator-core-2-months-voucher' => [
                'short' => 'Buy LinkedIn Sales Navigator Core 2 Months official voucher link for new users ($0 checkout for 2 months) with Activation Warranty.',
                'overview' => 'Supercharge your B2B lead generation and outbound sales with an official LinkedIn Sales Navigator Core 2 Months Voucher Link. Works on LinkedIn accounts that have never used any LinkedIn trial before and shows $0 for 2 months on the official checkout page.',
                'bullets' => [
                    '<strong>2 Months Sales Navigator Core:</strong> Unlock advanced lead and company filters, 50 InMails/month, and saved lead alerts.',
                    '<strong>Eligibility (New Users):</strong> Works on accounts that have never used any LinkedIn trial before; opens the official LinkedIn checkout page showing <strong>$0 for 2 Months</strong>.',
                    '<strong>Instant Voucher Delivery:</strong> Receive your redemption link right on your order page.',
                ],
                'warranty' => 'Official Activation Warranty (valid for fresh accounts that have never used any LinkedIn trial before).',
                'kw' => 'LinkedIn Sales Navigator Core 2 months price in Pakistan, buy Sales Navigator voucher Pakistan, LinkedIn Sales Navigator cheap subscription',
            ],
            'linkedin-sales-navigator-advance-1-months-voucher' => [
                'short' => 'Buy LinkedIn Sales Navigator Advanced 1 Month official voucher activated via your LinkedIn email (6 to 30 hours) with Full Warranty.',
                'overview' => 'Access enterprise B2B sales tools including Smart Links, TeamLink, and buyer intent signals with the LinkedIn Sales Navigator Advanced 1 Month Voucher. Activated directly via your LinkedIn email address within 6 to 30 hours and backed by a Full 1-Month Warranty.',
                'bullets' => [
                    '<strong>Sales Navigator Advanced (1 Month):</strong> Includes 50 InMails, Smart Links presentation tracking, TeamLink, and advanced CRM/lead filters.',
                    '<strong>Email-Only Activation:</strong> Activated via the email address of your LinkedIn account (no password required).',
                    '<strong>Delivery Time &amp; Warranty:</strong> Delivered in <strong>6 Hours to 30 Hours</strong> with <strong>Full Warranty</strong>.',
                ],
                'warranty' => 'Full 1-Month Subscription Warranty. Activated via your LinkedIn email within 6 to 30 hours.',
                'kw' => 'LinkedIn Sales Navigator Advance 1 month price in Pakistan, buy Sales Navigator Advanced voucher Pakistan, LinkedIn B2B sales tool',
            ],
            'ahrefs-1-month-with-free-gifts' => [
                'short' => 'Buy Ahrefs Premium 1 Month Package (50 Reports/Day, 20,000 Exports/Day) + FREE Bonus Access to 30+ SEO & AI Tools (Semrush, Moz Pro, Canva Pro) with Full Warranty.',
                'overview' => 'The ultimate all-in-one SEO and digital marketing bundle for smart learners, bloggers, agencies, and digital hustlers! Get Ahrefs Premium 1 Month access with 50 Reports/Day and 20,000 Exports/Day (Keyword Explorer, Domain Explorer, Batch Analysis v2, Content Explorer, Competitive Analysis, Link Intersect & Ahrefs Rank) PLUS 30+ bonus premium tools absolutely FREE with 99.9% uptime and Full Warranty.',
                'bullets' => [
                    '<strong>Ahrefs Premium Daily Limits:</strong> <strong>50 Reports/Day</strong> and <strong>20,000 Exports/Day</strong> across Keyword Explorer, Domain Explorer, Batch Analysis v2, Content Explorer, Competitive Analysis, Link Intersect, and Ahrefs Rank.',
                    '<strong>FREE Bonus Access to 30+ Premium Tools Included:</strong> (1) Semrush, (2) Moz Pro, (3) Ubersuggest, (4) Grammarly, (5) Canva Pro, (6) Keywords Everywhere, (7) VistaCreate, (8) QuillBot, (9) PicMonkey, (10) AnswerThePublic, (11) Envato Elements, (12) ChatGPT, (13) SEO Site Checkup, (14) Storyblocks, (15) SpyFu, (16) Closercopy, (17) WordHero, (18) SpinRewriter, (19) FotoJet, (20) Indexification &amp; 10+ more tools!',
                    '<strong>Built For:</strong> SEO Professionals, Bloggers &amp; Affiliate Marketers, Students &amp; Freelancers, Content Writers, Graphic &amp; Video Creators, and Digital Marketing Agencies.',
                    '<strong>Safe, Trusted &amp; 99.9% Uptime:</strong> Backed by our Full 1-Month Subscription Warranty.',
                ],
                'warranty' => 'Full 1-Month (30-Day) Subscription Warranty with 99.9% uptime across Ahrefs Premium (50 reports/day, 20,000 exports/day) and the 30+ bonus tools.',
                'kw' => 'Ahrefs 1 month price in Pakistan, buy Ahrefs Semrush bundle Pakistan, cheap Ahrefs group buy 50 reports day Pakistan, SEO tools bundle Easypaisa JazzCash',
            ],
            'stealth-writer' => [
                'short' => 'Buy StealthWriter AI Humanizer 1 Month subscription (20 to 50 Daily Requests deals starting at Rs 1,200) with Full Warranty. Bypass Turnitin & GPTZero.',
                'overview' => 'Humanize AI-generated essays, articles, and research papers into natural, 100% undetectable human writing with a StealthWriter AI 1 Month Subscription. Choose from 4 flexible daily humanizing request limit deals starting at Rs 1,200, all backed by a Full Warranty.',
                'bullets' => [
                    '<strong>Deal 1 (20 Requests/Day Limit):</strong> Rs 1,200 / month.',
                    '<strong>Deal 2 (30 Requests/Day Limit):</strong> Rs 1,799 / month.',
                    '<strong>Deal 3 (40 Requests/Day Limit):</strong> Rs 2,699 / month.',
                    '<strong>Deal 4 (50 Requests/Day Limit):</strong> Rs 3,200 / month.',
                    '<strong>Bypass AI Detectors:</strong> Built for students, researchers, and SEO writers to convert ChatGPT, Claude, and Gemini text into human-grade content that passes Turnitin, GPTZero, and Originality.ai.',
                ],
                'warranty' => 'Full 1-Month Subscription Warranty across all 4 daily request limit deals (20, 30, 40, or 50 requests/day).',
                'kw' => 'StealthWriter price in Pakistan, buy Stealth Writer AI humanizer subscription, bypass Turnitin GPTZero tool Pakistan, StealthWriter Easypaisa JazzCash',
            ],
            'windows-activation-key-lifetime' => [
                'short' => 'Buy genuine Windows 10 Pro / Windows 11 Pro 25-digit Official Lifetime Activation Key (32/64-bit) with instant delivery and Full Lifetime Warranty.',
                'overview' => 'Upgrade your PC or laptop with a 100% Genuine Windows 10 Pro or Windows 11 Pro Official Activation Key (Lifetime License). Permanently remove the "Activate Windows" watermark and unlock all official Microsoft Pro features, security patches, and updates with a one-time lifetime license.',
                'bullets' => [
                    '<strong>100% Genuine &amp; Official:</strong> Verifies and activates directly through official Microsoft servers with a one-time lifetime license (no monthly or annual renewal fees).',
                    '<strong>Dual Compatibility (Win 10 &amp; 11 Pro):</strong> Works seamlessly on both Windows 10 Pro and Windows 11 Pro (32-bit &amp; 64-bit) across all global languages.',
                    '<strong>Official Pro Features &amp; Updates:</strong> Receive all future Microsoft security updates, patches, BitLocker encryption, Remote Desktop, and Hyper-V.',
                    '<strong>What You Receive:</strong> 1x 25-Character Official Windows 10/11 Pro Activation Key (<code>XXXXX-XXXXX-XXXXX-XXXXX-XXXXX</code>), easy step-by-step activation guide, and full customer support.',
                    '<strong>How to Activate in 3 Simple Steps:</strong> Open PC Settings -&gt; Update &amp; Security (or System) -&gt; Activation -&gt; Click "Change Product Key" -&gt; Enter your 25-digit license key and click Activate.',
                ],
                'warranty' => 'Full Lifetime Activation Warranty. Includes 1x 25-character official Windows 10/11 Pro key (XXXXX-XXXXX-XXXXX-XXXXX-XXXXX) and full activation support.',
                'kw' => 'Windows 11 Pro key price in Pakistan, buy Windows 10 Pro activation key lifetime, genuine Microsoft Windows license key Pakistan, Windows Pro retail key Easypaisa JazzCash',
            ],
        ];

        $formatted = [];
        foreach ($items as $slug => $data) {
            $bulletsHtml = implode("\n", array_map(fn (string $b) => "<li>{$b}</li>", $data['bullets']));
            $titleClean = static::cleanProductTitle(ucwords(str_replace('-', ' ', $slug)));
            $descriptionHtml = <<<HTML
<h3>Product Overview</h3>
<p>{$data['overview']}</p>
<h3>What You Get &amp; Key Features</h3>
<ul>
{$bulletsHtml}
<li><strong>Instant Payment &amp; Delivery:</strong> Pay easily via Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer (Raast), or Binance USDT and receive your credentials on your order tracking page.</li>
</ul>
HTML;

            $candidateTitle = "Buy {$titleClean} | Best Price in Pakistan";
            if (mb_strlen($candidateTitle) > 60) {
                $candidateTitle = "Buy {$titleClean} in Pakistan";
            }
            if (mb_strlen($candidateTitle) > 60) {
                $candidateTitle = "Buy {$titleClean} Online";
            }
            $metaTitle = Str::limit($candidateTitle, 60, '');
            $shortClean = static::stripBotCodesFromCopy(trim($data['short']));
            $withSuffix = $shortClean.' Instant delivery in Pakistan & worldwide.';
            $metaDesc = mb_strlen($withSuffix) <= 155
                ? $withSuffix
                : (mb_strlen($shortClean) <= 155 ? $shortClean : Str::limit($shortClean, 152, '...'));

            $formatted[$slug] = [
                'short_description' => $shortClean,
                'description' => $descriptionHtml,
                'warranty' => $data['warranty'],
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
                'meta_keywords' => $data['kw'].", buy {$titleClean} online, {$titleClean} instant delivery, SellMeTools, cheap digital subscriptions Pakistan",
            ];
        }

        return $formatted;
    }
}
