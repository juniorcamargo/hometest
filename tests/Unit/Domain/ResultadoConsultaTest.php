<?php

use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\DebitoAtualizado;
use App\Domain\ValueObjects\Placa;
use App\Domain\ValueObjects\ResultadoConsulta;
use Brick\Math\BigDecimal;

// UT-RESULTADO-01 — zero débitos retorna totais "0.00"
test('montar com zero débitos retorna totais 0.00', function () {
    $placa = Placa::fromString('ABC1234');

    $resultado = ResultadoConsulta::montar($placa, [], []);

    expect((string) $resultado->totalOriginal)->toBe('0.00')
        ->and((string) $resultado->totalAtualizado)->toBe('0.00')
        ->and($resultado->debitos)->toBeEmpty()
        ->and($resultado->opcoesPagamento)->toBeEmpty();
});

test('montar soma corretamente múltiplos débitos', function () {
    $placa = Placa::fromString('ABC1234');
    $referencia = new DateTimeImmutable('2024-05-10');

    $d1 = new Debito('IPVA', BigDecimal::of('1500.00'), new DateTimeImmutable('2024-01-10'));
    $d2 = new Debito('MULTA', BigDecimal::of('300.50'), new DateTimeImmutable('2024-02-15'));

    $da1 = new DebitoAtualizado($d1, BigDecimal::of('1800.00'), BigDecimal::of('300.00'), 121);
    $da2 = new DebitoAtualizado($d2, BigDecimal::of('555.93'), BigDecimal::of('255.43'), 85);

    $resultado = ResultadoConsulta::montar($placa, [$da1, $da2], []);

    expect((string) $resultado->totalOriginal)->toBe('1800.50')
        ->and((string) $resultado->totalAtualizado)->toBe('2355.93');
});
