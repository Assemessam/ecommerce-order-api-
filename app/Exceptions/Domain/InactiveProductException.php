<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InactiveProductException extends RuntimeException implements ShouldntReport {}
