<?php

use App\CurrencyFx\Domain\RealisedFxCalculator;

it('calculates realised FX per exact tranche with configured rounding', function (): void {
    $result = (new RealisedFxCalculator)->calculate('10.0000', '100.00000000', '102.00000000', 4, 'half_even');

    expect($result)->toBe([
        'document_functional' => '1000.0000',
        'settlement_functional' => '1020.0000',
        'realised_fx' => '20.0000',
        'classification' => 'gain',
    ]);
});

it('classifies a negative realised difference as a loss', function (): void {
    $result = (new RealisedFxCalculator)->calculate('10.0000', '102.00000000', '100.00000000', 4, 'half_even');

    expect($result['realised_fx'])->toBe('-20.0000')
        ->and($result['classification'])->toBe('loss');
});

it('classifies settlement FX from the customer and vendor accounting perspectives', function (): void {
    $calculator = new RealisedFxCalculator;

    $customer = $calculator->calculateSettlement('10.0000', '100.00000000', '110.00000000', 'customer', 4, 'half_up');
    $vendor = $calculator->calculateSettlement('10.0000', '100.00000000', '110.00000000', 'vendor', 4, 'half_up');

    expect($customer['realised_fx'])->toBe('100.0000')
        ->and($customer['classification'])->toBe('gain')
        ->and($vendor['realised_fx'])->toBe('-100.0000')
        ->and($vendor['classification'])->toBe('loss');
});

it('classifies credit FX from the immutable source and comparison rates', function (): void {
    $calculator = new RealisedFxCalculator;

    $customer = $calculator->calculateCredit('10.0000', '100.00000000', '90.00000000', 'customer', 4, 'half_up');
    $vendor = $calculator->calculateCredit('10.0000', '100.00000000', '120.00000000', 'vendor', 4, 'half_up');

    expect($customer)->toBe(['source_functional' => '1000.0000', 'comparison_functional' => '900.0000', 'realised_fx' => '100.0000', 'classification' => 'gain'])
        ->and($vendor)->toBe(['source_functional' => '1000.0000', 'comparison_functional' => '1200.0000', 'realised_fx' => '200.0000', 'classification' => 'gain']);
});
