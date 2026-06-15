<?php

namespace App\Domain\Contracts;

interface ReferenceDateProviderInterface
{
    public function dataReferencia(): \DateTimeImmutable;
}
