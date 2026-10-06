<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductQueryRequest;
use App\Http\Resources\ProductResource;
use App\Services\Product\ProductService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(ProductQueryRequest $request): AnonymousResourceCollection
    {
        $products = $this->products->listProducts($request->toQuery());
        $products->appends($request->validated());

        return ProductResource::collection($products);
    }

    public function show(string $id): ProductResource
    {
        return new ProductResource($this->products->getProduct($id));
    }
}
