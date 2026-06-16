<?php

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;
use App\Integrations\Resilience\RetryingProviderClientDecorator;

// UT-RETRY-01
test('sempre falha: lança após 1 + retries tentativas', function () {
    $inner = new class implements ProviderClientInterface {
        public int $calls = 0;

        public function buscar(Placa $placa): string
        {
            $this->calls++;
            throw new ProviderUnavailableException('test', 'falha');
        }
    };

    $decorator = new RetryingProviderClientDecorator($inner, retries: 2, waitMs: 0);

    expect(fn () => $decorator->buscar(Placa::fromString('ABC1234')))
        ->toThrow(ProviderUnavailableException::class);

    expect($inner->calls)->toBe(3); // 1 inicial + 2 retries
});

// UT-RETRY-02
test('falha na primeira, sucesso na segunda: retorna payload sem lançar', function () {
    $inner = new class implements ProviderClientInterface {
        public int $calls = 0;

        public function buscar(Placa $placa): string
        {
            $this->calls++;
            if ($this->calls < 2) {
                throw new ProviderUnavailableException('test', 'falha transiente');
            }

            return '{"ok":true}';
        }
    };

    $decorator = new RetryingProviderClientDecorator($inner, retries: 2, waitMs: 0);

    $result = $decorator->buscar(Placa::fromString('ABC1234'));

    expect($result)->toBe('{"ok":true}');
    expect($inner->calls)->toBe(2);
});

// UT-RETRY-03
test('retries=0: lança sem nenhuma retentativa (1 tentativa total)', function () {
    $inner = new class implements ProviderClientInterface {
        public int $calls = 0;

        public function buscar(Placa $placa): string
        {
            $this->calls++;
            throw new ProviderUnavailableException('test', 'falha');
        }
    };

    $decorator = new RetryingProviderClientDecorator($inner, retries: 0, waitMs: 0);

    expect(fn () => $decorator->buscar(Placa::fromString('ABC1234')))
        ->toThrow(ProviderUnavailableException::class);

    expect($inner->calls)->toBe(1);
});
