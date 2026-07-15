<?php

namespace App\Http\Controllers\Period;

use App\Ledger\Application\LedgerAuthorizationService;
use App\Ledger\Application\PeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PeriodController
{
    public function show(Request $request, PeriodService $periods, LedgerAuthorizationService $authorization, string $ref): JsonResponse
    {
        $entityId = (string) $request->header('X-Entity-Id');
        $permission = 'periods.read';
        if (! $authorization->can($request->user(), $entityId, $permission)) {
            $result = $authorization->denyResponse($permission);

            return response()->json($result->payload, $result->status);
        }

        $period = $periods->show($entityId, $ref);
        if ($period === null) {
            return response()->json(['error_code' => 'not_found', 'message' => 'The accounting period was not found.', 'details' => []], 404);
        }

        return response()->json([
            'period' => [
                'id' => $period->id,
                'period_ref' => $period->period_ref,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'state' => $period->state,
                'vat_lock_status' => $period->vat_lock_status,
                'version' => $period->version,
            ],
        ]);
    }

    public function postable(Request $request, PeriodService $periods, LedgerAuthorizationService $authorization): JsonResponse
    {
        $entityId = (string) $request->header('X-Entity-Id');
        $permission = 'periods.read';
        if (! $authorization->can($request->user(), $entityId, $permission)) {
            $result = $authorization->denyResponse($permission);

            return response()->json($result->payload, $result->status);
        }

        $date = (string) $request->query('date');
        $period = $periods->postablePeriodForDate($entityId, $date);

        return response()->json([
            'date' => $date,
            'postable' => $period !== null,
            'period_ref' => $period?->period_ref,
        ]);
    }
}
