<?php

namespace App\Integrations\Providers;

use App\Domain\ValueObjects\Placa;

interface ProviderClientInterface
{
    /**
     * Retorna o payload bruto (string JSON ou XML) do provedor.
     *
     * @throws \App\Domain\Exceptions\ProviderUnavailableException
     */
    public function buscar(Placa $placa): string;
}
