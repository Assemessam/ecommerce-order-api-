<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InvalidCredentialsException extends RuntimeException implements ShouldntReport
{
    //
}
