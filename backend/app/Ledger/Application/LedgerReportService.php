<?php

namespace App\Ledger\Application;

use App\Identity\Application\EntityReferenceQuery;
use App\Ledger\Domain\DecimalAmount;
use App\Models\Ledger\JournalLine;
use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use App\Period\Application\PeriodQuery;
use Illuminate\Database\Eloquent\Collection;

final readonly class LedgerReportService
{
    public function __construct(private LedgerAuthorizationService $authorization, private EntityReferenceQuery $entities, private PeriodQuery $periods)
    {
        // Promoted readonly dependencies keep the application service immutable.
    }

    public function accountBalance(User $actor, string $entityId, string $accountId, ?string $asOf): LedgerActionResult
    {
        $permission = 'ledger.reports.read';
        if ($this->authorization->can($actor, $entityId, $permission) === false) {
            return $this->authorization->denyResponse($permission);
        }

        $account = LedgerAccount::query()->where('entity_id', $entityId)->find($accountId);
        if (($account instanceof LedgerAccount) === false) {
            return new LedgerActionResult(['error_code' => 'not_found', 'message' => 'The account was not found.', 'details' => []], 404);
        }

        $asOf ??= now('UTC')->toDateString();
        $balance = $this->sumAccount($entityId, $accountId, $asOf);
        $currency = $this->entities->functionalCurrency($entityId) ?? '';

        return new LedgerActionResult([
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'normal_balance' => $account->normal_balance],
            'as_of' => $asOf,
            'balance' => ['amount' => $balance->toString(), 'currency' => $currency],
        ]);
    }

    public function generalLedger(User $actor, string $entityId, string $accountId, ?string $from, ?string $to, int $limit = 50, ?string $cursor = null, ?string $sbu = null): LedgerActionResult
    {
        $permission = 'ledger.reports.read';
        if ($this->authorization->can($actor, $entityId, $permission) === false) {
            return $this->authorization->denyResponse($permission);
        }

        $account = LedgerAccount::query()->where('entity_id', $entityId)->find($accountId);
        if (($account instanceof LedgerAccount) === false) {
            return new LedgerActionResult(['error_code' => 'not_found', 'message' => 'The account was not found.', 'details' => []], 404);
        }

        $cursorState = $this->decodeCursor($cursor);
        if ($cursor !== null && $cursorState === null) {
            return new LedgerActionResult(['error_code' => 'validation', 'message' => 'The cursor is invalid.', 'details' => []], 400);
        }

        $boundary = $cursorState['boundary'] ?? now('UTC')->toISOString();
        $offset = $cursorState['offset'] ?? 0;
        $lines = JournalLine::query()
            ->with('journalEntry')
            ->where('journal_lines.entity_id', $entityId)
            ->where('journal_lines.account_id', $accountId)
            ->when($sbu !== null, fn ($query) => $query->where('journal_lines.sbu_tag', $sbu))
            ->whereHas('journalEntry', fn ($query) => $query->where('state', 'posted')->where('posted_at', '<=', $boundary)
                ->when($from !== null, fn ($query) => $query->whereDate('entry_date', '>=', $from))
                ->when($to !== null, fn ($query) => $query->whereDate('entry_date', '<=', $to)))
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.line_no')->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get();

        $opening = $this->sumAccount($entityId, $accountId, $from === null ? null : date('Y-m-d', strtotime($from.' -1 day')), $sbu);
        $running = $opening;
        $presented = $lines->map(function (JournalLine $line) use (&$running): array {
            $running = $running->add(DecimalAmount::fromString($line->debit))->subtract(DecimalAmount::fromString($line->credit));

            return [
                'journal_entry_id' => $line->journal_entry_id, 'line_id' => $line->id,
                'entry_date' => $line->journalEntry->entry_date->toDateString(), 'reference' => $line->journalEntry->reference,
                'description' => $line->description,
                'debit' => DecimalAmount::fromString($line->debit)->isZero() ? null : ['amount' => $line->debit, 'currency' => $line->currency],
                'credit' => DecimalAmount::fromString($line->credit)->isZero() ? null : ['amount' => $line->credit, 'currency' => $line->currency],
                'reversal_of_entry_id' => $line->journalEntry->reversal_of_entry_id,
                'running_balance' => ['amount' => $running->toString(), 'currency' => $line->currency],
            ];
        });
        $pageEntries = $presented->slice($offset, $limit)->values()->all();
        $nextOffset = $offset + count($pageEntries);
        $nextCursor = $nextOffset < $presented->count() ? base64_encode(json_encode(['offset' => $nextOffset, 'boundary' => $boundary], JSON_THROW_ON_ERROR)) : null;
        $currency = $this->entities->functionalCurrency($entityId) ?? '';

        return new LedgerActionResult([
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'normal_balance' => $account->normal_balance],
            'basis' => 'accrual',
            'range' => ['from' => $from, 'to' => $to],
            'opening_balance' => ['amount' => $opening->toString(), 'currency' => $currency],
            'entries' => $pageEntries,
            'closing_balance' => ['amount' => $running->toString(), 'currency' => $currency],
            'page' => ['limit' => $limit, 'next_cursor' => $nextCursor],
        ]);
    }

    /** @return array{offset:int,boundary:string}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }
        $decoded = base64_decode($cursor, true);
        $value = $decoded === false ? null : json_decode($decoded, true);
        if (is_array($value) === false || isset($value['offset'], $value['boundary']) === false || is_int($value['offset']) === false || is_string($value['boundary']) === false) {
            return null;
        }

        return ['offset' => $value['offset'], 'boundary' => $value['boundary']];
    }

    public function trialBalance(User $actor, string $entityId, ?string $asOf, ?string $periodRef = null, ?string $sbu = null): LedgerActionResult
    {
        $permission = 'ledger.reports.read';
        if ($this->authorization->can($actor, $entityId, $permission) === false) {
            return $this->authorization->denyResponse($permission);
        }

        $periodStart = null;
        if ($periodRef !== null) {
            $period = $this->periods->show($entityId, $periodRef);
            if ($period === null) {
                return new LedgerActionResult(['error_code' => 'not_found', 'message' => 'The period was not found.', 'details' => []], 404);
            }
            $periodStart = date('Y-m-d', strtotime($period->starts_on->toDateString().' -1 day'));
        }

        /** @var Collection<int, LedgerAccount> $accounts */
        $accounts = LedgerAccount::query()->where('entity_id', $entityId)->orderBy('code')->get();
        $accountIds = $accounts->pluck('id')->all();
        $balances = $this->sumAccounts($entityId, $accountIds, $asOf, $sbu);
        $openings = $periodStart !== null ? $this->sumAccounts($entityId, $accountIds, $periodStart, $sbu) : [];
        $totalDebit = DecimalAmount::zero();
        $totalCredit = DecimalAmount::zero();

        $rows = $accounts->map(function (LedgerAccount $account) use ($balances, $openings, $periodStart, &$totalDebit, &$totalCredit): array {
            $balance = $balances[$account->id];
            $debit = $balance->isPositive() ? $balance : DecimalAmount::zero();
            $credit = $balance->isPositive() ? DecimalAmount::zero() : $balance->negate();
            $totalDebit = $totalDebit->add($debit);
            $totalCredit = $totalCredit->add($credit);

            $row = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
            ];
            if ($periodStart !== null) {
                $opening = $openings[$account->id];
                $row['opening'] = $opening->toString();
                $row['movement'] = $balance->subtract($opening)->toString();
            }

            return $row;
        });

        return new LedgerActionResult([
            'as_of' => $asOf,
            'rows' => $rows->all(),
            'totals' => [
                'debit' => $totalDebit->toString(),
                'credit' => $totalCredit->toString(),
                'balanced' => $totalDebit->equals($totalCredit),
            ],
        ]);
    }

    private function sumAccount(string $entityId, string $accountId, ?string $asOf, ?string $sbu = null): DecimalAmount
    {
        /** @var Collection<int, JournalLine> $lines */
        $lines = JournalLine::query()
            ->where('entity_id', $entityId)
            ->where('account_id', $accountId)
            ->when($sbu !== null, fn ($query) => $query->where('sbu_tag', $sbu))
            ->whereHas('journalEntry', fn ($query) => $query->where('state', 'posted')
                ->when($asOf !== null, fn ($query) => $query->whereDate('entry_date', '<=', $asOf)))
            ->get();

        return $lines->reduce(
            fn (DecimalAmount $balance, JournalLine $line): DecimalAmount => $balance
                ->add(DecimalAmount::fromString($line->debit))
                ->subtract(DecimalAmount::fromString($line->credit)),
            DecimalAmount::zero(),
        );
    }

    /**
     * Batched equivalent of sumAccount() for every account in one call — a single query
     * instead of one query per account, used by trialBalance() to avoid an N+1 over the
     * full chart of accounts.
     *
     * @param  list<string>  $accountIds
     * @return array<string, DecimalAmount> accountId => balance
     */
    private function sumAccounts(string $entityId, array $accountIds, ?string $asOf, ?string $sbu = null): array
    {
        $balances = array_fill_keys($accountIds, DecimalAmount::zero());
        if ($accountIds === []) {
            return $balances;
        }
        /** @var Collection<int, JournalLine> $lines */
        $lines = JournalLine::query()
            ->where('entity_id', $entityId)
            ->whereIn('account_id', $accountIds)
            ->when($sbu !== null, fn ($query) => $query->where('sbu_tag', $sbu))
            ->whereHas('journalEntry', fn ($query) => $query->where('state', 'posted')
                ->when($asOf !== null, fn ($query) => $query->whereDate('entry_date', '<=', $asOf)))
            ->get(['account_id', 'debit', 'credit']);

        foreach ($lines as $line) {
            $balances[$line->account_id] = $balances[$line->account_id]
                ->add(DecimalAmount::fromString($line->debit))
                ->subtract(DecimalAmount::fromString($line->credit));
        }

        return $balances;
    }
}
