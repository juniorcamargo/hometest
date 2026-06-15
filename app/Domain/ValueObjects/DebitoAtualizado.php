<?php

namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;

final class DebitoAtualizado
{
    public function __construct(
        public readonly Debito $original,
        public readonly BigDecimal $valorAtualizado,
        public readonly BigDecimal $juros,
        public readonly int $diasAtraso,
    ) {}
}
