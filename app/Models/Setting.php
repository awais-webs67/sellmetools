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
        'greenapi_instance_id' => '',
        'greenapi_api_token' => '',
        'ntfy_topic' => '',
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
        'seo_keywords' => 'buy chatgpt plus in pakistan, chatgpt 4o price in pakistan, canva pro 1 year pakistan, capcut pro subscription pkr, midjourney subscription pakistan, cursor pro subscription pakistan, claude pro ai pakistan, netflix shared account pkr, cheap premium tools pakistan, buy digital subscriptions easypaisa, buy software jazzcash, binance usdt digital store pakistan, sellmetools pakistan',
        'google_site_verification' => '',
        'google_analytics_id' => '',
        'mail_enabled' => '1',
        'mail_send_order_delivered' => '1',
        'mail_host' => '',
        'mail_port' => '465',
        'mail_username' => '',
        'mail_password' => '',
        'mail_encryption' => 'ssl',
        'mail_from_address' => '',
        'mail_from_name' => 'SellMeTools',
    ];

    public static function values(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $db = static::query()->pluck('value', 'key')->all();
            $out = self::DEFAULTS;
            foreach ($db as $k => $v) {
                if ($v !== null && $v !== '') {
                    $out[$k] = $v;
                }
            }

            return $out;
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
