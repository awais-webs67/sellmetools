<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiSeoAgent
{
    public const PROVIDER_AUTO = 'auto';

    public const PROVIDER_GROQ = 'groq';

    public const PROVIDER_OPENROUTER = 'openrouter';

    public const PROVIDER_GEMINI = 'gemini';

    public const PROVIDER_CUSTOM = 'custom';

    /**
     * @return array<string, string>
     */
    public static function providerOptions(): array
    {
        return [
            self::PROVIDER_AUTO => 'Auto Fallback (Groq -> OpenRouter -> Gemini -> Custom -> Template)',
            self::PROVIDER_GROQ => 'Groq (Free Fast Tier — Llama 3.3 70B / Mixtral)',
            self::PROVIDER_OPENROUTER => 'OpenRouter (Free :free Models Router)',
            self::PROVIDER_GEMINI => 'Google Gemini (Free AI Studio API — Flash)',
            self::PROVIDER_CUSTOM => 'Custom OpenAI-Compatible Endpoint (AgentRouter / Together / Ollama)',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function hasConfiguredLlm(array $overrides = []): bool
    {
        foreach ([self::PROVIDER_GROQ, self::PROVIDER_OPENROUTER, self::PROVIDER_GEMINI, self::PROVIDER_CUSTOM] as $provider) {
            if (static::resolveApiKey($provider, $overrides) !== '') {
                if ($provider === self::PROVIDER_CUSTOM && static::resolveCustomBaseUrl($overrides) === '') {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    public static function shouldAutoRunOnSave(): bool
    {
        return (string) Setting::get('ai_seo_auto_enabled', '1') === '1'
            && static::hasConfiguredLlm();
    }

    /**
     * Generates a complete SEO bundle (short summary, structured HTML description, warranty,
     * meta title, meta description with price, and SEO tags) trained on live store data.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{
     *     fields: array{short_description: string, description: string, warranty: string, meta_title: string, meta_description: string, meta_keywords: string},
     *     source: string,
     *     model: string,
     *     error: ?string
     * }
     */
    public static function generateForProduct(
        Product $product,
        bool $useLlm = true,
        array $overrides = [],
        ?string $providerOverride = null,
        int $timeoutSeconds = 12
    ): array {
        $fallbackFields = ProductSeoOptimizer::buildDynamicSeoFields($product);
        $fallbackFields = static::normalizeGeneratedFields($fallbackFields, $product, $fallbackFields);

        if (! $useLlm || ! static::hasConfiguredLlm($overrides)) {
            return [
                'fields' => $fallbackFields,
                'source' => 'template',
                'model' => 'deterministic-store-optimizer',
                'error' => null,
            ];
        }

        $context = static::buildStoreTrainingContext($product);
        $systemPrompt = static::buildSystemPrompt();
        $userPrompt = static::buildProductPrompt($context);

        $providerChain = static::resolveProviderChain($providerOverride, $overrides);
        $lastError = null;

        foreach ($providerChain as $provider) {
            $result = match ($provider) {
                self::PROVIDER_GROQ => static::callOpenAiCompatible(
                    provider: self::PROVIDER_GROQ,
                    endpoint: 'https://api.groq.com/openai/v1/chat/completions',
                    apiKey: static::resolveApiKey(self::PROVIDER_GROQ, $overrides),
                    model: static::resolveModel(self::PROVIDER_GROQ, $overrides),
                    systemPrompt: $systemPrompt,
                    userPrompt: $userPrompt,
                    timeoutSeconds: $timeoutSeconds,
                ),
                self::PROVIDER_OPENROUTER => static::callOpenAiCompatible(
                    provider: self::PROVIDER_OPENROUTER,
                    endpoint: 'https://openrouter.ai/api/v1/chat/completions',
                    apiKey: static::resolveApiKey(self::PROVIDER_OPENROUTER, $overrides),
                    model: static::resolveModel(self::PROVIDER_OPENROUTER, $overrides),
                    systemPrompt: $systemPrompt,
                    userPrompt: $userPrompt,
                    timeoutSeconds: $timeoutSeconds,
                    extraHeaders: [
                        'HTTP-Referer' => url('/'),
                        'X-Title' => (string) Setting::get('store_name', 'SellMeTools'),
                    ],
                ),
                self::PROVIDER_GEMINI => static::callGemini(
                    apiKey: static::resolveApiKey(self::PROVIDER_GEMINI, $overrides),
                    model: static::resolveModel(self::PROVIDER_GEMINI, $overrides),
                    systemPrompt: $systemPrompt,
                    userPrompt: $userPrompt,
                    timeoutSeconds: $timeoutSeconds,
                ),
                self::PROVIDER_CUSTOM => static::callCustomOpenAiCompatible(
                    overrides: $overrides,
                    systemPrompt: $systemPrompt,
                    userPrompt: $userPrompt,
                    timeoutSeconds: $timeoutSeconds,
                ),
                default => ['ok' => false, 'content' => '', 'model' => '', 'error' => 'Unsupported provider.'],
            };

            if (! $result['ok']) {
                $lastError = $result['error'];

                continue;
            }

            $parsed = static::parseAiJsonPayload($result['content']);
            if ($parsed === null) {
                $lastError = "{$provider} returned non-JSON SEO output.";

                continue;
            }

            $normalized = static::normalizeGeneratedFields($parsed, $product, $fallbackFields);

            return [
                'fields' => $normalized,
                'source' => $provider,
                'model' => $result['model'],
                'error' => null,
            ];
        }

        return [
            'fields' => $fallbackFields,
            'source' => 'template',
            'model' => 'deterministic-store-optimizer',
            'error' => $lastError,
        ];
    }

    /**
     * Generates and persists optimized SEO fields on a product, refreshing sitemap & IndexNow via Product::saved.
     *
     * @return array{source: string, model: string, updated_fields: string[], error: ?string}
     */
    public static function optimizeProduct(Product $product, bool $overwriteExisting = true, bool $useLlm = true): array
    {
        $generated = static::generateForProduct($product, useLlm: $useLlm);
        $fields = $generated['fields'];
        $updated = [];

        foreach (['short_description', 'description', 'warranty', 'meta_title', 'meta_description', 'meta_keywords'] as $column) {
            $newValue = $fields[$column] ?? '';
            if ($newValue === '') {
                continue;
            }

            if ($overwriteExisting || blank($product->{$column})) {
                $product->{$column} = $newValue;
                $updated[] = $column;
            }
        }

        if ($updated !== []) {
            $product->save();
        }

        return [
            'source' => $generated['source'],
            'model' => $generated['model'],
            'updated_fields' => $updated,
            'error' => $generated['error'],
        ];
    }

    /**
     * Batch-optimizes products that have missing or sparse SEO descriptions/keywords.
     *
     * @return array{processed: int, ai_count: int, template_count: int, errors: string[]}
     */
    public static function optimizeMissingProducts(int $limit = 10): array
    {
        $products = Product::query()
            ->visible()
            ->with('category')
            ->where(function ($query): void {
                $query->whereNull('description')
                    ->orWhere('description', '')
                    ->orWhereNull('meta_keywords')
                    ->orWhere('meta_keywords', '')
                    ->orWhereNull('meta_description')
                    ->orWhere('meta_description', '')
                    ->orWhereRaw('LENGTH(description) < 350');
            })
            ->orderByDesc('is_featured')
            ->orderByDesc('sold_count')
            ->limit(max(1, min(25, $limit)))
            ->get();

        $aiCount = 0;
        $templateCount = 0;
        $errors = [];

        foreach ($products as $product) {
            $res = static::optimizeProduct($product, overwriteExisting: true, useLlm: true);
            if ($res['source'] === 'template') {
                $templateCount++;
                if ($res['error']) {
                    $errors[] = "{$product->name}: {$res['error']}";
                }
            } else {
                $aiCount++;
            }
        }

        return [
            'processed' => $products->count(),
            'ai_count' => $aiCount,
            'template_count' => $templateCount,
            'errors' => $errors,
        ];
    }

    /**
     * Tests the configured free LLM provider(s) using real SellMeTools catalog context.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{success: bool, source: string, model: string, message: string, sample?: array<string, string>}
     */
    public static function testConnection(array $overrides = []): array
    {
        if (! static::hasConfiguredLlm($overrides)) {
            return [
                'success' => false,
                'source' => 'none',
                'model' => '',
                'message' => 'Please enter at least one free API key (Groq, OpenRouter, Gemini, or Custom OpenAI-compatible) before testing.',
            ];
        }

        $sampleProduct = Product::query()->visible()->with('category')->first();
        if (! $sampleProduct) {
            $sampleProduct = new Product([
                'name' => 'ChatGPT Plus 1 Month Subscription',
                'price' => 1500,
                'delivery_type' => 'auto',
                'warranty' => 'Full 30-Day Replacement Warranty',
            ]);
        }

        $started = microtime(true);
        $res = static::generateForProduct($sampleProduct, useLlm: true, overrides: $overrides, timeoutSeconds: 15);
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        if ($res['source'] === 'template') {
            return [
                'success' => false,
                'source' => 'template',
                'model' => '',
                'message' => 'LLM API request failed and fell back to template: '.($res['error'] ?: 'Unknown error'),
            ];
        }

        return [
            'success' => true,
            'source' => $res['source'],
            'model' => $res['model'],
            'message' => "Connected to {$res['source']} ({$res['model']}) in {$elapsedMs}ms. Sample meta title: \"{$res['fields']['meta_title']}\"",
            'sample' => $res['fields'],
        ];
    }

    /**
     * Builds live, grounded training context from the store's own database, categories, pricing, and related tools.
     *
     * @return array<string, mixed>
     */
    public static function buildStoreTrainingContext(Product $product): array
    {
        $storeName = (string) Setting::get('store_name', 'SellMeTools');
        $usdtRate = max(1.0, Setting::usdtRate());
        $pricePkr = max(0, (float) ($product->price ?: $product->fixed_price ?: 0));
        $priceUsd = $pricePkr > 0 ? round($pricePkr / $usdtRate, 2) : 0.0;

        $category = $product->category;
        if (! $category && $product->category_id) {
            $category = Category::query()->find($product->category_id);
        }

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Category $c): array => [
                'name' => $c->name,
                'url' => url("/category/{$c->slug}"),
            ])
            ->all();

        $relatedProducts = Product::query()
            ->visible()
            ->when($product->id, fn ($q) => $q->where('id', '!=', $product->id))
            ->when($category?->id, fn ($q) => $q->where('category_id', $category->id))
            ->orderByDesc('is_featured')
            ->orderByDesc('sold_count')
            ->limit(5)
            ->get(['name', 'slug', 'price', 'is_free'])
            ->map(fn (Product $p): array => [
                'name' => $p->name,
                'url' => url("/products/{$p->slug}"),
                'price_pkr' => $p->isFree() ? 'Free' : money($p->price),
            ])
            ->all();

        $customBrandInstructions = trim((string) Setting::get('ai_seo_brand_instructions', ''));

        return [
            'store' => [
                'name' => $storeName,
                'url' => url('/'),
                'tagline' => (string) Setting::get('store_tagline', ''),
                'accepted_payments' => [
                    'Easypaisa',
                    'JazzCash',
                    'NayaPay',
                    'SadaPay',
                    'Bank Transfer (Raast)',
                    'Binance Pay',
                    'USDT (TRC20)',
                ],
                'whatsapp' => (string) Setting::get('support_whatsapp', ''),
                'email' => (string) Setting::get('support_email', ''),
                'custom_brand_instructions' => $customBrandInstructions,
                'categories' => $categories,
            ],
            'product' => [
                'name' => ProductSeoOptimizer::cleanProductTitle((string) $product->name) ?: (string) $product->name,
                'category' => $category?->name ?? 'Digital Subscriptions',
                'price_pkr' => $product->is_free ? 'Free' : ($pricePkr > 0 ? money($pricePkr) : 'Check live price'),
                'approx_usdt' => $priceUsd > 0 ? '$'.$priceUsd.' USDT' : null,
                'delivery_type' => (string) ($product->delivery_type ?: 'auto'),
                'delivery_label' => Product::deliveryTypeOptions()[$product->delivery_type ?: 'auto'] ?? 'Automated Digital Delivery',
                'existing_summary' => ProductSeoOptimizer::cleanRawSupplierText((string) $product->short_description),
                'existing_warranty' => ProductSeoOptimizer::cleanRawSupplierText((string) $product->warranty),
                'existing_notes' => Str::limit(ProductSeoOptimizer::cleanRawSupplierText((string) $product->description), 600),
                'related_store_products' => $relatedProducts,
            ],
        ];
    }

    protected static function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the dedicated E-commerce SEO & Product Copywriting Agent trained exclusively on SellMeTools.com's live store data.

Strict accuracy & SEO rules:
1. Ground every claim strictly in the provided STORE_DATA JSON. Never invent fake payment gateways (do NOT mention PayPal, Stripe, or Visa unless listed), never invent fake lifetime guarantees, and never cite wrong prices.
2. Write natural, conversion-focused, E-E-A-T compliant copy aimed at buyers in Pakistan and global USDT buyers.
3. NEVER use em dashes (—) or en dashes (–). Use standard hyphens (-) or commas.
4. Return ONLY a single valid JSON object (no markdown fences, no commentary outside JSON) with these exact 6 keys:
   - "short_description": 115-165 chars punchy buyer subtitle with delivery & payment trust.
   - "meta_title": 42-58 chars, keyword-frontloaded (e.g. "Buy {Tool} | Instant Delivery" or "Buy {Tool} in Pakistan").
   - "meta_description": 135-155 chars, starts with action verb ("Buy..." / "Get..."), includes exact price_pkr if fixed price > 0, and mentions instant delivery / local wallets or USDT.
   - "meta_keywords": 10-14 high-intent comma-separated SEO tags (price in Pakistan, Easypaisa/JazzCash, without credit card, USDT Binance, category, long-tail use cases).
   - "warranty": clean 1-2 sentence warranty & activation guarantee matching existing_warranty if provided.
   - "description": clean HTML (allowed tags ONLY: <h3>, <p>, <ul>, <li>, <strong>, <a href="...">). Structure into:
     * <h3>Overview &amp; Who It Is For</h3> with 1-2 helpful paragraphs
     * <h3>Key Features &amp; Benefits</h3> with 4-6 detailed <li><strong>Feature:</strong> explanation</li> items
     * <h3>Instant Delivery &amp; Activation Process</h3> explaining the exact delivery_label and order vault tracking
     * <h3>Accepted Payment Methods &amp; Related Tools</h3> mentioning Easypaisa, JazzCash, NayaPay, SadaPay, Raast & Binance USDT, and naturally linking 1-2 real URLs from related_store_products if non-empty.
     Target 260-420 words total in "description".
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected static function buildProductPrompt(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return "Generate the complete SEO JSON object for this product using the live STORE_DATA below:\n\n{$json}";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return string[]
     */
    protected static function resolveProviderChain(?string $providerOverride, array $overrides): array
    {
        $selected = $providerOverride ?: (string) ($overrides['ai_seo_provider'] ?? Setting::get('ai_seo_provider', self::PROVIDER_AUTO));
        $all = [self::PROVIDER_GROQ, self::PROVIDER_OPENROUTER, self::PROVIDER_GEMINI, self::PROVIDER_CUSTOM];

        $ordered = ($selected === self::PROVIDER_AUTO || ! in_array($selected, $all, true))
            ? $all
            : array_values(array_unique(array_merge([$selected], $all)));

        return array_values(array_filter($ordered, function (string $provider) use ($overrides): bool {
            if (static::resolveApiKey($provider, $overrides) === '') {
                return false;
            }
            if ($provider === self::PROVIDER_CUSTOM && static::resolveCustomBaseUrl($overrides) === '') {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected static function resolveApiKey(string $provider, array $overrides = []): string
    {
        $map = [
            self::PROVIDER_GROQ => ['groq_api_key', 'GROQ_API_KEY'],
            self::PROVIDER_OPENROUTER => ['openrouter_api_key', 'OPENROUTER_API_KEY'],
            self::PROVIDER_GEMINI => ['gemini_api_key', 'GEMINI_API_KEY'],
            self::PROVIDER_CUSTOM => ['custom_llm_api_key', 'AI_SEO_CUSTOM_API_KEY'],
        ];

        [$settingKey, $envKey] = $map[$provider] ?? ['', ''];
        if ($settingKey === '') {
            return '';
        }

        $override = trim((string) ($overrides[$settingKey] ?? ''));
        if ($override !== '') {
            return $override;
        }

        $stored = Setting::decryptSecret((string) Setting::get($settingKey, ''));
        if ($stored !== '') {
            return trim($stored);
        }

        return trim((string) env($envKey, ''));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected static function resolveModel(string $provider, array $overrides = []): string
    {
        $defaults = [
            self::PROVIDER_GROQ => ['groq_model', 'llama-3.3-70b-versatile'],
            self::PROVIDER_OPENROUTER => ['openrouter_model', 'meta-llama/llama-3.3-70b-instruct:free'],
            self::PROVIDER_GEMINI => ['gemini_model', 'gemini-2.5-flash'],
            self::PROVIDER_CUSTOM => ['custom_llm_model', 'gpt-4o-mini'],
        ];

        [$settingKey, $default] = $defaults[$provider] ?? ['', ''];
        $candidate = trim((string) ($overrides[$settingKey] ?? Setting::get($settingKey, $default)));

        return $candidate !== '' ? $candidate : $default;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected static function resolveCustomBaseUrl(array $overrides = []): string
    {
        $url = trim((string) ($overrides['custom_llm_base_url'] ?? Setting::get('custom_llm_base_url', (string) env('AI_SEO_CUSTOM_BASE_URL', ''))));

        return rtrim($url, '/');
    }

    /**
     * @param  array<string, string>  $extraHeaders
     * @return array{ok: bool, content: string, model: string, error: ?string}
     */
    protected static function callOpenAiCompatible(
        string $provider,
        string $endpoint,
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        int $timeoutSeconds = 12,
        array $extraHeaders = []
    ): array {
        try {
            $payload = [
                'model' => $model,
                'temperature' => 0.4,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ];

            if ($provider === self::PROVIDER_GROQ) {
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $res = Http::timeout($timeoutSeconds)
                ->withToken($apiKey)
                ->withHeaders(array_merge(['Accept' => 'application/json'], $extraHeaders))
                ->post($endpoint, $payload);

            if (! $res->successful()) {
                return [
                    'ok' => false,
                    'content' => '',
                    'model' => $model,
                    'error' => ucfirst($provider)." HTTP {$res->status()}: ".Str::limit(trim($res->body()), 180),
                ];
            }

            $content = (string) ($res->json('choices.0.message.content') ?? '');

            return [
                'ok' => $content !== '',
                'content' => $content,
                'model' => (string) ($res->json('model') ?? $model),
                'error' => $content === '' ? ucfirst($provider).' returned empty message content.' : null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'content' => '',
                'model' => $model,
                'error' => ucfirst($provider).' error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{ok: bool, content: string, model: string, error: ?string}
     */
    protected static function callCustomOpenAiCompatible(
        array $overrides,
        string $systemPrompt,
        string $userPrompt,
        int $timeoutSeconds = 12
    ): array {
        $base = static::resolveCustomBaseUrl($overrides);
        $endpoint = str_ends_with($base, '/chat/completions')
            ? $base
            : $base.'/chat/completions';

        return static::callOpenAiCompatible(
            provider: self::PROVIDER_CUSTOM,
            endpoint: $endpoint,
            apiKey: static::resolveApiKey(self::PROVIDER_CUSTOM, $overrides),
            model: static::resolveModel(self::PROVIDER_CUSTOM, $overrides),
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    /**
     * @return array{ok: bool, content: string, model: string, error: ?string}
     */
    protected static function callGemini(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        int $timeoutSeconds = 12
    ): array {
        $cleanModel = ltrim($model, '/');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$cleanModel}:generateContent?key=".urlencode($apiKey);

        try {
            $res = Http::timeout($timeoutSeconds)
                ->acceptJson()
                ->post($endpoint, [
                    'systemInstruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.4,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (! $res->successful()) {
                return [
                    'ok' => false,
                    'content' => '',
                    'model' => $cleanModel,
                    'error' => "Gemini HTTP {$res->status()}: ".Str::limit(trim($res->body()), 180),
                ];
            }

            $content = (string) ($res->json('candidates.0.content.parts.0.text') ?? '');

            return [
                'ok' => $content !== '',
                'content' => $content,
                'model' => $cleanModel,
                'error' => $content === '' ? 'Gemini returned empty candidate text.' : null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'content' => '',
                'model' => $cleanModel,
                'error' => 'Gemini error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function parseAiJsonPayload(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        // Strip markdown ```json ... ``` wrapper if a model emits one anyway.
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/is', '', $trimmed) ?? $trimmed;

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $trimmed, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $fallback
     * @return array{short_description: string, description: string, warranty: string, meta_title: string, meta_description: string, meta_keywords: string}
     */
    protected static function normalizeGeneratedFields(array $raw, Product $product, array $fallback): array
    {
        $cleanText = static function (mixed $value): string {
            if (! is_string($value)) {
                return '';
            }

            return trim(ProductSeoOptimizer::stripEmDashes($value));
        };

        $shortDesc = $cleanText($raw['short_description'] ?? '');
        if ($shortDesc === '') {
            $shortDesc = $fallback['short_description'];
        }
        $shortDesc = Str::limit($shortDesc, 495, '');

        $metaTitle = ProductSeoOptimizer::cleanProductTitle($cleanText($raw['meta_title'] ?? ''));
        if ($metaTitle === '') {
            $metaTitle = $fallback['meta_title'];
        }
        if (mb_strlen($metaTitle) > 58) {
            $cut = preg_replace('/\s+\S*$/u', '', mb_substr($metaTitle, 0, 58)) ?: mb_substr($metaTitle, 0, 58);
            $metaTitle = rtrim($cut, ' |-,:;');
        }

        $metaDesc = $cleanText($raw['meta_description'] ?? '');
        if ($metaDesc === '') {
            $metaDesc = $fallback['meta_description'];
        }
        $metaDesc = static::enforceMetaDescriptionPrice($metaDesc, $product);
        if (mb_strlen($metaDesc) > 155) {
            $cut = preg_replace('/\s+\S*$/u', '', mb_substr($metaDesc, 0, 155)) ?: mb_substr($metaDesc, 0, 155);
            $metaDesc = rtrim($cut, ' .,;:-');
        }

        $keywords = $raw['meta_keywords'] ?? '';
        if (is_array($keywords)) {
            $keywords = implode(', ', array_filter(array_map('strval', $keywords)));
        }
        $keywords = $cleanText($keywords);
        if ($keywords === '') {
            $keywords = $fallback['meta_keywords'];
        }
        $keywords = ProductSeoOptimizer::capKeywords($keywords, 490);

        $warranty = $cleanText($raw['warranty'] ?? '');
        if ($warranty === '') {
            $warranty = $fallback['warranty'];
        }

        $description = $cleanText($raw['description'] ?? '');
        if ($description !== '') {
            $description = strip_tags($description, '<h2><h3><h4><p><br><ul><ol><li><strong><b><em><i><u><s><a><blockquote><code><hr>');
        }
        if (trim(strip_tags($description)) === '') {
            $description = $fallback['description'];
        }

        return [
            'short_description' => $shortDesc,
            'description' => $description,
            'warranty' => $warranty,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDesc,
            'meta_keywords' => $keywords,
        ];
    }

    protected static function enforceMetaDescriptionPrice(string $metaDesc, Product $product): string
    {
        $price = (float) ($product->price ?: $product->fixed_price ?: 0);
        if ($product->is_free || $price <= 0) {
            return $metaDesc;
        }

        $formattedPrice = money($price);
        $plainDigits = (string) ((int) round($price));
        if (str_contains($metaDesc, $plainDigits) || str_contains($metaDesc, number_format($price, 0))) {
            return $metaDesc;
        }

        $prefix = "Only {$formattedPrice} - ";
        if (mb_strlen($prefix.$metaDesc) <= 155) {
            return $prefix.$metaDesc;
        }

        return $metaDesc;
    }
}

