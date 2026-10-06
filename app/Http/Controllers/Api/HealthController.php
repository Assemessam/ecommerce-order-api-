<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
            ],
        ]);
    }
}
