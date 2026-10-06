<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class CartTotalTooLargeException extends RuntimeException implements ShouldntReport {}
