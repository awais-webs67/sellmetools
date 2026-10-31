<?php

namespace App\Enums;

enum PricingMode: string
{
    case Fixed = 'fixed';
    case MarkupAmount = 'markup_amount';
    case MarkupPercent = 'markup_percent';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed price (PKR)',
            self::MarkupAmount => 'Cost + fixed amount (PKR)',
            self::MarkupPercent => 'Cost + percentage',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
