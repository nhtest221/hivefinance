<?php

namespace App\Identity\Infrastructure;

use App\Identity\Application\EntityReferenceQuery;
use App\Models\Identity\Entity;
use Illuminate\Support\Carbon;

final class EloquentEntityReferenceQuery implements EntityReferenceQuery
{
    public function functionalCurrency(string $entityId): ?string
    {
        $currency = Entity::query()->whereKey($entityId)->value('functional_currency');

        return is_string($currency) ? $currency : null;
    }

    public function fiscalYearForDate(string $entityId, string $date): ?string
    {
        $entity = Entity::query()->find($entityId);
        if (! $entity instanceof Entity) {
            return null;
        }$day = Carbon::createFromFormat('Y-m-d', $date, 'UTC');
        if ($day === null) {
            return null;
        }$start = $day->copy()->setDate($day->year, (int) $entity->fiscal_year_start_month, (int) $entity->fiscal_year_start_day);
        $year = $day->lt($start) ? $day->year - 1 : $day->year;

        return (string) $year;
    }

    public function fiscalYearStartDate(string $entityId, string $date): ?string
    {
        $entity = Entity::query()->find($entityId);
        if (! $entity instanceof Entity) {
            return null;
        }
        $day = Carbon::createFromFormat('Y-m-d', $date, 'UTC');
        if ($day === null) {
            return null;
        }
        $start = $day->copy()->setDate($day->year, (int) $entity->fiscal_year_start_month, (int) $entity->fiscal_year_start_day);
        if ($day->lt($start)) {
            $start = $start->copy()->subYear();
        }

        return $start->toDateString();
    }
}
