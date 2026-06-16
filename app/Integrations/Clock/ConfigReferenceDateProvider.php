<?php

namespace App\Integrations\Clock;

use App\Domain\Contracts\ReferenceDateProviderInterface;

final class ConfigReferenceDateProvider implements ReferenceDateProviderInterface
{
    public function dataReferencia(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(config('debitos.data_referencia'));
    }
}
