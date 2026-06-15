<?php

use App\Domain\Services\Juros\IpvaJurosStrategy;
use App\Domain\ValueObjects\Debito;
use Brick\Math\BigDecimal;

$referencia = new DateTimeImmutable('2024-05-10T00:00:00Z');
$strategy = new IpvaJurosStrategy();

// UT-IPVA-01 — teto de 20% aplicado (121 dias × 0,33% = 39,93% > 20%)
test('IPVA com teto: 1500.00, 121 dias -> juros=300.00, valor_atualizado=1800.00', function () use ($referencia, $strategy) {
    $debito = new Debito('IPVA', BigDecimal::of('1500.00'), new DateTimeImmutable('2024-01-10'));

    $resultado = $strategy->calcular($debito, $referencia);

    expect($resultado->diasAtraso)->toBe(121)
        ->and((string) $resultado->juros)->toBe('300.00')
        ->and((string) $resultado->valorAtualizado)->toBe('1800.00');
});

test('IPVA sem teto: taxa aplicada diretamente quando abaixo do limite', function () use ($referencia, $strategy) {
    // 1000.00 × 0.0033 × 5 = 16.50; teto = 200.00 → sem teto
    $debito = new Debito('IPVA', BigDecimal::of('1000.00'), new DateTimeImmutable('2024-05-05'));

    $resultado = $strategy->calcular($debito, $referencia);

    expect($resultado->diasAtraso)->toBe(5)
        ->and((string) $resultado->juros)->toBe('16.50')
        ->and((string) $resultado->valorAtualizado)->toBe('1016.50');
});

test('suporta apenas IPVA', function () use ($strategy) {
    expect($strategy->suporta('IPVA'))->toBeTrue()
        ->and($strategy->suporta('MULTA'))->toBeFalse()
        ->and($strategy->suporta('LICENCIAMENTO'))->toBeFalse();
});

// UT-EDGE-01
test('IPVA não vencido: juros=0 e valor_atualizado=valor_original', function () use ($strategy) {
    $referencia = new DateTimeImmutable('2024-05-10T00:00:00Z');
    $debito = new Debito('IPVA', BigDecimal::of('1500.00'), new DateTimeImmutable('2024-06-01'));

    $resultado = $strategy->calcular($debito, $referencia);

    expect($resultado->diasAtraso)->toBeLessThanOrEqual(0)
        ->and((string) $resultado->juros)->toBe('0')
        ->and((string) $resultado->valorAtualizado)->toBe('1500.00');
});

test('IPVA vencimento igual à data de referência: dias_atraso=0, sem juros', function () use ($strategy) {
    $referencia = new DateTimeImmutable('2024-05-10T00:00:00Z');
    $debito = new Debito('IPVA', BigDecimal::of('1000.00'), new DateTimeImmutable('2024-05-10'));

    $resultado = $strategy->calcular($debito, $referencia);

    expect($resultado->diasAtraso)->toBe(0)
        ->and((string) $resultado->juros)->toBe('0')
        ->and((string) $resultado->valorAtualizado)->toBe('1000.00');
});
