<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Requests\Reconciliation\ReconciliationAccountRequest;
use App\Http\Requests\Reconciliation\UpdateReconciliationAccountRequest;
use App\Reconciliation\Application\ReconciliationAccountService;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReconciliationAccountController
{
    public function store(ReconciliationAccountRequest $r, ReconciliationAccountService $s): JsonResponse
    {
        return $this->response($s->configure($r->user(), (string) $r->header('X-Entity-Id'), $r->validated(), $r->header('Idempotency-Key')));
    }

    public function update(UpdateReconciliationAccountRequest $r, ReconciliationAccountService $s, string $id): JsonResponse
    {
        return $this->response($s->update($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function show(Request $r, ReconciliationAccountService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->show($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function index(Request $r, ReconciliationAccountService $s): JsonResponse
    {
        $v = DocumentQuery::validate($r, ['limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string']]);

        return $v instanceof DocumentActionResult ? $this->response($v) : $this->response($s->list($r->user(), (string) $r->header('X-Entity-Id'), $v));
    }

    private function response(DocumentActionResult $r): JsonResponse
    {
        return response()->json($r->payload, $r->status, $r->headers);
    }
}
