# Design 04 — Resiliência e Orquestração de Provedores

**Status:** Final.
**Depende de:** `03-provider-adapters.md` (`ProviderClientInterface`,
`ProviderFallbackOrchestrator`, `ProviderUnavailableException`).
**Requisitos relacionados:** REQ-PROV-02, REQ-PROV-03, REQ-PROV-06, REQ-PROV-07.

## 1. Visão Geral

A resiliência é implementada como Decorators sobre `ProviderClientInterface`
(fronteira de I/O interna da infra), não sobre `DebtProviderInterface` (port do
domain). Os Adapters existentes (`ProviderAJsonAdapter`, `ProviderBXmlAdapter`)
permanecem sem alteração — apenas o Client que cada um recebe muda.

Cadeia de chamada por provedor:

```
ProviderFallbackOrchestrator          (DebtProviderInterface)
  └── ProviderAJsonAdapter            (DebtProviderInterface)
        └── CircuitBreakerProviderClientDecorator   ← novo
              └── RetryingProviderClientDecorator   ← novo
                    └── FixtureProviderAClient      (ProviderClientInterface)
  └── ProviderBXmlAdapter
        └── CircuitBreakerProviderClientDecorator
              └── RetryingProviderClientDecorator
                    └── FixtureProviderBClient
```

> **Por que decorar o Client e não o Adapter?**
> O Adapter tem responsabilidade de traduzir formato (JSON/XML → `Debito[]`).
> Retry e circuit breaker são preocupações de transporte — pertencem à camada
> de obtenção do payload bruto (`ProviderClientInterface`), não à de parsing.
> Separar permite testar cada responsabilidade de forma isolada.

## 2. `RetryingProviderClientDecorator`

Implementa `ProviderClientInterface`. Em caso de `ProviderUnavailableException`,
tenta novamente até `$retries` vezes adicionais (total de `$retries + 1` tentativas),
com um intervalo de `$waitMs` ms entre cada tentativa.

```php
namespace App\Integrations\Resilience;

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;

final class RetryingProviderClientDecorator implements ProviderClientInterface
{
    public function __construct(
        private readonly ProviderClientInterface $inner,
        private readonly int $retries = 3,
        private readonly int $waitMs = 200,
    ) {}

    public function buscar(Placa $placa): string
    {
        $last = null;

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            if ($attempt > 0 && $this->waitMs > 0) {
                usleep($this->waitMs * 1000);
            }

            try {
                return $this->inner->buscar($placa);
            } catch (ProviderUnavailableException $e) {
                $last = $e;
            }
        }

        throw $last;
    }
}
```

**Parâmetros:**
- `$retries = 3`: número de retentativas após a primeira falha (total: 4 tentativas).
- `$waitMs = 0` em testes: evita sleeps desnecessários na suíte.

## 3. `CircuitBreakerProviderClientDecorator`

Implementa `ProviderClientInterface`. Conta falhas consecutivas; ao atingir
`$threshold`, "abre" o circuito por `$resetAfterSeconds` segundos — chamadas
subsequentes lançam `ProviderUnavailableException` imediatamente, sem acionar o
inner. Um sucesso reseta o contador.

```php
namespace App\Integrations\Resilience;

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;

final class CircuitBreakerProviderClientDecorator implements ProviderClientInterface
{
    private int $failureCount = 0;
    private ?float $openUntil = null;

    public function __construct(
        private readonly ProviderClientInterface $inner,
        private readonly int $threshold = 5,
        private readonly int $resetAfterSeconds = 60,
    ) {}

    public function buscar(Placa $placa): string
    {
        if ($this->openUntil !== null && microtime(true) < $this->openUntil) {
            throw new ProviderUnavailableException('circuit_breaker', 'circuito aberto');
        }

        try {
            $result = $this->inner->buscar($placa);
            $this->failureCount = 0;
            $this->openUntil = null;

            return $result;
        } catch (ProviderUnavailableException $e) {
            $this->failureCount++;

            if ($this->failureCount >= $this->threshold) {
                $this->openUntil = microtime(true) + $this->resetAfterSeconds;
            }

            throw $e;
        }
    }
}
```

**Limitação de escopo:** estado em memória (`$failureCount`, `$openUntil`).
Em PHP-FPM (processo por requisição), o estado se perde entre requests. O circuito
funciona corretamente em processos long-running (`php artisan serve`, queue workers).
Para produção com PHP-FPM: persistir estado em Redis/Memcached.
O binding como `singleton` no container garante estado dentro do mesmo processo.

## 4. `AlwaysFailingProviderClient` (simulação de falha — REQ-PROV-06)

Implementa `ProviderClientInterface` e sempre lança `ProviderUnavailableException`.
Usada para simular indisponibilidade de provedor em testes e demonstrações.

```php
namespace App\Integrations\Resilience;

use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;
use App\Integrations\Providers\ProviderClientInterface;

final class AlwaysFailingProviderClient implements ProviderClientInterface
{
    public function buscar(Placa $placa): string
    {
        throw new ProviderUnavailableException('failing', 'simulação de falha configurada');
    }
}
```

Para demonstrar o fallback manualmente, substitua o binding em `DomainServiceProvider`:

```php
// Força Provider A a sempre falhar → Provider B assume
$this->app->singleton('cb_client_a', fn () =>
    new AlwaysFailingProviderClient()
);
```

## 5. Wiring — `DomainServiceProvider`

Os circuit breakers são registrados como `singleton` para que o estado persista
dentro do mesmo processo. Os adapters são construídos diretamente, sem passar pelo
container (não há mais bindings contextuais `when()->needs()->give()`).

```php
$this->app->singleton('cb_client_a', fn () =>
    new CircuitBreakerProviderClientDecorator(
        new RetryingProviderClientDecorator(new FixtureProviderAClient(), retries: 3, waitMs: 200),
        threshold: 5,
        resetAfterSeconds: 60,
    )
);

$this->app->singleton('cb_client_b', fn () =>
    new CircuitBreakerProviderClientDecorator(
        new RetryingProviderClientDecorator(new FixtureProviderBClient(), retries: 3, waitMs: 200),
        threshold: 5,
        resetAfterSeconds: 60,
    )
);

$this->app->bind(DebtProviderInterface::class, fn ($app) =>
    new ProviderFallbackOrchestrator([
        new ProviderAJsonAdapter($app->make('cb_client_a')),
        new ProviderBXmlAdapter($app->make('cb_client_b')),
    ])
);
```

## 6. Casos de Teste

### Unitários

| ID | Classe | Cenário | Esperado |
|---|---|---|---|
| UT-RETRY-01 | `RetryingProviderClientDecorator` | inner sempre falha, `retries=2` | lança após 3 tentativas |
| UT-RETRY-02 | `RetryingProviderClientDecorator` | inner falha 1x depois sucesso, `retries=2` | retorna payload na 2ª tentativa |
| UT-RETRY-03 | `RetryingProviderClientDecorator` | `retries=0` | lança com 1 tentativa total |
| UT-CB-01 | `CircuitBreakerProviderClientDecorator` | falhas < threshold | repassa exceção, não abre circuito |
| UT-CB-02 | `CircuitBreakerProviderClientDecorator` | falhas = threshold | abre circuito, próxima chamada lança sem chamar inner |
| UT-CB-03 | `CircuitBreakerProviderClientDecorator` | sucesso após falhas | reseta contador |

### Integração (Feature)

| ID | Cenário | Esperado | Requisito |
|---|---|---|---|
| IT-01 (CB-02) | Ambos os provedores com `AlwaysFailingProviderClient` | `503 {"error":"all_providers_unavailable"}` | REQ-PROV-02/03 |
| IT-02 | Provider A com `AlwaysFailingProviderClient`, Provider B com `FixtureProviderBClient` | `200` com JSON idêntico ao FT-01 (fallback transparente) | REQ-PROV-02 |

## 7. Rastreabilidade

| Requisito | Componente |
|---|---|
| REQ-PROV-02 | `ProviderFallbackOrchestrator` (Design 03) + decoradores (este doc) |
| REQ-PROV-03 | `AllProvidersUnavailableException` → 503 (IT-01) |
| REQ-PROV-06 | `AlwaysFailingProviderClient` |
| REQ-PROV-07 | `RetryingProviderClientDecorator`, `CircuitBreakerProviderClientDecorator` |
