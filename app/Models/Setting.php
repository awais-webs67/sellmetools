<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    private const CACHE_KEY = 'app_settings';

    /** Default values used when a setting has never been saved. */
    public const DEFAULTS = [
        'store_name' => 'SellMeTools',
        'store_tagline' => 'Premium tools & subscriptions, delivered instantly.',
        'store_logo' => '',
        'usdt_rate' => '295',
        'price_rounding' => '10',
        'order_expiry_minutes' => '15',
        'currency_symbol' => 'Rs',
        'sms_webhook_secret' => 'smt_auto_pay_secret_2026',
        'admin_quick_pin' => '7860',
        'whatsapp_callmebot_apikey' => '',
        'whatsapp_admin_webhook_url' => '',
        'telegram_bot_token' => '',
        'telegram_admin_chat_id' => '',
        'support_whatsapp' => '',
        'support_telegram' => '',
        'support_email' => '',
        'announcement' => '',
        'seo_title' => 'Buy ChatGPT Plus, Canva Pro, CapCut Pro & Digital Subscriptions in Pakistan',
        'seo_description' => 'Pakistan\'s #1 digital tools & subscriptions store. Buy genuine ChatGPT Plus, Canva Pro, CapCut Pro, Cursor, Netflix, VPNs & cloud accounts at wholesale rates. Instant delivery via Easypaisa, JazzCash, NayaPay, SadaPay & Binance.',
        'seo_keywords' => 'buy chatgpt plus in pakistan, canva pro pakistan, capcut pro price in pakistan, digital subscriptions pakistan, buy ai tools easypaisa jazzcash, cheap premium accounts pakistan, sellmetools',
        'google_site_verification' => '',
        'google_analytics_id' => '',
    ];

    public static function values(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return array_merge(self::DEFAULTS, static::query()->pluck('value', 'key')->all());
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::values()[$key] ?? null;

        return ($value === null || $value === '') ? ($default ?? self::DEFAULTS[$key] ?? null) : $value;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    public static function usdtRate(): float
    {
        return max(0.0001, (float) static::get('usdt_rate'));
    }
}
