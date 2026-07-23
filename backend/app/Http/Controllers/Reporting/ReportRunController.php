<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Requests\Reporting\ReportRunRequest;
use App\Reporting\Application\ReportRunService;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportRunController
{
    public function store(ReportRunRequest $r, ReportRunService $s): JsonResponse
    {
        return $this->response($s->generate($r->user(), (string) $r->header('X-Entity-Id'), $r->validated(), $r->header('Idempotency-Key')));
    }

    public function approve(Request $r, ReportRunService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->approve($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function show(Request $r, ReportRunService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->show($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function index(Request $r, ReportRunService $s): JsonResponse
    {
        $v = DocumentQuery::validate($r, ['report_type' => ['nullable', 'string'], 'period' => ['nullable', 'string'], 'state' => ['nullable', 'in:Generated,PendingApproval,Approved,Rejected,Superseded'], 'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string']]);

        return $v instanceof DocumentActionResult ? $this->response($v) : $this->response($s->list($r->user(), (string) $r->header('X-Entity-Id'), $v));
    }

    private function response(DocumentActionResult $r): JsonResponse
    {
        return response()->json($r->payload, $r->status, $r->headers);
    }
}
