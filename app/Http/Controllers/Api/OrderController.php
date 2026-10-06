<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderQueryRequest;
use App\Http\Resources\OrderResource;
use App\Services\Order\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(OrderQueryRequest $request): AnonymousResourceCollection
    {
        $orders = $this->orders->listOrders($request->user(), (int) $request->validated('page', 1), (int) $request->validated('per_page', 15));
        $orders->appends($request->validated());

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $id): OrderResource
    {
        return new OrderResource($this->orders->getOrder($request->user(), $id));
    }

    public function cancel(Request $request, string $id): OrderResource
    {
        return new OrderResource($this->orders->cancelOrder($request->user(), $id));
    }
}
