<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    private const CACHE_KEY = 'app_settings_v2';

    /** @var array<string, mixed>|null In-memory memoized settings per PHP request (0 repeated DB/Cache hits). */
    private static ?array $memoized = null;

    /** Default values used when a setting has never been saved. */
    public const DEFAULTS = [
        'store_name' => 'SellMeTools',
        'store_tagline' => 'Buy genuine AI tools & digital subscriptions online with instant worldwide delivery.',
        'store_logo' => '',
        'store_theme' => 'black',
        'shop_default_sort' => 'alpha',
        'home_default_sort' => 'random',
        'usdt_rate' => '295',
        'price_rounding' => '10',
        'order_expiry_minutes' => '15',
        'supplier_relay_url' => '',
        'currency_symbol' => 'Rs',
        'sms_webhook_secret' => 'smt_auto_pay_secret_2026',
        'admin_quick_pin' => '7860',
        'greenapi_instance_id' => '',
        'greenapi_api_token' => '',
        'whatsapp_send_customer_receipt' => '0',
        'whatsapp_include_customer_chat_link' => '0',
        'ntfy_topic' => '',
        'whatsapp_callmebot_apikey' => '',
        'whatsapp_admin_webhook_url' => '',
        'telegram_bot_token' => '',
        'telegram_admin_chat_id' => '',
        'telegram_store_bot_enabled' => '1',
        'support_whatsapp' => '',
        'support_telegram' => '',
        'support_email' => '',
        'footer_address_enabled' => '1',
        'footer_address' => 'Vilage Dongray Kalan Post Office Pindi Purbian city Zafarwal district narowal',
        'announcement' => '',
        // Editable Homepage & Shop Site Content (manageable by Super Admin & SEO Specialist):
        'home_hero_badge' => 'Global Digital Marketplace · Instant Worldwide Delivery',
        'home_hero_title' => 'Buy AI Tools & Digital Subscriptions Online',
        'home_hero_subtitle' => '',
        'home_about_heading' => 'Pakistan’s Trusted Marketplace for AI Tools & Subscriptions',
        'home_guide_heading' => 'Buy AI Tools & Digital Subscriptions Online — USDT & Local PKR Prices',
        'home_seo_content' => '',
        'shop_seo_title' => 'Shop All AI Tools, Premium Software & Digital Subscriptions',
        'shop_seo_description' => 'Browse 75+ genuine AI tools, SEO software, design suites, VPNs & streaming subscriptions at best prices in Pakistan & worldwide. Instant delivery via PKR or USDT.',
        // General store-wide SEO targets for global + local digital tools & subscriptions marketplace:
        'seo_title' => 'Buy AI Tools, Premium Software & Digital Subscriptions Online',
        'seo_description' => 'Trusted marketplace to buy genuine AI tools, SEO software, creative suites, VPNs, cloud & streaming subscriptions in Pakistan & worldwide. Instant delivery via PKR & USDT.',
        'seo_keywords' => 'buy ai tools in pakistan, chatgpt plus price in pakistan, capcut pro price in pakistan, canva pro price in pakistan, buy linkedin premium pakistan, ahrefs semrush bundle pakistan, claude pro price in pakistan, gemini pro subscription pakistan, surfshark vpn price in pakistan, expressvpn price in pakistan, youtube premium price in pakistan, envato elements pakistan, cursor pro pakistan, cheap digital subscriptions easypaisa jazzcash, buy tools without credit card pakistan, binance usdt digital store, sellmetools',
        'google_site_verification' => '',
        'bing_site_verification' => '',
        'indexnow_key' => 'a7f9c2e4b8d106359a2c4e6f8b0d2e4a',
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
        'backup_enabled' => '0',
        'backup_frequency' => 'weekly',
        'backup_email' => '',
        'backup_last_sent_at' => '',
        // Promotional Welcome Popup / Banner for First-Time Visitors:
        'promo_popup_enabled' => '0',
        'promo_popup_type' => 'both',
        'promo_popup_badge' => 'OFFICIAL CHANNEL & UPDATES',
        'promo_popup_title' => 'Join Our Official WhatsApp Channel for Exclusive Discounts',
        'promo_popup_body' => 'Get instant updates on restocks, flash deals, and new AI tools & digital subscriptions directly on your phone.',
        'promo_popup_image' => '',
        'promo_popup_cta_text' => 'Join Official Channel',
        'promo_popup_cta_url' => '',
        'promo_popup_version' => 'v1',
    ];

    public static function values(): array
    {
        if (self::$memoized !== null) {
            return self::$memoized;
        }

        self::$memoized = Cache::rememberForever(self::CACHE_KEY, function () {
            $db = static::query()->pluck('value', 'key')->all();
            $out = self::DEFAULTS;
            foreach ($db as $k => $v) {
                if ($v !== null && $v !== '') {
                    if ($k === 'seo_title' && in_array($v, [
                        'Buy AI Tools & Cheap Digital Subscriptions Online',
                        'Buy ChatGPT Plus, CapCut Pro, Canva Pro & AI Tools in Pakistan',
                    ], true)) {
                        continue;
                    }
                    $out[$k] = $v;
                }
            }

            return $out;
        });

        return self::$memoized;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::values()[$key] ?? null;

        return ($value === null || $value === '') ? ($default ?? self::DEFAULTS[$key] ?? null) : $value;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        self::$memoized = null;
        Cache::forget(self::CACHE_KEY);
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        self::$memoized = null;
        Cache::forget(self::CACHE_KEY);
    }

    public static function usdtRate(): float
    {
        return max(0.0001, (float) static::get('usdt_rate'));
    }
}
