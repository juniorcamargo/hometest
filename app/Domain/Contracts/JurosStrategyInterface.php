<?php

namespace App\Domain\Contracts;

use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\DebitoAtualizado;

interface JurosStrategyInterface
{
    public function suporta(string $tipoDebito): bool;

    public function calcular(Debito $debito, \DateTimeImmutable $dataReferencia): DebitoAtualizado;
}
