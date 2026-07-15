<?php

namespace App\Numbering\Domain;

use InvalidArgumentException;

final readonly class Sequence
{
    public function __construct(
        public string $id,
        public string $seriesPrefix,
        public SequenceScope $scope,
        public int $currentValue,
        public bool $gapless,
        public string $resetPolicy,
    ) {
        if ($seriesPrefix === '' || $currentValue < 0 || $resetPolicy === '') {
            throw new InvalidArgumentException('Sequence configuration is invalid.');
        }
    }
}
