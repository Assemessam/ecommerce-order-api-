<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CartController extends Controller
{
    public function __construct(private CartService $carts) {}

    public function show(Request $request): CartResource
    {
        return new CartResource($this->carts->getCart($request->user()));
    }

    public function store(AddCartItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $cart = $this->carts->addItem($request->user(), (int) $data['product_id'], (int) $data['quantity']);

        return (new CartResource($cart))->response()->setStatusCode(201);
    }

    public function update(UpdateCartItemRequest $request, string $id): CartResource
    {
        return new CartResource($this->carts->updateItem($request->user(), $id, (int) $request->validated('quantity')));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->carts->removeItem($request->user(), $id);

        return response()->noContent();
    }
}
