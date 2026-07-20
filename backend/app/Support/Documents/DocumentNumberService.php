<?php

namespace App\Support\Documents;

use App\Identity\Application\EntityReferenceQuery;
use App\Numbering\Application\SequenceRepository;
use App\Numbering\Domain\SequenceScope;

final readonly class DocumentNumberService
{
    public function __construct(private SequenceRepository $sequences, private EntityReferenceQuery $entities) {}

    /** @return array{number:string,prefix:string,scope:SequenceScope,value:int}|null */
    public function draw(string $kind, string $entityId, string $date): ?array
    {
        $prefix = config('documents.'.$kind.'.number_prefix');
        $format = config('documents.'.$kind.'.number_format');
        $year = $this->entities->fiscalYearForDate($entityId, $date);
        if (! is_string($prefix) || $prefix === '' || ! is_string($format) || $format === '' || $year === null) {
            return null;
        }$scope = new SequenceScope($entityId, $year);
        $sequence = $this->sequences->drawNext($prefix, $scope);
        $number = str_replace(['{prefix}', '{fiscal_year}', '{sequence}'], [$prefix, $year, (string) $sequence->currentValue], $format);
        if (str_contains($number, '{') || trim($number) === '') {
            return null;
        }

        return ['number' => $number, 'prefix' => $prefix, 'scope' => $scope, 'value' => $sequence->currentValue];
    }

    /** @param array{prefix:string,scope:SequenceScope,value:int} $draw */
    public function void(array $draw): void
    {
        $this->sequences->recordVoided($draw['prefix'], $draw['scope'], $draw['value']);
    }
}
