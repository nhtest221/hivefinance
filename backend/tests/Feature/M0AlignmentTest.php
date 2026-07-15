<?php

use App\Models\Identity\Entity;
use App\Models\OutboxMessage;
use App\Numbering\Application\SequenceRepository;
use App\Numbering\Domain\SequenceScope;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('draws a scoped gapless sequence and never reuses a voided number', function (): void {
    $entity = Entity::query()->create(['legal_name' => 'Entity', 'functional_currency' => 'BDT']);
    $repository = app(SequenceRepository::class);
    $scope = new SequenceScope($entity->id, '2026-2027');

    $first = $repository->drawNext('GENERIC', $scope);
    $second = $repository->drawNext('GENERIC', $scope);
    $repository->recordVoided('GENERIC', $scope, $first->currentValue);
    $third = $repository->drawNext('GENERIC', $scope);

    expect([$first->currentValue, $second->currentValue, $third->currentValue])->toBe([1, 2, 3])
        ->and(DB::table('numbering_voided_numbers')->where('value', 1)->exists())->toBeTrue();
});

it('dispatches only committed events and consumes replay idempotently', function (): void {
    $outbox = app(Outbox::class);
    $dispatcher = app(OutboxDispatcher::class);
    try {
        DB::transaction(function () use ($outbox): void {
            $outbox->record('JournalPosted', 'JournalEntry', '10000000-0000-4000-8000-000000000010', ['entryId' => '10000000-0000-4000-8000-000000000010']);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(OutboxMessage::query()->count())->toBe(0);

    $event = DB::transaction(fn () => $outbox->record('JournalPosted', 'JournalEntry', '10000000-0000-4000-8000-000000000011', ['entryId' => '10000000-0000-4000-8000-000000000011']));
    expect(OutboxMessage::query()->count())->toBe(1)->and($dispatcher->dispatchAvailable())->toBe(1);
    $event->update(['processed_at' => null]);
    expect($dispatcher->dispatchAvailable())->toBe(1)
        ->and(DB::table('outbox_consumptions')->where('event_id', $event->id)->count())->toBe(1);
});
