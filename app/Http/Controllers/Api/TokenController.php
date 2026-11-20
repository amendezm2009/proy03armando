<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordSecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TokenController extends Controller
{
    public function store(LoginRequest $request): UserResource
    {
        $user = $request->validatedUser();

        app(RecordSecurityEvent::class)->handle('auth.token_issued', ['user_id' => $user->id], $request);

        return (new UserResource($user))->additional([
            'token' => $user->createToken('api')->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => config('sanctum.expiration') * 60, // retorna el tiempo de expiración en segundos
        ]);
    }

    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        app(RecordSecurityEvent::class)->handle('auth.token_revoked', request: $request);

        return response()->noContent();
    }
}
