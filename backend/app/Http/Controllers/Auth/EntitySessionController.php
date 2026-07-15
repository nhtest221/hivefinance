<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\SwitchEntityRequest;
use App\Identity\Application\EntityAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EntitySessionController
{
    public function index(Request $request, EntityAccessService $entities): JsonResponse
    {
        return response()->json([
            'entities' => $entities->availableEntities($request->user()),
        ]);
    }

    public function switch(SwitchEntityRequest $request, EntityAccessService $entities): JsonResponse
    {
        $result = $entities->switch($request->user(), (string) $request->validated('entity_id'));

        return response()->json($result->payload, $result->status);
    }
}
