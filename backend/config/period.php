<?php

return [
    // M4-GOV-001 §12.6.4: baseline mandatory Hard Close gates and the context that owns each.
    // Configuration may add gates but never remove these five (API Contracts §12.6.4).
    'close_gates' => [
        'trial_balance_reviewed' => 'reporting',
        'profit_and_loss_approved' => 'reporting',
        'balance_sheet_approved' => 'reporting',
        'vat_outputs_approved' => 'reporting',
        'bank_reconciliation_completed' => 'reconciliation',
    ],
    // §12.6.3: Soft Close requires "M4 Soft Close adjustment configuration" to resolve.
    // This is the set of journal entry types a Soft Closed period continues to accept
    // (already enforced by Period::postablePeriodForDate); externalised here so Soft
    // Close can fail safely with 422 when it is unset, per §12.7's "missing/ambiguous
    // required configuration fails safely" rule.
    'soft_close_adjustment_entry_types' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('PERIOD_SOFT_CLOSE_ADJUSTMENT_ENTRY_TYPES', 'adjusting,revaluation'))))),
    // §12.6: VAT unlock during Reopen requires an explicit jurisdiction/entity policy.
    // null = policy not configured (422 vat_unlock_policy_missing); false = configured
    // but denies unlock (422 vat_unlock_not_permitted); true = permitted.
    'vat_unlock_permitted' => filter_var(env('PERIOD_VAT_UNLOCK_PERMITTED'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
];
