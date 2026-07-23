<?php

use App\Support\Documents\ExactDecimal;

it('builds a canonical Money array with a normalized amount and uppercase currency', function (): void {
    expect(ExactDecimal::money('100', 'bdt'))->toBe(['amount' => '100.0000', 'currency' => 'BDT'])
        ->and(ExactDecimal::money('42.5', 'usd'))->toBe(['amount' => '42.5000', 'currency' => 'USD'])
        ->and(ExactDecimal::money('10.0000', 'BDT'))->toBe(['amount' => '10.0000', 'currency' => 'BDT']);
});
