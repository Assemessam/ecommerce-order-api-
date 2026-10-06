<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated());

        return response()->json([
            'data' => [
                'user' => new UserResource($result->user),
                'token' => $result->token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->validated());

        return response()->json([
            'data' => [
                'user' => new UserResource($result->user),
                'token' => $result->token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request->user());

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
