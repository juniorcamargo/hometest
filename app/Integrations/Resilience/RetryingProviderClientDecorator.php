<?php

namespace App\Integrations\Resilience;

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RetryingProviderClientDecorator implements ProviderClientInterface
{
    public function __construct(
        private readonly ProviderClientInterface $inner,
        private readonly int $retries = 3,
        private readonly int $waitMs = 200,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function buscar(Placa $placa): string
    {
        $last = null;

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            if ($attempt > 0 && $this->waitMs > 0) {
                usleep($this->waitMs * 1000);
            }

            try {
                return $this->inner->buscar($placa);
            } catch (ProviderUnavailableException $e) {
                $last = $e;
                if ($attempt < $this->retries) {
                    $this->logger->warning('provider.retry', [
                        'provider'     => $e->provider,
                        'placa'        => $placa->mascarada(),
                        'attempt'      => $attempt + 1,
                        'max_attempts' => $this->retries + 1,
                    ]);
                }
            }
        }

        throw $last;
    }
}
