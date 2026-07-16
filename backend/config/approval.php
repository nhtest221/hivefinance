<?php

$retentionDays = env('APPROVAL_PAYLOAD_RETENTION_DAYS');

return [
    'payload_retention_days' => is_numeric($retentionDays) && (int) $retentionDays > 0
        ? (int) $retentionDays
        : null,
];
