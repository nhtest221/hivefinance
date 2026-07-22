<?php

namespace App\Http\Controllers\Period;

use App\Http\Requests\Period\PeriodRequest;
use App\Ledger\Application\LedgerAuthorizationService;
use App\Period\Application\PeriodCloseService;
use App\Period\Application\PeriodQuery;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PeriodController
{
    public function postable(Request $request, PeriodQuery $periods, LedgerAuthorizationService $authorization): JsonResponse
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

    public function index(Request $r, PeriodCloseService $s): JsonResponse
    {
        $v = DocumentQuery::validate($r, ['state' => ['nullable', 'in:Open,SoftClosed,HardClosed,Reopened'], 'fiscal_year' => ['nullable', 'string'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string']]);

        return $v instanceof DocumentActionResult ? $this->response($v) : $this->response($s->list($r->user(), (string) $r->header('X-Entity-Id'), $v));
    }

    public function show(Request $r, PeriodCloseService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->show($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function softClose(PeriodRequest $r, PeriodCloseService $s, string $id): JsonResponse
    {
        return $this->response($s->softClose($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function hardClose(PeriodRequest $r, PeriodCloseService $s, string $id): JsonResponse
    {
        return $this->response($s->hardClose($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function reopen(PeriodRequest $r, PeriodCloseService $s, string $id): JsonResponse
    {
        return $this->response($s->reopen($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    private function response(DocumentActionResult $r): JsonResponse
    {
        return response()->json($r->payload, $r->status, $r->headers);
    }
}
