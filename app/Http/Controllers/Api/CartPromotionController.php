<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\ApplyPromotionRequest;
use App\Http\Resources\CartResource;
use App\Services\Cart\CartPromotionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CartPromotionController extends Controller
{
    public function __construct(private CartPromotionService $promotions) {}

    public function store(ApplyPromotionRequest $request): CartResource
    {
        return new CartResource($this->promotions->apply($request->user(), $request->validated('code')));
    }

    public function destroy(Request $request): Response
    {
        $this->promotions->remove($request->user());

        return response()->noContent();
    }
}
