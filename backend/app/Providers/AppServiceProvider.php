<?php

namespace App\Providers;

use App\CurrencyFx\Application\FxApprovalCommandHandler;
use App\CurrencyFx\Application\RateReferenceService;
use App\CurrencyFx\Infrastructure\EloquentRateReferenceService;
use App\Identity\Application\ApprovalCommandRegistry;
use App\Identity\Application\ApprovalPayloadProtector;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Application\EntityReferenceQuery;
use App\Identity\Infrastructure\EloquentApprovalPolicyQuery;
use App\Identity\Infrastructure\EloquentEntityReferenceQuery;
use App\Identity\Infrastructure\LaravelApprovalPayloadProtector;
use App\Ledger\Application\AccountMovementQuery;
use App\Ledger\Application\AccountReferenceQuery;
use App\Ledger\Application\ForeignCurrencyPositionQuery;
use App\Ledger\Application\RecognitionPostingService;
use App\Ledger\Application\ReverseJournalApprovalHandler;
use App\Ledger\Application\SettlementPostingService;
use App\Ledger\Infrastructure\EloquentAccountMovementQuery;
use App\Ledger\Infrastructure\EloquentAccountReferenceQuery;
use App\Ledger\Infrastructure\EloquentForeignCurrencyPositionQuery;
use App\Ledger\Infrastructure\EloquentRecognitionPostingService;
use App\Ledger\Infrastructure\EloquentSettlementPostingService;
use App\Numbering\Application\SequenceRepository;
use App\Numbering\Infrastructure\DatabaseSequenceRepository;
use App\Payables\Application\BillApprovalCommandHandler;
use App\Payables\Application\BillVoidApprovalCommandHandler;
use App\Payables\Application\BillVoidService;
use App\Payables\Application\DebitNoteApprovalCommandHandler;
use App\Payables\Application\DebitNoteDispositionApprovalCommandHandler;
use App\Payables\Application\DebitNoteDispositionService;
use App\Payables\Application\DebitNoteQuery;
use App\Payables\Application\DebitNoteRepository;
use App\Payables\Application\DebitNoteService;
use App\Payables\Application\OpenPayableService;
use App\Payables\Infrastructure\EloquentDebitNoteQuery;
use App\Payables\Infrastructure\EloquentDebitNoteRepository;
use App\Payables\Infrastructure\EloquentOpenPayableService;
use App\Period\Application\CloseGateProviderRegistry;
use App\Period\Application\PeriodApprovalCommandHandler;
use App\Period\Application\PeriodCloseService;
use App\Period\Application\PeriodQuery;
use App\Period\Infrastructure\EloquentPeriodQuery;
use App\Period\Infrastructure\UnavailableCloseGateProvider;
use App\Receivables\Application\CreditNoteApprovalCommandHandler;
use App\Receivables\Application\CreditNoteDispositionApprovalCommandHandler;
use App\Receivables\Application\CreditNoteDispositionService;
use App\Receivables\Application\CreditNoteQuery;
use App\Receivables\Application\CreditNoteRepository;
use App\Receivables\Application\CreditNoteService;
use App\Receivables\Application\InvoiceVoidApprovalCommandHandler;
use App\Receivables\Application\InvoiceVoidService;
use App\Receivables\Application\OpenReceivableService;
use App\Receivables\Infrastructure\EloquentCreditNoteQuery;
use App\Receivables\Infrastructure\EloquentCreditNoteRepository;
use App\Receivables\Infrastructure\EloquentOpenReceivableService;
use App\Reporting\Application\AccountClassificationProvider;
use App\Reporting\Application\AgeingBucketProvider;
use App\Reporting\Application\CashViewPolicyProvider;
use App\Reporting\Application\GeneralLedgerQuery;
use App\Reporting\Application\ReportLayoutProvider;
use App\Reporting\Application\ReportRunApprovalCommandHandler;
use App\Reporting\Application\ReportRunRepository;
use App\Reporting\Application\ReportRunService;
use App\Reporting\Application\TrialBalanceQuery;
use App\Reporting\Infrastructure\EloquentAccountClassificationProvider;
use App\Reporting\Infrastructure\EloquentAgeingBucketProvider;
use App\Reporting\Infrastructure\EloquentCashViewPolicyProvider;
use App\Reporting\Infrastructure\EloquentReportLayoutProvider;
use App\Reporting\Infrastructure\EloquentReportRunRepository;
use App\Reporting\Infrastructure\LedgerGeneralLedgerQuery;
use App\Reporting\Infrastructure\LedgerTrialBalanceQuery;
use App\Settlement\Application\DocumentActivityQuery;
use App\Settlement\Application\SettlementApprovalCommandHandler;
use App\Settlement\Application\SettlementService;
use App\Settlement\Infrastructure\EloquentDocumentActivityQuery;
use App\Tax\Application\TaxApprovalCommandHandler;
use App\Tax\Application\TaxCommandExecutor;
use Illuminate\Support\ServiceProvider;
use Override;

final class AppServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(PeriodQuery::class, EloquentPeriodQuery::class);
        $this->app->bind(SequenceRepository::class, DatabaseSequenceRepository::class);
        $this->app->bind(EntityReferenceQuery::class, EloquentEntityReferenceQuery::class);
        $this->app->bind(ApprovalPayloadProtector::class, LaravelApprovalPayloadProtector::class);
        $this->app->bind(ApprovalPolicyQuery::class, EloquentApprovalPolicyQuery::class);
        $this->app->bind(RateReferenceService::class, EloquentRateReferenceService::class);
        $this->app->bind(AccountReferenceQuery::class, EloquentAccountReferenceQuery::class);
        $this->app->bind(ForeignCurrencyPositionQuery::class, EloquentForeignCurrencyPositionQuery::class);
        $this->app->bind(RecognitionPostingService::class, EloquentRecognitionPostingService::class);
        $this->app->bind(SettlementPostingService::class, EloquentSettlementPostingService::class);
        $this->app->bind(OpenReceivableService::class, EloquentOpenReceivableService::class);
        $this->app->bind(OpenPayableService::class, EloquentOpenPayableService::class);
        $this->app->bind(CreditNoteRepository::class, EloquentCreditNoteRepository::class);
        $this->app->bind(CreditNoteQuery::class, EloquentCreditNoteQuery::class);
        $this->app->bind(DebitNoteRepository::class, EloquentDebitNoteRepository::class);
        $this->app->bind(DebitNoteQuery::class, EloquentDebitNoteQuery::class);
        $this->app->bind(DocumentActivityQuery::class, EloquentDocumentActivityQuery::class);
        $this->app->bind(AccountMovementQuery::class, EloquentAccountMovementQuery::class);
        $this->app->bind(ReportRunRepository::class, EloquentReportRunRepository::class);
        $this->app->bind(ReportLayoutProvider::class, EloquentReportLayoutProvider::class);
        $this->app->bind(AccountClassificationProvider::class, EloquentAccountClassificationProvider::class);
        $this->app->bind(AgeingBucketProvider::class, EloquentAgeingBucketProvider::class);
        $this->app->bind(CashViewPolicyProvider::class, EloquentCashViewPolicyProvider::class);
        $this->app->bind(TrialBalanceQuery::class, LedgerTrialBalanceQuery::class);
        $this->app->bind(GeneralLedgerQuery::class, LedgerGeneralLedgerQuery::class);
        $this->app->singleton(ApprovalCommandRegistry::class);
        $this->app->singleton(CloseGateProviderRegistry::class, function (): CloseGateProviderRegistry {
            $registry = new CloseGateProviderRegistry;
            // M5 Reporting and M6 Reconciliation do not exist yet; every gate is honestly
            // `unmet` until their real providers are implemented (API Contracts §12.7).
            $registry->register('reporting', new UnavailableCloseGateProvider('reporting'));
            $registry->register('reconciliation', new UnavailableCloseGateProvider('reconciliation'));

            return $registry;
        });
    }

    public function boot(): void
    {
        $registry = $this->app->make(ApprovalCommandRegistry::class);
        $executor = $this->app->make(TaxCommandExecutor::class);
        foreach (['tax_code_create', 'tax_code_version_create', 'tax_pack_configure'] as $type) {
            $registry->register(new TaxApprovalCommandHandler($executor, $type));
        }
        $registry->register($this->app->make(ReverseJournalApprovalHandler::class));
        foreach (['fx_rate_create', 'fx_revaluation_run'] as $type) {
            $registry->register(new FxApprovalCommandHandler($type));
        }
        $registry->register($this->app->make(BillApprovalCommandHandler::class));
        $settlement = $this->app->make(SettlementService::class);
        foreach (['receipt', 'payment', 'credit_application', 'credit_refund', 'reversal'] as $type) {
            $registry->register(new SettlementApprovalCommandHandler($settlement, $type));
        }
        $periods = $this->app->make(PeriodCloseService::class);
        foreach (['soft_close', 'hard_close', 'reopen'] as $type) {
            $registry->register(new PeriodApprovalCommandHandler($periods, $type));
        }
        $registry->register(new CreditNoteApprovalCommandHandler($this->app->make(CreditNoteService::class), 'post'));
        $registry->register(new DebitNoteApprovalCommandHandler($this->app->make(DebitNoteService::class), 'post'));
        $creditNoteDisposition = $this->app->make(CreditNoteDispositionService::class);
        $debitNoteDisposition = $this->app->make(DebitNoteDispositionService::class);
        foreach (['hold', 'apply', 'refund', 'reverse'] as $type) {
            $registry->register(new CreditNoteDispositionApprovalCommandHandler($creditNoteDisposition, $type));
            $registry->register(new DebitNoteDispositionApprovalCommandHandler($debitNoteDisposition, $type));
        }
        $registry->register(new InvoiceVoidApprovalCommandHandler($this->app->make(InvoiceVoidService::class)));
        $registry->register(new BillVoidApprovalCommandHandler($this->app->make(BillVoidService::class)));
        $registry->register(new ReportRunApprovalCommandHandler($this->app->make(ReportRunService::class)));
    }
}
