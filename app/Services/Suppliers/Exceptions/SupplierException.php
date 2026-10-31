<?php

namespace App\Services\Suppliers\Exceptions;

use RuntimeException;

class SupplierException extends RuntimeException
{
    public array $context = [];

    public static function withContext(string $message, array $context = []): static
    {
        $e = new static($message);
        $e->context = $context;

        return $e;
    }
}
