<?php

namespace App\Integrations\Providers;

use App\Domain\Contracts\DebtProviderInterface;
use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\Placa;
use Brick\Math\BigDecimal;

final class ProviderAJsonAdapter implements DebtProviderInterface
{
    public function __construct(private readonly ProviderClientInterface $client) {}

    public function nome(): string
    {
        return 'provider_a';
    }

    /** @return Debito[] */
    public function consultar(Placa $placa): array
    {
        $raw = $this->client->buscar($placa);

        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderUnavailableException($this->nome(), 'resposta JSON inválida', $e);
        }

        return array_map(
            fn (array $debt) => new Debito(
                tipo: strtoupper((string) $debt['type']),
                valorOriginal: BigDecimal::of((string) $debt['amount']),
                vencimento: \DateTimeImmutable::createFromFormat(
                    'Y-m-d',
                    $debt['due_date'],
                    new \DateTimeZone('UTC'),
                ),
            ),
            $data['debts'] ?? [],
        );
    }
}
