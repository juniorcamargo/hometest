<?php

namespace App\Integrations\Providers;

final class FixtureDebtDataStore
{
    /**
     * Dataset canônico padrão — agnóstico de formato de provedor.
     *
     * @return array<string, list<array{type:string,amount:string,due_date:string}>>
     */
    public static function default(): array
    {
        return [
            'ABC1234' => [
                ['type' => 'IPVA',  'amount' => '1500.00', 'due_date' => '2024-01-10'],
                ['type' => 'MULTA', 'amount' => '300.50',  'due_date' => '2024-02-15'],
            ],
        ];
    }
}
