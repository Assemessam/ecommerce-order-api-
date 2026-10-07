<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductQueryRequest;
use App\Http\Requests\Admin\ProductStoreRequest;
use App\Http\Requests\Admin\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Services\Product\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(ProductQueryRequest $request): AnonymousResourceCollection
    {
        $products = $this->products->listAdminProducts($request->user(), $request->toQuery(), $request->statusFilter());
        $products->appends($request->validated());

        return ProductResource::collection($products);
    }

    public function show(Request $request, string $id): ProductResource
    {
        return new ProductResource($this->products->getAdminProduct($request->user(), $id));
    }

    public function store(ProductStoreRequest $request): JsonResponse
    {
        return (new ProductResource($this->products->createProduct($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(ProductUpdateRequest $request, string $id): ProductResource
    {
        return new ProductResource($this->products->updateProduct($request->user(), $id, $request->validated()));
    }
}
