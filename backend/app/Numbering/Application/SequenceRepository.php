<?php

namespace App\Numbering\Application;

use App\Numbering\Domain\Sequence;
use App\Numbering\Domain\SequenceScope;

interface SequenceRepository
{
    public function drawNext(string $seriesPrefix, SequenceScope $scope): Sequence;

    public function recordVoided(string $seriesPrefix, SequenceScope $scope, int $value): void;

    public function reset(string $seriesPrefix, SequenceScope $scope): Sequence;
}
