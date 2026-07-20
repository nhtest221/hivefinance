<?php

return [
    'tax' => [
        'jurisdictions' => array_values(array_filter(explode(',', (string) env('HIVEFIN_TAX_JURISDICTIONS', '')))),
        'calculation_methods' => array_values(array_filter(explode(',', (string) env('HIVEFIN_TAX_CALCULATION_METHODS', '')))),
        'exclusive_methods' => array_values(array_filter(explode(',', (string) env('HIVEFIN_TAX_EXCLUSIVE_METHODS', '')))),
        'inclusive_methods' => array_values(array_filter(explode(',', (string) env('HIVEFIN_TAX_INCLUSIVE_METHODS', '')))),
        'return_box_keys' => array_values(array_filter(explode(',', (string) env('HIVEFIN_TAX_RETURN_BOX_KEYS', '')))),
        'pack_template_keys' => array_values(array_filter(explode(',', (string) env('HIVEFIN_TAX_PACK_TEMPLATE_KEYS', '')))),
        'pack_policy_keys' => array_values(array_filter(explode(',', (string) env('HIVEFIN_TAX_PACK_POLICY_KEYS', '')))),
    ],
    'fx' => [
        'sources' => array_values(array_filter(explode(',', (string) env('HIVEFIN_FX_SOURCES', '')))),
        'source_precedence' => array_values(array_filter(explode(',', (string) env('HIVEFIN_FX_SOURCE_PRECEDENCE', '')))),
        'rounding_mode' => env('HIVEFIN_FX_ROUNDING_MODE'),
        'rounding_scale' => env('HIVEFIN_FX_ROUNDING_SCALE'),
        'unrealised_gain_account_id' => env('HIVEFIN_FX_UNREALISED_GAIN_ACCOUNT_ID'),
        'unrealised_loss_account_id' => env('HIVEFIN_FX_UNREALISED_LOSS_ACCOUNT_ID'),
    ],
];
