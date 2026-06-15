<?php

namespace App\Domain\Exceptions;

final class UnknownDebtTypeException extends DomainException
{
    public function __construct(public readonly string $tipo)
    {
        parent::__construct("Tipo de débito desconhecido: {$tipo}");
    }
}
