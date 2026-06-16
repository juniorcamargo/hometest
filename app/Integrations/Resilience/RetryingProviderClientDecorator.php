<?php

namespace App\Integrations\Resilience;

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;

final class RetryingProviderClientDecorator implements ProviderClientInterface
{
    public function __construct(
        private readonly ProviderClientInterface $inner,
        private readonly int $retries = 3,
        private readonly int $waitMs = 200,
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
            }
        }

        throw $last;
    }
}
