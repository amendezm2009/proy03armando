<?php

namespace App\Http\Controllers\Api;

use App\Actions\ChangeUserStatus;
use App\Actions\ConfirmPassword;
use App\Actions\InviteUser;
use App\Actions\SaveUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordConfirmationRequest;
use App\Http\Requests\UserIndexRequest;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Gestión de usuarios (API). Mismos permisos y reglas que la web; no hay eliminación, solo desactivación.
 */
class UserController extends Controller
{
    public function index(UserIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return UserResource::collection(
            User::filter($filters)
                ->with('roles')
                ->orderBy('name')
                ->paginate($filters['per_page'] ?? 15)
                ->withQueryString()
        );
    }

    public function store(UserRequest $request, SaveUser $save, ConfirmPassword $confirm): JsonResponse
    {
        $confirm->handle($request, $request->validated('current_password'));

        return (new UserResource($save->handle($request->validated(), $request->user())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    public function update(UserRequest $request, User $user, SaveUser $save, ConfirmPassword $confirm): UserResource
    {
        if ($request->requiresPasswordConfirmation()) {
            $confirm->handle($request, $request->validated('current_password'));
        }

        return new UserResource($save->handle($request->validated(), $request->user(), $user));
    }

    public function deactivate(PasswordConfirmationRequest $request, User $user, ChangeUserStatus $status, ConfirmPassword $confirm): UserResource
    {
        $confirm->handle($request, $request->validated('current_password'));

        return new UserResource($status->handle($user, $request->user(), false));
    }

    public function activate(PasswordConfirmationRequest $request, User $user, ChangeUserStatus $status, ConfirmPassword $confirm): UserResource
    {
        $confirm->handle($request, $request->validated('current_password'));

        return new UserResource($status->handle($user, $request->user(), true));
    }

    public function invite(PasswordConfirmationRequest $request, User $user, InviteUser $invite, ConfirmPassword $confirm): Response
    {
        $confirm->handle($request, $request->validated('current_password'));

        $invite->handle($user, $request->user());

        return response()->noContent();
    }
}
