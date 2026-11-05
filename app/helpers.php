<?php

use App\Models\PaymentMethod;
use App\Models\Setting;

if (! function_exists('money')) {
    /** Formats a PKR amount, e.g. "Rs 1,250". */
    function money(float|int|string|null $amount, ?string $currency = null): string
    {
        $amount = (float) $amount;
        $currency ??= Setting::get('currency_symbol', 'Rs');
        $decimals = floor($amount) == $amount ? 0 : 2;

        return $currency.' '.number_format($amount, $decimals);
    }
}

if (! function_exists('money_usd')) {
    /** Converts a PKR amount into formatted USD/USDT using the configured usdt_rate, e.g. "$4.07". */
    function money_usd(float|int|string|null $pkrAmount, string $prefix = '$'): string
    {
        $pkrAmount = (float) $pkrAmount;
        if ($pkrAmount <= 0) {
            return $prefix.'0.00';
        }
        $rate = Setting::usdtRate();
        $usd = $pkrAmount / max(1.0, $rate);

        return $prefix.number_format($usd, 2);
    }
}

if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }
}

if (! function_exists('format_whatsapp')) {
    /**
     * Normalizes a phone number into strict E.164 digits without '+' (e.g. 03001234567 -> 923001234567).
     */
    function format_whatsapp(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Pakistani 03XXXXXXXXX (11 digits) -> 923XXXXXXXXX
        if (strlen($digits) === 11 && str_starts_with($digits, '03')) {
            return '92'.substr($digits, 1);
        }

        // Pakistani 3XXXXXXXXX (10 digits without leading 0) -> 923XXXXXXXXX
        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            return '92'.$digits;
        }

        // 9203XXXXXXXXX -> 923XXXXXXXXX
        if (strlen($digits) === 13 && str_starts_with($digits, '9203')) {
            return '92'.substr($digits, 3);
        }

        // Saudi 05XXXXXXXX (10 digits) -> 9665XXXXXXXX
        if (strlen($digits) === 10 && str_starts_with($digits, '05')) {
            return '966'.substr($digits, 1);
        }

        // Saudi 96605XXXXXXXX (13 digits) -> 9665XXXXXXXX
        if (strlen($digits) === 13 && str_starts_with($digits, '96605')) {
            return '966'.substr($digits, 4);
        }

        return $digits;
    }
}

if (! function_exists('admin_whatsapp_number')) {
    /**
     * Resolves the Admin's direct WhatsApp number in strict 923XXXXXXXXX format,
     * falling back across payment methods and store settings so wa.me links ALWAYS open a direct chat.
     */
    function admin_whatsapp_number(?string $override = null): string
    {
        $candidates = array_filter([
            $override,
            (string) Setting::get('support_whatsapp', ''),
            (string) Setting::get('whatsapp_admin_phone', ''),
        ]);

        foreach ($candidates as $candidate) {
            $formatted = format_whatsapp($candidate);
            if (strlen($formatted) >= 10) {
                return $formatted;
            }
        }

        try {
            $methods = PaymentMethod::query()->where('is_active', true)->orderBy('sort')->get(['meta', 'account_number']);
            foreach ($methods as $method) {
                $wa = format_whatsapp($method->meta['whatsapp_number'] ?? null);
                if (strlen($wa) >= 10) {
                    return $wa;
                }
            }
            foreach ($methods as $method) {
                $acc = format_whatsapp($method->account_number);
                if (strlen($acc) === 12 && str_starts_with($acc, '923')) {
                    return $acc;
                }
            }
        } catch (Throwable) {
            // Ignore DB errors during early boot
        }

        return '';
    }
}
