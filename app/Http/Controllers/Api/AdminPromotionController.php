<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PromotionQueryRequest;
use App\Http\Requests\Admin\PromotionStoreRequest;
use App\Http\Requests\Admin\PromotionUpdateRequest;
use App\Http\Resources\AdminPromotionResource;
use App\Services\Promotion\PromotionAdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminPromotionController extends Controller
{
    public function __construct(private PromotionAdministrationService $promotions) {}

    public function index(PromotionQueryRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();
        $promotions = $this->promotions->listPromotions($request->user(), (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 15), isset($data['is_active']) ? (bool) $data['is_active'] : null);
        $promotions->appends($data);

        return AdminPromotionResource::collection($promotions);
    }

    public function show(Request $request, string $id): AdminPromotionResource
    {
        return new AdminPromotionResource($this->promotions->getPromotion($request->user(), $id));
    }

    public function store(PromotionStoreRequest $request): JsonResponse
    {
        return (new AdminPromotionResource($this->promotions->createPromotion($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(PromotionUpdateRequest $request, string $id): AdminPromotionResource
    {
        return new AdminPromotionResource($this->promotions->updatePromotion($request->user(), $id, $request->validated()));
    }
}
