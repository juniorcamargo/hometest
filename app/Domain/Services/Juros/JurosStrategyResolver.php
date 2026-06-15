<?php

namespace App\Domain\Services\Juros;

use App\Domain\Contracts\JurosStrategyInterface;
use App\Domain\Contracts\JurosStrategyResolverInterface;
use App\Domain\Exceptions\UnknownDebtTypeException;

final class JurosStrategyResolver implements JurosStrategyResolverInterface
{
    /** @param JurosStrategyInterface[] $strategies */
    public function __construct(private readonly array $strategies) {}

    public function resolve(string $tipoDebito): JurosStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->suporta($tipoDebito)) {
                return $strategy;
            }
        }

        throw new UnknownDebtTypeException($tipoDebito);
    }
}
