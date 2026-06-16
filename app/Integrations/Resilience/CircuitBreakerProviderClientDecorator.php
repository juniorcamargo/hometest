<?php

namespace App\Integrations\Resilience;

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CircuitBreakerProviderClientDecorator implements ProviderClientInterface
{
    private int $failureCount = 0;
    private ?float $openUntil = null;

    public function __construct(
        private readonly ProviderClientInterface $inner,
        private readonly int $threshold = 5,
        private readonly int $resetAfterSeconds = 60,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function buscar(Placa $placa): string
    {
        if ($this->openUntil !== null && microtime(true) < $this->openUntil) {
            $this->logger->warning('provider.circuit_breaker.fast_fail', [
                'placa'      => $placa->mascarada(),
                'open_until' => $this->openUntil,
            ]);
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
                $this->logger->error('provider.circuit_breaker.aberto', [
                    'failure_count'       => $this->failureCount,
                    'reset_after_seconds' => $this->resetAfterSeconds,
                ]);
            }

            throw $e;
        }
    }
}
