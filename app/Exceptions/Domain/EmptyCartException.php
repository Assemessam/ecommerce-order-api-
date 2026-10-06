<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class EmptyCartException extends RuntimeException implements ShouldntReport {}
