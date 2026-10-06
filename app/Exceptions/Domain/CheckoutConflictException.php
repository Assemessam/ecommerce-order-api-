<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class CheckoutConflictException extends RuntimeException implements ShouldntReport {}
