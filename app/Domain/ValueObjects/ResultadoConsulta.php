<?php

namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ResultadoConsulta
{
    private function __construct(
        public readonly Placa $placa,
        public readonly array $debitos,
        public readonly BigDecimal $totalOriginal,
        public readonly BigDecimal $totalAtualizado,
        public readonly array $opcoesPagamento,
    ) {}

    /**
     * @param DebitoAtualizado[] $debitos
     * @param OpcaoPagamento[] $opcoesPagamento
     */
    public static function montar(Placa $placa, array $debitos, array $opcoesPagamento): self
    {
        $totalOriginal = array_reduce(
            $debitos,
            fn (BigDecimal $acc, DebitoAtualizado $d) => $acc->plus($d->original->valorOriginal),
            BigDecimal::zero(),
        );

        $totalAtualizado = array_reduce(
            $debitos,
            fn (BigDecimal $acc, DebitoAtualizado $d) => $acc->plus($d->valorAtualizado),
            BigDecimal::zero(),
        );

        return new self(
            placa: $placa,
            debitos: $debitos,
            totalOriginal: $totalOriginal->toScale(2, RoundingMode::HalfUp),
            totalAtualizado: $totalAtualizado->toScale(2, RoundingMode::HalfUp),
            opcoesPagamento: $opcoesPagamento,
        );
    }
}
