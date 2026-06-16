<?php

namespace App\Integrations\Resilience;

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;

final class CircuitBreakerProviderClientDecorator implements ProviderClientInterface
{
    private int $failureCount = 0;
    private ?float $openUntil = null;

    public function __construct(
        private readonly ProviderClientInterface $inner,
        private readonly int $threshold = 5,
        private readonly int $resetAfterSeconds = 60,
    ) {}

    public function buscar(Placa $placa): string
    {
        if ($this->openUntil !== null && microtime(true) < $this->openUntil) {
            throw new ProviderUnavailableException('circuit_breaker', 'circuito aberto');
        }

        try {
            $result = $this->inner->buscar($placa);
            $this->failureCount = 0;
            $this->openUntil = null;

            return $result;
        } catch (ProviderUnavailableException $e) {
            $this->failureCount++;

            if ($this->failureCount >= $this->threshold) {
                $this->openUntil = microtime(true) + $this->resetAfterSeconds;
            }

            throw $e;
        }
    }
}
