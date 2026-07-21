<?php

namespace App\Settlement\Application;

use App\CurrencyFx\Application\RateReferenceService;
use App\CurrencyFx\Domain\RealisedFxCalculator;
use App\Identity\Application\ApprovalExecutionContext;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Application\EntityReferenceQuery;
use App\Identity\Domain\OriginatingCommand;
use App\Ledger\Application\AccountReferenceQuery;
use App\Ledger\Application\SettlementPostingService;
use App\Models\Settlement\Allocation;
use App\Models\Settlement\CreditConsumption;
use App\Models\Settlement\CreditTranche;
use App\Models\Settlement\PartyCreditBalance;
use App\Models\User;
use App\Payables\Application\OpenPayableService;
use App\Receivables\Application\OpenReceivableService;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use App\Tax\Application\WithholdingConfigurationQuery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class SettlementService
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private ApprovalPolicyQuery $approvalPolicy,
        private ApprovalLifecycleService $approvals,
        private OpenReceivableService $receivables,
        private OpenPayableService $payables,
        private AccountReferenceQuery $accounts,
        private EntityReferenceQuery $entities,
        private RateReferenceService $rates,
        private RealisedFxCalculator $fx,
        private WithholdingConfigurationQuery $withholding,
        private SettlementNumberService $numbers,
        private SettlementPostingService $posting,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    /** @param array<string,mixed> $data */
    public function receipt(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        return $this->submit($actor, $entityId, 'receipt', null, $data, $key, null);
    }

    /** @param array<string,mixed> $data */
    public function payment(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        return $this->submit($actor, $entityId, 'payment', null, $data, $key, null);
    }

    /** @param array<string,mixed> $data */
    public function applyCredit(User $actor, string $entityId, string $partyId, array $data, ?string $key): DocumentActionResult
    {
        return $this->submit($actor, $entityId, 'credit_application', $partyId, $data, $key, null);
    }

    /** @param array<string,mixed> $data */
    public function refundCredit(User $actor, string $entityId, string $partyId, array $data, ?string $key): DocumentActionResult
    {
        return $this->submit($actor, $entityId, 'credit_refund', $partyId, $data, $key, null);
    }

    public function reverse(User $actor, string $entityId, string $allocationId, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        return $this->submit($actor, $entityId, 'reversal', $allocationId, [], $key, $ifMatch);
    }

    /** @param array<string,mixed> $payload */
    public function executeApproved(string $type, array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! is_string($payload['idempotency_key'] ?? null)) {
            return $this->commands->error('validation', 'The approved settlement payload is invalid.', 400);
        }

        return $this->execute($type, $context->entityId, $payload['resource_id'] ?? null, $data, $payload['idempotency_key'], $payload['expected_version'] ?? null, $context->makerId, $context->approverId, $context->causationId);
    }

    /** @param array<string,mixed> $data */
    private function submit(User $actor, string $entityId, string $type, ?string $resourceId, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        $capability = $this->capability($type);
        if ($denied = $this->commands->authorize($actor, $entityId, $capability)) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = null;
        if ($type === 'reversal') {
            $expected = $this->commands->expectedVersion($ifMatch);
            if ($expected instanceof DocumentActionResult) {
                return $expected;
            }
        }
        $resourceId ??= $type === 'receipt' ? ($data['customer_id'] ?? null) : ($type === 'payment' ? ($data['vendor_id'] ?? null) : null);
        if (! is_string($resourceId) || ! Str::isUuid($resourceId)) {
            return $this->commands->error('validation', 'The Settlement resource identifier must be a UUID.', 400);
        }
        $preflight = $this->preflight($type, $entityId, $resourceId, $data, $expected);
        if ($preflight !== null) {
            return $preflight;
        }
        if ($this->approvalPolicy->isConfigured($entityId)) {
            $operation = $this->operation($type, $resourceId);
            $payload = ['data' => $data, 'resource_id' => $resourceId, 'expected_version' => $expected, 'idempotency_key' => $key];
            $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('settlement_'.$type, 1, $payload, $resourceId, $capability, $expected), $operation, (string) $key, $this->correlation());

            return new DocumentActionResult($result->payload, $result->status, $result->headers);
        }

        return $this->execute($type, $entityId, $resourceId, $data, (string) $key, $expected, $actor->id, $actor->id, (string) $key);
    }

    /** @param array<string,mixed> $data */
    private function execute(string $type, string $entityId, ?string $resourceId, array $data, string $key, ?int $expected, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        if (! is_string($resourceId)) {
            return $this->commands->error('validation', 'The Settlement resource identifier is required.', 400);
        }
        $operation = $this->operation($type, $resourceId);
        $hash = $this->commands->hash([$resourceId, $data, $expected]);
        if ($replay = $this->commands->replay($makerId, $entityId, $operation, $key, $hash)) {
            return $replay;
        }
        try {
            return match ($type) {
                'receipt', 'payment' => $this->executeCash($type, $entityId, $resourceId, $data, $key, $hash, $makerId, $executorId, $causationId),
                'credit_application' => $this->executeCreditApplication($entityId, $resourceId, $data, $key, $hash, $makerId, $executorId, $causationId),
                'credit_refund' => $this->executeCreditRefund($entityId, $resourceId, $data, $key, $hash, $makerId, $executorId, $causationId),
                'reversal' => $this->executeReversal($entityId, $resourceId, (int) $expected, $key, $hash, $makerId, $executorId, $causationId),
                default => $this->commands->error('validation', 'Unsupported Settlement command.', 400),
            };
        } catch (SettlementAbort $abort) {
            return $abort->result;
        } catch (UniqueConstraintViolationException) {
            return $this->commands->replay($makerId, $entityId, $operation, $key, $hash) ?? $this->commands->error('concurrency_conflict', 'A concurrent Settlement command succeeded first.', 409);
        }
    }

    /** @param array<string,mixed> $data */
    private function executeCash(string $type, string $entityId, string $partyId, array $data, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        $draw = $this->numbers->draw($type, $entityId, (string) $data['settlement_date']);
        if ($draw === null) {
            return $this->rule('missing_numbering_configuration', 'Settlement numbering configuration is unavailable.');
        }
        try {
            return DB::transaction(function () use ($type, $entityId, $partyId, $data, $key, $hash, $makerId, $executorId, $causationId, $draw): DocumentActionResult {
                $prepared = $this->prepareCash($type, $entityId, $partyId, $data);
                if ($prepared instanceof DocumentActionResult) {
                    throw new SettlementAbort($prepared);
                }
                $allocation = Allocation::query()->create(['entity_id' => $entityId, 'allocation_number' => $draw['number'], 'operation' => $type, 'party_type' => $prepared['party_type'], 'party_id' => $partyId, 'settlement_date' => $data['settlement_date'], 'bank_account_id' => $data['bank_account_id'], 'currency' => $prepared['currency'], 'gross_amount' => $prepared['gross'], 'bank_amount' => $prepared['bank'], 'withholding_amount' => $prepared['withholding'], 'allocated_amount' => $prepared['allocated'], 'unapplied_amount' => $prepared['unapplied'], 'functional_gross_amount' => $prepared['functional_gross'], 'rate_record_id' => $prepared['rate']['rate_record_id'] ?? null, 'exchange_rate_reference' => $prepared['rate'], 'journal_entry_ids' => [], 'state' => 'building', 'version' => 1, 'created_by' => $makerId, 'posted_at' => Carbon::now('UTC')]);
                $postingLines = [];
                $bankFunctional = $this->functional($prepared['bank'], $prepared['rate']);
                if ($bankFunctional === null) {
                    throw new SettlementAbort($this->rule('credit_fx_calculation_failed', 'Settlement FX calculation failed.'));
                }
                $postingLines[] = $this->line((string) $data['bank_account_id'], 'Settlement bank movement', $type === 'receipt' ? $bankFunctional : '0.0000', $type === 'payment' ? $bankFunctional : '0.0000', $prepared['currency'], $prepared['bank'], $prepared['rate']);
                foreach ($prepared['withholding_lines'] as $line) {
                    $allocation->withholdingLines()->create(['entity_id' => $entityId, 'withholding_code' => $line['withholding_code'], 'amount' => $line['amount'], 'tax_snapshot' => null, 'configuration_reference' => $line['configuration_reference'], 'account_id' => $line['account_id']]);
                    $functional = $this->functional($line['amount'], $prepared['rate']);
                    if ($functional === null) {
                        throw new SettlementAbort($this->rule('credit_fx_calculation_failed', 'Withholding FX calculation failed.'));
                    }
                    $postingLines[] = $this->line($line['account_id'], 'Settlement withholding', $line['posting_side'] === 'debit' ? $functional : '0.0000', $line['posting_side'] === 'credit' ? $functional : '0.0000', $prepared['currency'], $line['amount'], $prepared['rate']);
                }
                $fxResults = [];
                usort($prepared['documents'], fn (array $left, array $right): int => strcmp((string) $left['document_id'], (string) $right['document_id']));
                foreach ($prepared['documents'] as $document) {
                    $applied = $this->documentService($prepared['party_type'])->applySettlement($entityId, $document['document_id'], $document['amount'], $document['expected_version']);
                    if (isset($applied['error'])) {
                        throw new SettlementAbort($this->documentError($applied));
                    }
                    $result = $this->cashFx($prepared['party_type'], $document['amount'], $document['before']['exchange_rate_reference'], $prepared['rate'], $prepared['functional_currency']);
                    if ($result === false) {
                        throw new SettlementAbort($this->rule('credit_fx_calculation_failed', 'Realised FX calculation failed.'));
                    }
                    $documentFunctional = $result['document_functional_amount']['amount'] ?? $document['amount'];
                    $account = $prepared['party_type'] === 'customer' ? config('documents.invoice.receivable_account_id') : config('documents.bill.payable_account_id');
                    if (! is_string($account) || ! Str::isUuid($account)) {
                        throw new SettlementAbort($this->rule('missing_posting_configuration', 'Document control account mapping is unavailable.'));
                    }
                    $postingLines[] = $this->line($account, 'Settlement document clearing', $prepared['party_type'] === 'vendor' ? $documentFunctional : '0.0000', $prepared['party_type'] === 'customer' ? $documentFunctional : '0.0000', $prepared['currency'], $document['amount'], $document['before']['exchange_rate_reference']);
                    if (is_array($result)) {
                        $this->appendFxPosting($postingLines, $entityId, $result);
                        if (($result['classification'] ?? 'none') !== 'none') {
                            $fxResults[] = $result;
                        }
                    }
                    $allocation->links()->create(['entity_id' => $entityId, 'document_type' => $document['document_type'], 'document_id' => $document['document_id'], 'document_number' => $applied['before']['document_number'], 'document_party_id' => $applied['before']['party_id'], 'credit_tranche_id' => null, 'applied_amount' => $document['amount'], 'expected_version' => $document['expected_version'], 'open_balance_before' => $applied['before']['open_balance'], 'open_balance_after' => $applied['after']['open_balance'], 'version_before' => $applied['before']['version'], 'version_after' => $applied['after']['version'], 'status_before' => $applied['before']['status'], 'status_after' => $applied['after']['status'], 'document_rate_record_id' => $applied['before']['rate_record_id'], 'realised_fx_result' => is_array($result) ? $result : null]);
                    $this->outbox->record($prepared['party_type'] === 'customer' ? 'InvoiceStatusChanged' : 'BillStatusChanged', $document['document_type'] === 'invoice' ? 'Invoice' : 'Bill', $document['document_id'], ['documentId' => $document['document_id'], 'status' => $applied['after']['status'], 'openBalance' => ['amount' => $applied['after']['open_balance'], 'currency' => $prepared['currency']]], $entityId, metadata: ['causation_id' => $causationId]);
                }
                $createdTranches = [];
                if ($prepared['unapplied'] !== '0.0000') {
                    $projection = PartyCreditBalance::query()->where(['entity_id' => $entityId, 'party_type' => $prepared['party_type'], 'party_id' => $partyId, 'currency' => $prepared['currency']])->first();
                    $actualVersion = $projection instanceof PartyCreditBalance ? $projection->version : 0;
                    if ($actualVersion !== (int) $data['party_credit_expected_version']) {
                        throw new SettlementAbort($this->commands->error('concurrency_conflict', 'Party-credit projection version is stale.', 409, ['required_version' => $actualVersion]));
                    }
                    $functional = $this->functional($prepared['unapplied'], $prepared['rate']);
                    if ($functional === null) {
                        throw new SettlementAbort($this->rule('credit_fx_calculation_failed', 'Credit carrying value could not be calculated.'));
                    }
                    $tranche = CreditTranche::query()->create(['entity_id' => $entityId, 'party_type' => $prepared['party_type'], 'party_id' => $partyId, 'currency' => $prepared['currency'], 'original_amount' => $prepared['unapplied'], 'remaining_amount' => $prepared['unapplied'], 'original_functional_amount' => $functional, 'remaining_functional_amount' => $functional, 'source_rate_record_id' => $prepared['rate']['rate_record_id'] ?? null, 'source_exchange_rate_reference' => $prepared['rate'], 'source_allocation_id' => $allocation->id, 'source_reference' => $allocation->allocation_number, 'version' => 1]);
                    $createdTranches[] = $this->eventSource($tranche, $prepared['unapplied'], $functional, null, null);
                    $this->rebuildProjection($entityId, $prepared['party_type'], $partyId, $prepared['currency'], $actualVersion);
                    $creditAccount = $this->creditAccount($prepared['party_type']);
                    if ($creditAccount === null) {
                        throw new SettlementAbort($this->rule('missing_posting_configuration', 'Party-credit account mapping is unavailable.'));
                    }
                    $postingLines[] = $this->line($creditAccount, 'Unapplied party credit', $prepared['party_type'] === 'vendor' ? $functional : '0.0000', $prepared['party_type'] === 'customer' ? $functional : '0.0000', $prepared['currency'], $prepared['unapplied'], $prepared['rate']);
                }
                $posted = $this->posting->post($entityId, $allocation->id, (string) $data['settlement_date'], $executorId, $causationId, $postingLines);
                if ($posted->errorCode !== null || $posted->journalId === null) {
                    throw new SettlementAbort($this->postingError($posted->errorCode));
                }
                $allocation->journal_entry_ids = [$posted->journalId];
                $allocation->state = 'posted';
                $allocation->save();
                if ($prepared['rate']) {
                    $this->rates->markReferenced($entityId, (string) $prepared['rate']['rate_record_id']);
                }
                $allocation->load(['links', 'withholdingLines']);
                $keyName = $type === 'receipt' ? 'receipt' : 'payment';
                $body = [$keyName => $this->presentAllocation($allocation)];
                if ($prepared['unapplied'] !== '0.0000') {
                    $body['party_credit'] = $this->legacyProjection($entityId, $prepared['party_type'], $partyId, $prepared['currency']);
                    $this->outbox->record('CreditHeld', 'CreditTranche', $allocation->id, ['allocationId' => $allocation->id, 'partyType' => $prepared['party_type'], 'partyId' => $partyId, 'money' => $this->money($prepared['unapplied'], $prepared['currency']), 'creditSources' => $createdTranches], $entityId, 2, ['causation_id' => $causationId]);
                }
                $event = $type === 'receipt' ? 'ReceiptAllocated' : 'PaymentAllocated';
                $this->outbox->record($event, 'Allocation', $allocation->id, ['allocationId' => $allocation->id, 'links' => $allocation->links->map(fn ($l): array => ['documentId' => $l->document_id, 'applied' => $this->money($l->applied_amount, $allocation->currency)])->all(), 'bankAccountId' => $allocation->bank_account_id, 'rateRef' => $allocation->exchange_rate_reference, 'withholding' => $this->money($allocation->withholding_amount, $allocation->currency)], $entityId, metadata: ['causation_id' => $causationId]);
                foreach ($allocation->withholdingLines as $line) {
                    $this->outbox->record('WithholdingCaptured', 'Allocation', $allocation->id, ['allocationId' => $allocation->id, 'type' => $line->withholding_code, 'money' => $this->money($line->amount, $allocation->currency), 'accountId' => $line->account_id], $entityId, metadata: ['causation_id' => $causationId]);
                }
                foreach ($fxResults as $result) {
                    $this->recordFxEvent($result, $allocation->id, $entityId, $causationId);
                }
                $this->audit->record('settlement', $type.'_posted', 'allocation', $allocation->id, $executorId, $entityId, after: $this->presentAllocation($allocation), metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
                $this->commands->store($makerId, $entityId, $this->operation($type, $partyId), $key, $hash, 201, $body);

                return new DocumentActionResult($body, 201);
            });
        } catch (Throwable $throwable) {
            $this->numbers->void($draw);
            if ($throwable instanceof SettlementAbort) {
                throw $throwable;
            }
            throw $throwable;
        }
    }

    /** @param array<string,mixed> $data */
    private function executeCreditApplication(string $entityId, string $partyId, array $data, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        return DB::transaction(function () use ($entityId, $partyId, $data, $key, $hash, $makerId, $executorId, $causationId): DocumentActionResult {
            $partyType = (string) $data['party_type'];
            $currency = (string) $data['currency'];
            $functional = $this->entities->functionalCurrency($entityId);
            if ($functional === null) {
                throw new SettlementAbort($this->rule('credit_fx_calculation_failed', 'Functional currency is unavailable.'));
            }
            $sources = $this->loadSources($entityId, $partyType, $partyId, $currency, $data['credit_sources']);
            if ($sources instanceof DocumentActionResult) {
                throw new SettlementAbort($sources);
            }
            $sourceTotal = $this->sumMoney($data['credit_sources']);
            $allocationTotal = $this->sumApplied($data['allocations']);
            if ($sourceTotal !== $allocationTotal) {
                throw new SettlementAbort($this->rule('amount_equation_mismatch', 'Selected credit and document allocations must reconcile.'));
            }
            $groupedBySource = [];
            $groupedByDocument = [];
            foreach ($data['allocations'] as $line) {
                $trancheId = (string) $line['credit_tranche_id'];
                if (! isset($sources[$trancheId])) {
                    throw new SettlementAbort($this->rule('credit_tranche_not_found', 'A selected allocation source was not supplied.', 404));
                }
                $documentId = (string) ($line[$partyType === 'customer' ? 'invoice_id' : 'bill_id'] ?? '');
                $groupedBySource[$trancheId][] = $line;
                $documentKey = $documentId.'|'.(int) $line['expected_version'];
                $groupedByDocument[$documentKey] ??= ['document_id' => $documentId, 'expected_version' => (int) $line['expected_version'], 'amount' => '0.0000'];
                $groupedByDocument[$documentKey]['amount'] = ExactDecimal::add($groupedByDocument[$documentKey]['amount'], (string) $line['applied_amount']['amount']);
            }
            foreach ($sources as $trancheId => $selected) {
                if ($this->sumApplied($groupedBySource[$trancheId] ?? []) !== $selected['selected_amount']) {
                    throw new SettlementAbort($this->rule('amount_equation_mismatch', 'Allocation pairings must equal every selected tranche amount.'));
                }
            }
            ksort($groupedByDocument, SORT_STRING);
            $documents = [];
            foreach ($groupedByDocument as $group) {
                $service = $this->documentService($partyType);
                $before = $partyType === 'customer' ? $service->getOpenReceivable($entityId, $group['document_id']) : $service->getOpenPayable($entityId, $group['document_id']);
                if ($before === null) {
                    throw new SettlementAbort($this->rule('not_found', 'The open document was not found.', 404));
                }
                if ($before['party_id'] !== $partyId) {
                    throw new SettlementAbort($this->rule('document_party_mismatch', 'The document belongs to another party.'));
                }
                if ($before['currency'] !== $currency) {
                    throw new SettlementAbort($this->rule('credit_tranche_currency_mismatch', 'Document and credit currencies differ.'));
                }
                $applied = $service->applySettlement($entityId, $group['document_id'], $group['amount'], $group['expected_version']);
                if (isset($applied['error'])) {
                    throw new SettlementAbort($this->documentError($applied));
                }
                $documents[$group['document_id']] = $applied;
                $this->outbox->record($partyType === 'customer' ? 'InvoiceStatusChanged' : 'BillStatusChanged', $partyType === 'customer' ? 'Invoice' : 'Bill', $group['document_id'], ['documentId' => $group['document_id'], 'status' => $applied['after']['status'], 'openBalance' => $this->money($applied['after']['open_balance'], $currency)], $entityId, metadata: ['causation_id' => $causationId]);
            }
            $allocation = Allocation::query()->create(['entity_id' => $entityId, 'allocation_number' => null, 'operation' => 'credit_application', 'party_type' => $partyType, 'party_id' => $partyId, 'settlement_date' => $data['application_date'], 'bank_account_id' => null, 'currency' => $currency, 'gross_amount' => $sourceTotal, 'bank_amount' => '0.0000', 'withholding_amount' => '0.0000', 'allocated_amount' => $allocationTotal, 'unapplied_amount' => '0.0000', 'functional_gross_amount' => '0.0000', 'rate_record_id' => null, 'exchange_rate_reference' => null, 'journal_entry_ids' => [], 'state' => 'building', 'version' => 1, 'created_by' => $makerId, 'posted_at' => Carbon::now('UTC')]);
            $postingLines = [];
            $consumed = [];
            $fxResults = [];
            $eventSources = [];
            $functionalGross = '0.0000';
            foreach ($sources as $trancheId => $selected) {
                /** @var CreditTranche $tranche */
                $tranche = $selected['tranche'];
                $sourceLines = $groupedBySource[$trancheId];
                $sourceFunctional = '0.0000';
                foreach ($sourceLines as $index => $line) {
                    $amount = ExactDecimal::normalize((string) $line['applied_amount']['amount']);
                    $documentId = (string) ($line[$partyType === 'customer' ? 'invoice_id' : 'bill_id']);
                    $document = $documents[$documentId];
                    $isFinalFullLine = $selected['selected_amount'] === $tranche->remaining_amount && $index === array_key_last($sourceLines);
                    $carrying = $isFinalFullLine ? ExactDecimal::subtract($tranche->remaining_functional_amount, $sourceFunctional) : $this->sourceFunctional($amount, $tranche, $functional);
                    if ($carrying === null) {
                        throw new SettlementAbort($this->rule('missing_credit_rate_reference', 'Credit source RateRecord is unavailable.'));
                    }
                    $comparisonReference = $document['before']['exchange_rate_reference'];
                    $fx = $this->creditFx($partyType, $amount, $tranche->source_exchange_rate_reference, $comparisonReference, $functional);
                    if ($fx === false) {
                        throw new SettlementAbort($this->rule('credit_fx_calculation_failed', 'Credit application FX calculation failed.'));
                    }
                    $comparison = is_array($fx) ? $fx['comparison_functional_amount']['amount'] : $amount;
                    if (is_array($fx)) {
                        $fx = [...$fx, 'source_functional_amount' => $this->money($carrying, $functional), 'credit_tranche_id' => $tranche->id, 'document_id' => $documentId];
                        $fxResults[] = $fx;
                        $this->appendFxPosting($postingLines, $entityId, $fx);
                    }
                    $consumption = CreditConsumption::query()->create(['entity_id' => $entityId, 'credit_tranche_id' => $tranche->id, 'allocation_id' => $allocation->id, 'operation' => 'application', 'amount' => $amount, 'functional_amount' => $carrying, 'source_rate_record_id' => $tranche->source_rate_record_id, 'comparison_rate_record_id' => $comparisonReference['rate_record_id'] ?? null, 'document_id' => $documentId, 'occurred_at' => Carbon::now('UTC')]);
                    $eventSources[] = $this->eventSource($tranche, $amount, $carrying, $comparisonReference['rate_record_id'] ?? null, $consumption->id, $documentId);
                    $allocation->links()->create(['entity_id' => $entityId, 'document_type' => $partyType === 'customer' ? 'invoice' : 'bill', 'document_id' => $documentId, 'document_number' => $document['before']['document_number'], 'document_party_id' => $document['before']['party_id'], 'credit_tranche_id' => $tranche->id, 'applied_amount' => $amount, 'expected_version' => (int) $line['expected_version'], 'open_balance_before' => $document['before']['open_balance'], 'open_balance_after' => $document['after']['open_balance'], 'version_before' => $document['before']['version'], 'version_after' => $document['after']['version'], 'status_before' => $document['before']['status'], 'status_after' => $document['after']['status'], 'document_rate_record_id' => $document['before']['rate_record_id'], 'realised_fx_result' => is_array($fx) ? $fx : null]);
                    $postingLines[] = $this->line($partyType === 'customer' ? (string) config('documents.invoice.receivable_account_id') : (string) config('documents.bill.payable_account_id'), 'Credit applied to document', $partyType === 'vendor' ? $comparison : '0.0000', $partyType === 'customer' ? $comparison : '0.0000', $currency, $amount, $comparisonReference);
                    $sourceFunctional = ExactDecimal::add($sourceFunctional, $carrying);
                }
                $updated = CreditTranche::query()->whereKey($tranche->id)->where('entity_id', $entityId)->where('version', $tranche->version)->where('remaining_amount', $tranche->remaining_amount)->update(['remaining_amount' => ExactDecimal::subtract($tranche->remaining_amount, $selected['selected_amount']), 'remaining_functional_amount' => ExactDecimal::subtract($tranche->remaining_functional_amount, $sourceFunctional), 'version' => $tranche->version + 1, 'updated_at' => now('UTC')]);
                if ($updated !== 1) {
                    throw new SettlementAbort($this->commands->error('concurrency_conflict', 'Credit tranche version is stale.', 409, ['rule' => 'credit_tranche_concurrency_conflict', 'required_version' => (int) CreditTranche::query()->whereKey($tranche->id)->value('version')]));
                }
                $tranche->refresh();
                $functionalGross = ExactDecimal::add($functionalGross, $sourceFunctional);
                $creditAccount = $this->creditAccount($partyType);
                if ($creditAccount === null) {
                    throw new SettlementAbort($this->rule('missing_posting_configuration', 'Party-credit account mapping is unavailable.'));
                }
                $postingLines[] = $this->line($creditAccount, 'Party credit consumed', $partyType === 'customer' ? $sourceFunctional : '0.0000', $partyType === 'vendor' ? $sourceFunctional : '0.0000', $currency, $selected['selected_amount'], $tranche->source_exchange_rate_reference);
                $consumed[] = ['credit_tranche_id' => $tranche->id, 'amount' => $this->money($selected['selected_amount'], $currency), 'functional_amount' => $this->money($sourceFunctional, $functional), 'remaining_amount' => $this->money($tranche->remaining_amount, $currency), 'remaining_functional_amount' => $this->money($tranche->remaining_functional_amount, $functional), 'version' => $tranche->version];
            }
            $allocation->functional_gross_amount = $functionalGross;
            $posted = $this->posting->post($entityId, $allocation->id, (string) $data['application_date'], $executorId, $causationId, $postingLines);
            if ($posted->errorCode !== null || $posted->journalId === null) {
                throw new SettlementAbort($this->postingError($posted->errorCode));
            }
            $allocation->journal_entry_ids = [$posted->journalId];
            $allocation->state = 'posted';
            $allocation->save();
            $this->rebuildProjection($entityId, $partyType, $partyId, $currency);
            $allocation->load('links');
            $body = ['allocation' => $this->presentAllocation($allocation), 'consumed_credit_sources' => $consumed, 'realised_fx_results' => $fxResults];
            $this->audit->record('settlement', 'credit_applied', 'allocation', $allocation->id, $executorId, $entityId, after: $this->presentAllocation($allocation), metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
            $this->outbox->record('CreditApplied', 'Allocation', $allocation->id, ['allocationId' => $allocation->id, 'partyType' => $partyType, 'partyId' => $partyId, 'money' => $this->money($sourceTotal, $currency), 'targetDocId' => count($documents) === 1 ? array_key_first($documents) : null, 'creditSources' => $eventSources], $entityId, 2, ['causation_id' => $causationId]);
            foreach ($fxResults as $result) {
                $this->recordFxEvent($result, $allocation->id, $entityId, $causationId);
            }
            $this->commands->store($makerId, $entityId, $this->operation('credit_application', $partyId), $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param array<string,mixed> $data */
    private function executeCreditRefund(string $entityId, string $partyId, array $data, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        $draw = $this->numbers->draw('refund', $entityId, (string) $data['refund_date']);
        if ($draw === null) {
            return $this->rule('missing_numbering_configuration', 'Refund numbering configuration is unavailable.');
        }
        try {
            return DB::transaction(function () use ($entityId, $partyId, $data, $key, $hash, $makerId, $executorId, $causationId, $draw): DocumentActionResult {
                $partyType = (string) $data['party_type'];
                $currency = (string) $data['refund_amount']['currency'];
                $refundAmount = ExactDecimal::normalize((string) $data['refund_amount']['amount']);
                $functional = $this->entities->functionalCurrency($entityId);
                if ($functional === null || ! $this->accounts->isActiveBank($entityId, (string) $data['bank_account_id'])) {
                    throw new SettlementAbort($this->rule('missing_posting_configuration', 'Functional currency or bank account configuration is unavailable.'));
                }
                $sources = $this->loadSources($entityId, $partyType, $partyId, $currency, $data['credit_sources']);
                if ($sources instanceof DocumentActionResult) {
                    throw new SettlementAbort($sources);
                }
                $projection = PartyCreditBalance::query()->where(['entity_id' => $entityId, 'party_type' => $partyType, 'party_id' => $partyId, 'currency' => $currency])->lockForUpdate()->first();
                $expectedBalance = ExactDecimal::normalize((string) $data['expected_available_balance']['amount']);
                if (! $projection instanceof PartyCreditBalance || $projection->available_balance !== $expectedBalance) {
                    throw new SettlementAbort($this->rule('credit_balance_mismatch', 'Expected party-credit balance does not match the projection.'));
                }
                if ($this->sumMoney($data['credit_sources']) !== $refundAmount) {
                    throw new SettlementAbort($this->rule('amount_equation_mismatch', 'Selected credit sources must equal the refund amount.'));
                }
                $rate = $this->resolveRate($entityId, $currency, $functional, (string) $data['refund_date'], $data['rate_record_id'] ?? null);
                if ($rate instanceof DocumentActionResult) {
                    throw new SettlementAbort($rate);
                }
                $allocation = Allocation::query()->create(['entity_id' => $entityId, 'allocation_number' => $draw['number'], 'operation' => 'credit_refund', 'party_type' => $partyType, 'party_id' => $partyId, 'settlement_date' => $data['refund_date'], 'bank_account_id' => $data['bank_account_id'], 'currency' => $currency, 'gross_amount' => $refundAmount, 'bank_amount' => $refundAmount, 'withholding_amount' => '0.0000', 'allocated_amount' => $refundAmount, 'unapplied_amount' => '0.0000', 'functional_gross_amount' => '0.0000', 'rate_record_id' => $rate['rate_record_id'] ?? null, 'exchange_rate_reference' => $rate, 'journal_entry_ids' => [], 'state' => 'building', 'version' => 1, 'created_by' => $makerId, 'posted_at' => Carbon::now('UTC')]);
                $postingLines = [];
                $consumed = [];
                $fxResults = [];
                $eventSources = [];
                $carryingTotal = '0.0000';
                $comparisonTotal = '0.0000';
                foreach ($sources as $selected) {
                    /** @var CreditTranche $tranche */
                    $tranche = $selected['tranche'];
                    $amount = $selected['selected_amount'];
                    $carrying = $amount === $tranche->remaining_amount ? $tranche->remaining_functional_amount : $this->sourceFunctional($amount, $tranche, $functional);
                    if ($carrying === null) {
                        throw new SettlementAbort($this->rule('missing_credit_rate_reference', 'Credit source RateRecord is unavailable.'));
                    }
                    $fx = $this->creditFx($partyType, $amount, $tranche->source_exchange_rate_reference, $rate, $functional);
                    if ($fx === false) {
                        throw new SettlementAbort($this->rule('credit_fx_calculation_failed', 'Credit refund FX calculation failed.'));
                    }
                    $comparison = is_array($fx) ? $fx['comparison_functional_amount']['amount'] : $amount;
                    if (is_array($fx)) {
                        $fx = [...$fx, 'source_functional_amount' => $this->money($carrying, $functional), 'credit_tranche_id' => $tranche->id];
                        $fxResults[] = $fx;
                        $this->appendFxPosting($postingLines, $entityId, $fx);
                    }
                    $consumption = CreditConsumption::query()->create(['entity_id' => $entityId, 'credit_tranche_id' => $tranche->id, 'allocation_id' => $allocation->id, 'operation' => 'refund', 'amount' => $amount, 'functional_amount' => $carrying, 'source_rate_record_id' => $tranche->source_rate_record_id, 'comparison_rate_record_id' => $rate['rate_record_id'] ?? null, 'document_id' => null, 'occurred_at' => Carbon::now('UTC')]);
                    $eventSources[] = $this->eventSource($tranche, $amount, $carrying, $rate['rate_record_id'] ?? null, $consumption->id);
                    if (CreditTranche::query()->whereKey($tranche->id)->where('entity_id', $entityId)->where('version', $tranche->version)->where('remaining_amount', $tranche->remaining_amount)->update(['remaining_amount' => ExactDecimal::subtract($tranche->remaining_amount, $amount), 'remaining_functional_amount' => ExactDecimal::subtract($tranche->remaining_functional_amount, $carrying), 'version' => $tranche->version + 1, 'updated_at' => now('UTC')]) !== 1) {
                        throw new SettlementAbort($this->commands->error('concurrency_conflict', 'Credit tranche version is stale.', 409, ['rule' => 'credit_tranche_concurrency_conflict', 'required_version' => (int) CreditTranche::query()->whereKey($tranche->id)->value('version')]));
                    }
                    $tranche->refresh();
                    $consumed[] = ['credit_tranche_id' => $tranche->id, 'amount' => $this->money($amount, $currency), 'functional_amount' => $this->money($carrying, $functional), 'remaining_amount' => $this->money($tranche->remaining_amount, $currency), 'remaining_functional_amount' => $this->money($tranche->remaining_functional_amount, $functional), 'version' => $tranche->version];
                    $carryingTotal = ExactDecimal::add($carryingTotal, $carrying);
                    $comparisonTotal = ExactDecimal::add($comparisonTotal, $comparison);
                }
                $creditAccount = $this->creditAccount($partyType);
                if ($creditAccount === null) {
                    throw new SettlementAbort($this->rule('missing_posting_configuration', 'Party-credit account mapping is unavailable.'));
                }
                $postingLines[] = $this->line($creditAccount, 'Party credit refunded', $partyType === 'customer' ? $carryingTotal : '0.0000', $partyType === 'vendor' ? $carryingTotal : '0.0000', $functional, null, null);
                $postingLines[] = $this->line((string) $data['bank_account_id'], 'Credit refund bank movement', $partyType === 'vendor' ? $comparisonTotal : '0.0000', $partyType === 'customer' ? $comparisonTotal : '0.0000', $currency, $refundAmount, $rate);
                $posted = $this->posting->post($entityId, $allocation->id, (string) $data['refund_date'], $executorId, $causationId, $postingLines);
                if ($posted->errorCode !== null || $posted->journalId === null) {
                    throw new SettlementAbort($this->postingError($posted->errorCode));
                }
                $allocation->functional_gross_amount = $comparisonTotal;
                $allocation->journal_entry_ids = [$posted->journalId];
                $allocation->state = 'posted';
                $allocation->save();
                if ($rate) {
                    $this->rates->markReferenced($entityId, (string) $rate['rate_record_id']);
                }
                $this->rebuildProjection($entityId, $partyType, $partyId, $currency);
                $body = ['allocation' => $this->presentAllocation($allocation), 'consumed_credit_sources' => $consumed, 'realised_fx_results' => $fxResults];
                $this->audit->record('settlement', 'credit_refunded', 'allocation', $allocation->id, $executorId, $entityId, after: $this->presentAllocation($allocation), metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
                $this->outbox->record('CreditRefunded', 'Allocation', $allocation->id, ['allocationId' => $allocation->id, 'partyType' => $partyType, 'partyId' => $partyId, 'money' => $this->money($refundAmount, $currency), 'targetDocId' => null, 'creditSources' => $eventSources], $entityId, 2, ['causation_id' => $causationId]);
                foreach ($fxResults as $result) {
                    $this->recordFxEvent($result, $allocation->id, $entityId, $causationId);
                }
                $this->commands->store($makerId, $entityId, $this->operation('credit_refund', $partyId), $key, $hash, 201, $body);

                return new DocumentActionResult($body, 201);
            });
        } catch (Throwable $throwable) {
            $this->numbers->void($draw);
            if ($throwable instanceof SettlementAbort) {
                throw $throwable;
            }
            throw $throwable;
        }
    }

    private function executeReversal(string $entityId, string $allocationId, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        return DB::transaction(function () use ($entityId, $allocationId, $expected, $key, $hash, $makerId, $executorId, $causationId): DocumentActionResult {
            $original = Allocation::query()->with(['links', 'withholdingLines'])->where('entity_id', $entityId)->whereKey($allocationId)->lockForUpdate()->first();
            if (! $original) {
                throw new SettlementAbort($this->rule('not_found', 'The allocation was not found.', 404));
            }
            if ($original->state !== 'posted' || $original->operation === 'reversal' || $original->reversed_by_id !== null) {
                throw new SettlementAbort($this->rule('allocation_already_reversed', 'The allocation cannot be reversed.'));
            }
            if ($original->version !== $expected) {
                throw new SettlementAbort($this->commands->error('concurrency_conflict', 'Allocation version is stale.', 409, ['required_version' => $original->version]));
            }
            $date = Carbon::today('UTC')->toDateString();
            $reversal = Allocation::query()->create(['entity_id' => $entityId, 'allocation_number' => null, 'operation' => 'reversal', 'party_type' => $original->party_type, 'party_id' => $original->party_id, 'settlement_date' => $date, 'bank_account_id' => $original->bank_account_id, 'currency' => $original->currency, 'gross_amount' => $original->gross_amount, 'bank_amount' => $original->bank_amount, 'withholding_amount' => $original->withholding_amount, 'allocated_amount' => $original->allocated_amount, 'unapplied_amount' => $original->unapplied_amount, 'functional_gross_amount' => $original->functional_gross_amount, 'rate_record_id' => $original->rate_record_id, 'exchange_rate_reference' => $original->exchange_rate_reference, 'journal_entry_ids' => [], 'state' => 'building', 'reversal_of_id' => $original->id, 'version' => 1, 'created_by' => $makerId, 'posted_at' => Carbon::now('UTC')]);
            $consumptions = CreditConsumption::query()->where('entity_id', $entityId)->where('allocation_id', $original->id)->whereIn('operation', ['application', 'refund'])->orderBy('credit_tranche_id')->orderBy('created_at')->get();
            $createdSources = CreditTranche::query()->where('entity_id', $entityId)->where('source_allocation_id', $original->id)->orderBy('id')->get();
            $trancheIds = $consumptions->pluck('credit_tranche_id')->merge($createdSources->pluck('id'))->unique()->sort()->values();
            $lockedTranches = CreditTranche::query()->where('entity_id', $entityId)->whereIn('id', $trancheIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $documentGroups = [];
            foreach ($original->links as $link) {
                $documentGroups[$link->document_id] ??= ['amount' => '0.0000', 'document_type' => $link->document_type];
                $documentGroups[$link->document_id]['amount'] = ExactDecimal::add($documentGroups[$link->document_id]['amount'], $link->applied_amount);
            }
            foreach ($documentGroups as $documentId => $group) {
                $service = $this->documentService($original->party_type);
                $current = $original->party_type === 'customer' ? $service->getOpenReceivable($entityId, $documentId) : $service->getOpenPayable($entityId, $documentId);
                if ($current === null) {
                    throw new SettlementAbort($this->rule('reversal_not_allowed', 'A settled document no longer exists.'));
                }
                $restored = $service->reverseSettlement($entityId, $documentId, $group['amount'], (int) $current['version']);
                if (isset($restored['error'])) {
                    throw new SettlementAbort($this->documentError($restored));
                }
                $this->outbox->record($original->party_type === 'customer' ? 'InvoiceStatusChanged' : 'BillStatusChanged', $group['document_type'] === 'invoice' ? 'Invoice' : 'Bill', $documentId, ['documentId' => $documentId, 'status' => $restored['after']['status'], 'openBalance' => $this->money($restored['after']['open_balance'], $original->currency)], $entityId, metadata: ['causation_id' => $causationId]);
            }
            $restoredSources = [];
            $restoredEventSources = [];
            foreach ($consumptions as $consumption) {
                $tranche = $lockedTranches->get($consumption->credit_tranche_id);
                if (! $tranche || ExactDecimal::compare(ExactDecimal::add($tranche->remaining_amount, $consumption->amount), $tranche->original_amount) > 0) {
                    throw new SettlementAbort($this->rule('credit_balance_conflict', 'The exact credit source cannot be restored.'));
                }
                $restoration = CreditConsumption::query()->create(['entity_id' => $entityId, 'credit_tranche_id' => $tranche->id, 'allocation_id' => $reversal->id, 'operation' => 'restoration', 'amount' => $consumption->amount, 'functional_amount' => $consumption->functional_amount, 'source_rate_record_id' => $consumption->source_rate_record_id, 'comparison_rate_record_id' => $consumption->comparison_rate_record_id, 'document_id' => $consumption->document_id, 'reverses_consumption_id' => $consumption->id, 'occurred_at' => Carbon::now('UTC')]);
                if (CreditTranche::query()->whereKey($tranche->id)->where('version', $tranche->version)->update(['remaining_amount' => ExactDecimal::add($tranche->remaining_amount, $consumption->amount), 'remaining_functional_amount' => ExactDecimal::add($tranche->remaining_functional_amount, $consumption->functional_amount), 'version' => $tranche->version + 1, 'updated_at' => now('UTC')]) !== 1) {
                    throw new SettlementAbort($this->commands->error('concurrency_conflict', 'Credit tranche version is stale.', 409, ['rule' => 'credit_tranche_concurrency_conflict']));
                }
                $tranche->refresh();
                $restoredSources[] = ['credit_tranche_id' => $tranche->id, 'restored_amount' => $this->money($consumption->amount, $tranche->currency), 'restored_functional_amount' => $this->money($consumption->functional_amount, $this->entities->functionalCurrency($entityId) ?? $tranche->currency), 'source_rate_record_id' => $consumption->source_rate_record_id, 'comparison_rate_record_id' => $consumption->comparison_rate_record_id, 'original_consumption_id' => $consumption->id, 'new_version' => $tranche->version];
                $restoredEventSources[] = ['creditTrancheId' => $tranche->id, 'transactionMoney' => $this->money($consumption->amount, $tranche->currency), 'functionalMoney' => $this->money($consumption->functional_amount, $this->entities->functionalCurrency($entityId) ?? $tranche->currency), 'sourceRateRecordId' => $consumption->source_rate_record_id, 'comparisonRateRecordId' => $consumption->comparison_rate_record_id, 'originalConsumptionId' => $consumption->id, 'restorationConsumptionId' => $restoration->id];
            }
            foreach ($createdSources as $tranche) {
                $tranche = $lockedTranches->get($tranche->id);
                if (! $tranche instanceof CreditTranche) {
                    throw new SettlementAbort($this->rule('credit_balance_conflict', 'The exact credit source cannot be restored.'));
                }
                if ($tranche->remaining_amount !== $tranche->original_amount || $tranche->remaining_functional_amount !== $tranche->original_functional_amount) {
                    throw new SettlementAbort($this->rule('credit_balance_conflict', 'Held credit was subsequently consumed and cannot be reversed.'));
                }
                CreditTranche::query()->whereKey($tranche->id)->where('version', $tranche->version)->update(['remaining_amount' => '0.0000', 'remaining_functional_amount' => '0.0000', 'version' => $tranche->version + 1, 'updated_at' => now('UTC')]);
            }
            if ($consumptions->isNotEmpty() || $createdSources->isNotEmpty()) {
                $this->rebuildProjection($entityId, $original->party_type, $original->party_id, $original->currency);
            }
            $posted = $this->posting->reverse($entityId, $reversal->id, $date, $executorId, $causationId, $original->journal_entry_ids);
            if ($posted->errorCode !== null || $posted->journalId === null) {
                throw new SettlementAbort($this->postingError($posted->errorCode));
            }
            $reversal->journal_entry_ids = explode(',', $posted->journalId);
            $reversal->state = 'posted';
            $reversal->save();
            if (Allocation::query()->whereKey($original->id)->where('entity_id', $entityId)->where('state', 'posted')->where('version', $expected)->whereNull('reversed_by_id')->update(['state' => 'reversed', 'reversed_by_id' => $reversal->id, 'version' => $expected + 1, 'updated_at' => now('UTC')]) !== 1) {
                throw new SettlementAbort($this->commands->error('concurrency_conflict', 'Allocation version is stale.', 409));
            }
            $original->refresh();
            $body = ['original_allocation' => $this->presentAllocation($original), 'reversal_allocation' => $this->presentAllocation($reversal), 'restored_credit_sources' => $restoredSources, 'reversal_linkage' => ['original_allocation_id' => $original->id, 'reversal_allocation_id' => $reversal->id, 'reversed_at' => $reversal->posted_at->toIso8601ZuluString()]];
            $this->audit->record('settlement', 'allocation_reversed', 'allocation', $original->id, $executorId, $entityId, before: ['state' => 'posted', 'version' => $expected], after: ['state' => 'reversed', 'version' => $original->version, 'reversed_by_id' => $reversal->id], metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
            $this->outbox->record('AllocationReversed', 'Allocation', $original->id, ['allocationId' => $original->id, 'reversalAllocationId' => $reversal->id, 'restoredCreditSources' => $restoredEventSources], $entityId, 2, ['causation_id' => $causationId]);
            $this->commands->store($makerId, $entityId, $this->operation('reversal', $original->id), $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param array<string,mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'settlement.allocations.read')) {
            return $denied;
        }
        $limit = (int) ($filters['limit'] ?? 50);
        $bindingFilters = $filters;
        unset($bindingFilters['cursor']);
        $binding = ['entity_id' => $entityId, 'filters' => $bindingFilters, 'order' => 'settlement_date_desc,posted_at_desc,id_desc'];
        try {
            [$cursor, $boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $exception) {
            return $this->commands->error('validation', $exception->getMessage(), 400);
        }
        $query = Allocation::query()->where('entity_id', $entityId)->where('created_at', '<=', $boundary)
            ->when($filters['operation'] ?? null, fn ($query, $value) => $query->where('operation', $value))
            ->when($filters['state'] ?? null, fn ($query, $value) => $query->where('state', $value))
            ->when($filters['party_type'] ?? null, fn ($query, $value) => $query->where('party_type', $value))
            ->when($filters['party'] ?? null, fn ($query, $value) => $query->where('party_id', $value))
            ->when($filters['bank_account_id'] ?? null, fn ($query, $value) => $query->where('bank_account_id', $value))
            ->when($filters['document'] ?? null, fn ($query, $value) => $query->whereHas('links', fn ($links) => $links->where('document_id', $value)))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate('settlement_date', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate('settlement_date', '<=', $value));
        $page = $query->orderByDesc('settlement_date')->orderByDesc('posted_at')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return new DocumentActionResult(['allocations' => $page->getCollection()->map(fn (Allocation $allocation): array => $this->summary($allocation))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    /** @param array<string,mixed> $filters */
    public function credits(User $actor, string $entityId, string $partyId, array $filters): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'settlement.credits.read')) {
            return $denied;
        }
        $partyType = (string) $filters['party_type'];
        if ($this->party($entityId, $partyType, $partyId) === null) {
            return $this->rule('not_found', 'The party was not found.', 404);
        }
        $limit = (int) ($filters['limit'] ?? 50);
        $binding = ['entity_id' => $entityId, 'party_id' => $partyId, 'party_type' => $partyType, 'currency' => $filters['currency'] ?? null, 'order' => 'created_at_desc,id_desc'];
        try {
            [$cursor, $boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $exception) {
            return $this->commands->error('validation', $exception->getMessage(), 400);
        }
        $query = CreditTranche::query()->where(['entity_id' => $entityId, 'party_type' => $partyType, 'party_id' => $partyId])->where('created_at', '<=', $boundary)->when($filters['currency'] ?? null, fn ($query, $value) => $query->where('currency', $value));
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);
        $balances = PartyCreditBalance::query()->where(['entity_id' => $entityId, 'party_type' => $partyType, 'party_id' => $partyId])->when($filters['currency'] ?? null, fn ($query, $value) => $query->where('currency', $value))->orderBy('currency')->get();

        return new DocumentActionResult(['party_credit' => ['party_type' => $partyType, 'party_id' => $partyId, 'balances' => $balances->map(fn (PartyCreditBalance $balance): array => ['available_balance' => $this->money($balance->available_balance, $balance->currency), 'functional_carrying_balance' => $this->money($balance->functional_carrying_balance, $this->entities->functionalCurrency($entityId) ?? $balance->currency)])->all(), 'projection_version' => (int) ($balances->max('version') ?? 0)], 'credit_tranches' => $page->getCollection()->map(fn (CreditTranche $tranche): array => $this->presentTranche($tranche))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    /** @param array<string,mixed> $data */
    private function preflight(string $type, string $entityId, string $resourceId, array $data, ?int $expected): ?DocumentActionResult
    {
        if ($type === 'reversal') {
            $allocation = Allocation::query()->where('entity_id', $entityId)->find($resourceId);
            if (! $allocation) {
                return $this->rule('not_found', 'The allocation was not found.', 404);
            }
            if ($allocation->state !== 'posted' || $allocation->operation === 'reversal' || $allocation->reversed_by_id !== null) {
                return $this->rule('allocation_already_reversed', 'The allocation cannot be reversed.');
            }
            if ($allocation->version !== $expected) {
                return $this->commands->error('concurrency_conflict', 'Allocation version is stale.', 409, ['required_version' => $allocation->version]);
            }

            return null;
        }
        $partyType = $type === 'receipt' ? 'customer' : ($type === 'payment' ? 'vendor' : ($data['party_type'] ?? null));
        if (! is_string($partyType) || ! in_array($partyType, ['customer', 'vendor'], true)) {
            return $this->commands->error('validation', 'party_type is invalid.', 400);
        }
        $party = $this->party($entityId, $partyType, $resourceId);
        if ($party === null) {
            return $this->rule('not_found', 'The party was not found.', 404);
        }
        if ($party['status'] !== 'active') {
            return $this->rule($partyType === 'customer' ? 'customer_inactive' : 'vendor_inactive', 'The Settlement party must be active.');
        }

        return null;
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>|DocumentActionResult
     */
    private function prepareCash(string $type, string $entityId, string $partyId, array $data): array|DocumentActionResult
    {
        $partyType = $type === 'receipt' ? 'customer' : 'vendor';
        $party = $this->party($entityId, $partyType, $partyId);
        if ($party === null || $party['status'] !== 'active') {
            return $this->rule($partyType === 'customer' ? 'customer_inactive' : 'vendor_inactive', 'The Settlement party must be active.');
        }
        if (! $this->accounts->isActiveBank($entityId, (string) $data['bank_account_id'])) {
            return $this->rule('missing_posting_configuration', 'The bank account is invalid or inactive.');
        }
        try {
            $gross = ExactDecimal::normalize((string) $data['gross_amount']['amount']);
            $bank = ExactDecimal::normalize((string) $data['bank_amount']['amount']);
            $withholding = ExactDecimal::normalize((string) $data['withholding_amount']['amount']);
            $unapplied = ExactDecimal::normalize((string) $data['unapplied_amount']['amount']);
            $allocated = $this->sumApplied($data['allocations']);
        } catch (InvalidArgumentException) {
            return $this->commands->error('validation', 'Money values must be exact decimal strings.', 400);
        }
        $currency = (string) $data['gross_amount']['currency'];
        foreach (['bank_amount', 'withholding_amount', 'unapplied_amount'] as $field) {
            if (($data[$field]['currency'] ?? null) !== $currency) {
                return $this->rule('amount_equation_mismatch', 'Settlement Money currencies must agree.');
            }
        }
        if (str_starts_with($gross.$bank.$withholding.$unapplied, '-') || ExactDecimal::add($bank, $withholding) !== $gross || ExactDecimal::add($allocated, $unapplied) !== $gross || ($data['allocations'] === [] && $unapplied === '0.0000')) {
            return $this->rule('amount_equation_mismatch', 'Settlement amount equations do not reconcile.');
        }
        $withholdingTotal = '0.0000';
        $withholdingLines = [];
        foreach ($data['withholding_lines'] as $line) {
            if (($line['amount']['currency'] ?? null) !== $currency) {
                return $this->rule('withholding_total_mismatch', 'Withholding currencies must agree.');
            }
            $amount = ExactDecimal::normalize((string) $line['amount']['amount']);
            $configuration = $this->withholding->resolve($entityId, $partyType, (string) $line['withholding_code'], (string) $data['settlement_date']);
            if ($configuration === null) {
                return $this->rule('missing_withholding_configuration', 'Withholding configuration is unavailable.');
            }
            $withholdingTotal = ExactDecimal::add($withholdingTotal, $amount);
            $withholdingLines[] = ['withholding_code' => $line['withholding_code'], 'amount' => $amount, ...$configuration];
        }
        if ($withholdingTotal !== $withholding) {
            return $this->rule('withholding_total_mismatch', 'Withholding lines do not equal withholding_amount.');
        }
        $functional = $this->entities->functionalCurrency($entityId);
        if ($functional === null) {
            return $this->rule('missing_rate_reference', 'Functional currency is unavailable.');
        }
        $rate = $this->resolveRate($entityId, $currency, $functional, (string) $data['settlement_date'], $data['rate_record_id'] ?? null);
        if ($rate instanceof DocumentActionResult) {
            return $rate;
        }
        $documents = [];
        foreach ($data['allocations'] as $line) {
            $documentId = (string) ($line[$partyType === 'customer' ? 'invoice_id' : 'bill_id'] ?? '');
            $service = $this->documentService($partyType);
            $before = $partyType === 'customer' ? $service->getOpenReceivable($entityId, $documentId) : $service->getOpenPayable($entityId, $documentId);
            if ($before === null) {
                return $this->rule('not_found', 'An open document was not found.', 404);
            }
            if ($before['party_id'] !== $partyId) {
                return $this->rule('document_party_mismatch', 'A document belongs to another party.');
            }
            if ($before['currency'] !== $currency) {
                return $this->rule('amount_equation_mismatch', 'Document and settlement currencies differ.');
            }
            if ($before['version'] !== (int) $line['expected_version']) {
                return $this->commands->error('concurrency_conflict', 'Document version is stale.', 409, ['required_version' => $before['version']]);
            }
            $documents[] = ['document_id' => $documentId, 'document_type' => $partyType === 'customer' ? 'invoice' : 'bill', 'amount' => ExactDecimal::normalize((string) $line['applied_amount']['amount']), 'expected_version' => (int) $line['expected_version'], 'before' => $before];
        }
        $functionalGross = $this->functional($gross, $rate);
        if ($functionalGross === null) {
            return $this->rule('credit_fx_calculation_failed', 'Settlement FX configuration is unavailable.');
        }

        return compact('partyType', 'currency', 'gross', 'bank', 'withholding', 'allocated', 'unapplied', 'withholdingLines', 'functional', 'rate', 'documents') + ['party_type' => $partyType, 'withholding_lines' => $withholdingLines, 'functional_currency' => $functional, 'functional_gross' => $functionalGross];
    }

    /** @param list<array<string,mixed>> $requested
     * @return array<string,array{tranche:CreditTranche,selected_amount:string}>|DocumentActionResult
     */
    private function loadSources(string $entityId, string $partyType, string $partyId, string $currency, array $requested): array|DocumentActionResult
    {
        usort($requested, fn (array $left, array $right): int => strcmp((string) ($left['credit_tranche_id'] ?? ''), (string) ($right['credit_tranche_id'] ?? '')));
        $selected = [];
        foreach ($requested as $source) {
            $id = (string) $source['credit_tranche_id'];
            if (isset($selected[$id])) {
                return $this->commands->error('validation', 'Duplicate credit_tranche_id values are not allowed.', 400);
            }
            $tranche = CreditTranche::query()->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
            if (! $tranche) {
                return $this->rule('credit_tranche_not_found', 'The credit tranche was not found.', 404);
            }
            if ($tranche->party_type !== $partyType || $tranche->party_id !== $partyId) {
                return $this->rule('credit_tranche_party_mismatch', 'The credit tranche belongs to another party.');
            }
            if ($tranche->currency !== $currency || ($source['amount']['currency'] ?? null) !== $currency) {
                return $this->rule('credit_tranche_currency_mismatch', 'The credit tranche currency differs.');
            }
            if (! array_key_exists('expected_version', $source)) {
                return $this->commands->error('precondition_required', 'Every credit source requires expected_version.', 428);
            }
            if ($tranche->version !== (int) $source['expected_version']) {
                return $this->commands->error('concurrency_conflict', 'Credit tranche version is stale.', 409, ['rule' => 'credit_tranche_concurrency_conflict', 'required_version' => $tranche->version]);
            }
            $amount = ExactDecimal::normalize((string) $source['amount']['amount']);
            if (! ExactDecimal::positive($amount) || ExactDecimal::compare($amount, $tranche->remaining_amount) > 0) {
                return $this->rule('insufficient_credit_tranche_balance', 'Selected credit exceeds the tranche remainder.');
            }
            $selected[$id] = ['tranche' => $tranche, 'selected_amount' => $amount];
        }

        return $selected;
    }

    /** @return array<string,mixed>|DocumentActionResult|null */
    private function resolveRate(string $entityId, string $currency, string $functional, string $date, mixed $rateId): array|DocumentActionResult|null
    {
        if ($currency === $functional) {
            return $rateId === null ? null : $this->rule('invalid_rate_reference', 'Functional-currency settlement rejects rate_record_id.');
        }
        if (! is_string($rateId) || ! Str::isUuid($rateId)) {
            return $this->rule('missing_rate_reference', 'Foreign settlement requires an exact RateRecord.');
        }
        $rate = $this->rates->exactById($entityId, $rateId, $currency, $functional, $date);

        return $rate ?? $this->rule('invalid_rate_reference', 'The RateRecord is not exact or applicable.');
    }

    /** @param array<string,mixed>|null $reference */
    private function functional(string $amount, ?array $reference): ?string
    {
        if ($reference === null) {
            return ExactDecimal::normalize($amount);
        }
        $scale = config('valuation.fx.rounding_scale');
        $mode = config('valuation.fx.rounding_mode');
        if (! is_numeric($scale) || ! is_string($mode)) {
            return null;
        }

        return $this->fx->calculate($amount, (string) $reference['rate'], (string) $reference['rate'], (int) $scale, $mode)['document_functional'];
    }

    private function sourceFunctional(string $amount, CreditTranche $tranche, string $functional): ?string
    {
        if ($tranche->currency === $functional) {
            return ExactDecimal::normalize($amount);
        }

        return $this->functional($amount, $tranche->source_exchange_rate_reference);
    }

    /** @param array<string,mixed>|null $documentReference
     * @param  array<string,mixed>|null  $settlementReference
     * @return array<string,mixed>|null|false
     */
    private function cashFx(string $partyType, string $amount, ?array $documentReference, ?array $settlementReference, string $functional): array|null|false
    {
        if ($documentReference === null && $settlementReference === null) {
            return null;
        }
        if ($documentReference === null || $settlementReference === null) {
            return false;
        }
        $scale = config('valuation.fx.rounding_scale');
        $mode = config('valuation.fx.rounding_mode');
        if (! is_numeric($scale) || ! is_string($mode)) {
            return false;
        }
        $calculated = $this->fx->calculateSettlement($amount, (string) $documentReference['rate'], (string) $settlementReference['rate'], $partyType, (int) $scale, $mode);

        return ['document_rate_record_id' => $documentReference['rate_record_id'], 'settlement_rate_record_id' => $settlementReference['rate_record_id'], 'document_functional_amount' => $this->money($calculated['document_functional'], $functional), 'settlement_functional_amount' => $this->money($calculated['settlement_functional'], $functional), 'realised_fx' => $this->money($calculated['realised_fx'], $functional), 'classification' => $calculated['classification']];
    }

    /** @param array<string,mixed>|null $sourceReference
     * @param  array<string,mixed>|null  $comparisonReference
     * @return array<string,mixed>|null|false
     */
    private function creditFx(string $partyType, string $amount, ?array $sourceReference, ?array $comparisonReference, string $functional): array|null|false
    {
        if ($sourceReference === null && $comparisonReference === null) {
            return null;
        }
        if ($sourceReference === null || $comparisonReference === null) {
            return false;
        }
        $scale = config('valuation.fx.rounding_scale');
        $mode = config('valuation.fx.rounding_mode');
        if (! is_numeric($scale) || ! is_string($mode)) {
            return false;
        }
        $calculated = $this->fx->calculateCredit($amount, (string) $sourceReference['rate'], (string) $comparisonReference['rate'], $partyType, (int) $scale, $mode);

        return ['source_functional_amount' => $this->money($calculated['source_functional'], $functional), 'comparison_functional_amount' => $this->money($calculated['comparison_functional'], $functional), 'realised_fx' => $this->money($calculated['realised_fx'], $functional), 'classification' => $calculated['classification'], 'source_rate_record_id' => $sourceReference['rate_record_id'], 'comparison_rate_record_id' => $comparisonReference['rate_record_id']];
    }

    /** @param list<array<string,mixed>> $postingLines
     * @param  array<string,mixed>  $result
     */
    private function appendFxPosting(array &$postingLines, string $entityId, array $result): void
    {
        $amount = (string) ($result['realised_fx']['amount'] ?? '0.0000');
        if ($amount === '0.0000') {
            return;
        }
        $gain = $amount[0] !== '-';
        $absolute = $gain ? $amount : ExactDecimal::subtract('0.0000', $amount);
        $account = config('settlement.accounts.'.($gain ? 'realised_fx_gain' : 'realised_fx_loss'));
        if (! is_string($account) || ! Str::isUuid($account) || ! $this->accounts->isOwnedByEntity($entityId, $account)) {
            throw new SettlementAbort($this->rule('missing_posting_configuration', 'Realised FX account mapping is unavailable.'));
        }
        $postingLines[] = $this->line($account, $gain ? 'Realised FX gain' : 'Realised FX loss', $gain ? '0.0000' : $absolute, $gain ? $absolute : '0.0000', (string) $result['realised_fx']['currency'], null, null);
    }

    /** @param array<string,mixed>|null $reference
     * @return array<string,mixed>
     */
    private function line(string $accountId, string $description, string $debit, string $credit, string $transactionCurrency, ?string $foreignAmount, ?array $reference): array
    {
        return ['account_id' => $accountId, 'description' => $description, 'debit' => $debit, 'credit' => $credit, 'currency' => $reference['quote_currency'] ?? $transactionCurrency, 'fx_amount' => $reference ? $foreignAmount : null, 'fx_currency' => $reference ? $transactionCurrency : null, 'rate_record_id' => $reference['rate_record_id'] ?? null, 'fx_rate' => $reference['rate'] ?? null, 'fx_rate_effective_date' => $reference['effective_date'] ?? null, 'sbu_tag' => null];
    }

    private function rebuildProjection(string $entityId, string $partyType, string $partyId, string $currency, ?int $expectedVersion = null): PartyCreditBalance
    {
        $projection = PartyCreditBalance::query()->where(['entity_id' => $entityId, 'party_type' => $partyType, 'party_id' => $partyId, 'currency' => $currency])->lockForUpdate()->first();
        $actualVersion = $projection instanceof PartyCreditBalance ? $projection->version : 0;
        if ($expectedVersion !== null && $actualVersion !== $expectedVersion) {
            throw new SettlementAbort($this->commands->error('concurrency_conflict', 'Party-credit projection version is stale.', 409, ['required_version' => $actualVersion]));
        }
        $available = '0.0000';
        $functional = '0.0000';
        foreach (CreditTranche::query()->where(['entity_id' => $entityId, 'party_type' => $partyType, 'party_id' => $partyId, 'currency' => $currency])->get() as $tranche) {
            $available = ExactDecimal::add($available, $tranche->remaining_amount);
            $functional = ExactDecimal::add($functional, $tranche->remaining_functional_amount);
        }
        if (! $projection instanceof PartyCreditBalance) {
            return PartyCreditBalance::query()->create(['entity_id' => $entityId, 'party_type' => $partyType, 'party_id' => $partyId, 'currency' => $currency, 'available_balance' => $available, 'functional_carrying_balance' => $functional, 'version' => 1]);
        }
        $updated = PartyCreditBalance::query()->whereKey($projection->id)->where('version', $actualVersion)->update(['available_balance' => $available, 'functional_carrying_balance' => $functional, 'version' => $actualVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            throw new SettlementAbort($this->commands->error('concurrency_conflict', 'Party-credit projection version is stale.', 409));
        }
        $projection->refresh();

        return $projection;
    }

    /** @return array<string,mixed> */
    private function legacyProjection(string $entityId, string $partyType, string $partyId, string $currency): array
    {
        $projection = PartyCreditBalance::query()->where(['entity_id' => $entityId, 'party_type' => $partyType, 'party_id' => $partyId, 'currency' => $currency])->firstOrFail();

        return ['available_balance' => $this->money($projection->available_balance, $currency), 'version' => $projection->version];
    }

    /** @return array<string,mixed> */
    private function party(string $entityId, string $partyType, string $partyId): ?array
    {
        return $partyType === 'customer' ? $this->receivables->getCustomer($entityId, $partyId) : $this->payables->getVendor($entityId, $partyId);
    }

    private function documentService(string $partyType): OpenReceivableService|OpenPayableService
    {
        return $partyType === 'customer' ? $this->receivables : $this->payables;
    }

    private function creditAccount(string $partyType): ?string
    {
        $account = config('settlement.accounts.'.($partyType === 'customer' ? 'customer_credit' : 'vendor_credit'));

        return is_string($account) && Str::isUuid($account) ? $account : null;
    }

    /** @param list<array<string,mixed>> $values */
    private function sumMoney(array $values): string
    {
        $total = '0.0000';
        foreach ($values as $value) {
            $total = ExactDecimal::add($total, (string) $value['amount']['amount']);
        }

        return $total;
    }

    /** @param list<array<string,mixed>> $values */
    private function sumApplied(array $values): string
    {
        $total = '0.0000';
        foreach ($values as $value) {
            $total = ExactDecimal::add($total, (string) $value['applied_amount']['amount']);
        }

        return $total;
    }

    /** @return array<string,mixed> */
    private function eventSource(CreditTranche $tranche, string $amount, string $functionalAmount, ?string $comparisonRateRecordId, ?string $consumptionId, ?string $documentId = null): array
    {
        $source = [
            'creditTrancheId' => $tranche->id,
            'transactionMoney' => $this->money($amount, $tranche->currency),
            'functionalMoney' => $this->money($functionalAmount, $this->entities->functionalCurrency($tranche->entity_id) ?? $tranche->currency),
            'sourceRateRecordId' => $tranche->source_rate_record_id,
            'comparisonRateRecordId' => $comparisonRateRecordId,
            'consumptionId' => $consumptionId,
        ];

        if ($consumptionId === null) {
            $source['sourceAllocationId'] = $tranche->source_allocation_id;
        } else {
            $source['targetDocumentId'] = $documentId;
        }

        return $source;
    }

    /** @return array<string,mixed> */
    private function presentAllocation(Allocation $allocation): array
    {
        $allocation->loadMissing(['links', 'withholdingLines']);

        return [
            'id' => $allocation->id,
            'allocation_number' => $allocation->allocation_number,
            'operation' => $allocation->operation,
            'party_type' => $allocation->party_type,
            'party_id' => $allocation->party_id,
            'settlement_date' => $allocation->settlement_date,
            'bank_account_id' => $allocation->bank_account_id,
            'gross_amount' => $this->money($allocation->gross_amount, $allocation->currency),
            'bank_amount' => $this->money($allocation->bank_amount, $allocation->currency),
            'withholding_amount' => $this->money($allocation->withholding_amount, $allocation->currency),
            'allocated_amount' => $this->money($allocation->allocated_amount, $allocation->currency),
            'unapplied_amount' => $this->money($allocation->unapplied_amount, $allocation->currency),
            'exchange_rate_reference' => $allocation->exchange_rate_reference,
            'functional_gross_amount' => $this->money($allocation->functional_gross_amount, $this->entities->functionalCurrency($allocation->entity_id) ?? $allocation->currency),
            'links' => $allocation->links->map(fn ($link): array => [
                'id' => $link->id,
                'document_type' => $link->document_type,
                'document_id' => $link->document_id,
                'credit_tranche_id' => $link->credit_tranche_id,
                'applied_amount' => $this->money($link->applied_amount, $allocation->currency),
                'expected_version' => $link->expected_version,
                'open_document' => [
                    'document_number' => $link->document_number,
                    'party_id' => $link->document_party_id,
                    'open_balance_before' => $this->money($link->open_balance_before, $allocation->currency),
                    'open_balance_after' => $this->money($link->open_balance_after, $allocation->currency),
                    'version_before' => $link->version_before,
                    'version_after' => $link->version_after,
                    'status_before' => $link->status_before,
                    'status_after' => $link->status_after,
                ],
                'realised_fx_result' => $link->realised_fx_result,
            ])->values()->all(),
            'withholding_lines' => $allocation->withholdingLines->map(fn ($line): array => [
                'withholding_code' => $line->withholding_code,
                'amount' => $this->money($line->amount, $allocation->currency),
                'tax_snapshot' => $line->tax_snapshot,
                'withholding_configuration_reference' => $line->configuration_reference,
            ])->values()->all(),
            'journal_entry_ids' => $allocation->journal_entry_ids,
            'state' => $allocation->state,
            'version' => $allocation->version,
            'reverses_allocation_id' => $allocation->reversal_of_id,
            'reversed_by_allocation_id' => $allocation->reversed_by_id,
            'posted_at' => optional($allocation->posted_at)->toISOString(),
            'created_at' => optional($allocation->created_at)->toISOString(),
        ];
    }

    /** @return array<string,mixed> */
    private function summary(Allocation $allocation): array
    {
        return [
            'id' => $allocation->id,
            'allocation_number' => $allocation->allocation_number,
            'operation' => $allocation->operation,
            'party_type' => $allocation->party_type,
            'party_id' => $allocation->party_id,
            'settlement_date' => $allocation->settlement_date->toDateString(),
            'gross_amount' => $this->money($allocation->gross_amount, $allocation->currency),
            'bank_amount' => $this->money($allocation->bank_amount, $allocation->currency),
            'withholding_amount' => $this->money($allocation->withholding_amount, $allocation->currency),
            'allocated_amount' => $this->money($allocation->allocated_amount, $allocation->currency),
            'unapplied_amount' => $this->money($allocation->unapplied_amount, $allocation->currency),
            'state' => $allocation->state,
            'version' => $allocation->version,
            'posted_at' => $allocation->posted_at->toISOString(),
        ];
    }

    /** @return array<string,mixed> */
    private function presentTranche(CreditTranche $tranche): array
    {
        return [
            'credit_tranche_id' => $tranche->id,
            'party_type' => $tranche->party_type,
            'party_id' => $tranche->party_id,
            'currency' => $tranche->currency,
            'source_allocation_id' => $tranche->source_allocation_id,
            'source_reference' => $tranche->source_reference,
            'original_amount' => $this->money($tranche->original_amount, $tranche->currency),
            'remaining_amount' => $this->money($tranche->remaining_amount, $tranche->currency),
            'original_functional_amount' => $this->money($tranche->original_functional_amount, $this->entities->functionalCurrency($tranche->entity_id) ?? $tranche->currency),
            'remaining_functional_amount' => $this->money($tranche->remaining_functional_amount, $this->entities->functionalCurrency($tranche->entity_id) ?? $tranche->currency),
            'source_exchange_rate_reference' => $tranche->source_exchange_rate_reference,
            'version' => $tranche->version,
            'created_at' => optional($tranche->created_at)->toISOString(),
        ];
    }

    private function capability(string $type): string
    {
        return match ($type) {
            'receipt' => 'settlement.receipts.create',
            'payment' => 'settlement.payments.create',
            'credit_application' => 'settlement.credits.apply',
            'credit_refund' => 'settlement.credits.refund',
            'reversal' => 'settlement.allocations.reverse',
            default => 'settlement.none',
        };
    }

    private function operation(string $type, string $resourceId): string
    {
        return match ($type) {
            'receipt' => 'POST /v1/receipts',
            'payment' => 'POST /v1/payments',
            'credit_application' => "POST /v1/credits/{$resourceId}/apply",
            'credit_refund' => "POST /v1/credits/{$resourceId}/refund",
            'reversal' => "POST /v1/allocations/{$resourceId}/reverse",
            default => 'POST /v1/settlement/unsupported',
        };
    }

    private function rule(string $rule, string $message, int $status = 422): DocumentActionResult
    {
        $code = match ($status) {
            404 => 'not_found',
            409 => 'concurrency_conflict',
            default => 'invariant_violation',
        };

        return $this->commands->error($code, $message, $status, ['rule' => $rule]);
    }

    private function postingError(string $code): DocumentActionResult
    {
        return match ($code) {
            'period_locked' => $this->commands->error('period_locked', 'The Settlement date is not postable.', 423),
            'invalid_rate_record' => $this->rule('invalid_rate_record', 'The immutable RateRecord reference is invalid.'),
            'missing_posting_configuration' => $this->rule('missing_posting_configuration', 'Required Settlement account mapping is unavailable.'),
            default => $this->rule($code !== '' ? $code : 'unbalanced_settlement', 'The Settlement posting is not exactly balanced.'),
        };
    }

    /** @param array<string,mixed> $error */
    private function documentError(array $error): DocumentActionResult
    {
        return match ($error['error'] ?? null) {
            'not_found' => $this->commands->error('not_found', 'The open document was not found.', 404),
            'concurrency_conflict' => $this->commands->error('concurrency_conflict', 'The open document version is stale.', 409, ['required_version' => $error['required_version'] ?? null]),
            default => $this->rule((string) ($error['rule'] ?? $error['error'] ?? 'invalid_document_state'), 'The open document cannot accept this Settlement.'),
        };
    }

    /** @param array<string,mixed> $result */
    private function recordFxEvent(array $result, string $allocationId, string $entityId, string $causationId): void
    {
        if (($result['classification'] ?? 'none') === 'none') {
            return;
        }
        $this->outbox->record('RealisedFXRecognised', 'Allocation', $allocationId, [
            'allocationId' => $allocationId,
            'money' => $result['realised_fx'],
            'classification' => $result['classification'],
            'accountId' => config('settlement.accounts.'.(($result['classification'] ?? null) === 'gain' ? 'realised_fx_gain' : 'realised_fx_loss')),
            'rateRecordIds' => array_values(array_filter([$result['document_rate_record_id'] ?? null, $result['settlement_rate_record_id'] ?? null, $result['source_rate_record_id'] ?? null, $result['comparison_rate_record_id'] ?? null])),
        ], $entityId, metadata: ['causation_id' => $causationId]);
    }

    /** @return array{amount:string,currency:string} */
    private function money(string $amount, string $currency): array
    {
        return ['amount' => ExactDecimal::normalize($amount), 'currency' => strtoupper($currency)];
    }

    private function correlation(): string
    {
        $correlation = request()->attributes->get('correlation_id');

        return is_string($correlation) && Str::isUuid($correlation) ? $correlation : (string) Str::uuid();
    }
}
