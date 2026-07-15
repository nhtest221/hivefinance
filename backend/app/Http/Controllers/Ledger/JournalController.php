<?php

namespace App\Http\Controllers\Ledger;

use App\Http\Requests\Ledger\ReverseJournalRequest;
use App\Http\Requests\Ledger\StoreJournalRequest;
use App\Ledger\Application\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JournalController
{
    public function index(Request $request, JournalService $journals): JsonResponse
    {
        $result = $journals->list(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $request->query('account') !== null ? (string) $request->query('account') : null,
            $request->query('period') !== null ? (string) $request->query('period') : null,
            $request->query('status') !== null ? (string) $request->query('status') : null,
        );

        return response()->json($result->payload, $result->status);
    }

    public function store(StoreJournalRequest $request, JournalService $journals): JsonResponse
    {
        $result = $journals->createDraft(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $request->validated(),
        );

        return response()->json($result->payload, $result->status);
    }

    public function post(Request $request, JournalService $journals, string $id): JsonResponse
    {
        $result = $journals->post(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $id,
            $request->headers->get('Idempotency-Key'),
        );

        return response()->json($result->payload, $result->status);
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

        return response()->json($result->payload, $result->status);
    }
}
