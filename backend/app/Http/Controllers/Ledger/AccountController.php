<?php

namespace App\Http\Controllers\Ledger;

use App\Http\Requests\Ledger\StoreAccountRequest;
use App\Http\Requests\Ledger\UpdateAccountRequest;
use App\Ledger\Application\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController
{
    public function index(Request $request, AccountService $accounts): JsonResponse
    {
        $limit = filter_var($request->query('limit', 50), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            return response()->json(['error_code' => 'validation', 'message' => 'limit must be between 1 and 100.', 'details' => []], 400);
        }
        $status = (string) $request->query('status', 'active');
        if (! in_array($status, ['active', 'deactivated'], true)) {
            return response()->json(['error_code' => 'validation', 'message' => 'status is invalid.', 'details' => []], 400);
        }
        $result = $accounts->list($request->user(), (string) $request->header('X-Entity-Id'), $status, $limit, $request->query('cursor'));

        return response()->json($result->payload, $result->status);
    }

    public function store(StoreAccountRequest $request, AccountService $accounts): JsonResponse
    {
        $result = $accounts->create(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $request->validated(),
        );

        return response()->json($result->payload, $result->status);
    }

    public function update(UpdateAccountRequest $request, AccountService $accounts, string $id): JsonResponse
    {
        $result = $accounts->update(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $id,
            $request->validated(),
        );

        return response()->json($result->payload, $result->status);
    }

    public function deactivate(Request $request, AccountService $accounts, string $id): JsonResponse
    {
        $result = $accounts->deactivate($request->user(), (string) $request->header('X-Entity-Id'), $id);

        return response()->json($result->payload, $result->status);
    }
}
