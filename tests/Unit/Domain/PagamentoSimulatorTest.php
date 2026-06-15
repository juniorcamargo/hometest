<?php

use App\Domain\Services\Juros\IpvaJurosStrategy;
use App\Domain\Services\Juros\MultaJurosStrategy;
use App\Domain\Services\Pagamento\CartaoCreditoCalculator;
use App\Domain\Services\Pagamento\PagamentoSimulator;
use App\Domain\Services\Pagamento\PixCalculator;
use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\DebitoAtualizado;
use App\Domain\ValueObjects\OpcaoPagamento;
use Brick\Math\BigDecimal;

$simulator = new PagamentoSimulator(new PixCalculator(), new CartaoCreditoCalculator());

function makeDebitoAtualizado(string $tipo, string $valorOriginal, string $valorAtualizado, string $juros, int $dias): DebitoAtualizado
{
    $debito = new Debito($tipo, BigDecimal::of($valorOriginal), new DateTimeImmutable('2024-01-01'));

    return new DebitoAtualizado(
        original: $debito,
        valorAtualizado: BigDecimal::of($valorAtualizado),
        juros: BigDecimal::of($juros),
        diasAtraso: $dias,
    );
}

/** @param OpcaoPagamento[] $opcoes */
function findOpcao(array $opcoes, string $tipo): OpcaoPagamento
{
    foreach ($opcoes as $opcao) {
        if ($opcao->tipo === $tipo) {
            return $opcao;
        }
    }

    throw new \RuntimeException("Opção '{$tipo}' não encontrada.");
}

// UT-SIM-04 — zero débitos → apenas TOTAL com 0.00
test('zero débitos retorna apenas TOTAL com valor_base 0.00', function () use ($simulator) {
    $opcoes = $simulator->gerarOpcoes([]);

    expect($opcoes)->toHaveCount(1);

    $total = findOpcao($opcoes, 'TOTAL');
    expect((string) $total->valorBase)->toBe('0.00');
});

// UT-SIM-01 — TOTAL agrega todos os valor_atualizado
test('TOTAL agrega IPVA 1800.00 + MULTA 555.93 = 2355.93', function () use ($simulator) {
    $debitos = [
        makeDebitoAtualizado('IPVA', '1500.00', '1800.00', '300.00', 121),
        makeDebitoAtualizado('MULTA', '300.50', '555.93', '255.43', 85),
    ];

    $opcoes = $simulator->gerarOpcoes($debitos);

    $total = findOpcao($opcoes, 'TOTAL');
    expect((string) $total->valorBase)->toBe('2355.93');
});

// UT-SIM-02 — SOMENTE_<TIPO> por tipo presente
test('gera SOMENTE_IPVA e SOMENTE_MULTA com valores corretos', function () use ($simulator) {
    $debitos = [
        makeDebitoAtualizado('IPVA', '1500.00', '1800.00', '300.00', 121),
        makeDebitoAtualizado('MULTA', '300.50', '555.93', '255.43', 85),
    ];

    $opcoes = $simulator->gerarOpcoes($debitos);

    expect($opcoes)->toHaveCount(3);

    $soIpva = findOpcao($opcoes, 'SOMENTE_IPVA');
    expect((string) $soIpva->valorBase)->toBe('1800.00');

    $soMulta = findOpcao($opcoes, 'SOMENTE_MULTA');
    expect((string) $soMulta->valorBase)->toBe('555.93');
});

// UT-SIM-03 — múltiplos débitos do mesmo tipo → uma única SOMENTE_MULTA somando ambos (CB-07)
test('dois débitos MULTA geram uma única SOMENTE_MULTA somando ambos', function () use ($simulator) {
    $debitos = [
        makeDebitoAtualizado('MULTA', '200.00', '210.00', '10.00', 5),
        makeDebitoAtualizado('MULTA', '300.00', '330.00', '30.00', 10),
    ];

    $opcoes = $simulator->gerarOpcoes($debitos);

    expect($opcoes)->toHaveCount(2);

    $soMulta = findOpcao($opcoes, 'SOMENTE_MULTA');
    expect((string) $soMulta->valorBase)->toBe('540.00');
});

// UT-GOLDEN-01 — exemplo completo do enunciado (tabela do Design 02 §5)
test('golden: IPVA 1800.00 + MULTA 555.93 produz tabela completa de pagamentos', function () {
    $referencia = new DateTimeImmutable('2024-05-10T00:00:00Z');

    $ipvaDebito = new Debito('IPVA', BigDecimal::of('1500.00'), new DateTimeImmutable('2024-01-10'));
    $multaDebito = new Debito('MULTA', BigDecimal::of('300.50'), new DateTimeImmutable('2024-02-15'));

    $debitoIpva = (new IpvaJurosStrategy())->calcular($ipvaDebito, $referencia);
    $debitoMulta = (new MultaJurosStrategy())->calcular($multaDebito, $referencia);

    $simulator = new PagamentoSimulator(new PixCalculator(), new CartaoCreditoCalculator());
    $opcoes = $simulator->gerarOpcoes([$debitoIpva, $debitoMulta]);

    // TOTAL — índice 0 é garantido pelo código
    $total = findOpcao($opcoes, 'TOTAL');
    expect((string) $total->valorBase)->toBe('2355.93')
        ->and((string) $total->pix->totalComDesconto)->toBe('2238.13')
        ->and((string) $total->cartaoCredito->parcelas[0]->valorParcela)->toBe('2355.93')
        ->and((string) $total->cartaoCredito->parcelas[1]->valorParcela)->toBe('427.72')
        ->and((string) $total->cartaoCredito->parcelas[2]->valorParcela)->toBe('229.67');

    // SOMENTE_IPVA
    $soIpva = findOpcao($opcoes, 'SOMENTE_IPVA');
    expect((string) $soIpva->valorBase)->toBe('1800.00')
        ->and((string) $soIpva->pix->totalComDesconto)->toBe('1710.00')
        ->and((string) $soIpva->cartaoCredito->parcelas[0]->valorParcela)->toBe('1800.00')
        ->and((string) $soIpva->cartaoCredito->parcelas[1]->valorParcela)->toBe('326.79')
        ->and((string) $soIpva->cartaoCredito->parcelas[2]->valorParcela)->toBe('175.48');

    // SOMENTE_MULTA
    $soMulta = findOpcao($opcoes, 'SOMENTE_MULTA');
    expect((string) $soMulta->valorBase)->toBe('555.93')
        ->and((string) $soMulta->pix->totalComDesconto)->toBe('528.13')
        ->and((string) $soMulta->cartaoCredito->parcelas[0]->valorParcela)->toBe('555.93')
        ->and((string) $soMulta->cartaoCredito->parcelas[1]->valorParcela)->toBe('100.93')
        ->and((string) $soMulta->cartaoCredito->parcelas[2]->valorParcela)->toBe('54.20');
});
