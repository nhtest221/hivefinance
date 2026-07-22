<?php

namespace App\Http\Controllers\Receivables;

use App\Http\Requests\Documents\M4ANoteDispositionRequest;
use App\Http\Requests\Documents\M4ANoteRequest;
use App\Receivables\Application\CreditNoteDispositionService;
use App\Receivables\Application\CreditNoteService;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CreditNoteController
{
    public function store(M4ANoteRequest $r, CreditNoteService $s): JsonResponse
    {
        return $this->response($s->create($r->user(), (string) $r->header('X-Entity-Id'), $r->validated(), $r->header('Idempotency-Key')));
    }

    public function update(M4ANoteRequest $r, CreditNoteService $s, string $id): JsonResponse
    {
        return $this->response($s->update($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function post(Request $r, CreditNoteService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->post($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function apply(M4ANoteDispositionRequest $r, CreditNoteDispositionService $s, string $id): JsonResponse
    {
        return $this->response($s->apply($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function hold(M4ANoteDispositionRequest $r, CreditNoteDispositionService $s, string $id): JsonResponse
    {
        return $this->response($s->hold($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function refund(M4ANoteDispositionRequest $r, CreditNoteDispositionService $s, string $id): JsonResponse
    {
        return $this->response($s->refund($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function reverse(M4ANoteDispositionRequest $r, CreditNoteDispositionService $s, string $id): JsonResponse
    {
        return $this->response($s->reverse($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function show(Request $r, CreditNoteService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->show($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function index(Request $r, CreditNoteService $s): JsonResponse
    {
        $v = DocumentQuery::validate($r, ['party' => ['nullable', 'uuid'], 'source_document' => ['nullable', 'uuid'], 'state' => ['nullable', 'in:draft,posted,reversed'], 'reason_code' => ['nullable', 'string'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string']]);

        return $v instanceof DocumentActionResult ? $this->response($v) : $this->response($s->list($r->user(), (string) $r->header('X-Entity-Id'), $v));
    }

    private function response(DocumentActionResult $r): JsonResponse
    {
        return response()->json($r->payload, $r->status, $r->headers);
    }
}
