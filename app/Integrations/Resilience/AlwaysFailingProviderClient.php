<?php

namespace App\Integrations\Resilience;

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;

final class AlwaysFailingProviderClient implements ProviderClientInterface
{
    public function buscar(Placa $placa): string
    {
        throw new ProviderUnavailableException('failing', 'simulação de falha configurada');
    }
}
