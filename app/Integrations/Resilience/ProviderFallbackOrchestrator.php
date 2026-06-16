<?php

namespace App\Integrations\Resilience;

use App\Domain\Contracts\DebtProviderInterface;
use App\Domain\Exceptions\AllProvidersUnavailableException;
use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;

final class ProviderFallbackOrchestrator implements DebtProviderInterface
{
    /** @param DebtProviderInterface[] $providers em ordem de tentativa */
    public function __construct(private readonly array $providers) {}

    public function nome(): string
    {
        return 'orchestrator';
    }

    public function consultar(Placa $placa): array
    {
        $tentativas = [];

        foreach ($this->providers as $provider) {
            try {
                return $provider->consultar($placa);
            } catch (ProviderUnavailableException $e) {
                $tentativas[$provider->nome()] = $e->getMessage();
            }
        }

        throw new AllProvidersUnavailableException($tentativas);
    }
}
