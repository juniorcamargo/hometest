<?php

use App\Domain\Contracts\DebtProviderInterface;
use App\Integrations\Providers\FixtureProviderBClient;
use App\Integrations\Providers\ProviderAJsonAdapter;
use App\Integrations\Providers\ProviderBXmlAdapter;
use App\Integrations\Resilience\AlwaysFailingProviderClient;
use App\Integrations\Resilience\ProviderFallbackOrchestrator;

// IT-01 (CB-02)
test('todos os provedores falhando retorna 503', function () {
    /** @var \Tests\TestCase $this */
    $this->app->bind(DebtProviderInterface::class, fn () =>
        new ProviderFallbackOrchestrator([
            new ProviderAJsonAdapter(new AlwaysFailingProviderClient()),
            new ProviderBXmlAdapter(new AlwaysFailingProviderClient()),
        ])
    );

    $this->postJson('/api/veiculos/debitos', ['placa' => 'ABC1234'])
        ->assertStatus(503)
        ->assertExactJson(['error' => 'all_providers_unavailable']);
});

// IT-02
test('provedor A falha e provedor B responde com fallback transparente', function () {
    /** @var \Tests\TestCase $this */
    $this->app->bind(DebtProviderInterface::class, fn () =>
        new ProviderFallbackOrchestrator([
            new ProviderAJsonAdapter(new AlwaysFailingProviderClient()),
            new ProviderBXmlAdapter(new FixtureProviderBClient()),
        ])
    );

    $this->postJson('/api/veiculos/debitos', ['placa' => 'ABC1234'])
        ->assertStatus(200)
        ->assertExactJson([
            'data' => [
                'placa'   => 'ABC1234',
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
