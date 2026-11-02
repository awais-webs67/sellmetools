<?php

namespace Tests\Feature;

use App\Enums\PricingMode;
use App\Models\MediaItem;
use App\Services\PriceCalculator;
use App\Support\TextCleaner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use CreatesStore, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    public function test_fixed_price_is_used_as_is(): void
    {
        $sp = $this->supplierProduct($this->supplier(), ['cost' => 1.00]); // Rs 300
        $product = $this->listing([$sp], ['fixed_price' => 550]);

        $this->assertSame(550.0, (float) $product->price);
        $this->assertSame(300.0, (float) $product->cost_price);
    }

    public function test_markup_amount_and_percent_with_rounding(): void
    {
        $sp = $this->supplierProduct($this->supplier(), ['cost' => 1.017]); // Rs 305.10

        $amount = $this->listing([$sp], ['name' => 'A', 'pricing_mode' => PricingMode::MarkupAmount, 'markup_value' => 250]);
        $this->assertSame(560.0, (float) $amount->price); // 555.10 → ceil to 10

        $percent = $this->listing([$sp], ['name' => 'B', 'pricing_mode' => PricingMode::MarkupPercent, 'markup_value' => 50]);
        $this->assertSame(460.0, (float) $percent->price); // 457.65 → 460
    }

    public function test_cheapest_sellable_source_defines_cost(): void
    {
        $expensive = $this->supplierProduct($this->supplier(), ['cost' => 2.0]);
        $cheap = $this->supplierProduct($this->supplier(), ['cost' => 1.0]);
        $outOfStock = $this->supplierProduct($this->supplier(), ['cost' => 0.5, 'stock' => 0]);

        $product = $this->listing([$expensive, $cheap, $outOfStock]);

        $this->assertSame(300.0, (float) $product->cost_price);
    }

    public function test_fixed_price_product_is_auto_disabled_when_cost_exceeds_price_and_reenabled_later(): void
    {
        $sp = $this->supplierProduct($this->supplier(), ['cost' => 1.0]);
        $product = $this->listing([$sp], ['fixed_price' => 550]);

        $sp->update(['cost' => 2.0]); // Rs 600 > 550
        $result = app(PriceCalculator::class)->refresh($product->refresh());

        $this->assertTrue($result['disabled']);
        $this->assertFalse($product->refresh()->is_active);
        $this->assertSame(PriceCalculator::REASON_BELOW_COST, $product->disabled_reason);

        $sp->update(['cost' => 1.5]); // Rs 450 < 550
        $result = app(PriceCalculator::class)->refresh($product->refresh());

        $this->assertTrue($result['enabled']);
        $this->assertTrue($product->refresh()->is_active);
    }

    public function test_text_cleaner_strips_telegram_markup(): void
    {
        $raw = "NON \n{{ce:6028551194861899805|??}}HOLD: HOLD WARRANTY JUST 2-HOURS{{ce:6267039884016358504|??}}";
        $this->assertSame("NON\nHOLD: HOLD WARRANTY JUST 2-HOURS", TextCleaner::clean($raw));

        $html = '<blockquote><b>YOU WILL RECEIVE A LINK\nREDEEM IT</b></blockquote>';
        $this->assertSame("YOU WILL RECEIVE A LINK\nREDEEM IT", TextCleaner::clean($html));
    }

    public function test_out_of_stock_markup_product_has_zero_price_and_seo_outputs_never_leak_rs_zero(): void
    {
        $sp = $this->supplierProduct($this->supplier(), ['cost' => 1.0, 'stock' => 0]); // Out of stock, cost Rs 300
        $product = $this->listing([$sp], [
            'name' => 'CapCut Pro 1M (FW)',
            'pricing_mode' => PricingMode::MarkupAmount,
            'markup_value' => 250,
            'fixed_price' => 0,
            'short_description' => 'YOU WILL RECEIVE A CAPCUT PRO TEAM PLAN 1 MONTH READY-MADE ACCOUNT.',
        ]);

        // Out-of-stock supplier product has 0 price and is NOT marked as free unless is_free is true
        $this->assertSame(0.0, (float) $product->price);
        $this->assertFalse($product->isFree());
        $this->assertSame('CapCut', $product->brandName());
        $this->assertStringNotContainsString('YOU WILL RECEIVE', $product->seoDescription());
        $this->assertStringContainsString('You will receive a capcut pro team plan', $product->seoDescription());
        $this->assertStringNotContainsString('Rs 0', $product->fresh()->seoDescription());

        $this->get(route('llms'))
            ->assertOk()
            ->assertDontSee('Price: **Rs 0**');

        $this->get(route('feed.google-shopping'))
            ->assertOk()
            ->assertDontSee('0.00 PKR');
    }

    public function test_updating_media_item_in_gallery_automatically_updates_linked_products(): void
    {
        $media = MediaItem::query()->create([
            'name' => 'Canva Pro Cover',
            'path' => 'gallery/canva-old.webp',
        ]);

        $product = $this->listing([], [
            'name' => 'Canva Pro 1 Month',
            'image' => $media->path,
        ]);

        $media->update(['path' => 'gallery/canva-new.webp']);

        $this->assertSame('gallery/canva-new.webp', $product->fresh()->image);
    }
}
