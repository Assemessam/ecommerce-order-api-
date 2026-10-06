<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class EmailAlreadyRegisteredException extends RuntimeException implements ShouldntReport
{
    //
}
