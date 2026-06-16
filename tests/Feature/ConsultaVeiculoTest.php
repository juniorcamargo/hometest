<?php

use App\Domain\Contracts\DebtProviderInterface;
use App\Integrations\Providers\FixtureProviderAClient;
use App\Integrations\Providers\ProviderAJsonAdapter;

// FT-01
test('ABC1234 retorna 200 com JSON completo', function () {
    /** @var \Tests\TestCase $this */
    $this->postJson('/api/veiculos/debitos', ['placa' => 'ABC1234'])
        ->assertStatus(200)
        ->assertExactJson([
            'data' => [
                'placa' => 'ABC1234',
                'debitos' => [
                    [
                        'tipo'             => 'IPVA',
                        'valor_original'   => '1500.00',
                        'valor_atualizado' => '1800.00',
                        'vencimento'       => '2024-01-10',
                        'dias_atraso'      => 121,
                    ],
                    [
                        'tipo'             => 'MULTA',
                        'valor_original'   => '300.50',
                        'valor_atualizado' => '555.93',
                        'vencimento'       => '2024-02-15',
                        'dias_atraso'      => 85,
                    ],
                ],
                'resumo' => [
                    'total_original'   => '1800.50',
                    'total_atualizado' => '2355.93',
                ],
                'pagamentos' => [
                    'opcoes' => [
                        [
                            'tipo'       => 'TOTAL',
                            'valor_base' => '2355.93',
                            'pix'        => ['total_com_desconto' => '2238.13'],
                            'cartao_credito' => [
                                'parcelas' => [
                                    ['quantidade' => 1,  'valor_parcela' => '2355.93'],
                                    ['quantidade' => 6,  'valor_parcela' => '427.72'],
                                    ['quantidade' => 12, 'valor_parcela' => '229.67'],
                                ],
                            ],
                        ],
                        [
                            'tipo'       => 'SOMENTE_IPVA',
                            'valor_base' => '1800.00',
                            'pix'        => ['total_com_desconto' => '1710.00'],
                            'cartao_credito' => [
                                'parcelas' => [
                                    ['quantidade' => 1,  'valor_parcela' => '1800.00'],
                                    ['quantidade' => 6,  'valor_parcela' => '326.79'],
                                    ['quantidade' => 12, 'valor_parcela' => '175.48'],
                                ],
                            ],
                        ],
                        [
                            'tipo'       => 'SOMENTE_MULTA',
                            'valor_base' => '555.93',
                            'pix'        => ['total_com_desconto' => '528.13'],
                            'cartao_credito' => [
                                'parcelas' => [
                                    ['quantidade' => 1,  'valor_parcela' => '555.93'],
                                    ['quantidade' => 6,  'valor_parcela' => '100.93'],
                                    ['quantidade' => 12, 'valor_parcela' => '54.20'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
});

// FT-02 (CB-01)
test('placa sem débitos retorna 200 com debitos vazio e totais zerados', function () {
    /** @var \Tests\TestCase $this */
    $this->postJson('/api/veiculos/debitos', ['placa' => 'ZZZ9999'])
        ->assertStatus(200)
        ->assertExactJson([
            'data' => [
                'placa'   => 'ZZZ9999',
                'debitos' => [],
                'resumo'  => [
                    'total_original'   => '0.00',
                    'total_atualizado' => '0.00',
                ],
                'pagamentos' => [
                    'opcoes' => [
                        [
                            'tipo'       => 'TOTAL',
                            'valor_base' => '0.00',
                            'pix'        => ['total_com_desconto' => '0.00'],
                            'cartao_credito' => [
                                'parcelas' => [
                                    ['quantidade' => 1,  'valor_parcela' => '0.00'],
                                    ['quantidade' => 6,  'valor_parcela' => '0.00'],
                                    ['quantidade' => 12, 'valor_parcela' => '0.00'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
});

// FT-03 (CB-03)
test('tipo de débito desconhecido retorna 422 com unknown_debt_type', function () {
    /** @var \Tests\TestCase $this */
    $this->app->bind(
        DebtProviderInterface::class,
        fn() =>
        new ProviderAJsonAdapter(
            new FixtureProviderAClient([
                'ZZZ1234' => [['type' => 'OUTROS', 'amount' => '100.00', 'due_date' => '2024-01-10']],
            ])
        )
    );

    $this->postJson('/api/veiculos/debitos', ['placa' => 'ZZZ1234'])
        ->assertStatus(422)
        ->assertExactJson(['error' => 'unknown_debt_type', 'type' => 'OUTROS']);
});

// FT-04 (CB-05)
test('placa inválida retorna 400 com invalid_plate', function () {
    /** @var \Tests\TestCase $this */
    $this->postJson('/api/veiculos/debitos', ['placa' => 'AB1'])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_plate']);
});
