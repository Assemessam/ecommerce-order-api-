<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InventoryAdjustmentConflictException extends RuntimeException implements ShouldntReport {}
