<?php

namespace App\Domain\Contracts;

interface JurosStrategyResolverInterface
{
    /** @throws \App\Domain\Exceptions\UnknownDebtTypeException */
    public function resolve(string $tipoDebito): JurosStrategyInterface;
}
