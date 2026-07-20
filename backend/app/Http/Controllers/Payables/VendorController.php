<?php

namespace App\Http\Controllers\Payables;

use App\Http\Requests\Documents\M2DocumentRequest;
use App\Payables\Application\VendorService;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VendorController
{
    public function store(M2DocumentRequest $r, VendorService $s): JsonResponse
    {
        return $this->response($s->create($r->user(), (string) $r->header('X-Entity-Id'), $r->validated(), $r->header('Idempotency-Key')));
    }

    public function update(M2DocumentRequest $r, VendorService $s, string $id): JsonResponse
    {
        return $this->response($s->update($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->validated(), $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function deactivate(M2DocumentRequest $r, VendorService $s, string $id): JsonResponse
    {
        return $this->response($s->deactivate($r->user(), (string) $r->header('X-Entity-Id'), $id, $r->header('Idempotency-Key'), $r->header('If-Match')));
    }

    public function show(Request $r, VendorService $s, string $id): JsonResponse
    {
        if ($e = DocumentQuery::empty($r)) {
            return $this->response($e);
        }

        return $this->response($s->show($r->user(), (string) $r->header('X-Entity-Id'), $id));
    }

    public function index(Request $r, VendorService $s): JsonResponse
    {
        $v = DocumentQuery::validate($r, ['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:active,deactivated'], 'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string']]);

        return $v instanceof DocumentActionResult ? $this->response($v) : $this->response($s->list($r->user(), (string) $r->header('X-Entity-Id'), $v));
    }

    private function response(DocumentActionResult $r): JsonResponse
    {
        return response()->json($r->payload, $r->status, $r->headers);
    }
}
