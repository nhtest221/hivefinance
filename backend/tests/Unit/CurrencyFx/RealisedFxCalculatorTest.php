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
