<?php

namespace App\Domain\Exceptions;

final class ProviderUnavailableException extends \RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $motivo,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("Provider {$provider} indisponível: {$motivo}", 0, $previous);
    }
}
