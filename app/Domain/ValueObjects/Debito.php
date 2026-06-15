<?php

namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Debito
{
    private const SECONDS_PER_DAY = 86_400;

    public readonly \DateTimeImmutable $vencimento;
    public readonly BigDecimal $valorOriginal;

    public function __construct(
        public readonly string $tipo,
        BigDecimal $valorOriginal,
        \DateTimeImmutable $vencimento,
    ) {
        $this->valorOriginal = $valorOriginal->toScale(2, RoundingMode::HALF_UP);
        $this->vencimento = $vencimento->setTime(0, 0, 0);
    }

    public function diasAtraso(\DateTimeImmutable $dataReferencia): int
    {
        $referencia = $dataReferencia->setTime(0, 0, 0);
        $segundos = $referencia->getTimestamp() - $this->vencimento->getTimestamp();

        return intdiv($segundos, self::SECONDS_PER_DAY);
    }
}
