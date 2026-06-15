<?php

use App\Domain\Services\Juros\MultaJurosStrategy;
use App\Domain\ValueObjects\Debito;
use Brick\Math\BigDecimal;

$referencia = new DateTimeImmutable('2024-05-10T00:00:00Z');
$strategy = new MultaJurosStrategy();

// UT-MULTA-01 — sem teto, arredondamento HALF_UP
test('MULTA sem teto: 300.50, 85 dias -> juros=255.43, valor_atualizado=555.93', function () use ($referencia, $strategy) {
    $debito = new Debito('MULTA', BigDecimal::of('300.50'), new DateTimeImmutable('2024-02-15'));

    $resultado = $strategy->calcular($debito, $referencia);

    expect($resultado->diasAtraso)->toBe(85)
        ->and((string) $resultado->juros)->toBe('255.43')
        ->and((string) $resultado->valorAtualizado)->toBe('555.93');
});

test('suporta apenas MULTA', function () use ($strategy) {
    expect($strategy->suporta('MULTA'))->toBeTrue()
        ->and($strategy->suporta('IPVA'))->toBeFalse()
        ->and($strategy->suporta('OUTROS'))->toBeFalse();
});

// UT-EDGE-01 — débito não vencido (compartilhado com IPVA, testado aqui para MULTA)
test('MULTA não vencida: juros=0.00 e valor_atualizado=valor_original', function () use ($strategy) {
    $referencia = new DateTimeImmutable('2024-05-10T00:00:00Z');
    $debito = new Debito('MULTA', BigDecimal::of('500.00'), new DateTimeImmutable('2024-06-01'));

    $resultado = $strategy->calcular($debito, $referencia);

    expect($resultado->diasAtraso)->toBeLessThanOrEqual(0)
        ->and((string) $resultado->juros)->toBe('0')
        ->and((string) $resultado->valorAtualizado)->toBe('500.00');
});
