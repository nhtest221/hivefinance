<?php

namespace App\Providers;

use App\Identity\Application\EntityReferenceQuery;
use App\Identity\Infrastructure\EloquentEntityReferenceQuery;
use App\Numbering\Application\SequenceRepository;
use App\Numbering\Infrastructure\DatabaseSequenceRepository;
use App\Period\Application\PeriodQuery;
use App\Period\Infrastructure\EloquentPeriodQuery;
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
    }

    public function boot(): void
    {
        //
    }
}
