<?php

namespace App\Http\Controllers\Reports;

use App\Ledger\Application\LedgerReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LedgerReportController
{
    public function accountBalance(Request $request, LedgerReportService $reports, string $id): JsonResponse
    {
        $result = $reports->accountBalance(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $id,
            $request->query('asOf') !== null ? (string) $request->query('asOf') : null,
        );

        return response()->json($result->payload, $result->status);
    }

    public function generalLedger(Request $request, LedgerReportService $reports): JsonResponse
    {
        $accountId = (string) $request->query('account');
        $range = $this->dateRange($request->query('range') !== null ? (string) $request->query('range') : null);
        $limit = filter_var($request->query('limit', 50), FILTER_VALIDATE_INT);
        if (! preg_match('/^[0-9a-f-]{36}$/i', $accountId) || $range['from'] === null || $range['to'] === null || $range['from'] > $range['to'] || $limit === false || $limit < 1 || $limit > 100) {
            return response()->json(['error_code' => 'validation', 'message' => 'account, range, or limit is invalid.', 'details' => []], 400);
        }
        $result = $reports->generalLedger(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $accountId,
            $range['from'],
            $range['to'],
            $limit,
            $request->query('cursor') !== null ? (string) $request->query('cursor') : null,
        );

        return response()->json($result->payload, $result->status);
    }

    public function trialBalance(Request $request, LedgerReportService $reports): JsonResponse
    {
        $result = $reports->trialBalance(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            $request->query('asOf') !== null ? (string) $request->query('asOf') : null,
        );

        return response()->json($result->payload, $result->status);
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    private function dateRange(?string $range): array
    {
        if ($range === null || ! str_contains($range, '..')) {
            return ['from' => null, 'to' => null];
        }

        [$from, $to] = explode('..', $range, 2);

        return ['from' => $from !== '' ? $from : null, 'to' => $to !== '' ? $to : null];
    }
}
