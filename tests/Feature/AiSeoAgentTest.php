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
}

