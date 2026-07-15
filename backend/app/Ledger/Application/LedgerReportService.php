<?php

namespace App\Ledger\Application;

use App\Ledger\Domain\DecimalAmount;
use App\Models\Ledger\JournalLine;
use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final readonly class LedgerReportService
{
    public function __construct(private LedgerAuthorizationService $authorization) {}

    public function accountBalance(User $actor, string $entityId, string $accountId, ?string $asOf): LedgerActionResult
    {
        $permission = 'ledger.reports.read';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        $account = LedgerAccount::query()->where('entity_id', $entityId)->find($accountId);
        if (! $account instanceof LedgerAccount) {
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

    public function generalLedger(User $actor, string $entityId, string $accountId, ?string $from, ?string $to): LedgerActionResult
    {
        $permission = 'ledger.reports.read';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        /** @var Collection<int, JournalLine> $lines */
        $lines = JournalLine::query()
            ->with('journalEntry')
            ->where('entity_id', $entityId)
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->where('state', 'posted')
                ->when($from !== null, fn ($query) => $query->whereDate('entry_date', '>=', $from))
                ->when($to !== null, fn ($query) => $query->whereDate('entry_date', '<=', $to)))
            ->orderBy('id')
            ->get();

        $running = DecimalAmount::zero();

        return new LedgerActionResult([
            'account_id' => $accountId,
            'range' => ['from' => $from, 'to' => $to],
            'entries' => $lines->map(function (JournalLine $line) use (&$running): array {
                $running = $running
                    ->add(DecimalAmount::fromString($line->debit))
                    ->subtract(DecimalAmount::fromString($line->credit));

                return [
                    'journal_entry_id' => $line->journal_entry_id,
                    'entry_date' => $line->journalEntry->entry_date->toDateString(),
                    'description' => $line->description,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'currency' => $line->currency,
                    'running_balance' => $running->toString(),
                ];
            })->all(),
        ]);
    }

    public function trialBalance(User $actor, string $entityId, ?string $asOf): LedgerActionResult
    {
        $permission = 'ledger.reports.read';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
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
