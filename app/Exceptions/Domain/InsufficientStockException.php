<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InsufficientStockException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly int $productId, public readonly int $available)
    {
        parent::__construct('The requested quantity is no longer available.');
    }
}
