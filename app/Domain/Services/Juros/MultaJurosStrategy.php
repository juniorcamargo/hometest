<?php

namespace App\Domain\Services\Juros;

use App\Domain\Contracts\JurosStrategyInterface;
use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\DebitoAtualizado;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class MultaJurosStrategy implements JurosStrategyInterface
{
    private const TIPO = 'MULTA';
    private const TAXA_DIARIA = '0.01';

    public function suporta(string $tipoDebito): bool
    {
        return $tipoDebito === self::TIPO;
    }

    public function calcular(Debito $debito, \DateTimeImmutable $dataReferencia): DebitoAtualizado
    {
        $diasAtraso = $debito->diasAtraso($dataReferencia);

        if ($diasAtraso <= 0) {
            return new DebitoAtualizado(
                original: $debito,
                valorAtualizado: $debito->valorOriginal,
                juros: BigDecimal::zero(),
                diasAtraso: $diasAtraso,
            );
        }

        $juros = $debito->valorOriginal
            ->multipliedBy(self::TAXA_DIARIA)
            ->multipliedBy($diasAtraso)
            ->toScale(2, RoundingMode::HalfUp);

        $valorAtualizado = $debito->valorOriginal->plus($juros);

        return new DebitoAtualizado($debito, $valorAtualizado, $juros, $diasAtraso);
    }
}
