<?php

namespace App\Domain\Contracts;

use App\Domain\ValueObjects\DebitoAtualizado;
use App\Domain\ValueObjects\OpcaoPagamento;

interface PaymentSimulatorInterface
{
    /**
     * @param DebitoAtualizado[] $debitos
     * @return OpcaoPagamento[]
     */
    public function gerarOpcoes(array $debitos): array;
}
