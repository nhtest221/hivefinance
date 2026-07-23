<?php

namespace App\Reporting\Application;

use App\Identity\Application\EntityReferenceQuery;
use App\Ledger\Application\AccountMovementQuery;
use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use App\Period\Application\PeriodQuery;
use App\Reporting\Domain\AccountClassificationMap;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;

/**
 * API Contracts §13.7: the frozen nine-line Profit and Loss skeleton, computed from
 * versioned ReportLayout/AccountClassificationMap — never inferred from name or code.
 */
final readonly class ProfitAndLossQuery
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private PeriodQuery $periods,
        private ReportLayoutProvider $layouts,
        private AccountClassificationProvider $classifications,
        private AccountMovementQuery $movements,
        private EntityReferenceQuery $entities,
    ) {}

    public function fetch(User $actor, string $entityId, string $periodRef, ?string $sbu, string $basis, ?string $compareTo): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.profit_and_loss.read')) {
            return $denied;
        }
        if ($basis !== 'accrual') {
            return $this->commands->error('unsupported_basis', 'Profit and Loss basis=cash is excluded from M5 MVP.', 422);
        }
        $period = $this->periods->show($entityId, $periodRef);
        if ($period === null) {
            return $this->commands->error('not_found', 'The period was not found.', 404);
        }
        $asOf = $period->ends_on->toDateString();
        $layout = $this->layouts->getEffective($entityId, 'profit_and_loss', $asOf);
        if ($layout === null) {
            return $this->commands->error('missing_report_layout', 'No effective Profit and Loss report layout is configured.', 422);
        }
        $map = $this->classifications->getEffective($entityId, $asOf);
        if ($map === null) {
            return $this->commands->error('missing_account_classification', 'No effective account classification map is configured.', 422);
        }

        $currency = $this->entities->functionalCurrency($entityId) ?? '';
        $lines = $this->computeLines($entityId, $period->starts_on->toDateString(), $asOf, $sbu, $map, $currency);
        if ($lines instanceof DocumentActionResult) {
            return $lines;
        }

        $body = ['period_ref' => $periodRef, 'basis' => $basis, 'layout_version' => $layout->versionNumber, 'classification_version' => $map->versionNumber, 'lines' => $lines];

        if ($compareTo !== null) {
            $comparePeriod = $this->periods->show($entityId, $compareTo);
            if ($comparePeriod === null) {
                return $this->commands->error('not_found', 'The comparison period was not found.', 404);
            }
            $compareLines = $this->computeLines($entityId, $comparePeriod->starts_on->toDateString(), $comparePeriod->ends_on->toDateString(), $sbu, $map, $currency);
            if ($compareLines instanceof DocumentActionResult) {
                return $compareLines;
            }
            $body['compare_to'] = ['period_ref' => $compareTo, 'lines' => $compareLines];
        }

        return new DocumentActionResult($body);
    }

    /**
     * @return list<array<string, mixed>>|DocumentActionResult
     */
    private function computeLines(string $entityId, string $from, string $to, ?string $sbu, AccountClassificationMap $map, string $currency): array|DocumentActionResult
    {
        $accounts = LedgerAccount::query()->where('entity_id', $entityId)->get(['id', 'code']);
        $accountIds = $accounts->pluck('id')->all();
        $movements = $this->movements->movementByAccount($entityId, $accountIds, $from, $to, $sbu);

        $groups = ['sales_revenue' => '0.0000', 'cost_of_sales' => '0.0000', 'operating_expense' => '0.0000', 'non_operating_income' => '0.0000', 'non_operating_expense' => '0.0000'];
        foreach ($accounts as $account) {
            $movement = $movements[$account->id] ?? '0.0000';
            if (ExactDecimal::compare($movement, '0.0000') === 0) {
                continue;
            }
            $classification = $map->classify($account->id);
            if ($classification === null) {
                return $this->commands->error('unclassified_account', 'An account posted to in the period has no configured classification.', 422, ['account_id' => $account->id, 'account_code' => $account->code]);
            }
            if (! array_key_exists($classification, $groups)) {
                continue;
            }
            $groups[$classification] = ExactDecimal::add($groups[$classification], $movement);
        }

        // Revenue/liability/equity are credit-normal, so JournalLine's debit-minus-credit
        // movement is negative for normal activity; present the natural positive magnitude.
        $salesRevenue = ExactDecimal::multiply(ExactDecimal::normalize($groups['sales_revenue']), '-1.0000');
        $costOfSales = ExactDecimal::normalize($groups['cost_of_sales']);
        $grossProfit = ExactDecimal::subtract($salesRevenue, $costOfSales);
        $operatingExpense = ExactDecimal::normalize($groups['operating_expense']);
        $operatingProfit = ExactDecimal::subtract($grossProfit, $operatingExpense);
        $nonOperatingIncome = ExactDecimal::subtract(ExactDecimal::multiply(ExactDecimal::normalize($groups['non_operating_income']), '-1.0000'), ExactDecimal::normalize($groups['non_operating_expense']));
        $netProfit = ExactDecimal::add($operatingProfit, $nonOperatingIncome);
        $zero = ExactDecimal::compare($salesRevenue, '0.0000') === 0;
        $grossProfitPct = $zero ? null : bcmul(bcdiv($grossProfit, $salesRevenue, 8), '100', 4);
        $netProfitPct = $zero ? null : bcmul(bcdiv($netProfit, $salesRevenue, 8), '100', 4);

        $money = fn (string $amount): array => ['amount' => $amount, 'currency' => $currency];

        return [
            ['section_id' => 'sales_revenue', 'label' => 'Sales Revenue', 'amount' => $money($salesRevenue)],
            ['section_id' => 'total_cost_of_sales', 'label' => 'Total Cost of Sales', 'amount' => $money($costOfSales)],
            ['section_id' => 'gross_profit', 'label' => 'Gross Profit', 'amount' => $money($grossProfit)],
            ['section_id' => 'gross_profit_pct', 'label' => 'Gross Profit %', 'percentage' => $grossProfitPct],
            ['section_id' => 'total_operating_expense', 'label' => 'Total Operating Expenses', 'amount' => $money($operatingExpense)],
            ['section_id' => 'operating_profit', 'label' => 'Operating Profit', 'amount' => $money($operatingProfit)],
            ['section_id' => 'total_non_operating_income', 'label' => 'Total Non-Operating Income', 'amount' => $money($nonOperatingIncome)],
            ['section_id' => 'net_profit', 'label' => 'Net Profit', 'amount' => $money($netProfit)],
            ['section_id' => 'net_profit_pct', 'label' => 'Net Profit %', 'percentage' => $netProfitPct],
        ];
    }
}
