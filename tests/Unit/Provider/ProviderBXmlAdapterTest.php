<?php

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\FixtureProviderBClient;
use App\Integrations\Providers\ProviderBXmlAdapter;
use App\Integrations\Providers\ProviderClientInterface;
use Brick\Math\BigDecimal;

function makeAdapterB(string $payload): ProviderBXmlAdapter
{
    $client = new class ($payload) implements ProviderClientInterface {
        public function __construct(private readonly string $payload) {}
        public function buscar(Placa $placa): string { return $this->payload; }
    };
    return new ProviderBXmlAdapter($client);
}

// UT-ADAPTERB-01 — parse XML completo com fixture ABC1234
it('parseia XML completo retornando 2 débitos canônicos', function () {
    $adapter = new ProviderBXmlAdapter(new FixtureProviderBClient());
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

// UT-ADAPTERB-02 — <debts/> autofechado retorna array vazio
it('retorna array vazio para <debts/> autofechado', function () {
    $adapter = new ProviderBXmlAdapter(new FixtureProviderBClient());
    $placa = Placa::fromString('ZZZ9999');

    $debitos = $adapter->consultar($placa);

    expect($debitos)->toBe([]);
});

// UT-ADAPTERB-03 — XML malformado lança ProviderUnavailableException
it('lança ProviderUnavailableException para XML malformado', function () {
    $adapter = makeAdapterB('<response><unclosed>');
    $placa = Placa::fromString('ABC1234');

    $adapter->consultar($placa);
})->throws(ProviderUnavailableException::class);
