<?php

namespace App\Domain\Services\Juros;

use App\Domain\Contracts\JurosStrategyInterface;
use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\DebitoAtualizado;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class IpvaJurosStrategy implements JurosStrategyInterface
{
    private const TIPO = 'IPVA';
    private const TAXA_DIARIA = '0.0033';
    private const TETO_PERCENTUAL = '0.20';

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

        $jurosCalculado = $debito->valorOriginal
            ->multipliedBy(self::TAXA_DIARIA)
            ->multipliedBy($diasAtraso);

        $teto = $debito->valorOriginal->multipliedBy(self::TETO_PERCENTUAL);

        $juros = $jurosCalculado->isGreaterThan($teto) ? $teto : $jurosCalculado;
        $juros = $juros->toScale(2, RoundingMode::HALF_UP);

        $valorAtualizado = $debito->valorOriginal->plus($juros);

        return new DebitoAtualizado($debito, $valorAtualizado, $juros, $diasAtraso);
    }
}
