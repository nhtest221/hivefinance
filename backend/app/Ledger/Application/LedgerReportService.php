<?php

namespace App\Ledger\Application;

use App\Ledger\Domain\DecimalAmount;
use App\Identity\Application\EntityReferenceQuery;
use App\Models\Ledger\JournalLine;
use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final readonly class LedgerReportService
{
    public function __construct(private LedgerAuthorizationService $authorization, private EntityReferenceQuery $entities)
    {
    }

    public function accountBalance(User $actor, string $entityId, string $accountId, ?string $asOf): LedgerActionResult
    {
        $permission = 'ledger.reports.read';
        if (!$this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        $account = LedgerAccount::query()->where('entity_id', $entityId)->find($accountId);
        if (!$account instanceof LedgerAccount) {
            return new LedgerActionResult(['error_code' => 'not_found', 'message' => 'The account was not found.', 'details' => []], 404);
        }

        $balance = $this->sumAccount($entityId, $accountId, $asOf);

        return new LedgerActionResult([
            'account_id' => $accountId,
            'as_of' => $asOf,
            'balance' => $balance->toString(),
            'normal_balance' => $account->normal_balance,
        ]);
    }

    public function generalLedger(User $actor, string $entityId, string $accountId, ?string $from, ?string $to, int $limit = 50, ?string $cursor = null): LedgerActionResult
    {
        $permission = 'ledger.reports.read';
        if (!$this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        $account = LedgerAccount::query()->where('entity_id', $entityId)->find($accountId);
        if (!$account instanceof LedgerAccount) {
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
            ->where('entity_id', $entityId)
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->where('state', 'posted')->where('posted_at', '<=', $boundary)
                ->when($from !== null, fn ($query) => $query->whereDate('entry_date', '>=', $from))
                ->when($to !== null, fn ($query) => $query->whereDate('entry_date', '<=', $to)))
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.line_no')->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get();

        $opening = $this->sumAccount($entityId, $accountId, $from === null ? null : date('Y-m-d', strtotime($from.' -1 day')));
        $running = $opening;
        $presented = $lines->map(function (JournalLine $line) use (&$running): array {
            $running = $running->add(DecimalAmount::fromString($line->debit))->subtract(DecimalAmount::fromString($line->credit));
            return [
                'journal_entry_id' => $line->journal_entry_id, 'line_id' => $line->id,
                'entry_date' => $line->journalEntry->entry_date->toDateString(), 'reference' => $line->journalEntry->reference,
                'description' => $line->description,
                'debit' => DecimalAmount::fromString($line->debit)->isZero() ? null : ['amount' => $line->debit, 'currency' => $line->currency],
                'credit' => DecimalAmount::fromString($line->credit)->isZero() ? null : ['amount' => $line->credit, 'currency' => $line->currency],
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
        if (!is_array($value) || !isset($value['offset'], $value['boundary']) || !is_int($value['offset']) || !is_string($value['boundary'])) {
            return null;
        }
        return ['offset' => $value['offset'], 'boundary' => $value['boundary']];
    }

    public function trialBalance(User $actor, string $entityId, ?string $asOf): LedgerActionResult
    {
        $permission = 'ledger.reports.read';
        if (!$this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        /** @var Collection<int, LedgerAccount> $accounts */
        $accounts = LedgerAccount::query()->where('entity_id', $entityId)->orderBy('code')->get();
        $totalDebit = DecimalAmount::zero();
        $totalCredit = DecimalAmount::zero();

        $rows = $accounts->map(function (LedgerAccount $account) use ($entityId, $asOf, &$totalDebit, &$totalCredit): array {
            $balance = $this->sumAccount($entityId, $account->id, $asOf);
            $debit = $balance->isPositive() ? $balance : DecimalAmount::zero();
            $credit = $balance->isPositive() ? DecimalAmount::zero() : $balance->negate();
            $totalDebit = $totalDebit->add($debit);
            $totalCredit = $totalCredit->add($credit);

            return [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
            ];
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

    private function sumAccount(string $entityId, string $accountId, ?string $asOf): DecimalAmount
    {
        /** @var Collection<int, JournalLine> $lines */
        $lines = JournalLine::query()
            ->where('entity_id', $entityId)
            ->where('account_id', $accountId)
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
}
