<?php

namespace App\Http\Controllers\CurrencyFx;

use App\CurrencyFx\Application\FxService;
use App\Http\Requests\CurrencyFx\RunRevaluationRequest;
use App\Http\Requests\CurrencyFx\StoreRateRecordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class FxController
{
    public function storeRate(StoreRateRecordRequest $request, FxService $fx): JsonResponse
    {
        $result = $fx->addRate($request->user(), (string) $request->header('X-Entity-Id'), $request->validated(), $request->header('Idempotency-Key'));

        return response()->json($result->payload, $result->status, $result->headers);
    }

    public function rates(Request $request, FxService $fx): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'base_currency' => ['nullable', 'string', 'size:3', 'uppercase'], 'quote_currency' => ['nullable', 'string', 'size:3', 'uppercase'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'source' => ['nullable', 'string', 'max:100'], 'referenced' => ['nullable', 'in:true,false,1,0'],
            'limit' => ['nullable', 'integer', 'between:1,100'], 'cursor' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['error_code' => 'validation', 'message' => 'The request is invalid.', 'details' => $validator->errors()->toArray()], 400);
        }
        $limit = filter_var($request->query('limit', 50), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            return response()->json(['error_code' => 'validation', 'message' => 'limit must be between 1 and 100.', 'details' => []], 400);
        }
        $referenced = $request->query('referenced');
        if ($referenced !== null) {
            $referenced = filter_var($referenced, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($referenced === null) {
                return response()->json(['error_code' => 'validation', 'message' => 'referenced must be boolean.', 'details' => []], 400);
            }
        }
        $filters = ['base_currency' => $request->query('base_currency'), 'quote_currency' => $request->query('quote_currency'), 'effective_from' => $request->query('effective_from'), 'effective_to' => $request->query('effective_to'), 'source' => $request->query('source'), 'referenced' => $referenced];
        $result = $fx->rates($request->user(), (string) $request->header('X-Entity-Id'), $filters, $limit, $request->query('cursor'));

        return response()->json($result->payload, $result->status, $result->headers);
    }

    public function revalue(RunRevaluationRequest $request, FxService $fx): JsonResponse
    {
        $result = $fx->revalue($request->user(), (string) $request->header('X-Entity-Id'), $request->validated(), $request->header('Idempotency-Key'));

        return response()->json($result->payload, $result->status, $result->headers);
    }

    public function revaluations(Request $request, FxService $fx): JsonResponse
    {
        $validator = Validator::make($request->query(), ['period' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'status' => ['nullable', 'in:posted,reversed']]);
        if ($validator->fails()) {
            return response()->json(['error_code' => 'validation', 'message' => 'The request is invalid.', 'details' => $validator->errors()->toArray()], 400);
        }
        $period = $request->query('period');
        if (! is_string($period) || preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            return response()->json(['error_code' => 'validation', 'message' => 'period is required.', 'details' => []], 400);
        }
        $result = $fx->revaluations($request->user(), (string) $request->header('X-Entity-Id'), $period, $request->query('status'));

        return response()->json($result->payload, $result->status);
    }
}
