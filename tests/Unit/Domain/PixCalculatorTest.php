<?php

use App\Domain\Services\Pagamento\PixCalculator;
use Brick\Math\BigDecimal;

$calculator = new PixCalculator();

// UT-PIX-01
test('desconto de 5%: 2355.93 × 0.95 = 2238.13', function () use ($calculator) {
    $pix = $calculator->calcular(BigDecimal::of('2355.93'));

    expect((string) $pix->totalComDesconto)->toBe('2238.13');
});

test('arredondamento HALF_UP na quinta casa decimal', function () use ($calculator) {
    // 100.00 × 0.95 = 95.00 exato
    $pix = $calculator->calcular(BigDecimal::of('100.00'));
    expect((string) $pix->totalComDesconto)->toBe('95.00');
});

test('zero como valor_base resulta em 0.00', function () use ($calculator) {
    $pix = $calculator->calcular(BigDecimal::of('0.00'));
    expect((string) $pix->totalComDesconto)->toBe('0.00');
});
