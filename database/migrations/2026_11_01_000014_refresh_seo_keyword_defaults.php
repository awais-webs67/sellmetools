<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Refresh homepage SEO text to the researched keyword set, but only where the
 * store still uses an older built-in default. Custom text typed by the admin is kept.
 */
return new class extends Migration
{
    private const OLD_DEFAULTS = [
        'seo_title' => [
            'Buy ChatGPT Plus, Canva Pro, CapCut Pro & Digital Subscriptions in Pakistan',
        ],
        'seo_description' => [
            "Pakistan's #1 digital tools & subscriptions store. Buy genuine ChatGPT Plus, Canva Pro, CapCut Pro, Cursor, Netflix, VPNs & cloud accounts at wholesale rates. Instant delivery via Easypaisa, JazzCash, NayaPay, SadaPay & Binance.",
        ],
        'seo_keywords' => [
            'buy chatgpt plus in pakistan, chatgpt 4o price in pakistan, canva pro 1 year pakistan, capcut pro subscription pkr, midjourney subscription pakistan, cursor pro subscription pakistan, claude pro ai pakistan, netflix shared account pkr, cheap premium tools pakistan, buy digital subscriptions easypaisa, buy software jazzcash, binance usdt digital store pakistan, sellmetools pakistan',
        ],
    ];

    private const NEW_VALUES = [
        'seo_title' => 'AI Tools & Premium Subscriptions Price in Pakistan',
        'seo_description' => 'Buy ChatGPT Plus, Canva Pro, CapCut Pro, Claude, Cursor and more in Pakistan at local prices. Pay with Easypaisa, JazzCash, NayaPay, SadaPay or Binance.',
        'seo_keywords' => 'ai tools price in pakistan, premium tools pakistan, buy digital subscriptions in pakistan, chatgpt plus price in pakistan, buy chatgpt plus in pakistan, canva pro price in pakistan, capcut pro price in pakistan, claude pro price in pakistan, gemini pro price in pakistan, cursor pro price in pakistan, grammarly premium price in pakistan, netflix subscription price in pakistan, midjourney price in pakistan, premium accounts easypaisa jazzcash, cheap ai tools pakistan, sellmetools',
    ];

    public function up(): void
    {
        foreach (self::NEW_VALUES as $key => $value) {
            $current = DB::table('settings')->where('key', $key)->value('value');

            if ($current === null || trim((string) $current) === '' || in_array(trim((string) $current), self::OLD_DEFAULTS[$key], true)) {
                DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now()]);
            }
        }

        Cache::forget('app_settings');
    }

    public function down(): void
    {
        // Content-only change; nothing to roll back.
    }
};
