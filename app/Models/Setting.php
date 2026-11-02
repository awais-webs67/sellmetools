<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    private const CACHE_KEY = 'app_settings_v2';

    /** Default values used when a setting has never been saved. */
    public const DEFAULTS = [
        'store_name' => 'SellMeTools',
        'store_tagline' => 'Buy genuine AI tools & digital subscriptions online with instant worldwide delivery.',
        'store_logo' => '',
        'store_theme' => 'black',
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
        // General store-wide SEO targets for global + local digital tools & subscriptions marketplace:
        'seo_title' => 'Buy AI Tools & Digital Subscriptions Online',
        'seo_description' => 'Buy genuine AI tools, creative software, cloud accounts and digital subscriptions worldwide. Pay via Binance USDT or local PKR wallets with instant delivery.',
        'seo_keywords' => 'buy ai tools online, digital subscriptions store, cheap ai tools usdt, buy software subscriptions online, cloud accounts, creative tools, binance pay digital store, easypaisa jazzcash subscriptions, sellmetools',
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
                    if ($k === 'seo_title' && str_contains($v, 'in Pakistan')) {
                        continue;
                    }
                    if ($k === 'seo_description' && str_contains($v, 'in Pakistan at local PKR prices')) {
                        continue;
                    }
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
