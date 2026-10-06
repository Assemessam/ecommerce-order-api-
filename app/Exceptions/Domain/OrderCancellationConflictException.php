<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class OrderCancellationConflictException extends RuntimeException implements ShouldntReport {}
