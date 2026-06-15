# Design 03 — Adapters de Provedores

**Status:** Final.
**Depende de:** `01-domain-model.md` (`Debito`, `Placa`, `DebtProviderInterface`,
`ProviderUnavailableException`).
**Requisitos relacionados:** REQ-PROV-01, REQ-PROV-04, REQ-PROV-05,
REQ-NORM-01, REQ-NORM-02.

## 1. Visão Geral

Dois Adapters — `ProviderAJsonAdapter` (JSON) e `ProviderBXmlAdapter` (XML)
— implementam `DebtProviderInterface`, traduzindo o formato nativo de cada
provedor para `Debito[]` (modelo canônico).

Cada Adapter delega a **obtenção** do payload bruto a um `ProviderClientInterface`
(fronteira de I/O) — separando "buscar dados" de "traduzir dados". Isso é o
que permite, no Design 04, envolver o Client em Decorators de retry/circuit
breaker/simulação de falha **sem tocar nos Adapters**.

```
ProviderAJsonAdapter  --usa-->  ProviderClientInterface (Infrastructure)
ProviderBXmlAdapter   --usa-->  ProviderClientInterface (Infrastructure)
```

> `ProviderClientInterface` é um contrato **interno da Infrastructure**
> (`App\Integrations\Providers`) — não é um port do `Domain`. O `Domain` só
> conhece `DebtProviderInterface`.

## 2. `ProviderClientInterface`

```php
namespace App\Integrations\Providers;

use App\Domain\ValueObjects\Placa;

interface ProviderClientInterface
{
    /**
     * Retorna o payload bruto (string JSON ou XML) do provedor.
     *
     * @throws \App\Domain\Exceptions\ProviderUnavailableException
     */
    public function buscar(Placa $placa): string;
}
```

## 3. `ProviderAJsonAdapter`

Formato de entrada:

```json
{
  "vehicle": "ABC1234",
  "debts": [
    { "type": "IPVA",  "amount": 1500.00, "due_date": "2024-01-10" },
    { "type": "MULTA", "amount":  300.50, "due_date": "2024-02-15" }
  ]
}
```

```php
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
                valorOriginal: BigDecimal::of($debt['amount']),
                vencimento: \DateTimeImmutable::createFromFormat(
                    'Y-m-d',
                    $debt['due_date'],
                    new \DateTimeZone('UTC'),
                ),
            ),
            $data['debts'] ?? [], // lista vazia é válida (REQ-PROV-04)
        );
    }
}
```

## 4. `ProviderBXmlAdapter`

Formato de entrada (e o caso `<debts/>` autofechado quando vazio):

```xml
<response>
  <plate>ABC1234</plate>
  <debts>
    <debt><category>IPVA</category><value>1500.00</value><expiration>2024-01-10</expiration></debt>
    <debt><category>MULTA</category><value>300.50</value><expiration>2024-02-15</expiration></debt>
  </debts>
</response>
```

```php
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

        $xml = @simplexml_load_string($raw);
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

        return $debitos; // <debts/> -> $xml->debts->debt itera zero vezes -> []
    }
}
```

> `<debts/>` (autofechado, zero filhos) faz `$xml->debts->debt` ser um
> `SimpleXMLElement` vazio — o `foreach` simplesmente não itera, retornando
> `[]` naturalmente. Nenhum `if` especial é necessário (REQ-PROV-04).

## 5. Clientes Simulados (Fixtures)

Como o enunciado pede provedores **simulados**, cada Client tem um pequeno
mapa `placa => payload bruto`, com `ABC1234` reproduzindo exatamente os
exemplos do enunciado. Placas não mapeadas retornam "zero débitos" no
formato nativo de cada provedor — cobrindo CB-01 desde já.

```php
namespace App\Integrations\Providers;

use App\Domain\ValueObjects\Placa;

final class FixtureProviderAClient implements ProviderClientInterface
{
    /** @param array<string,string> $extra payloads adicionais (testes) */
    public function __construct(private readonly array $extra = []) {}

    public function buscar(Placa $placa): string
    {
        $fixtures = $this->extra + [
            'ABC1234' => json_encode([
                'vehicle' => 'ABC1234',
                'debts' => [
                    ['type' => 'IPVA',  'amount' => 1500.00, 'due_date' => '2024-01-10'],
                    ['type' => 'MULTA', 'amount' => 300.50,  'due_date' => '2024-02-15'],
                ],
            ]),
        ];

        return $fixtures[$placa->valor] ?? json_encode([
            'vehicle' => $placa->valor,
            'debts' => [],
        ]);
    }
}
```

```php
namespace App\Integrations\Providers;

use App\Domain\ValueObjects\Placa;

final class FixtureProviderBClient implements ProviderClientInterface
{
    /** @param array<string,string> $extra payloads adicionais (testes) */
    public function __construct(private readonly array $extra = []) {}

    public function buscar(Placa $placa): string
    {
        $fixtures = $this->extra + [
            'ABC1234' => <<<XML
                <response>
                  <plate>ABC1234</plate>
                  <debts>
                    <debt><category>IPVA</category><value>1500.00</value><expiration>2024-01-10</expiration></debt>
                    <debt><category>MULTA</category><value>300.50</value><expiration>2024-02-15</expiration></debt>
                  </debts>
                </response>
                XML,
        ];

        return $fixtures[$placa->valor] ?? <<<XML
            <response>
              <plate>{$placa->valor}</plate>
              <debts/>
            </response>
            XML;
    }
}
```

### Binding (contextual — cada Adapter recebe seu próprio Client)

```php
// DomainServiceProvider (00-architecture.md §5.3)
$this->app->when(ProviderAJsonAdapter::class)
    ->needs(ProviderClientInterface::class)
    ->give(FixtureProviderAClient::class);

$this->app->when(ProviderBXmlAdapter::class)
    ->needs(ProviderClientInterface::class)
    ->give(FixtureProviderBClient::class);
```

> Quando um novo provedor "real" (HTTP) for adicionado, basta criar um
> `HttpProviderXClient implements ProviderClientInterface` e trocar o
> binding — Adapter e Domain permanecem intocados (REQ-PROV-05).

## 6. Adendo ao Design 01 — Normalização de `valorOriginal`

**Aplicado:** o construtor de `Debito` (01-domain-model.md §2.2) agora
normaliza `valorOriginal` para 2 casas (HALF_UP), da mesma forma que já
normalizava `vencimento` para meia-noite UTC.

Motivo: o Provedor A entrega `amount` como número JSON (`1500.00` →
`float 1500.0` → `(string) "1500"`, escala 0), enquanto o Provedor B
entrega `value` como texto XML (`"1500.00"`, escala 2). Sem normalização,
`Debito`s equivalentes vindos de A e de B teriam `BigDecimal`s com escalas
diferentes — funcionalmente corretos (BigDecimal opera bem entre escalas),
mas inconsistentes para comparação/serialização direta. Normalizar na
fronteira do Value Object resolve isso de uma vez, para todos os Adapters
presentes e futuros.

## 7. Casos de Teste (Pest)

| ID | Descrição | Entrada | Esperado | Requisito |
|---|---|---|---|---|
| UT-ADAPTERA-01 | Parse JSON completo | fixture A, `ABC1234` | 2 `Debito`: `IPVA 1500.00/2024-01-10`, `MULTA 300.50/2024-02-15` | REQ-NORM-01 |
| UT-ADAPTERA-02 | Placa sem débitos | fixture A, placa não mapeada | `[]` | REQ-PROV-04 |
| UT-ADAPTERA-03 | JSON malformado | payload inválido | `ProviderUnavailableException` | REQ-PROV-02 |
| UT-ADAPTERB-01 | Parse XML completo | fixture B, `ABC1234` | 2 `Debito` equivalentes ao A | REQ-NORM-01 |
| UT-ADAPTERB-02 | `<debts/>` autofechado | fixture B, placa não mapeada | `[]` | REQ-PROV-04 |
| UT-ADAPTERB-03 | XML malformado | payload inválido | `ProviderUnavailableException` | REQ-PROV-02 |
| UT-NORM-01 | Equivalência A vs B | mesma placa, ambos adapters | `Debito[]` com mesmos `tipo`/`valorOriginal`(escala 2)/`vencimento` | REQ-NORM-01/02 |

## 8. Critérios de Aceite

- [ ] `ProviderAJsonAdapter`/`ProviderBXmlAdapter` não conhecem `Placa::mascarada()`,
      `JurosStrategy` ou qualquer coisa de pagamento — só traduzem formato.
- [ ] `<debts/>` e `"debts": []` produzem `[]` sem exceção.
- [ ] `Debito::$valorOriginal` sempre tem escala 2, independente do adapter
      de origem (UT-NORM-01).
- [ ] Payload malformado em qualquer adapter lança `ProviderUnavailableException`
      (nunca deixa exceção genérica de parsing escapar).

## 9. Fora de Escopo

- `ProviderFallbackOrchestrator`, retry, circuit breaker e simulação
  configurável de falha (decorators sobre `ProviderClientInterface`) →
  **Design 04**.
- Binding final do `DebtProviderInterface` (composto pelo orquestrador) →
  **Design 04** + `00-architecture.md` §5.3.
