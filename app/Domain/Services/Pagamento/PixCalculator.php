<?php

namespace App\Domain\Services\Pagamento;

use App\Domain\ValueObjects\PixOpcao;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PixCalculator
{
    private const FATOR_DESCONTO = '0.95';

    public function calcular(BigDecimal $valorBase): PixOpcao
    {
        $totalComDesconto = $valorBase
            ->multipliedBy(self::FATOR_DESCONTO)
            ->toScale(2, RoundingMode::HalfUp);

        return new PixOpcao($totalComDesconto);
    }
}
