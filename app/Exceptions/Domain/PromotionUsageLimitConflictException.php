<?php

namespace App\Exceptions\Domain;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class PromotionUsageLimitConflictException extends RuntimeException implements ShouldntReport {}
