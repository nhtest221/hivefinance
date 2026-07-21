<?php

namespace App\Http\Controllers\Settlement;

use App\Http\Requests\Settlement\M3SettlementRequest;
use App\Settlement\Application\SettlementService;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SettlementController
{
    public function receipt(M3SettlementRequest $request, SettlementService $service): JsonResponse
    {
        return $this->response($service->receipt($request->user(), (string) $request->header('X-Entity-Id'), $request->validated(), $request->header('Idempotency-Key')));
    }

    public function payment(M3SettlementRequest $request, SettlementService $service): JsonResponse
    {
        return $this->response($service->payment($request->user(), (string) $request->header('X-Entity-Id'), $request->validated(), $request->header('Idempotency-Key')));
    }

    public function apply(M3SettlementRequest $request, SettlementService $service, string $party): JsonResponse
    {
        return $this->response($service->applyCredit($request->user(), (string) $request->header('X-Entity-Id'), $party, $request->validated(), $request->header('Idempotency-Key')));
    }

    public function refund(M3SettlementRequest $request, SettlementService $service, string $party): JsonResponse
    {
        return $this->response($service->refundCredit($request->user(), (string) $request->header('X-Entity-Id'), $party, $request->validated(), $request->header('Idempotency-Key')));
    }

    public function reverse(Request $request, SettlementService $service, string $id): JsonResponse
    {
        if ($error = DocumentQuery::empty($request)) {
            return $this->response($error);
        }

        return $this->response($service->reverse($request->user(), (string) $request->header('X-Entity-Id'), $id, $request->header('Idempotency-Key'), $request->header('If-Match')));
    }

    public function allocations(Request $request, SettlementService $service): JsonResponse
    {
        $validated = DocumentQuery::validate($request, [
            'operation' => ['nullable', 'in:receipt,payment,credit_application,credit_refund,reversal'],
            'state' => ['nullable', 'in:posted,reversed'],
            'party_type' => ['nullable', 'in:customer,vendor', 'required_with:party'],
            'party' => ['nullable', 'uuid'],
            'document' => ['nullable', 'uuid'],
            'bank_account_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string'],
        ]);

        return $this->response($validated instanceof DocumentActionResult ? $validated : $service->list($request->user(), (string) $request->header('X-Entity-Id'), $validated));
    }

    public function credits(Request $request, SettlementService $service, string $party): JsonResponse
    {
        $validated = DocumentQuery::validate($request, [
            'party_type' => ['required', 'in:customer,vendor'],
            'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string'],
        ]);

        return $this->response($validated instanceof DocumentActionResult ? $validated : $service->credits($request->user(), (string) $request->header('X-Entity-Id'), $party, $validated));
    }

    private function response(DocumentActionResult $result): JsonResponse
    {
        return response()->json($result->payload, $result->status, $result->headers);
    }
}
