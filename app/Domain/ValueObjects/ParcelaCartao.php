<?php

namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;

final class ParcelaCartao
{
    public function __construct(
        public readonly int $quantidade,
        public readonly BigDecimal $valorParcela,
    ) {}
}
