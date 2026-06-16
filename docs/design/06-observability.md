# Design 06 — Observabilidade

**Status:** Final.
**Depende de:** `00-architecture.md`, `04-resilience-orchestration.md`
(`ProviderFallbackOrchestrator`, decoradores, `Placa::mascarada()`).
**Requisitos relacionados:** REQ-NFR-03.

## 1. Visão Geral

Três objetivos:
1. **Logs estruturados (JSON)** — cada entrada de log é um objeto JSON de
   uma linha, compatível com ferramentas de agregação (ELK, Datadog, etc.).
2. **Placa sempre mascarada** — qualquer log que mencione a placa usa
   `$placa->mascarada()` (`ABC1234` → `ABC**34`), nunca o valor original.
   Requisito LGPD.
3. **Request ID** — UUID gerado por requisição, propagado para todas as
   entradas de log da mesma requisição via `Log::shareContext()`.

## 2. Canal JSON (`config/logging.php`)

```php
'json' => [
    'driver'       => 'monolog',
    'handler'      => StreamHandler::class,
    'handler_with' => [
        'stream' => storage_path('logs/laravel.log'),
        'level'  => env('LOG_LEVEL', 'debug'),
    ],
    'formatter' => \Monolog\Formatter\JsonFormatter::class,
],
```

Canal padrão: `LOG_CHANNEL=json`. Cada linha do arquivo de log:

```json
{"message":"consulta.iniciada","context":{"placa":"ABC**34","data_referencia":"2024-05-10","request_id":"uuid"},"level_name":"INFO","datetime":"..."}
```

## 3. Request ID — `RequestIdMiddleware`

**Arquivo:** `app/Http/Middleware/RequestIdMiddleware.php`

Registrado no grupo `api` (junto com `ForceJsonResponse`).

```php
public function handle(Request $request, Closure $next): Response
{
    $requestId = $request->header('X-Request-ID') ?? Str::uuid()->toString();
    Log::shareContext(['request_id' => $requestId]);
    $response = $next($request);
    $response->headers->set('X-Request-ID', $requestId);
    return $response;
}
```

- Aceita `X-Request-ID` do cliente (para correlação ponta-a-ponta).
- Gera UUID v4 se não fornecido.
- `Log::shareContext()` injeta `request_id` em **todas** as entradas de log
  subsequentes da requisição, sem necessidade de passar o ID explicitamente
  para cada logger.
- Devolve o ID no header de resposta — o cliente pode usá-lo para
  correlacionar com seus próprios logs.

## 4. Decisão de camada para logging

| Camada | Abordagem | Motivo |
|---|---|---|
| Application (`ConsultarDebitosVeiculoUseCase`) | `Psr\Log\LoggerInterface` injetado | Application não deve depender do framework Laravel |
| Infrastructure (`ProviderFallbackOrchestrator`) | `Illuminate\Support\Facades\Log` | Já é infra; testado via feature tests que bootam o app |
| Infrastructure (decoradores) | `Psr\Log\LoggerInterface` + `NullLogger` default | Testados em unit tests sem app container; NullLogger evita falha em testes puros |

O `NullLogger` como default nos decoradores usa a sintaxe PHP 8.1+ de `new`
em parâmetros default:
```php
private readonly LoggerInterface $logger = new NullLogger(),
```

## 5. Eventos de log

| Evento | Nível | Classe | Campos |
|---|---|---|---|
| `consulta.iniciada` | info | `ConsultarDebitosVeiculoUseCase` | `placa`*, `data_referencia` |
| `consulta.concluida` | info | `ConsultarDebitosVeiculoUseCase` | `placa`*, `total_debitos`, `total_atualizado` |
| `provider.tentativa` | debug | `ProviderFallbackOrchestrator` | `provider`, `placa`* |
| `provider.falha` | warning | `ProviderFallbackOrchestrator` | `provider`, `placa`*, `motivo` |
| `provider.retry` | warning | `RetryingProviderClientDecorator` | `provider`, `placa`*, `attempt`, `max_attempts` |
| `provider.circuit_breaker.fast_fail` | warning | `CircuitBreakerProviderClientDecorator` | `placa`*, `open_until` |
| `provider.circuit_breaker.aberto` | error | `CircuitBreakerProviderClientDecorator` | `failure_count`, `reset_after_seconds` |

\* campo `placa` sempre via `$placa->mascarada()` — nunca `$placa->valor`.

Todos os eventos recebem `request_id` automaticamente via `shareContext`.

## 6. Justificativa LGPD para mascaramento

A placa veicular é um dado pessoal indireto (permite identificar o
proprietário do veículo via consulta a bases de registro). Por precaução e
alinhamento com o princípio de minimização de dados da LGPD (Art. 6º, III),
a placa é mascarada em qualquer saída de log. O método `mascarada()` preserva
os 3 primeiros e os 2 últimos caracteres (`ABC**34`), o suficiente para
diagnóstico operacional sem expor o identificador completo.

## 7. Verificação manual

```bash
# Em um terminal:
php artisan serve --host=0.0.0.0 --port=8000

# Em outro:
curl -s -X POST http://localhost:8000/api/veiculos/debitos \
  -H 'Content-Type: application/json' \
  -d '{"placa":"ABC1234"}' -D - | grep X-Request-ID

tail -5 storage/logs/laravel.log | python3 -m json.tool
```

Verificar:
- Cada linha é JSON válido com campos `message`, `context`, `level_name`, `datetime`.
- `context.placa` contém `ABC**34`, nunca `ABC1234`.
- `context.request_id` é o mesmo UUID em todas as linhas da requisição.
- Header `X-Request-ID` presente na resposta HTTP.
