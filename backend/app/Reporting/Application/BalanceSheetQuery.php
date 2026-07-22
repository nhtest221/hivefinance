<?php

namespace App\Reporting\Application;

use App\Identity\Application\EntityReferenceQuery;
use App\Ledger\Application\AccountMovementQuery;
use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use App\Reporting\Domain\AccountClassificationMap;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;

/**
 * API Contracts §13.8: the frozen Balance Sheet layout v1, invariant Assets = Liabilities
 * + Equity, current-period result via explicit classification and exact references.
 */
final readonly class BalanceSheetQuery
{
    private const array ASSET_KEYS = ['asset_current', 'asset_non_current'];

    private const array LIABILITY_KEYS = ['liability_current', 'liability_non_current'];

    public function __construct(
        private DocumentCommandSupport $commands,
        private ReportLayoutProvider $layouts,
        private AccountClassificationProvider $classifications,
        private AccountMovementQuery $movements,
        private EntityReferenceQuery $entities,
    ) {}

    public function fetch(User $actor, string $entityId, string $asOf, ?string $sbu, ?string $compareTo): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.balance_sheet.read')) {
            return $denied;
        }
        $layout = $this->layouts->getEffective($entityId, 'balance_sheet', $asOf);
        if ($layout === null) {
            return $this->commands->error('missing_report_layout', 'No effective Balance Sheet report layout is configured.', 422);
        }
        $map = $this->classifications->getEffective($entityId, $asOf);
        if ($map === null) {
            return $this->commands->error('missing_account_classification', 'No effective account classification map is configured.', 422);
        }
        $currency = $this->entities->functionalCurrency($entityId) ?? '';
        $body = $this->computeSheet($entityId, $asOf, $sbu, $map, $currency);
        if ($body instanceof DocumentActionResult) {
            return $body;
        }
        $body = ['as_of' => $asOf, 'layout_version' => $layout->versionNumber, 'classification_version' => $map->versionNumber, ...$body];

        if ($compareTo !== null) {
            $compareBody = $this->computeSheet($entityId, $compareTo, $sbu, $map, $currency);
            if ($compareBody instanceof DocumentActionResult) {
                return $compareBody;
            }
            $body['compare_to'] = ['as_of' => $compareTo, ...$compareBody];
        }

        return new DocumentActionResult($body);
    }

    /** @return array<string, mixed>|DocumentActionResult */
    private function computeSheet(string $entityId, string $asOf, ?string $sbu, AccountClassificationMap $map, string $currency): array|DocumentActionResult
    {
        $accounts = LedgerAccount::query()->where('entity_id', $entityId)->orderBy('code')->get(['id', 'code', 'name']);
        $accountIds = $accounts->pluck('id')->all();
        $balances = $this->movements->movementByAccount($entityId, $accountIds, null, $asOf, $sbu);

        $assetRows = $liabilityRows = $equityRows = [];
        $totalAssets = $totalLiabilities = $totalEquity = '0.0000';
        foreach ($accounts as $account) {
            $balance = $balances[$account->id] ?? '0.0000';
            if (ExactDecimal::compare($balance, '0.0000') === 0) {
                continue;
            }
            $classification = $map->classify($account->id);
            if ($classification === null) {
                return $this->commands->error('unclassified_account', 'An account posted to has no configured classification.', 422, ['account_id' => $account->id, 'account_code' => $account->code]);
            }
            $row = ['code' => $account->code, 'name' => $account->name, 'amount' => ['amount' => $balance, 'currency' => $currency]];
            if (in_array($classification, self::ASSET_KEYS, true)) {
                $assetRows[] = $row;
                $totalAssets = ExactDecimal::add($totalAssets, $balance);
            } elseif (in_array($classification, self::LIABILITY_KEYS, true)) {
                $magnitude = ExactDecimal::multiply($balance, '-1.0000');
                $liabilityRows[] = ['code' => $account->code, 'name' => $account->name, 'amount' => ['amount' => $magnitude, 'currency' => $currency]];
                $totalLiabilities = ExactDecimal::add($totalLiabilities, $magnitude);
            } elseif ($classification === 'equity') {
                $magnitude = ExactDecimal::multiply($balance, '-1.0000');
                $equityRows[] = ['code' => $account->code, 'name' => $account->name, 'amount' => ['amount' => $magnitude, 'currency' => $currency]];
                $totalEquity = ExactDecimal::add($totalEquity, $magnitude);
            }
        }

        $fiscalStart = $this->entities->fiscalYearStartDate($entityId, $asOf);
        if ($fiscalStart !== null) {
            $currentResult = $this->fiscalYtdNetProfit($entityId, $fiscalStart, $asOf, $sbu, $map);
            if (ExactDecimal::compare($currentResult, '0.0000') !== 0) {
                $equityRows[] = ['code' => null, 'name' => 'Current-Year P&L', 'amount' => ['amount' => $currentResult, 'currency' => $currency]];
                $totalEquity = ExactDecimal::add($totalEquity, $currentResult);
            }
        }

        $totalLiabilitiesAndEquity = ExactDecimal::add($totalLiabilities, $totalEquity);
        $difference = ExactDecimal::subtract($totalAssets, $totalLiabilitiesAndEquity);
        if (ExactDecimal::compare($difference, '0.0000') !== 0) {
            return $this->commands->error('report_unbalanced', 'The Balance Sheet does not balance.', 422, ['difference' => $difference]);
        }

        return [
            'sections' => [
                ['section_id' => 'assets', 'label' => 'Assets', 'rows' => $assetRows, 'subtotal' => ['amount' => $totalAssets, 'currency' => $currency]],
                ['section_id' => 'liabilities', 'label' => 'Liabilities', 'rows' => $liabilityRows, 'subtotal' => ['amount' => $totalLiabilities, 'currency' => $currency]],
                ['section_id' => 'equity', 'label' => 'Equity', 'rows' => $equityRows, 'subtotal' => ['amount' => $totalEquity, 'currency' => $currency]],
            ],
            'total_assets' => ['amount' => $totalAssets, 'currency' => $currency],
            'total_liabilities' => ['amount' => $totalLiabilities, 'currency' => $currency],
            'total_equity' => ['amount' => $totalEquity, 'currency' => $currency],
            'total_liabilities_and_equity' => ['amount' => $totalLiabilitiesAndEquity, 'currency' => $currency],
            'difference' => ['amount' => $difference, 'currency' => $currency],
        ];
    }

    private function fiscalYtdNetProfit(string $entityId, string $from, string $to, ?string $sbu, AccountClassificationMap $map): string
    {
        $pnlKeys = ['sales_revenue', 'cost_of_sales', 'operating_expense', 'non_operating_income', 'non_operating_expense'];
        $pnlAccountIds = array_values(array_map(fn (array $e): string => $e['account_id'], array_filter($map->entries, fn (array $e): bool => in_array($e['classification'], $pnlKeys, true))));
        $movements = $this->movements->movementByAccount($entityId, $pnlAccountIds, $from, $to, $sbu);
        $net = '0.0000';
        foreach ($map->entries as $entry) {
            if (! in_array($entry['classification'], $pnlKeys, true)) {
                continue;
            }
            $movement = $movements[$entry['account_id']] ?? '0.0000';
            $isIncome = in_array($entry['classification'], ['sales_revenue', 'non_operating_income'], true);
            $net = $isIncome
                ? ExactDecimal::add($net, ExactDecimal::multiply($movement, '-1.0000'))
                : ExactDecimal::subtract($net, $movement);
        }

        return $net;
    }
}
