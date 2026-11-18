<?php

namespace App\Http\Controllers\Api;

use App\Actions\RemoveAvatar;
use App\Actions\SaveAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\AvatarRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Foto de perfil (API): mismas reglas que la web. POST /api/user/avatar (campo multipart "avatar"),
 * DELETE /api/user/avatar y, para administradores, DELETE /api/users/{user}/avatar.
 */
class AvatarController extends Controller
{
    public function update(AvatarRequest $request, SaveAvatar $save): UserResource
    {
        return new UserResource($save->handle($request->user(), $request->file('avatar')));
    }

    public function destroy(Request $request, RemoveAvatar $remove): Response
    {
        $remove->handle($request->user(), $request->user());

        return response()->noContent();
    }

    public function destroyFor(Request $request, User $user, RemoveAvatar $remove): Response
    {
        $remove->handle($user, $request->user());

        return response()->noContent();
    }
}
