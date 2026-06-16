<?php

namespace App\Http\Resources;

use App\Domain\ValueObjects\ResultadoConsulta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ResultadoConsultaResource extends JsonResource
{
    public function __construct(private readonly ResultadoConsulta $resultado)
    {
        parent::__construct($resultado);
    }

    public function toArray(Request $request): array
    {
        return [
            'placa' => $this->resultado->placa->valor,
            'debitos' => array_map(fn ($d) => [
                'tipo'             => $d->original->tipo,
                'valor_original'   => (string) $d->original->valorOriginal,
                'valor_atualizado' => (string) $d->valorAtualizado,
                'vencimento'       => $d->original->vencimento->format('Y-m-d'),
                'dias_atraso'      => $d->diasAtraso,
            ], $this->resultado->debitos),
            'resumo' => [
                'total_original'   => (string) $this->resultado->totalOriginal,
                'total_atualizado' => (string) $this->resultado->totalAtualizado,
            ],
            'pagamentos' => [
                'opcoes' => array_map(fn ($o) => [
                    'tipo'       => $o->tipo,
                    'valor_base' => (string) $o->valorBase,
                    'pix'        => [
                        'total_com_desconto' => (string) $o->pix->totalComDesconto,
                    ],
                    'cartao_credito' => [
                        'parcelas' => array_map(fn ($p) => [
                            'quantidade'    => $p->quantidade,
                            'valor_parcela' => (string) $p->valorParcela,
                        ], $o->cartaoCredito->parcelas),
                    ],
                ], $this->resultado->opcoesPagamento),
            ],
        ];
    }
}
