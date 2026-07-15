<?php

use App\Ledger\Domain\DecimalAmount;

it('adds exact fixed precision amounts without float arithmetic', function (): void {
    $sum = DecimalAmount::fromString('10.1000')
        ->add(DecimalAmount::fromString('0.2000'))
        ->subtract(DecimalAmount::fromString('1.3000'));

    expect($sum->toString())->toBe('9.0000');
});

it('rejects float money values', function (): void {
    DecimalAmount::fromString(10.1);
})->throws(InvalidArgumentException::class, 'Money amounts must not be floats.');
