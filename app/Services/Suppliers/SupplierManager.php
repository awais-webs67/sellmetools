<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Contracts\SupplierDriver;
use App\Services\Suppliers\Drivers\StandardBotApiDriver;
use InvalidArgumentException;

class SupplierManager
{
    /** Register new API formats here. */
    public const DRIVERS = [
        'standard_bot' => StandardBotApiDriver::class,
    ];

    public static function driverOptions(): array
    {
        return [
            'standard_bot' => 'Standard Bot API (/products, /balance, /order)',
        ];
    }

    public function driver(Supplier $supplier): SupplierDriver
    {
        $class = self::DRIVERS[$supplier->driver] ?? null;

        if (! $class) {
            throw new InvalidArgumentException("Unknown supplier driver [{$supplier->driver}].");
        }

        return new $class($supplier);
    }
}
