<?php

namespace App\Integrations\Providers;

use App\Domain\ValueObjects\Placa;

final class FixtureProviderAClient implements ProviderClientInterface
{
    /**
     * @param array<string, list<array{type:string,amount:string,due_date:string}>>|null $data
     *        null = usa FixtureDebtDataStore::default(); array = substitui inteiramente o dataset.
     */
    public function __construct(private readonly ?array $data = null) {}

    public function buscar(Placa $placa): string
    {
        $store = $this->data ?? FixtureDebtDataStore::default();
        $debts = $store[$placa->valor] ?? [];

        return json_encode([
            'vehicle' => $placa->valor,
            'debts' => array_map(
                fn (array $d) => [
                    'type'     => $d['type'],
                    'amount'   => $d['amount'],
                    'due_date' => $d['due_date'],
                ],
                $debts,
            ),
        ]);
    }
}
