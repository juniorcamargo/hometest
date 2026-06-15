<?php

namespace App\Domain\Exceptions;

final class AllProvidersUnavailableException extends DomainException
{
    /** @param array<string,string> $tentativas provider => motivo da falha */
    public function __construct(public readonly array $tentativas)
    {
        parent::__construct('Todos os provedores estão indisponíveis');
    }
}
