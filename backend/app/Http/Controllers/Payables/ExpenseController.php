<?php

namespace App\Http\Controllers\Payables;

use App\Http\Requests\Documents\M2DocumentRequest;
use App\Payables\Application\ExpenseService;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExpenseController
{
    public function store(M2DocumentRequest $r, ExpenseService $s): JsonResponse
    {
        return $this->response($s->create($r->user(), (string) $r->header('X-Entity-Id'), $r->validated(), $r->header('Idempotency-Key')));
    }

    public function show(Request $r, ExpenseService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->show($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function index(Request $r, ExpenseService $s): JsonResponse
    {
        $v = DocumentQuery::validate($r, ['vendor' => ['nullable', 'uuid'], 'category_account_id' => ['nullable', 'uuid'], 'sbu_code' => ['nullable', 'string', 'max:100'], 'settlement_type' => ['nullable', 'in:cash,accrued'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string']]);

        return $v instanceof DocumentActionResult ? $this->response($v) : $this->response($s->list($r->user(), (string) $r->header('X-Entity-Id'), $v));
    }

    private function response(DocumentActionResult $r): JsonResponse
    {
        return response()->json($r->payload, $r->status, $r->headers);
    }
}
