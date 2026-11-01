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
        // Keyword targets researched against Pakistani competitors (SasteTools, DigiFaizan, digitaltools.com.pk):
        // "<tool> price in pakistan" and "buy <tool> in pakistan" are the highest-intent search patterns.
        'seo_title' => 'AI Tools & Premium Subscriptions Price in Pakistan',
        'seo_description' => 'Buy ChatGPT Plus, Canva Pro, CapCut Pro, Claude, Cursor and more in Pakistan at local prices. Pay with Easypaisa, JazzCash, NayaPay, SadaPay or Binance.',
        'seo_keywords' => 'ai tools price in pakistan, premium tools pakistan, buy digital subscriptions in pakistan, chatgpt plus price in pakistan, buy chatgpt plus in pakistan, canva pro price in pakistan, capcut pro price in pakistan, claude pro price in pakistan, gemini pro price in pakistan, cursor pro price in pakistan, grammarly premium price in pakistan, netflix subscription price in pakistan, midjourney price in pakistan, premium accounts easypaisa jazzcash, cheap ai tools pakistan, sellmetools',
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
