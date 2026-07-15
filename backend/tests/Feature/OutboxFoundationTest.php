<?php

use App\Support\Outbox\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records an outbox message', function (): void {
    $message = app(Outbox::class)->record(
        eventType: 'PlatformHealthChecked',
        aggregateType: 'platform',
        aggregateId: '00000000-0000-4000-8000-000000000002',
        payload: ['status' => 'ok'],
    );

    expect($message->event_type)->toBe('PlatformHealthChecked');
});
