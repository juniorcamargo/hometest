<?php

namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;

final class PixOpcao
{
    public function __construct(public readonly BigDecimal $totalComDesconto) {}
}
