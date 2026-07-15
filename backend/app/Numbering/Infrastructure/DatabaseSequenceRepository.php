<?php

namespace App\Numbering\Infrastructure;

use App\Numbering\Application\SequenceRepository;
use App\Numbering\Domain\Sequence;
use App\Numbering\Domain\SequenceScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DatabaseSequenceRepository implements SequenceRepository
{
    public function drawNext(string $seriesPrefix, SequenceScope $scope): Sequence
    {
        return DB::transaction(function () use ($seriesPrefix, $scope): Sequence {
            DB::table('numbering_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'entity_id' => $scope->entityId,
                'fiscal_year' => $scope->fiscalYear, 'series_prefix' => $seriesPrefix,
                'current_value' => 0, 'gapless' => true, 'reset_policy' => 'configured',
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $row = DB::table('numbering_sequences')
                ->where('entity_id', $scope->entityId)->where('fiscal_year', $scope->fiscalYear)
                ->where('series_prefix', $seriesPrefix)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Sequence could not be loaded.');
            }
            $next = ((int) $row->current_value) + 1;
            DB::table('numbering_sequences')->where('id', $row->id)->update(['current_value' => $next, 'updated_at' => now('UTC')]);

            return new Sequence($row->id, $row->series_prefix, $scope, $next, (bool) $row->gapless, $row->reset_policy);
        }, 3);
    }

    public function recordVoided(string $seriesPrefix, SequenceScope $scope, int $value): void
    {
        DB::transaction(function () use ($seriesPrefix, $scope, $value): void {
            $row = DB::table('numbering_sequences')->where('entity_id', $scope->entityId)
                ->where('fiscal_year', $scope->fiscalYear)->where('series_prefix', $seriesPrefix)->lockForUpdate()->first();
            if ($row === null || $value < 1 || $value > (int) $row->current_value) {
                throw new RuntimeException('Only an issued number can be recorded voided.');
            }
            DB::table('numbering_voided_numbers')->insertOrIgnore([
                'sequence_id' => $row->id, 'value' => $value, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        });
    }

    public function reset(string $seriesPrefix, SequenceScope $scope): Sequence
    {
        throw new RuntimeException('Reset policy is configuration-dependent and is not enabled by M0.');
    }
}
