<?php

namespace App\Http\Controllers\Receivables;

use App\Http\Requests\Documents\M2DocumentRequest;
use App\Http\Requests\Documents\M4AVoidRequest;
use App\Receivables\Application\InvoiceService;
use App\Receivables\Application\InvoiceVoidService;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InvoiceController
{
    public function store(M2DocumentRequest $r, InvoiceService $s): JsonResponse
    {
        return $this->response($s->create($r->user(), (string) $r->header('X-Entity-Id'), $r->validated(), $r->header('Idempotency-Key')));
    }

    public function void(M4AVoidRequest $r, InvoiceVoidService $s, string $id): JsonResponse
    {
        return $this->response($s->void($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function update(M2DocumentRequest $r, InvoiceService $s, string $id): JsonResponse
    {
        return $this->response($s->update($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function issue(M2DocumentRequest $r, InvoiceService $s, string $id): JsonResponse
    {
        return $this->response($s->issue($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function show(Request $r, InvoiceService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->show($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function index(Request $r, InvoiceService $s): JsonResponse
    {
        $v = DocumentQuery::validate($r, ['customer' => ['nullable', 'uuid'], 'status' => ['nullable', 'in:draft,sent'], 'overdue' => ['nullable', 'boolean'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string']]);

        return $v instanceof DocumentActionResult ? $this->response($v) : $this->response($s->list($r->user(), (string) $r->header('X-Entity-Id'), $v));
    }

    public function pdf(Request $r, InvoiceService $s, string $id): Response|JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }$result = $s->pdf($r->user(), (string) $r->header('X-Entity-Id'), $id);
        if ($result instanceof DocumentActionResult) {
            return $this->response($result);
        }if ($r->header('If-None-Match') === $result['etag']) {
            return response('', 304, ['ETag' => $result['etag']]);
        }$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $result['number']).'.pdf';

        return response($result['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$filename.'"', 'Content-Length' => (string) strlen($result['content']), 'ETag' => $result['etag'], 'X-Document-Id' => $id, 'X-Document-Number' => $result['number']]);
    }

    private function response(DocumentActionResult $r): JsonResponse
    {
        return response()->json($r->payload, $r->status, $r->headers);
    }
}
