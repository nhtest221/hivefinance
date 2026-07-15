<?php

namespace App\Ledger\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class DecimalAmount implements Stringable
{
    private const FACTOR = 10000;

    private const SCALE = 4;

    private function __construct(private int $units)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromString(mixed $amount): self
    {
        if (is_float($amount)) {
            throw new InvalidArgumentException('Money amounts must not be floats.');
        }

        if (is_string($amount) === false && is_int($amount) === false) {
            throw new InvalidArgumentException('Amount must be a string or integer decimal.');
        }

        $value = trim((string) $amount);

        if (preg_match('/^-?\d+(\.\d{1,4})?$/', $value) !== 1) {
            throw new InvalidArgumentException('Amount must be an exact decimal with at most 4 places.');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = str_pad($fraction, self::SCALE, '0');
        $units = ((int) $whole * self::FACTOR) + (int) $fraction;

        return new self($negative ? 0 - $units : $units);
    }

    public function add(self $amount): self
    {
        return new self($this->units + $amount->units);
    }

    public function subtract(self $amount): self
    {
        return new self($this->units - $amount->units);
    }

    public function negate(): self
    {
        return new self(0 - $this->units);
    }

    public function isZero(): bool
    {
        return $this->units === 0;
    }

    public function isPositive(): bool
    {
        return $this->units > 0;
    }

    public function equals(self $amount): bool
    {
        return $this->units === $amount->units;
    }

    public function toString(): string
    {
        $negative = $this->units < 0;
        $absolute = abs($this->units);
        $whole = intdiv($absolute, self::FACTOR);
        $fraction = str_pad((string) ($absolute % self::FACTOR), self::SCALE, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
