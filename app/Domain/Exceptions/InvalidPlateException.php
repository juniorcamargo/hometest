<?php

namespace App\Domain\Exceptions;

final class InvalidPlateException extends DomainException
{
    public function __construct(public readonly string $placaOriginal)
    {
        parent::__construct("Placa inválida: {$placaOriginal}");
    }
}
