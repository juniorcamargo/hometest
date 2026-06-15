<?php

namespace App\Domain\ValueObjects;

final class CartaoCreditoOpcao
{
    /** @param ParcelaCartao[] $parcelas */
    public function __construct(public readonly array $parcelas) {}
}
