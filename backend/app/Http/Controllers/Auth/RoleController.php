<?php

namespace App\Http\Controllers\Auth;

use App\Identity\Application\RoleAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoleController
{
    public function __invoke(Request $request, RoleAuthorizationService $authorization): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'roles' => $authorization->roleSlugs($user, $user->active_entity_id)->all(),
            'permissions' => $authorization->permissions($user, $user->active_entity_id),
        ]);
    }
}
