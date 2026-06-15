<?php

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\FixtureProviderAClient;
use App\Integrations\Providers\ProviderAJsonAdapter;
use App\Integrations\Providers\ProviderClientInterface;
use Brick\Math\BigDecimal;

function makeAdapterA(string $payload): ProviderAJsonAdapter
{
    $placa = Placa::fromString('ABC1234');
    $client = new class ($payload) implements ProviderClientInterface {
        public function __construct(private readonly string $payload) {}
        public function buscar(Placa $placa): string { return $this->payload; }
    };
    return new ProviderAJsonAdapter($client);
}

// UT-ADAPTERA-01 — parse JSON completo com fixture ABC1234
it('parseia JSON completo retornando 2 débitos canônicos', function () {
    $adapter = new ProviderAJsonAdapter(new FixtureProviderAClient());
    $placa = Placa::fromString('ABC1234');

    $debitos = $adapter->consultar($placa);

    expect($debitos)->toHaveCount(2);

    expect($debitos[0]->tipo)->toBe('IPVA');
    expect($debitos[0]->valorOriginal)->toEqual(BigDecimal::of('1500.00'));
    expect($debitos[0]->vencimento->format('Y-m-d'))->toBe('2024-01-10');

    expect($debitos[1]->tipo)->toBe('MULTA');
    expect($debitos[1]->valorOriginal)->toEqual(BigDecimal::of('300.50'));
    expect($debitos[1]->vencimento->format('Y-m-d'))->toBe('2024-02-15');
});

// UT-ADAPTERA-02 — placa sem débitos retorna array vazio
it('retorna array vazio para placa não mapeada', function () {
    $adapter = new ProviderAJsonAdapter(new FixtureProviderAClient());
    $placa = Placa::fromString('ZZZ9999');

    $debitos = $adapter->consultar($placa);

    expect($debitos)->toBe([]);
});

// UT-ADAPTERA-03 — JSON malformado lança ProviderUnavailableException
it('lança ProviderUnavailableException para JSON malformado', function () {
    $adapter = makeAdapterA('not valid json {{{');
    $placa = Placa::fromString('ABC1234');

    $adapter->consultar($placa);
})->throws(ProviderUnavailableException::class);
