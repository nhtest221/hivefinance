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
        $result = $accounts->list($request->user(), (string) $request->header('X-Entity-Id'));

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
