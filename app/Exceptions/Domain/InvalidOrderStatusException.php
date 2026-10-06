<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InvalidOrderStatusException extends RuntimeException implements ShouldntReport {}
