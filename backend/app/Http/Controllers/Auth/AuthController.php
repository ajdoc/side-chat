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

        return $this->tokenResponse($request, $user, 201);
    }

    public function login(LoginRequest $request, LoginUserAction $action): JsonResponse
    {
        $user = $action->handle(LoginUserData::fromArray($request->validated()));

        return $this->tokenResponse($request, $user);
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

    /**
     * The signed-in payload: the account, and the token to keep using it with.
     *
     * The user resolver has to be set by hand here, and it matters more than it looks. These
     * two endpoints are the only ones that serialise a user on a request that isn't
     * authenticated *yet* — the token is what this response hands out. UserResource decides
     * whether to include your private fields by asking `$request->user()`, which on a login
     * request is null, so without this the response omits your own role, notification
     * defaults and push setting.
     *
     * That's not cosmetic: `role` is what the client hangs the admin panel off, so an admin
     * signing in would land in the ordinary app with no way through to the panel until
     * something forced a reload and `/api/auth/me` filled the gap in.
     */
    private function tokenResponse(Request $request, User $user, int $status = 200): JsonResponse
    {
        $asThisUser = fn () => $user;

        $request->setUserResolver($asThisUser);
        /*
         * And again on the container's request, which is a *different object* from the
         * FormRequest injected into the action above. The resource is serialised while the
         * response renders, against whatever `app('request')` is — so setting the resolver
         * only on the FormRequest changes nothing that anybody reads.
         */
        app('request')->setUserResolver($asThisUser);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $user->createToken('auth')->accessToken,
            'token_type' => 'Bearer',
        ], $status);
    }
}
