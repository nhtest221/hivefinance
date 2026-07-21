<?php

return [
    'receipt' => ['number_prefix' => env('SETTLEMENT_RECEIPT_NUMBER_PREFIX'), 'number_format' => env('SETTLEMENT_RECEIPT_NUMBER_FORMAT')],
    'payment' => ['number_prefix' => env('SETTLEMENT_PAYMENT_NUMBER_PREFIX'), 'number_format' => env('SETTLEMENT_PAYMENT_NUMBER_FORMAT')],
    'refund' => ['number_prefix' => env('SETTLEMENT_REFUND_NUMBER_PREFIX'), 'number_format' => env('SETTLEMENT_REFUND_NUMBER_FORMAT')],
    'accounts' => [
        'customer_credit' => env('SETTLEMENT_CUSTOMER_CREDIT_ACCOUNT_ID'),
        'vendor_credit' => env('SETTLEMENT_VENDOR_CREDIT_ACCOUNT_ID'),
        'realised_fx_gain' => env('SETTLEMENT_REALISED_FX_GAIN_ACCOUNT_ID'),
        'realised_fx_loss' => env('SETTLEMENT_REALISED_FX_LOSS_ACCOUNT_ID'),
    ],
    'withholding' => json_decode((string) env('SETTLEMENT_WITHHOLDING_CONFIG_JSON', '{}'), true) ?: [],
];
