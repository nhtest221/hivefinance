<?php

return [
    'supported_currencies' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('DOCUMENT_SUPPORTED_CURRENCIES', ''))))),
    'payment_terms' => json_decode((string) env('DOCUMENT_PAYMENT_TERMS_JSON', '{}'), true) ?: [],
    // M4-GOV-001 §12: Credit Note, Debit Note, void, and Period Reopen commands all require
    // a "configured reason_code". One shared entity-agnostic catalog backs every one of them;
    // no jurisdiction-specific reason taxonomy has been approved, so this is deployment
    // configuration only (DOM-07-style), never a legal/business value invented in code.
    'reason_codes' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('DOCUMENT_REASON_CODES', ''))))),
    'invoice' => [
        'number_prefix' => env('INVOICE_NUMBER_PREFIX'),
        'number_format' => env('INVOICE_NUMBER_FORMAT'),
        'revenue_account_id' => env('INVOICE_REVENUE_ACCOUNT_ID'),
        'receivable_account_id' => env('INVOICE_RECEIVABLE_ACCOUNT_ID'),
    ],
    'bill' => [
        'number_prefix' => env('BILL_NUMBER_PREFIX'),
        'number_format' => env('BILL_NUMBER_FORMAT'),
        'payable_account_id' => env('BILL_PAYABLE_ACCOUNT_ID'),
    ],
    'expense' => [
        'payable_account_id' => env('EXPENSE_PAYABLE_ACCOUNT_ID'),
    ],
    'credit_note' => [
        'number_prefix' => env('CREDIT_NOTE_NUMBER_PREFIX'),
        'number_format' => env('CREDIT_NOTE_NUMBER_FORMAT'),
    ],
    'debit_note' => [
        'number_prefix' => env('DEBIT_NOTE_NUMBER_PREFIX'),
        'number_format' => env('DEBIT_NOTE_NUMBER_FORMAT'),
    ],
];
