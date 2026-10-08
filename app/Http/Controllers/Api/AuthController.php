<?php

namespace App\Http\Controllers\Api;

use App\DTOs\Auth\LoginData;
use App\DTOs\Auth\RegisterUserData;
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
        $data = RegisterUserData::fromArray($request->validated());
        $result = $this->auth->register($data);

        return response()->json([
            'data' => [
                'user' => new UserResource($result->user),
                /** @var string */
                'token' => $result->token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = LoginData::fromArray($request->validated());
        $result = $this->auth->login($data);

        return response()->json([
            'data' => [
                'user' => new UserResource($result->user),
                /** @var string */
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
