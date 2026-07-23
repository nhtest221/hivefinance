<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Requests\Reconciliation\CreateBankEntryRequest;
use App\Http\Requests\Reconciliation\ImportStatementRequest;
use App\Http\Requests\Reconciliation\MatchLineRequest;
use App\Http\Requests\Reconciliation\OpenReconciliationRequest;
use App\Reconciliation\Application\ReconciliationExportService;
use App\Reconciliation\Application\ReconciliationService;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReconciliationController
{
    public function store(OpenReconciliationRequest $r, ReconciliationService $s): JsonResponse
    {
        return $this->response($s->open($r->user(), (string) $r->header('X-Entity-Id'), $r->validated(), $r->header('Idempotency-Key')));
    }

    public function show(Request $r, ReconciliationService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->show($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function index(Request $r, ReconciliationService $s): JsonResponse
    {
        $v = DocumentQuery::validate($r, ['bank_account_id' => ['nullable', 'string'], 'state' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string']]);

        return $v instanceof DocumentActionResult ? $this->response($v) : $this->response($s->list($r->user(), (string) $r->header('X-Entity-Id'), $v));
    }

    public function import(ImportStatementRequest $r, ReconciliationService $s, string $id): JsonResponse
    {
        return $this->response($s->importStatement($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key')));
    }

    public function generateMatchSuggestions(Request $r, ReconciliationService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->generateMatchSuggestions($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key')));
    }

    public function unmatched(Request $r, ReconciliationService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->unmatched($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function matchLine(MatchLineRequest $r, ReconciliationService $s, string $id, string $lineId): JsonResponse
    {
        return $this->response($s->matchLine($r->user(), (string) $r->header('X-Entity-Id'), $id, $lineId, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function confirmMatch(Request $r, ReconciliationService $s, string $id, string $lineId): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->confirmMatch($r->user(), (string) $r->header('X-Entity-Id'), $id, $lineId, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function createBankEntry(CreateBankEntryRequest $r, ReconciliationService $s, string $id, string $lineId): JsonResponse
    {
        return $this->response($s->createBankEntry($r->user(), (string) $r->header('X-Entity-Id'), $id, $lineId, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function complete(Request $r, ReconciliationService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->complete($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function reopen(Request $r, ReconciliationService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->reopen($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function export(Request $r, ReconciliationExportService $s, string $id): JsonResponse|Response
    {
        $v = DocumentQuery::validate($r, ['format' => ['required', 'in:pdf,csv']]);
        if ($v instanceof DocumentActionResult) {
            return $this->response($v);
        }
        $result = $s->export($r->user(), (string) $r->header('X-Entity-Id'), $id, (string) $v['format']);
        if ($result instanceof DocumentActionResult) {
            return $this->response($result);
        }

        return response($result->content, 200, [
            'Content-Type' => $result->mimeType,
            'Content-Disposition' => 'attachment; filename="'.$result->filename.'"',
        ]);
    }

    private function response(DocumentActionResult $r): JsonResponse
    {
        return response()->json($r->payload, $r->status, $r->headers);
    }
}
