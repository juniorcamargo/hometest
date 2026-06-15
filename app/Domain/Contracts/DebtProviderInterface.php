<?php

namespace App\Domain\Contracts;

use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\Placa;

interface DebtProviderInterface
{
    /**
     * @return Debito[]
     * @throws \App\Domain\Exceptions\ProviderUnavailableException
     */
    public function consultar(Placa $placa): array;

    public function nome(): string;
}
