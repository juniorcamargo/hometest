<?php

namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;

final class OpcaoPagamento
{
    public function __construct(
        public readonly string $tipo,
        public readonly BigDecimal $valorBase,
        public readonly PixOpcao $pix,
        public readonly CartaoCreditoOpcao $cartaoCredito,
    ) {}
}
