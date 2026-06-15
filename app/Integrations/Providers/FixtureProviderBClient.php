<?php

namespace App\Integrations\Providers;

use App\Domain\ValueObjects\Placa;

final class FixtureProviderBClient implements ProviderClientInterface
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

        if (empty($debts)) {
            return "<response><plate>{$placa->valor}</plate><debts/></response>";
        }

        $debtXml = implode('', array_map(
            fn (array $d) => "<debt>"
                . "<category>{$d['type']}</category>"
                . "<value>{$d['amount']}</value>"
                . "<expiration>{$d['due_date']}</expiration>"
                . "</debt>",
            $debts,
        ));

        return "<response><plate>{$placa->valor}</plate><debts>{$debtXml}</debts></response>";
    }
}
