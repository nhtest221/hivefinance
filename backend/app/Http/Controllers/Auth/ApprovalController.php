<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ApproveRequest;
use App\Identity\Application\ApprovalLifecycleService;
use Illuminate\Http\JsonResponse;

final class ApprovalController
{
    public function approve(ApproveRequest $request, ApprovalLifecycleService $approvals, string $id): JsonResponse
    {
        $result = $approvals->approve(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $id,
            $request->header('Idempotency-Key'),
            $request->header('If-Match'),
            (string) $request->attributes->get('correlation_id'),
        );

        return response()->json($result->payload, $result->status, $result->headers);
    }
}
