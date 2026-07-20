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
use App\Ledger\Application\AccountReferenceQuery;
use App\Ledger\Application\ForeignCurrencyPositionQuery;
use App\Ledger\Application\RecognitionPostingService;
use App\Ledger\Application\ReverseJournalApprovalHandler;
use App\Ledger\Infrastructure\EloquentAccountReferenceQuery;
use App\Ledger\Infrastructure\EloquentForeignCurrencyPositionQuery;
use App\Ledger\Infrastructure\EloquentRecognitionPostingService;
use App\Numbering\Application\SequenceRepository;
use App\Numbering\Infrastructure\DatabaseSequenceRepository;
use App\Payables\Application\BillApprovalCommandHandler;
use App\Period\Application\PeriodQuery;
use App\Period\Infrastructure\EloquentPeriodQuery;
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
        $this->app->singleton(ApprovalCommandRegistry::class);
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
    }
}
