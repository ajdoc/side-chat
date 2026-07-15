<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUserAction;
use App\Actions\Auth\RegisterUserAction;
use App\DTOs\Auth\LoginUserData;
use App\DTOs\Auth\RegisterUserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $user = $action->handle(RegisterUserData::fromArray($request->validated()));

        return $this->tokenResponse($user, 201);
    }

    public function login(LoginRequest $request, LoginUserAction $action): JsonResponse
    {
        $user = $action->handle(LoginUserData::fromArray($request->validated()));

        return $this->tokenResponse($user);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->token()->revoke();

        return response()->json(['message' => 'Logged out.']);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($user),
            'token' => $user->createToken('auth')->accessToken,
            'token_type' => 'Bearer',
        ], $status);
    }
}
