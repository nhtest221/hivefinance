<?php

namespace App\Http\Controllers\Reporting;

use App\Reporting\Application\APAgeingQuery;
use App\Reporting\Application\ARAgeingQuery;
use App\Reporting\Application\BalanceSheetQuery;
use App\Reporting\Application\CashViewQuery;
use App\Reporting\Application\FXRevaluationQuery;
use App\Reporting\Application\ProfitAndLossQuery;
use App\Reporting\Application\TaxSummaryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportingController
{
    public function profitAndLoss(Request $request, ProfitAndLossQuery $query): JsonResponse
    {
        $result = $query->fetch(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            (string) $request->query('period'),
            $request->query('sbu') !== null ? (string) $request->query('sbu') : null,
            (string) ($request->query('basis') ?? 'accrual'),
            $request->query('compare_to') !== null ? (string) $request->query('compare_to') : null,
        );

        return response()->json($result->payload, $result->status);
    }

    public function balanceSheet(Request $request, BalanceSheetQuery $query): JsonResponse
    {
        $result = $query->fetch(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            (string) $request->query('asOf'),
            $request->query('sbu') !== null ? (string) $request->query('sbu') : null,
            $request->query('compare_to') !== null ? (string) $request->query('compare_to') : null,
        );

        return response()->json($result->payload, $result->status);
    }

    public function arAgeing(Request $request, ARAgeingQuery $query): JsonResponse
    {
        $result = $query->fetch(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            (string) $request->query('asOf'),
            $request->query('customer') !== null ? (string) $request->query('customer') : null,
        );

        return response()->json($result->payload, $result->status);
    }

    public function apAgeing(Request $request, APAgeingQuery $query): JsonResponse
    {
        $result = $query->fetch(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            (string) $request->query('asOf'),
            $request->query('vendor') !== null ? (string) $request->query('vendor') : null,
        );

        return response()->json($result->payload, $result->status);
    }

    public function taxSummary(Request $request, TaxSummaryQuery $query): JsonResponse
    {
        $result = $query->fetch($request->user(), (string) $request->header('X-Entity-Id'), (string) $request->query('period'));

        return response()->json($result->payload, $result->status);
    }

    public function fxRevaluation(Request $request, FXRevaluationQuery $query): JsonResponse
    {
        $result = $query->fetch($request->user(), (string) $request->header('X-Entity-Id'), (string) $request->query('period'));

        return response()->json($result->payload, $result->status);
    }

    public function cashView(Request $request, CashViewQuery $query): JsonResponse
    {
        $result = $query->fetch(
            $request->user(),
            (string) $request->header('X-Entity-Id'),
            (string) $request->query('period'),
            $request->query('sbu') !== null ? (string) $request->query('sbu') : null,
        );

        return response()->json($result->payload, $result->status);
    }
}
