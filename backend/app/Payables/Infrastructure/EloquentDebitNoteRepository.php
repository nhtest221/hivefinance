<?php

namespace App\Payables\Infrastructure;

use App\Models\Payables\DebitNote;
use App\Models\Payables\DebitNoteApplication;
use App\Models\Payables\DebitNoteDisposition;
use App\Models\Payables\DebitNoteReversal;
use App\Payables\Application\DebitNoteRepository;
use Illuminate\Support\Carbon;

final class EloquentDebitNoteRepository implements DebitNoteRepository
{
    public function getById(string $entityId, string $id): ?DebitNote
    {
        return DebitNote::query()->with('lines')->where('entity_id', $entityId)->find($id);
    }

    public function addDraft(array $attributes, array $lines): DebitNote
    {
        $note = DebitNote::query()->create($attributes);
        $this->replaceLines($note, $lines);
        $note->load('lines');

        return $note;
    }

    public function saveDraft(string $entityId, string $id, array $attributes, array $lines, int $expectedVersion): ?DebitNote
    {
        $updated = DebitNote::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expectedVersion)->where('state', 'draft')
            ->update([...$attributes, 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return null;
        }
        $note = DebitNote::query()->whereKey($id)->firstOrFail();
        $note->lines()->delete();
        $this->replaceLines($note, $lines);
        $note->load('lines');

        return $note;
    }

    public function commitPost(string $entityId, string $id, array $attributes, int $expectedVersion): ?DebitNote
    {
        $updated = DebitNote::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expectedVersion)->where('state', 'draft')
            ->update([...$attributes, 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return null;
        }

        return DebitNote::query()->with('lines')->whereKey($id)->first();
    }

    public function appendDisposition(string $entityId, string $id, array $noteAttributes, array $dispositionAttributes, int $expectedVersion): ?DebitNoteDisposition
    {
        $updated = DebitNote::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expectedVersion)->where('state', 'posted')
            ->update([...$noteAttributes, 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return null;
        }

        return DebitNoteDisposition::query()->create([...$dispositionAttributes, 'debit_note_id' => $id, 'entity_id' => $entityId, 'created_at' => Carbon::now('UTC')]);
    }

    public function recordApplications(array $applicationRows): void
    {
        foreach ($applicationRows as $row) {
            DebitNoteApplication::query()->create($row);
        }
    }

    public function commitReversal(string $entityId, string $id, array $reversalAttributes, int $expectedVersion): ?DebitNoteReversal
    {
        $updated = DebitNote::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expectedVersion)->where('state', 'posted')
            ->update(['state' => 'reversed', 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return null;
        }

        return DebitNoteReversal::query()->create([...$reversalAttributes, 'debit_note_id' => $id, 'entity_id' => $entityId]);
    }

    public function findReversal(string $entityId, string $id): ?DebitNoteReversal
    {
        return DebitNoteReversal::query()->where('entity_id', $entityId)->where('debit_note_id', $id)->first();
    }

    /** @param  list<array<string,mixed>>  $lines */
    private function replaceLines(DebitNote $note, array $lines): void
    {
        foreach ($lines as $line) {
            $note->lines()->create(['entity_id' => $note->entity_id, ...$line]);
        }
    }
}
