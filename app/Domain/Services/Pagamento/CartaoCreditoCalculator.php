<?php

namespace App\Domain\Services\Pagamento;

use App\Domain\ValueObjects\CartaoCreditoOpcao;
use App\Domain\ValueObjects\ParcelaCartao;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class CartaoCreditoCalculator
{
    private const TAXA_MENSAL = '0.025';
    private const QUANTIDADES = [1, 6, 12];
    private const ESCALA_INTERNA = 10;

    public function calcular(BigDecimal $valorBase): CartaoCreditoOpcao
    {
        $parcelas = array_map(
            fn (int $n) => new ParcelaCartao($n, $this->valorParcela($valorBase, $n)),
            self::QUANTIDADES,
        );

        return new CartaoCreditoOpcao($parcelas);
    }

    private function valorParcela(BigDecimal $valorBase, int $n): BigDecimal
    {
        if ($n === 1) {
            return $valorBase->toScale(2, RoundingMode::HalfUp);
        }

        $i = BigDecimal::of(self::TAXA_MENSAL);
        $umMaisI = $i->plus(1);
        $potencia = $umMaisI->power($n);

        $numerador = $i->multipliedBy($potencia);
        $denominador = $potencia->minus(1);

        $fator = $numerador->dividedBy($denominador, self::ESCALA_INTERNA, RoundingMode::HalfUp);

        return $valorBase->multipliedBy($fator)->toScale(2, RoundingMode::HalfUp);
    }
}
