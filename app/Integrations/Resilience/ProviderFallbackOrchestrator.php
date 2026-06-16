<?php

namespace App\Integrations\Resilience;

use App\Domain\Contracts\DebtProviderInterface;
use App\Domain\Exceptions\AllProvidersUnavailableException;
use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use Illuminate\Support\Facades\Log;

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
            Log::debug('provider.tentativa', [
                'provider' => $provider->nome(),
                'placa'    => $placa->mascarada(),
            ]);

            try {
                return $provider->consultar($placa);
            } catch (ProviderUnavailableException $e) {
                Log::warning('provider.falha', [
                    'provider' => $provider->nome(),
                    'placa'    => $placa->mascarada(),
                    'motivo'   => $e->getMessage(),
                ]);
                $tentativas[$provider->nome()] = $e->getMessage();
            }
        }

        throw new AllProvidersUnavailableException($tentativas);
    }
}
