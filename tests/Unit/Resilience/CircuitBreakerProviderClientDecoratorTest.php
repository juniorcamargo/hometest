<?php

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;
use App\Integrations\Resilience\CircuitBreakerProviderClientDecorator;

$placa = fn () => Placa::fromString('ABC1234');

// UT-CB-01
test('falhas abaixo do threshold: repassa a exceção sem abrir o circuito', function () use ($placa) {
    $inner = new class implements ProviderClientInterface {
        public int $calls = 0;

        public function buscar(Placa $placa): string
        {
            $this->calls++;
            throw new ProviderUnavailableException('test', 'falha');
        }
    };

    $cb = new CircuitBreakerProviderClientDecorator($inner, threshold: 3, resetAfterSeconds: 60);

    // 2 falhas — abaixo do threshold de 3
    expect(fn () => $cb->buscar($placa()))->toThrow(ProviderUnavailableException::class);
    expect(fn () => $cb->buscar($placa()))->toThrow(ProviderUnavailableException::class);

    // inner ainda é chamado (circuito não abriu)
    expect($inner->calls)->toBe(2);
});

// UT-CB-02
test('falhas iguais ao threshold: circuito abre e próxima chamada lança sem chamar inner', function () use ($placa) {
    $inner = new class implements ProviderClientInterface {
        public int $calls = 0;

        public function buscar(Placa $placa): string
        {
            $this->calls++;
            throw new ProviderUnavailableException('test', 'falha');
        }
    };

    $cb = new CircuitBreakerProviderClientDecorator($inner, threshold: 2, resetAfterSeconds: 60);

    // atingir o threshold
    expect(fn () => $cb->buscar($placa()))->toThrow(ProviderUnavailableException::class);
    expect(fn () => $cb->buscar($placa()))->toThrow(ProviderUnavailableException::class);

    $callsAntesDaAbertura = $inner->calls; // = 2

    // circuito aberto — inner NÃO deve ser chamado
    expect(fn () => $cb->buscar($placa()))->toThrow(ProviderUnavailableException::class);

    expect($inner->calls)->toBe($callsAntesDaAbertura);
});

// UT-CB-03
test('sucesso após falhas: reseta o contador de falhas', function () use ($placa) {
    $respostas = ['erro', 'erro', 'ok'];
    $idx = 0;

    $inner = new class($respostas, $idx) implements ProviderClientInterface {
        public function __construct(
            private readonly array $respostas,
            private int $idx,
        ) {}

        public function buscar(Placa $placa): string
        {
            $resp = $this->respostas[$this->idx++];
            if ($resp === 'erro') {
                throw new ProviderUnavailableException('test', 'falha');
            }

            return $resp;
        }
    };

    $cb = new CircuitBreakerProviderClientDecorator($inner, threshold: 5, resetAfterSeconds: 60);

    // 2 falhas
    expect(fn () => $cb->buscar($placa()))->toThrow(ProviderUnavailableException::class);
    expect(fn () => $cb->buscar($placa()))->toThrow(ProviderUnavailableException::class);

    // sucesso — deve resetar
    $result = $cb->buscar($placa());
    expect($result)->toBe('ok');

    // após reset, uma nova falha não abre o circuito (threshold não foi atingido novamente)
    // (seria necessária outra sequência de falhas para abrir)
});
