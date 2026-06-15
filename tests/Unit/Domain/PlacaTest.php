<?php

use App\Domain\Exceptions\InvalidPlateException;
use App\Domain\ValueObjects\Placa;

// UT-PLACA-01
test('aceita placa no formato antigo', function () {
    $placa = Placa::fromString('ABC1234');

    expect($placa->valor)->toBe('ABC1234');
});

// UT-PLACA-02
test('aceita placa no formato Mercosul', function () {
    $placa = Placa::fromString('ABC1D23');

    expect($placa->valor)->toBe('ABC1D23');
});

test('normaliza para maiúsculas', function () {
    $placa = Placa::fromString('abc1234');

    expect($placa->valor)->toBe('ABC1234');
});

// UT-PLACA-03
test('rejeita placa fora do padrão', function (string $invalida) {
    expect(fn () => Placa::fromString($invalida))
        ->toThrow(InvalidPlateException::class);
})->with(['AB1234', '1234ABC', 'ABC12345', 'AB1', 'ABCD234', 'ABC123']);

test('mascarada oculta os caracteres centrais', function () {
    $placa = Placa::fromString('ABC1234');

    expect($placa->mascarada())->toBe('ABC**34');
});
