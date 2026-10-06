<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class CartConflictException extends RuntimeException implements ShouldntReport {}
