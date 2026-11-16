<?php

namespace Tests\Feature;

use App\Filament\Pages\StoreSettings;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiSeoAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AiSeoAgentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->category = Category::create([
            'name' => 'AI Tools',
            'slug' => 'ai-tools',
            'is_active' => true,
            'sort' => 1,
        ]);

        Product::create([
            'category_id' => $this->category->id,
            'name' => 'Claude Pro 1 Month Subscription',
            'slug' => 'claude-pro-1-month',
            'price' => 2500,
            'fixed_price' => 2500,
            'is_active' => true,
            'stock_unlimited' => true,
        ]);
    }

    public function test_new_or_updated_product_automatically_appears_in_sitemap_and_populates_seo(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Perplexity Pro 1 Year Voucher',
            'price' => 3200,
            'fixed_price' => 3200,
            'is_active' => true,
            'stock_unlimited' => true,
        ]);

        $this->assertNotEmpty($product->slug);
        $this->assertNotEmpty($product->short_description);
        $this->assertStringContainsString('<h3>', (string) $product->description);
        $this->assertNotEmpty($product->meta_title);
        $this->assertNotEmpty($product->meta_description);
        $this->assertNotEmpty($product->meta_keywords);

        $sitemap = $this->get('/sitemap.xml');
        $sitemap->assertOk();
        $sitemap->assertSee('/products/'.$product->slug);

        $newSlug = 'perplexity-pro-1-year-official';
        $product->update([
            'name' => 'Perplexity Pro 1 Year Official Voucher',
            'slug' => $newSlug,
        ]);

        $updatedSitemap = $this->get('/sitemap.xml');
        $updatedSitemap->assertOk();
        $updatedSitemap->assertSee('/products/'.$newSlug);
    }

    public function test_ai_seo_agent_trains_on_live_store_data_and_uses_free_llm_apis_with_fallback(): void
    {
        Setting::set('ai_seo_auto_enabled', '1');
        Setting::set('ai_seo_provider', AiSeoAgent::PROVIDER_AUTO);
        Setting::set('groq_api_key', Setting::encryptSecret('gsk_test_key_123'));
        Setting::set('openrouter_api_key', Setting::encryptSecret('sk-or-test-456'));

        Http::fake([
            'https://api.groq.com/*' => Http::response(['error' => 'rate limited'], 429),
            'https://openrouter.ai/*' => Http::response([
                'model' => 'meta-llama/llama-3.3-70b-instruct:free',
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'short_description' => 'Buy Cursor Pro 1 Month Subscription in Pakistan with instant delivery via Easypaisa, JazzCash & USDT.',
                            'meta_title' => 'Buy Cursor Pro 1 Month | Instant Delivery',
                            'meta_description' => 'Buy Cursor Pro 1 Month subscription for Rs 4,500 in Pakistan. Instant automated delivery via Easypaisa, JazzCash & Binance USDT.',
                            'meta_keywords' => 'cursor pro pakistan, buy cursor pro subscription, cursor ai price in pakistan, buy cursor pro easypaisa jazzcash, cursor pro usdt',
                            'warranty' => 'Full 30-day replacement and activation guarantee included.',
                            'description' => '<h3>Overview &amp; Who It Is For</h3><p>Get Cursor Pro AI coding IDE at an unbeatable PKR price on SellMeTools.</p><h3>Key Features &amp; Benefits</h3><ul><li><strong>Fast AI Coding:</strong> Claude &amp; GPT coding completions included.</li></ul><script>alert(1)</script>',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Cursor Pro 1 Month Subscription',
            'price' => 4500,
            'fixed_price' => 4500,
            'is_active' => true,
            'stock_unlimited' => true,
        ]);

        $this->assertSame('Buy Cursor Pro 1 Month | Instant Delivery', $product->meta_title);
        $this->assertStringContainsString('4,500', (string) $product->meta_description);
        $this->assertStringContainsString('cursor pro pakistan', (string) $product->meta_keywords);
        $this->assertStringNotContainsString('<script>', (string) $product->description);
        $this->assertStringContainsString('<h3>Overview', (string) $product->description);

        $context = AiSeoAgent::buildStoreTrainingContext($product);
        $this->assertContains('Easypaisa', $context['store']['accepted_payments']);
        $this->assertSame('AI Tools', $context['product']['category']);
        $this->assertNotEmpty($context['product']['related_store_products']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'openrouter.ai')) {
                return false;
            }
            $body = $request->body();

            return str_contains($body, 'Claude Pro 1 Month Subscription')
                && str_contains($body, 'Easypaisa');
        });
    }

    public function test_store_settings_encrypts_llm_keys_and_never_exposes_them_to_browser(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(StoreSettings::class)
            ->fillForm([
                'ai_seo_auto_enabled' => true,
                'ai_seo_provider' => AiSeoAgent::PROVIDER_GROQ,
                'groq_api_key' => 'gsk_super_secret_999',
                'openrouter_api_key' => 'sk_or_secret_888',
                'gemini_api_key' => 'AIza_secret_777',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $rawGroq = (string) Setting::query()->where('key', 'groq_api_key')->value('value');
        $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, $rawGroq);
        $this->assertSame('gsk_super_secret_999', Setting::decryptSecret($rawGroq));
        $this->assertSame('sk_or_secret_888', Setting::decryptSecret((string) Setting::get('openrouter_api_key')));
        $this->assertSame('AIza_secret_777', Setting::decryptSecret((string) Setting::get('gemini_api_key')));

        // Mounting StoreSettings again must keep secret inputs blank.
        Livewire::test(StoreSettings::class)
            ->assertSchemaStateSet([
                'groq_api_key' => '',
                'openrouter_api_key' => '',
                'gemini_api_key' => '',
            ], 'form');
    }

    public function test_groq_auto_discovers_available_model_on_404_and_individual_provider_tests_work(): void
    {
        Setting::set('groq_api_key', Setting::encryptSecret('gsk_live_key'));
        Setting::set('groq_model', 'llama-3.3-70b-versatile');
        Setting::set('openrouter_api_key', Setting::encryptSecret('sk_or_key'));
        Setting::set('gemini_api_key', Setting::encryptSecret('AIza_gemini_key'));

        Http::fake(function ($request) {
            $url = $request->url();

            if ($url === 'https://api.groq.com/openai/v1/models') {
                return Http::response([
                    'data' => [
                        ['id' => 'whisper-large-v3', 'active' => true],
                        ['id' => 'qwen/qwen3-32b', 'active' => true],
                    ],
                ], 200);
            }

            if ($url === 'https://api.groq.com/openai/v1/chat/completions') {
                $body = json_decode($request->body(), true);
                if (($body['model'] ?? '') !== 'qwen/qwen3-32b') {
                    return Http::response([
                        'error' => ['message' => 'model_not_found', 'code' => 'model_not_found'],
                    ], 404);
                }

                return Http::response([
                    'model' => 'qwen/qwen3-32b',
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'short_description' => 'Buy Claude Pro subscription with instant Easypaisa delivery.',
                                'meta_title' => 'Buy Claude Pro 1 Month | Instant Delivery',
                                'meta_description' => 'Buy Claude Pro 1 Month subscription for Rs 2,500 in Pakistan with instant delivery.',
                                'meta_keywords' => 'claude pro pakistan, buy claude pro',
                                'warranty' => 'Full 30-day warranty included.',
                                'description' => '<h3>Overview</h3><p>Claude Pro subscription on SellMeTools.</p>',
                            ]),
                        ],
                    ]],
                ], 200);
            }

            if (str_contains($url, 'generativelanguage.googleapis.com')) {
                return Http::response([
                    'candidates' => [[
                        'content' => [
                            'parts' => [[
                                'text' => json_encode([
                                    'short_description' => 'Gemini generated summary for Claude Pro.',
                                    'meta_title' => 'Buy Claude Pro | Best Price Pakistan',
                                    'meta_description' => 'Buy Claude Pro for Rs 2,500 via Easypaisa, JazzCash & USDT.',
                                    'meta_keywords' => 'claude pro, ai tools pakistan',
                                    'warranty' => 'Full warranty.',
                                    'description' => '<h3>Overview</h3><p>Generated by Gemini Flash.</p>',
                                ]),
                            ]],
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['error' => 'rate limited'], 429);
        });

        $groqRes = AiSeoAgent::testProvider(AiSeoAgent::PROVIDER_GROQ);
        $this->assertTrue($groqRes['success']);
        $this->assertSame('groq', $groqRes['source']);
        $this->assertSame('qwen/qwen3-32b', $groqRes['model']);

        // OpenRouter returns 429 — individual test must fail directly without auto-shifting.
        $orRes = AiSeoAgent::testProvider(AiSeoAgent::PROVIDER_OPENROUTER);
        $this->assertFalse($orRes['success']);
        $this->assertSame('openrouter', $orRes['source']);

        // Gemini works directly.
        $geminiRes = AiSeoAgent::testProvider(AiSeoAgent::PROVIDER_GEMINI);
        $this->assertTrue($geminiRes['success']);
        $this->assertSame('gemini', $geminiRes['source']);

        // Auto-shift chain starting with OpenRouter (429) shifts automatically to Groq (200).
        Setting::set('ai_seo_provider', AiSeoAgent::PROVIDER_OPENROUTER);
        $chainRes = AiSeoAgent::testConnection();
        $this->assertTrue($chainRes['success']);
        $this->assertSame('groq', $chainRes['source']);
        $this->assertStringContainsString('Auto-shifted', $chainRes['message']);

        // If Groq & OpenRouter are both unavailable, auto-shift reaches Gemini.
        Setting::set('groq_api_key', '');
        $geminiChainRes = AiSeoAgent::testConnection();
        $this->assertTrue($geminiChainRes['success']);
        $this->assertSame('gemini', $geminiChainRes['source']);
        $this->assertStringContainsString('Auto-shifted', $geminiChainRes['message']);
    }

    public function test_ai_seo_optimization_saves_backup_and_can_be_reverted_with_full_training_context(): void
    {
        Setting::set('groq_api_key', Setting::encryptSecret('gsk_test_key_revert'));

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'model' => 'openai/gpt-oss-20b',
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'short_description' => 'AI upgraded subtitle for ChatGPT Plus with instant PKR & USDT delivery.',
                            'meta_title' => 'Buy ChatGPT Plus AI Upgraded | Instant Delivery',
                            'meta_description' => 'Buy ChatGPT Plus for Rs 1,800 via Easypaisa, JazzCash & Binance USDT.',
                            'meta_keywords' => 'chatgpt plus ai upgraded, buy chatgpt plus pakistan',
                            'warranty' => 'AI upgraded 30-day warranty.',
                            'description' => '<h3>Buy ChatGPT Plus Online</h3><p>Rich AI-written SEO description.</p>',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'ChatGPT Plus 1 Month Official',
            'price' => 1800,
            'fixed_price' => 1800,
            'short_description' => 'Original custom subtitle',
            'description' => '<h3>Original Heading</h3><p>Original hand-written description.</p>',
            'warranty' => 'Original 7-day warranty',
            'meta_title' => 'Original Meta Title',
            'meta_description' => 'Original meta description for Rs 1,800.',
            'meta_keywords' => 'original tag 1, original tag 2',
            'is_active' => true,
            'stock_unlimited' => true,
        ]);

        $context = AiSeoAgent::buildStoreTrainingContext($product);
        $this->assertArrayHasKey('top_selling_products', $context['store']);
        $this->assertArrayHasKey('sellmetools_style_reference', $context);
        $this->assertNotEmpty($context['sellmetools_style_reference']);
        $this->assertSame('Unlimited Instant Stock', $context['product']['stock_status']);

        $this->assertFalse($product->hasSeoBackup());

        $res = AiSeoAgent::optimizeProduct($product, overwriteExisting: true, useLlm: true);
        $this->assertSame('groq', $res['source']);

        $product->refresh();
        $this->assertTrue($product->hasSeoBackup());
        $this->assertSame('Buy ChatGPT Plus AI Upgraded | Instant Delivery', $product->meta_title);
        $this->assertStringContainsString('Rich AI-written SEO description', (string) $product->description);

        $reverted = AiSeoAgent::revertProduct($product);
        $this->assertTrue($reverted);

        $product->refresh();
        $this->assertFalse($product->hasSeoBackup());
        $this->assertSame('Original Meta Title', $product->meta_title);
        $this->assertSame('Original custom subtitle', $product->short_description);
        $this->assertSame('<h3>Original Heading</h3><p>Original hand-written description.</p>', $product->description);
        $this->assertSame('Original 7-day warranty', $product->warranty);
        $this->assertSame('Original meta description for Rs 1,800.', $product->meta_description);
        $this->assertSame('original tag 1, original tag 2', $product->meta_keywords);
    }
}

