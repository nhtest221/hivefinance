<?php

use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records an audit log entry', function (): void {
    $entry = app(AuditLogger::class)->record(
        module: 'platform',
        action: 'health_checked',
        recordType: 'health_check',
        recordId: '00000000-0000-4000-8000-000000000001',
    );

    expect($entry->module)->toBe('platform');
});
