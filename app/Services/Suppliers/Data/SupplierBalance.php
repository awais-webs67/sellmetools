<?php

namespace App\Services\Suppliers\Data;

final readonly class SupplierBalance
{
    public function __construct(
        public float $balance,
        public string $currency = 'USDT',
        public array $raw = [],
    ) {}
}
