<?php

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

if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }
}
