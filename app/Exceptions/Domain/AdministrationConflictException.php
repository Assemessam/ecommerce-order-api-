<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class AdministrationConflictException extends RuntimeException implements ShouldntReport {}
