<?php

namespace App\Filament\Support;

use App\Models\Setting;

/** Formatting helpers shared across admin resources. */
final class Money
{
    public static function symbol(): string
    {
        return (string) Setting::get('currency_symbol', 'Rs');
    }

    /** Formats a PKR amount, e.g. "Rs 1,250". Decimals are shown only when present. */
    public static function pkr(float|int|string|null $amount, string $placeholder = '—'): string
    {
        if ($amount === null || $amount === '') {
            return $placeholder;
        }

        $amount = (float) $amount;
        $decimals = fmod($amount, 1.0) === 0.0 ? 0 : 2;

        return self::symbol().' '.number_format($amount, $decimals);
    }

    /**
     * Formats an amount in an arbitrary currency keeping 2–4 decimals,
     * e.g. 12.5000 → "12.50 USDT", 0.0350 → "0.035 USDT".
     */
    public static function currency(float|int|string|null $amount, ?string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        if (strtoupper((string) $currency) === 'PKR') {
            return self::pkr($amount);
        }

        $formatted = number_format((float) $amount, 4);
        $formatted = preg_replace('/(\.\d{2}\d*?)0+$/', '$1', $formatted);

        return trim($formatted.' '.$currency);
    }
}
