<?php

namespace App\Http\Controllers\Tax;

use App\Http\Requests\Tax\StoreTaxCodeRequest;
use App\Http\Requests\Tax\StoreTaxCodeVersionRequest;
use App\Http\Requests\Tax\StoreTaxPackRequest;
use App\Tax\Application\TaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaxController
{
    public function index(Request $request, TaxService $tax): JsonResponse
    {
        $limit = filter_var($request->query('limit', 50), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            return response()->json(['error_code' => 'validation', 'message' => 'limit must be between 1 and 100.', 'details' => []], 400);
        }
        $result = $tax->list($request->user(), (string) $request->header('X-Entity-Id'), $request->query('jurisdiction'), $request->query('status'), $request->query('effective_on'), $limit, $request->query('cursor'));

        return response()->json($result->payload, $result->status, $result->headers);
    }

    public function show(Request $request, TaxService $tax, string $id): JsonResponse
    {
        $result = $tax->show($request->user(), (string) $request->header('X-Entity-Id'), $id);

        return response()->json($result->payload, $result->status);
    }

    public function store(StoreTaxCodeRequest $request, TaxService $tax): JsonResponse
    {
        return $this->command($request, $tax, 'tax_code_create', $request->validated());
    }

    public function version(StoreTaxCodeVersionRequest $request, TaxService $tax, string $id): JsonResponse
    {
        return $this->command($request, $tax, 'tax_code_version_create', [...$request->validated(), 'tax_code_id' => $id]);
    }

    public function pack(StoreTaxPackRequest $request, TaxService $tax): JsonResponse
    {
        return $this->command($request, $tax, 'tax_pack_configure', $request->validated());
    }

    /** @param array<string, mixed> $data */
    private function command(Request $request, TaxService $tax, string $type, array $data): JsonResponse
    {
        $result = $tax->requestCommand($request->user(), (string) $request->header('X-Entity-Id'), $type, $data, $request->header('Idempotency-Key'), $request->header('If-Match'), (string) $request->attributes->get('correlation_id'));

        return response()->json($result->payload, $result->status, $result->headers);
    }
}
