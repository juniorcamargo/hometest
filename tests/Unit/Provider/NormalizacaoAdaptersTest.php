<?php

use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\FixtureProviderAClient;
use App\Integrations\Providers\FixtureProviderBClient;
use App\Integrations\Providers\ProviderAJsonAdapter;
use App\Integrations\Providers\ProviderBXmlAdapter;

// UT-NORM-01 — equivalência A vs B com o dataset padrão (ABC1234)
it('adapters A e B produzem Débitos equivalentes para a mesma placa', function () {
    $placa = Placa::fromString('ABC1234');

    $adapterA = new ProviderAJsonAdapter(new FixtureProviderAClient());
    $adapterB = new ProviderBXmlAdapter(new FixtureProviderBClient());

    $debitosA = $adapterA->consultar($placa);
    $debitosB = $adapterB->consultar($placa);

    expect($debitosA)->toHaveCount(2);
    expect($debitosB)->toHaveCount(2);

    foreach (range(0, 1) as $i) {
        expect($debitosA[$i]->tipo)->toBe($debitosB[$i]->tipo);
        expect((string) $debitosA[$i]->valorOriginal)->toBe((string) $debitosB[$i]->valorOriginal);
        expect($debitosA[$i]->vencimento->format('Y-m-d'))->toBe($debitosB[$i]->vencimento->format('Y-m-d'));
    }
});

// UT-NORM-02 — fonte compartilhada: mesmo array passado a ambos os clients
it('ambos os clients produzem Débitos idênticos a partir da mesma fonte de dados', function () {
    $data = [
        'XYZ1A23' => [
            ['type' => 'IPVA', 'amount' => '750.00', 'due_date' => '2024-03-01'],
        ],
    ];
    $placa = Placa::fromString('XYZ1A23');

    $adapterA = new ProviderAJsonAdapter(new FixtureProviderAClient($data));
    $adapterB = new ProviderBXmlAdapter(new FixtureProviderBClient($data));

    $debitosA = $adapterA->consultar($placa);
    $debitosB = $adapterB->consultar($placa);

    expect($debitosA)->toHaveCount(1);
    expect($debitosB)->toHaveCount(1);
    expect((string) $debitosA[0]->valorOriginal)->toBe((string) $debitosB[0]->valorOriginal);
    expect($debitosA[0]->tipo)->toBe($debitosB[0]->tipo);
    expect($debitosA[0]->vencimento->format('Y-m-d'))->toBe($debitosB[0]->vencimento->format('Y-m-d'));
});

// UT-NORM-03 — bancos divergentes: placa existe em A mas não em B
it('simula banco divergente: Provider A encontra a placa, Provider B não encontra', function () {
    $dataA = [
        'XYZ1A23' => [
            ['type' => 'MULTA', 'amount' => '200.00', 'due_date' => '2024-02-01'],
        ],
    ];
    $dataB = []; // banco B não tem essa placa

    $placa = Placa::fromString('XYZ1A23');

    $adapterA = new ProviderAJsonAdapter(new FixtureProviderAClient($dataA));
    $adapterB = new ProviderBXmlAdapter(new FixtureProviderBClient($dataB));

    expect($adapterA->consultar($placa))->toHaveCount(1);
    expect($adapterB->consultar($placa))->toBe([]);
});
