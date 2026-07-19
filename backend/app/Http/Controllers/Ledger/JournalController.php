<?php

namespace App\Http\Controllers\Ledger;

use App\Http\Requests\Ledger\ReverseJournalRequest;
use App\Http\Requests\Ledger\StoreJournalRequest;
use App\Ledger\Application\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class JournalController
{
    public function index(Request $request, JournalService $journals): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'account' => ['nullable', 'uuid'], 'period' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:draft,posted,reversed'],
            'entry_type' => ['nullable', 'in:manual,system,adjusting,reversal,revaluation,conversion'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source_document_id' => ['nullable', 'uuid'], 'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['error_code' => 'validation', 'message' => 'The request is invalid.', 'details' => $validator->errors()->toArray()], 400);
        }
        $limit = filter_var($request->query('limit', 50), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            return response()->json(['error_code' => 'validation', 'message' => 'limit must be between 1 and 100.', 'details' => []], 400);
        }
        $status = $request->query('status');
        $type = $request->query('entry_type');
        if ($status !== null && ! in_array($status, ['draft', 'posted', 'reversed'], true)) {
            return response()->json(['error_code' => 'validation', 'message' => 'status is invalid.', 'details' => []], 400);
        }
        if ($type !== null && ! in_array($type, ['manual', 'system', 'adjusting', 'reversal', 'revaluation', 'conversion'], true)) {
            return response()->json(['error_code' => 'validation', 'message' => 'entry_type is invalid.', 'details' => []], 400);
        }
        if ($request->query('from') !== null && $request->query('to') !== null && $request->query('from') > $request->query('to')) {
            return response()->json(['error_code' => 'validation', 'message' => 'from must not be after to.', 'details' => []], 400);
        }
        $result = $journals->list(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            ['account' => $request->query('account'), 'period' => $request->query('period'), 'status' => $status, 'entry_type' => $type, 'from' => $request->query('from'), 'to' => $request->query('to'), 'source_document_id' => $request->query('source_document_id')],
            $limit,
            $request->query('cursor'),
        );

        return response()->json($result->payload, $result->status, $result->headers);
    }

    public function store(StoreJournalRequest $request, JournalService $journals): JsonResponse
    {
        $result = $journals->createDraft(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $request->validated(),
            $request->headers->get('Idempotency-Key'),
        );

        return response()->json($result->payload, $result->status, $result->headers);
    }

    public function post(Request $request, JournalService $journals, string $id): JsonResponse
    {
        if ($request->all() !== []) {
            return response()->json(['error_code' => 'validation', 'message' => 'Unknown fields are not allowed.', 'details' => ['body' => array_keys($request->all())]], 400);
        }
        $result = $journals->post(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $id,
            $request->headers->get('Idempotency-Key'),
            $request->headers->get('If-Match'),
        );

        return response()->json($result->payload, $result->status, $result->headers);
    }

    public function reverse(ReverseJournalRequest $request, JournalService $journals, string $id): JsonResponse
    {
        $result = $journals->reverse(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $id,
            $request->validated(),
            $request->headers->get('Idempotency-Key'),
        );

        return response()->json($result->payload, $result->status, $result->headers);
    }
}
