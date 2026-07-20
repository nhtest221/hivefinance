<?php

namespace App\Http\Controllers\Receivables;

use App\Http\Requests\Documents\M2DocumentRequest;
use App\Receivables\Application\CustomerService;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerController
{
    public function store(M2DocumentRequest $r, CustomerService $s): JsonResponse
    {
        return $this->response($s->create($r->user(), (string) $r->header('X-Entity-Id'), $r->validated(), $r->header('Idempotency-Key')));
    }

    public function update(M2DocumentRequest $r, CustomerService $s, string $id): JsonResponse
    {
        return $this->response($s->update($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function deactivate(M2DocumentRequest $r, CustomerService $s, string $id): JsonResponse
    {
        return $this->response($s->deactivate($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function show(Request $r, CustomerService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

return $this->response($s->show($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function index(Request $r, CustomerService $s): JsonResponse
    {
        $v = DocumentQuery::validate($r, ['search' => ['nullable', 'string', 'max:255'], 'type' => ['nullable', 'in:local,foreign'], 'status' => ['nullable', 'in:active,deactivated'], 'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string']]);

        return $v instanceof DocumentActionResult ? $this->response($v) : $this->response($s->list($r->user(), (string) $r->header('X-Entity-Id'), $v));
    }

    private function response(DocumentActionResult $r): JsonResponse
    {
        return response()->json($r->payload,$r->status,$r->headers);
    }
}
