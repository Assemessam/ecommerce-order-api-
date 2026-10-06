<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InventoryRestorationOverflowException extends RuntimeException implements ShouldntReport {}
