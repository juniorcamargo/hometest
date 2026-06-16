# Tasks — Plano de Implementação

**Como usar:** uma fase por vez. Cada item referencia `REQ-XX` / `UT-XX` /
seção de design. Rode os testes Pest da fase antes de avançar para a
próxima. Marque `[x]` ao concluir.

Fases 1–4 cobrem 100% do P0 (resposta correta para `ABC1234` + os 3 códigos
de erro). Fases 5–7 cobrem os itens "seria bacana" (P2). Fase 8 é entrega.

É extremamente importante marcar os itens concluidos com `[x]` ao concluir, sempre que finalizar uma fase certifique que os itens foram marcados.

---

## Fase 0 — Setup do Projeto

- [x] `composer create-project laravel/laravel:^12.0 .`
- [x] `composer require brick/math` (já incluso no lock do Laravel 12)
- [x] `composer require laravel/boost --dev && php artisan boost:install`
      (pacote inexistente — substituído por: `pestphp/pest` + `pest-plugin-laravel`
      instalados manualmente; `CLAUDE.md` criado manualmente)
- [x] Criar estrutura de pastas conforme `00-architecture.md` §3
      (`Domain/`, `Application/`, `Integrations/`, `Http/`)
- [x] Copiar `docs/requirements.md` e `docs/design/*.md` para o repositório
- [x] Referenciar os docs no `CLAUDE.md` gerado pelo Boost

---

## Fase 1 — Domain (Design 01)

- [x] `Placa` (VO) — `UT-PLACA-01/02/03`
- [x] `Debito` (VO, com `diasAtraso()` e normalização de `valorOriginal`/`vencimento`)
- [x] `DebitoAtualizado`, `ParcelaCartao`, `PixOpcao`, `CartaoCreditoOpcao`,
      `OpcaoPagamento`
- [x] `ResultadoConsulta` + `montar()` — `UT-RESULTADO-01`
- [x] Contracts: `DebtProviderInterface`, `JurosStrategyInterface`,
      `JurosStrategyResolverInterface`, `PaymentSimulatorInterface`,
      `ReferenceDateProviderInterface`
- [x] Exceptions: `DomainException`, `InvalidPlateException`,
      `UnknownDebtTypeException`, `ProviderUnavailableException`,
      `AllProvidersUnavailableException`
- [x] `IpvaJurosStrategy` — `UT-IPVA-01`
- [x] `MultaJurosStrategy` — `UT-MULTA-01`
- [x] Caso de borda `dias_atraso <= 0` — `UT-EDGE-01`
- [x] `JurosStrategyResolver` (OCP) — `UT-RESOLVER-01/02`

**Checkpoint:** `php artisan test --filter=Domain` — todos os 9 casos da
Seção 6 de `01-domain-model.md` passando.

---

## Fase 2 — Pagamento (Design 02)

- [x] `PixCalculator` — `UT-PIX-01`
- [x] `CartaoCreditoCalculator` — `UT-CARTAO-01/02/03/04`
- [x] `PagamentoSimulator` — `UT-SIM-01/02/03/04`
- [x] Teste "golden" — `UT-GOLDEN-01` (tabela completa do `02 §5`)

**Checkpoint:** `php artisan test --filter=Pagamento`.

---

## Fase 3 — Provider Adapters (Design 03)

- [x] `ProviderClientInterface`
- [x] `FixtureProviderAClient` + `ProviderAJsonAdapter` —
      `UT-ADAPTERA-01/02/03`
- [x] `FixtureProviderBClient` + `ProviderBXmlAdapter` —
      `UT-ADAPTERB-01/02/03`
- [x] Equivalência A vs B — `UT-NORM-01`

**Checkpoint:** `php artisan test --filter=Provider`.

---

## Fase 4 — Application + HTTP (MVP, REQ-API-01)

Esta fase ainda não tem um Design doc dedicado (05). O suficiente já está
definido em `requirements.md` (REQ-API-01, REQ-OUT-01/02/03) e em
`00-architecture.md` (§4 fluxo, §5.3 wiring, §5.4 mapeamento de erros, §5.6
resolver). Os artefatos abaixo fecham o end-to-end do P0.

### 4.1 `ConsultarDebitosVeiculoUseCase` [x]

Implementar exatamente como em `00-architecture.md` §4.

### 4.2 `ConfigReferenceDateProvider` [x]

```php
namespace App\Integrations\Clock;

use App\Domain\Contracts\ReferenceDateProviderInterface;

final class ConfigReferenceDateProvider implements ReferenceDateProviderInterface
{
    public function dataReferencia(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(config('debitos.data_referencia'));
    }
}
```

`config/debitos.php`:
```php
return [
    'data_referencia' => '2024-05-10T00:00:00Z',
];
```

### 4.3 `ProviderFallbackOrchestrator` (v1 — sem retry/circuit breaker ainda) [x]

Implementa `DebtProviderInterface` via Composite: tenta cada provedor em
ordem, acumula falhas, lança `AllProvidersUnavailableException` se todos
falharem (REQ-PROV-02/03). Design 04 vai *decorar* cada `$provider` desta
lista com retry/circuit breaker — esta classe não muda.

```php
namespace App\Integrations\Resilience;

use App\Domain\Contracts\DebtProviderInterface;
use App\Domain\Exceptions\AllProvidersUnavailableException;
use App\Domain\Exceptions\ProviderUnavailableException;
use App\Domain\ValueObjects\Placa;

final class ProviderFallbackOrchestrator implements DebtProviderInterface
{
    /** @param DebtProviderInterface[] $providers em ordem de tentativa */
    public function __construct(private readonly array $providers) {}

    public function nome(): string
    {
        return 'orchestrator';
    }

    public function consultar(Placa $placa): array
    {
        $tentativas = [];

        foreach ($this->providers as $provider) {
            try {
                return $provider->consultar($placa);
            } catch (ProviderUnavailableException $e) {
                $tentativas[$provider->nome()] = $e->getMessage();
            }
        }

        throw new AllProvidersUnavailableException($tentativas);
    }
}
```

### 4.4 `DomainServiceProvider` — wiring completo [x]

```php
public function register(): void
{
    $this->app->bind(JurosStrategyResolverInterface::class, fn ($app) => new JurosStrategyResolver([
        $app->make(IpvaJurosStrategy::class),
        $app->make(MultaJurosStrategy::class),
    ]));

    $this->app->bind(PaymentSimulatorInterface::class, PagamentoSimulator::class);
    $this->app->bind(ReferenceDateProviderInterface::class, ConfigReferenceDateProvider::class);

    $this->app->when(ProviderAJsonAdapter::class)
        ->needs(ProviderClientInterface::class)
        ->give(FixtureProviderAClient::class);

    $this->app->when(ProviderBXmlAdapter::class)
        ->needs(ProviderClientInterface::class)
        ->give(FixtureProviderBClient::class);

    $this->app->bind(DebtProviderInterface::class, fn ($app) => new ProviderFallbackOrchestrator([
        $app->make(ProviderAJsonAdapter::class),
        $app->make(ProviderBXmlAdapter::class),
    ]));
}
```

### 4.5 `ConsultaVeiculoRequest` [x]

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConsultaVeiculoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Formato da placa NÃO é validado aqui (00-architecture.md §5.4) —
        // apenas presença/tipo. Formato é invariante de Placa::fromString().
        return ['placa' => ['required', 'string']];
    }
}
```

### 4.6 `ResultadoConsultaResource` [x]

```php
namespace App\Http\Resources;

use App\Domain\ValueObjects\ResultadoConsulta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ResultadoConsultaResource extends JsonResource
{
    public function __construct(private readonly ResultadoConsulta $resultado)
    {
        parent::__construct($resultado);
    }

    public function toArray(Request $request): array
    {
        return [
            'placa' => $this->resultado->placa->valor,
            'debitos' => array_map(fn ($d) => [
                'tipo' => $d->original->tipo,
                'valor_original' => (string) $d->original->valorOriginal,
                'valor_atualizado' => (string) $d->valorAtualizado,
                'vencimento' => $d->original->vencimento->format('Y-m-d'),
                'dias_atraso' => $d->diasAtraso,
            ], $this->resultado->debitos),
            'resumo' => [
                'total_original' => (string) $this->resultado->totalOriginal,
                'total_atualizado' => (string) $this->resultado->totalAtualizado,
            ],
            'pagamentos' => [
                'opcoes' => array_map(fn ($o) => [
                    'tipo' => $o->tipo,
                    'valor_base' => (string) $o->valorBase,
                    'pix' => ['total_com_desconto' => (string) $o->pix->totalComDesconto],
                    'cartao_credito' => [
                        'parcelas' => array_map(fn ($p) => [
                            'quantidade' => $p->quantidade,
                            'valor_parcela' => (string) $p->valorParcela,
                        ], $o->cartaoCredito->parcelas),
                    ],
                ], $this->resultado->opcoesPagamento),
            ],
        ];
    }
}
```

### 4.7 `ConsultaVeiculoController` + rota [x]

```php
namespace App\Http\Controllers;

use App\Application\UseCases\ConsultarDebitosVeiculoUseCase;
use App\Http\Requests\ConsultaVeiculoRequest;
use App\Http\Resources\ResultadoConsultaResource;

final class ConsultaVeiculoController
{
    public function __construct(private readonly ConsultarDebitosVeiculoUseCase $useCase) {}

    public function __invoke(ConsultaVeiculoRequest $request): ResultadoConsultaResource
    {
        return new ResultadoConsultaResource(
            $this->useCase->handle($request->string('placa')->toString()),
        );
    }
}
```

```php
// routes/api.php
Route::post('/veiculos/debitos', \App\Http\Controllers\ConsultaVeiculoController::class);
```

### 4.8 Exception Handler (mapeamento — `00-architecture.md` §5.4) [x]

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(fn (InvalidPlateException $e) =>
        response()->json(['error' => 'invalid_plate'], 400));

    $exceptions->render(fn (UnknownDebtTypeException $e) =>
        response()->json(['error' => 'unknown_debt_type', 'type' => $e->tipo], 422));

    $exceptions->render(fn (AllProvidersUnavailableException $e) =>
        response()->json(['error' => 'all_providers_unavailable'], 503));
});
```

### 4.9 Testes de Feature (end-to-end)

- [x] `FT-01` — `POST /api/veiculos/debitos {"placa":"ABC1234"}` → `200` com
      o JSON **exatamente** igual ao do enunciado (placa, debitos, resumo,
      pagamentos.opcoes).
- [x] `FT-02` (CB-01) — placa sem débitos → `200`, `debitos:[]`, totais
      `"0.00"`, `opcoes` só `TOTAL`.
- [x] `FT-03` (CB-03) — forçar tipo desconhecido (fixture com `"type":"OUTROS"`)
      → `422 {"error":"unknown_debt_type","type":"OUTROS"}`.
- [x] `FT-04` (CB-05) — `{"placa":"AB1"}` → `400 {"error":"invalid_plate"}`.

**Checkpoint:** suíte completa verde. Neste ponto, **todo o P0 está
funcional** — é um bom momento para um commit/tag (`v0.1-mvp`).

---

## Fase 5 — Resiliência (Design 04, P2)

- [x] Escrever `docs/design/04-resilience-orchestration.md` (mesmo formato
      de `00`–`03`): Decorators `RetryingProviderClientDecorator` e
      `CircuitBreakerProviderClientDecorator` sobre `ProviderClientInterface`;
      mecanismo de simulação de falha (REQ-PROV-06/07).
- [x] Implementar conforme o doc acima.
- [x] `IT-01` (CB-02) — todos os provedores falhando →
      `503 {"error":"all_providers_unavailable"}`.
- [x] `IT-02` — Provedor A falha, Provedor B responde → resultado igual ao
      de A respondendo direto (fallback transparente).

---

## Fase 6 — Observabilidade (Design 06, P2)

- [x] Escrever `docs/design/06-observability.md`: logs estruturados (JSON),
      `Placa::mascarada()` em todo log que toque a placa, correlação por
      request ID.
- [x] Implementar (Monolog processor/canal customizado).

---

## Fase 7 — Demais Itens "Seria Bacana" (P2)

- [ ] REQ-INPUT-03 — limite de 1 MiB no corpo (`bodyParameter` /
      `PHP_INT_MAX` via middleware) → `413 {"error":"payload_too_large"}`.
- [ ] REQ-INPUT-04 — rejeitar campos além de `placa` →
      `422 {"error":"unexpected_fields","fields":[...]}`.
- [ ] CB-06 — escrever no README a estratégia para provedores com dados
      divergentes (requirements §3.2 / REQ-PROV-08) — apenas documentação.

---

## Fase 8 — README e Entrega (REQ-DELIV-01/02)

- [ ] Como rodar (setup, comandos, exemplo de `curl`).
- [ ] Decisões técnicas / padrões utilizados — reaproveitar a tabela de
      `00-architecture.md` §6.
- [ ] Trade-offs — `00-architecture.md` §5.7 (interfaces de implementação
      única) e outros que surgirem.
- [ ] Divergências do enunciado e justificativas — `requirements.md` §6.1
      (fail-fast em `unknown_debt_type`) e §6.3 (resposta sem débitos).
- [ ] Estratégia para provedores divergentes (CB-06 / REQ-PROV-08).
- [ ] Melhorias futuras (ex: provedores reais via HTTP, persistência,
      observabilidade completa se Fase 6 não for feita).
