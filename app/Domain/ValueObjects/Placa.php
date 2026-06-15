<?php

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\InvalidPlateException;

final class Placa
{
    private function __construct(public readonly string $valor) {}

    public static function fromString(string $valor): self
    {
        $normalizado = strtoupper(trim($valor));

        if (!self::isValid($normalizado)) {
            throw new InvalidPlateException($valor);
        }

        return new self($normalizado);
    }

    public static function isValid(string $valor): bool
    {
        return (bool) preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $valor);
    }

    public function mascarada(): string
    {
        return substr($this->valor, 0, 3) . '**' . substr($this->valor, -2);
    }
}
