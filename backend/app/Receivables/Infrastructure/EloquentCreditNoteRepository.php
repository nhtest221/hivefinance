<?php

namespace App\Receivables\Infrastructure;

use App\Models\Receivables\CreditNote;
use App\Models\Receivables\CreditNoteApplication;
use App\Models\Receivables\CreditNoteDisposition;
use App\Models\Receivables\CreditNoteReversal;
use App\Receivables\Application\CreditNoteRepository;
use Illuminate\Support\Carbon;

final class EloquentCreditNoteRepository implements CreditNoteRepository
{
    public function getById(string $entityId, string $id): ?CreditNote
    {
        return CreditNote::query()->with('lines')->where('entity_id', $entityId)->find($id);
    }

    public function addDraft(array $attributes, array $lines): CreditNote
    {
        $note = CreditNote::query()->create($attributes);
        $this->replaceLines($note, $lines);
        $note->load('lines');

        return $note;
    }

    public function saveDraft(string $entityId, string $id, array $attributes, array $lines, int $expectedVersion): ?CreditNote
    {
        $updated = CreditNote::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expectedVersion)->where('state', 'draft')
            ->update([...$attributes, 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return null;
        }
        $note = CreditNote::query()->whereKey($id)->firstOrFail();
        $note->lines()->delete();
        $this->replaceLines($note, $lines);
        $note->load('lines');

        return $note;
    }

    public function commitPost(string $entityId, string $id, array $attributes, int $expectedVersion): ?CreditNote
    {
        $updated = CreditNote::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expectedVersion)->where('state', 'draft')
            ->update([...$attributes, 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return null;
        }

        return CreditNote::query()->with('lines')->whereKey($id)->first();
    }

    public function appendDisposition(string $entityId, string $id, array $noteAttributes, array $dispositionAttributes, int $expectedVersion): ?CreditNoteDisposition
    {
        // Conditional UPDATE first: if the note's version has moved, no disposition row is
        // ever created, avoiding any orphan fact inconsistent with the note's current state.
        $updated = CreditNote::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expectedVersion)->where('state', 'posted')
            ->update([...$noteAttributes, 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return null;
        }

        return CreditNoteDisposition::query()->create([...$dispositionAttributes, 'credit_note_id' => $id, 'entity_id' => $entityId, 'created_at' => Carbon::now('UTC')]);
    }

    public function recordApplications(array $applicationRows): void
    {
        foreach ($applicationRows as $row) {
            CreditNoteApplication::query()->create($row);
        }
    }

    public function commitReversal(string $entityId, string $id, array $reversalAttributes, int $expectedVersion): ?CreditNoteReversal
    {
        $updated = CreditNote::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expectedVersion)->where('state', 'posted')
            ->update(['state' => 'reversed', 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return null;
        }

        return CreditNoteReversal::query()->create([...$reversalAttributes, 'credit_note_id' => $id, 'entity_id' => $entityId]);
    }

    public function findReversal(string $entityId, string $id): ?CreditNoteReversal
    {
        return CreditNoteReversal::query()->where('entity_id', $entityId)->where('credit_note_id', $id)->first();
    }

    /** @param  list<array<string,mixed>>  $lines */
    private function replaceLines(CreditNote $note, array $lines): void
    {
        foreach ($lines as $line) {
            $note->lines()->create(['entity_id' => $note->entity_id, ...$line]);
        }
    }
}
