# API de Consulta de Débitos Veiculares

API REST que recebe uma placa, consulta provedores externos, calcula juros
sobre os débitos em atraso e simula opções de pagamento (PIX e cartão de
crédito).

**Stack:** Laravel 12 · PHP 8.2 · Pest

---

## Como rodar

**Pré-requisitos:** PHP ≥ 8.2, Composer 2. Não há banco de dados — nenhum
`migrate` é necessário.

```bash
git clone <url> hometest && cd hometest
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

**Exemplo de uso:**

```bash
curl -s -X POST http://localhost:8000/api/veiculos/debitos \
  -H 'Content-Type: application/json' \
  -d '{"placa":"ABC1234"}' | jq
```

---

## Contrato da API

### Endpoint

```
POST /api/veiculos/debitos
Content-Type: application/json
```

### Request

```json
{ "placa": "ABC1234" }
```

Aceita placas no formato antigo (`ABC1234`) e Mercosul (`ABC1D23`).

### Resposta de sucesso — `200`

```json
{
  "data": {
    "placa": "ABC1234",
    "debitos": [
      {
        "tipo": "IPVA",
        "valor_original": "1500.00",
        "valor_atualizado": "1800.00",
        "vencimento": "2024-01-10",
        "dias_atraso": 121
      }
    ],
    "resumo": {
      "total_original": "1500.00",
      "total_atualizado": "1800.00"
    },
    "pagamentos": {
      "opcoes": [
        {
          "tipo": "TOTAL",
          "valor_base": "1800.00",
          "pix": { "total_com_desconto": "1710.00" },
          "cartao_credito": {
            "parcelas": [
              { "quantidade": 1,  "valor_parcela": "1800.00" },
              { "quantidade": 6,  "valor_parcela": "326.79" },
              { "quantidade": 12, "valor_parcela": "175.48" }
            ]
          }
        }
      ]
    }
  }
}
```

> Valores monetários são sempre strings decimais com 2 casas. Cada tipo de
> débito presente gera uma opção `SOMENTE_<TIPO>` além do `TOTAL`.

### Respostas de erro

| HTTP | `error` | Causa |
|---|---|---|
| 400 | `invalid_plate` | Placa fora do padrão antigo ou Mercosul |
| 413 | `payload_too_large` | Corpo da requisição maior que 1 MiB |
| 422 | `unexpected_fields` + `fields[]` | Campos além de `placa` no body |
| 422 | `unknown_debt_type` + `type` | Débito com tipo sem regra de juros definida |
| 503 | `all_providers_unavailable` | Todos os provedores falharam |

---

## Testes

```bash
php artisan test                     # suite completa — 60 testes
php artisan test --filter=Unit       # 51 testes unitários
php artisan test --filter=Feature    # 9 testes de feature
```

| Grupo | O que cobre |
|---|---|
| Domain (Unit) | `Placa`, `Debito`, Strategies de juros (IPVA/MULTA), calculators de pagamento, resolver |
| Provider (Unit) | `ProviderAJsonAdapter`, `ProviderBXmlAdapter`, normalização A↔B |
| Resilience (Unit) | `RetryingProviderClientDecorator` (tentativas, backoff), `CircuitBreakerProviderClientDecorator` (estados) |
| Feature | Fluxo HTTP completo (FT-01/02/03/04), fallback de provedores, validação de entrada (413/422) |

**Simular falha de provedor (REQ-PROV-06):**
Os feature tests em `tests/Feature/ResilienciaTest.php` usam
`AlwaysFailingProviderClient` para demonstrar 503 (ambos falham) e fallback
transparente (A falha, B responde com o mesmo resultado). Para ver o
comportamento via HTTP, edite temporariamente o `DomainServiceProvider`
substituindo `FixtureProviderAClient` por `AlwaysFailingProviderClient`.

---

## Arquitetura

| Camada | Pasta | Responsabilidade |
|---|---|---|
| Domain | `app/Domain/` | Value Objects, Strategies de juros, Ports (Contracts), Exceptions — PHP puro, zero `Illuminate\*` |
| Application | `app/Application/` | `ConsultarDebitosVeiculoUseCase` — orquestra o Domain via ports |
| Infrastructure | `app/Integrations/` + `app/Http/` | Adapters dos provedores, retry/circuit breaker, Controllers, Middleware |

**Regra de dependência:** Domain não conhece Laravel. Application não conhece
HTTP. Dependências apontam sempre para dentro (em direção ao Domain).

---

## Padrões de Projeto

| Padrão | Onde | Por quê / Requisito |
|---|---|---|
| **Value Object** | `Placa`, `Debito`, `DebitoAtualizado`, money via `BigDecimal` | Imutabilidade, validação encapsulada |
| **Strategy** | `JurosStrategyInterface` (`IpvaJurosStrategy`, `MultaJurosStrategy`) | REQ-JUROS-07 — novo tipo de débito = nova classe, sem alterar as existentes |
| **Adapter** | `ProviderAJsonAdapter`, `ProviderBXmlAdapter` | REQ-PROV-05 — normaliza formatos externos (JSON/XML) para o modelo canônico |
| **Composite / Chain of Responsibility** | `ProviderFallbackOrchestrator` | REQ-PROV-02 — tenta provedores em ordem até um responder |
| **Decorator** | `RetryingProviderClientDecorator`, `CircuitBreakerProviderClientDecorator` | REQ-PROV-07 — retry com backoff e circuit breaker sobre cada adapter |
| **Ports & Adapters (Hexagonal)** | `Domain/Contracts` vs `Integrations`/`Http` | REQ-NFR-01 — isola regras de negócio de framework e I/O |
| **Use Case / Application Service** | `ConsultarDebitosVeiculoUseCase` | REQ-NFR-02 — único orquestrador do fluxo |
| **Factory/Resolver** | `JurosStrategyResolver` (OCP via `suporta()`) | Resolve a Strategy correta por tipo; extensível sem alterar código existente |

---

## Decisões Técnicas

### Precisão monetária com `brick/math`

Nenhum `float` circula no domínio. Todos os valores são `BigDecimal` até a
borda de saída (`ResultadoConsultaResource`), onde são convertidos para string
com arredondamento HALF_UP e 2 casas decimais. Evita erros acumulados em
cálculos de juros compostos e amortização Price.

### Data de referência como port

A data `2024-05-10` não está hardcoded no Use Case — vem de
`ReferenceDateProviderInterface`, implementada por `ConfigReferenceDateProvider`
(lê `config/debitos.php`). Permite escrever testes com qualquer data sem
Global Clock nem `Carbon::setTestNow()` global.

### `FormRequest` não valida formato de placa

`ConsultaVeiculoRequest` verifica apenas que `placa` é uma string presente.
A validação de formato é invariante do Value Object `Placa::fromString()`.
Se o `FormRequest` validasse via regex, o Laravel responderia com
`{"message":...,"errors":{...}}` (HTTP 422) — conflitando com o
`{"error":"invalid_plate"}` (HTTP 400) exigido por REQ-INPUT-02.

---

## Trade-offs

### Interfaces com implementação única

`JurosStrategyResolverInterface` e `PaymentSimulatorInterface` têm hoje uma
única implementação concreta cada. Mantidas porque:

- O `ConsultarDebitosVeiculoUseCase` depende apenas de ports do Domain —
  testável com fakes sem instanciar o grafo real de Strategies/Calculators.
- Torna explícita, no código, a fronteira Ports & Adapters exigida pelo
  enunciado (REQ-NFR-01).

Custo: uma camada extra de indireção para algo sem segunda implementação hoje.
Decisão consciente — cf. `docs/design/00-architecture.md` §5.7.

### Ordem dos provedores hardcoded no composition root

Provider A tem precedência; B é fallback puro. A ordem está no
`DomainServiceProvider` (composition root) — ponto único de mudança, não
espalhado em lógica de negócio. Melhoria futura: tornar configurável via
`config/debitos.php`.

### Estado do Circuit Breaker em memória

`CircuitBreakerProviderClientDecorator` é registrado como `singleton` no
container Laravel — o estado de falhas persiste dentro do processo PHP.
Limitação em PHP-FPM: cada request é um novo processo, então o estado reseta
a cada requisição. Para persistência real o estado precisaria de Redis.

---

## Divergências do Enunciado e Justificativas

### `unknown_debt_type` — fail-fast

O enunciado contém duas redações conflitantes:

- **Seção de regras de juros:** *"Tipos de débito não previstos devem causar
  erro HTTP 422. Não silenciar, não converter para 'OUTROS'."* → qualquer
  débito desconhecido invalida a requisição inteira.
- **Seção de tratamento de erros:** *"HTTP 422 quando **todos** os débitos
  retornados são de tipo desconhecido."* → só falha se nenhum for conhecido.

**Decisão adotada:** qualquer tipo desconhecido causa 422 imediato (fail-fast).
É a leitura mais explícita e mais conservadora para um sistema financeiro —
evita retornar totais silenciosamente incompletos ao calcular apenas os débitos
conhecidos e ignorar os demais.

### Placa sem débitos — resposta válida

O enunciado não exemplifica o caso de zero débitos. Decisão adotada: `200`
com estrutura completa, `debitos: []`, totais `"0.00"` e `pagamentos.opcoes`
contendo apenas `TOTAL` com `valor_base: "0.00"` — sem opções `SOMENTE_<TIPO>`,
já que nenhum tipo está presente. Mantém o contrato de resposta estável
independente do resultado.

---

## Estratégia para Provedores com Dados Divergentes

### Comportamento atual

O `ProviderFallbackOrchestrator` usa o **primeiro provedor que responder com
sucesso**. A ordem atual é Provider A → Provider B. Se o Provider A retornar
dados, eles são usados integralmente; o Provider B nem é consultado. Se o
Provider A falhar (timeout, 5xx, circuit breaker aberto), a consulta é
repassada ao Provider B.

Em operação normal, apenas um provedor fornece a resposta — não há situação
em que os dois retornam dados divergentes simultaneamente. O cenário de
divergência só ocorre se os dois forem consultados em paralelo (o que não é
o caso hoje).

### Por que não reconciliamos os dados entre provedores

O enunciado (REQ-PROV-08) reconhece que provedores distintos podem retornar
dados ligeiramente diferentes para a mesma placa, mas não especifica o
comportamento esperado. Adotar reconciliação sem requisito definido adicionaria
complexidade sem garantia de corretude.

### Estratégias possíveis para uma versão futura

| Estratégia | Descrição | Quando usar |
|---|---|---|
| **Provider primário fixo** (atual) | A tem precedência, B é fallback puro | Dados de cada provedor são confiáveis individualmente |
| **Reconciliação por campo** | Usar o valor mais conservador (maior valor, vencimento mais recente) | Contexto financeiro onde subestimar é pior que superestimar |
| **Interseção** | Retornar só os débitos presentes nos dois provedores | Quando falsos positivos são mais problemáticos que falsos negativos |
| **União com deduplicação** | Retornar todos os débitos de ambos, deduplicando por chave de negócio | Quando cada provedor pode ter débitos que o outro não tem |
| **Divergência como erro** | Retornar `409 Conflict` se os dois diferirem acima de uma tolerância | Quando divergência indica inconsistência a ser tratada upstream |

### Decisão documentada

A estratégia atual ("primeiro que responde vence") é intencional e defensável
para v1: simples, previsível e sem risco de introduzir dados incorretos por
mescla indevida. A ordem dos provedores está hardcoded no `DomainServiceProvider`
— uma melhoria futura seria torná-la configurável via `config/debitos.php`.

---

## Melhorias Futuras

- **Provedores reais via HTTP:** substituir `FixtureProvider[A/B]Client` por
  implementações com `Http::client()` — apenas uma nova classe implementando
  `ProviderClientInterface`, nenhuma outra camada muda.
- **Ordem dos provedores configurável:** mover a lista para
  `config/debitos.php`; permite priorização por latência, custo ou
  confiabilidade sem alterar código.
- **Circuit Breaker persistente:** salvar estado (`failureCount`, `openUntil`)
  em Redis para sobreviver entre processos PHP-FPM.
- **Consulta paralela de provedores:** usar `Http::pool()` para consultar A e
  B simultaneamente — reduz latência p99 ao custo de precisar de uma
  estratégia de reconciliação.
- **Novos tipos de débito:** implementar `JurosStrategyInterface` para
  `LICENCIAMENTO`, `DPVAT` etc. e registrar no `DomainServiceProvider` —
  nenhuma linha existente muda (OCP).
- **Observabilidade completa:** integrar OpenTelemetry para métricas de
  latência de provedores, taxa de circuit breaker aberto e distribuição de
  `dias_atraso`.
