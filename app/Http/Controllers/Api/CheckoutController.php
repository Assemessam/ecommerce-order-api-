<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(private CheckoutService $checkout) {}

    public function __invoke(CheckoutRequest $request): JsonResponse
    {
        $result = $this->checkout->checkout($request->user(), $request->validated('idempotency_key'));

        return (new OrderResource($result->order))->response()->setStatusCode($result->isReplay ? 200 : 201);
    }
}
