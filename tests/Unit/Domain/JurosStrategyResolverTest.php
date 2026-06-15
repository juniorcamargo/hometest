<?php

use App\Domain\Contracts\JurosStrategyInterface;
use App\Domain\Exceptions\UnknownDebtTypeException;
use App\Domain\Services\Juros\IpvaJurosStrategy;
use App\Domain\Services\Juros\JurosStrategyResolver;
use App\Domain\Services\Juros\MultaJurosStrategy;
use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\DebitoAtualizado;
use Brick\Math\BigDecimal;

// UT-RESOLVER-01
test('tipo desconhecido lança UnknownDebtTypeException com o tipo correto', function () {
    $resolver = new JurosStrategyResolver([new IpvaJurosStrategy(), new MultaJurosStrategy()]);

    expect(fn () => $resolver->resolve('LICENCIAMENTO'))
        ->toThrow(UnknownDebtTypeException::class);

    try {
        $resolver->resolve('LICENCIAMENTO');
    } catch (UnknownDebtTypeException $e) {
        expect($e->tipo)->toBe('LICENCIAMENTO');
    }
});

// UT-RESOLVER-02 — extensibilidade OCP: Strategy fake adicional sem editar Resolver
test('estratégia fake adicional é resolvida sem alterar Resolver nem Strategies existentes', function () {
    $fakeStrategy = new class implements JurosStrategyInterface {
        public function suporta(string $tipoDebito): bool
        {
            return $tipoDebito === 'TAXA_AMBIENTAL';
        }

        public function calcular(Debito $debito, \DateTimeImmutable $dataReferencia): DebitoAtualizado
        {
            return new DebitoAtualizado($debito, $debito->valorOriginal, BigDecimal::zero(), 0);
        }
    };

    $resolver = new JurosStrategyResolver([
        new IpvaJurosStrategy(),
        new MultaJurosStrategy(),
        $fakeStrategy,
    ]);

    $strategy = $resolver->resolve('TAXA_AMBIENTAL');

    expect($strategy->suporta('TAXA_AMBIENTAL'))->toBeTrue();
});

test('resolve IPVA e MULTA corretamente', function () {
    $resolver = new JurosStrategyResolver([new IpvaJurosStrategy(), new MultaJurosStrategy()]);

    expect($resolver->resolve('IPVA'))->toBeInstanceOf(IpvaJurosStrategy::class)
        ->and($resolver->resolve('MULTA'))->toBeInstanceOf(MultaJurosStrategy::class);
});
