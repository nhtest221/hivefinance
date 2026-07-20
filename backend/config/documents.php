<?php

return [
    'supported_currencies' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('DOCUMENT_SUPPORTED_CURRENCIES', ''))))),
    'payment_terms' => json_decode((string) env('DOCUMENT_PAYMENT_TERMS_JSON', '{}'), true) ?: [],
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
];
