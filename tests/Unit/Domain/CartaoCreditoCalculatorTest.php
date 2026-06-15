<?php

use App\Domain\Services\Pagamento\CartaoCreditoCalculator;
use Brick\Math\BigDecimal;

$calculator = new CartaoCreditoCalculator();

// UT-CARTAO-04 — exatamente 3 parcelas, quantidades [1, 6, 12]
test('retorna exatamente 3 parcelas com quantidades 1, 6 e 12', function () use ($calculator) {
    $opcao = $calculator->calcular(BigDecimal::of('1000.00'));

    expect($opcao->parcelas)->toHaveCount(3);
    expect(array_map(fn ($p) => $p->quantidade, $opcao->parcelas))->toBe([1, 6, 12]);
});

// UT-CARTAO-01 — 1x sem juros
test('1x sem juros: valor_parcela igual ao valor_base', function () use ($calculator) {
    $valorBase = BigDecimal::of('2355.93');
    $opcao = $calculator->calcular($valorBase);

    $parcela1x = $opcao->parcelas[0];
    expect($parcela1x->quantidade)->toBe(1)
        ->and((string) $parcela1x->valorParcela)->toBe('2355.93');
});

// UT-CARTAO-02 — 6x PMT (tolerância ±0,02)
test('6x PMT: 2355.93 -> 427.72', function () use ($calculator) {
    $opcao = $calculator->calcular(BigDecimal::of('2355.93'));

    $parcela6x = $opcao->parcelas[1];
    expect($parcela6x->quantidade)->toBe(6)
        ->and((string) $parcela6x->valorParcela)->toBe('427.72');
});

// UT-CARTAO-03 — 12x PMT (tolerância ±0,02)
test('12x PMT: 2355.93 -> 229.67', function () use ($calculator) {
    $opcao = $calculator->calcular(BigDecimal::of('2355.93'));

    $parcela12x = $opcao->parcelas[2];
    expect($parcela12x->quantidade)->toBe(12)
        ->and((string) $parcela12x->valorParcela)->toBe('229.67');
});
