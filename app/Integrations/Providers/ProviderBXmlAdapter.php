<?php

namespace App\Integrations\Providers;

use App\Domain\Contracts\DebtProviderInterface;
use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\Placa;
use Brick\Math\BigDecimal;

final class ProviderBXmlAdapter implements DebtProviderInterface
{
    public function __construct(private readonly ProviderClientInterface $client) {}

    public function nome(): string
    {
        return 'provider_b';
    }

    /** @return Debito[] */
    public function consultar(Placa $placa): array
    {
        $raw = $this->client->buscar($placa);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if ($xml === false) {
            throw new ProviderUnavailableException($this->nome(), 'resposta XML inválida');
        }

        $debitos = [];
        foreach ($xml->debts->debt as $debt) {
            $debitos[] = new Debito(
                tipo: strtoupper((string) $debt->category),
                valorOriginal: BigDecimal::of((string) $debt->value),
                vencimento: \DateTimeImmutable::createFromFormat(
                    'Y-m-d',
                    (string) $debt->expiration,
                    new \DateTimeZone('UTC'),
                ),
            );
        }

        return $debitos;
    }
}
