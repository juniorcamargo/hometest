# Requirements — Serviço de Consulta e Pagamento de Débitos Veiculares

**Versão:** 1.0 (aprovado)
**Fonte:** enunciado do home test (Backend Engineer)

## 1. Visão Geral

O sistema expõe um endpoint que recebe uma placa, consulta múltiplos
provedores externos (em formatos diferentes), normaliza os dados em um
modelo canônico, calcula valores atualizados com juros simples, e simula
opções de pagamento (PIX e cartão de crédito) para o total e para cada tipo
de débito isoladamente.

## 2. Glossário (linguagem ubíqua)

| Termo | Significado |
|---|---|
| **Placa** | Identificador do veículo, formato antigo (`ABC1234`) ou Mercosul (`ABC1D23`). |
| **Provedor** | Fonte externa de dados de débitos (Provedor A = JSON, Provedor B = XML). |
| **Débito** | Um item de dívida do veículo: tipo, valor original, data de vencimento. |
| **Tipo de Débito** | Categoria do débito (`IPVA`, `MULTA`, e futuramente outros). |
| **Data de Referência** | Data fixa usada para todos os cálculos: `2024-05-10T00:00:00Z`. |
| **Dias de Atraso** | Diferença em dias (UTC) entre a Data de Referência e o vencimento. |
| **Juros** | Valor calculado sobre o débito em atraso, segundo a regra do seu tipo. |
| **Valor Atualizado** | `valor_original + juros`, arredondado HALF_UP/2 casas. |
| **Opção de Pagamento** | Agrupamento (`TOTAL` ou `SOMENTE_<TIPO>`) com simulações PIX e cartão. |

## 3. Requisitos Funcionais

### 3.0 Contrato do Endpoint

**REQ-API-01** (P0) — O sistema DEVE expor o seguinte endpoint:

```
POST /api/veiculos/debitos
Content-Type: application/json

{ "placa": "ABC1234" }
```

Resposta de sucesso: `HTTP 200` com o corpo descrito em REQ-OUT-01.

### 3.1 Entrada e Validação

**REQ-INPUT-01** (P0) — O sistema DEVE aceitar requisições no formato
`{"placa": "<string>"}`.
*Aceite:* corpo válido é processado normalmente.

**REQ-INPUT-02** (P0) — QUANDO a placa não corresponder ao padrão antigo
(`ABC1234`) nem ao padrão Mercosul (`ABC1D23`), O sistema DEVE responder
`HTTP 400 {"error":"invalid_plate"}`.
*Aceite:* placas como `AB1234`, `1234ABC`, `ABC12345` retornam 400.

**REQ-INPUT-03** (P2 — "seria bacana") — O sistema DEVE limitar o corpo da
requisição a 1 MiB. QUANDO excedido, O sistema DEVE responder
`HTTP 413 {"error":"payload_too_large"}`.

**REQ-INPUT-04** (P2 — "seria bacana") — O sistema DEVE rejeitar JSON de
entrada contendo campos desconhecidos (ex: além de `placa`), respondendo
`HTTP 422 {"error":"unexpected_fields","fields":["<campo>", ...]}`.

### 3.2 Integração com Provedores

**REQ-PROV-01** (P0) — O sistema DEVE consultar provedores externos
configurados, em uma ordem definida, para obter os débitos de uma placa.

**REQ-PROV-02** (P0) — QUANDO um provedor falhar (erro, timeout,
indisponibilidade), O sistema DEVE tentar o próximo provedor na ordem
configurada (fallback).

**REQ-PROV-03** (P0) — QUANDO todos os provedores configurados falharem,
O sistema DEVE responder `HTTP 503 {"error":"all_providers_unavailable"}`.

**REQ-PROV-04** (P0) — Uma resposta de provedor sem débitos (lista vazia no
JSON, ou `<debts/>` autofechado no XML) É uma resposta VÁLIDA com zero
débitos — NÃO deve disparar fallback nem erro.

**REQ-PROV-05** (P0) — A arquitetura DEVE permitir adicionar um novo
provedor (novo formato de dados) sem alterar a lógica de domínio
(cálculo de juros / pagamento) — via padrão Adapter/Strategy.

**REQ-PROV-06** (P2 — "seria bacana") — O sistema DEVE oferecer um meio de
simular falha de provedor (timeout / indisponibilidade) para fins de
demonstração/teste.

**REQ-PROV-07** (P2 — "seria bacana") — O sistema DEVE implementar retry
com backoff e/ou circuit breaker na chamada a provedores.

**REQ-PROV-08** (Documentação apenas) — O sistema DEVE descrever (no
README) a estratégia para lidar com provedores que retornam dados
divergentes para a mesma placa. Implementação é opcional.

### 3.3 Normalização (Modelo Canônico)

**REQ-NORM-01** (P0) — Cada adapter de provedor DEVE traduzir seu formato
nativo (JSON do Provedor A, XML do Provedor B) para o modelo canônico de
Débito: `{ tipo, valor_original, vencimento }`.

**REQ-NORM-02** (P0) — A arquitetura DEVE permitir adicionar novos tipos de
débito sem alterar os adapters de provedores (eles são agnósticos ao tipo).

### 3.4 Cálculo de Juros

**REQ-JUROS-01** (P0) — Todos os cálculos de "dias de atraso" DEVEM usar a
Data de Referência fixa `2024-05-10T00:00:00Z` (UTC), comparando apenas as
datas (sem componente de hora).

**REQ-JUROS-02** (P0) — SE `dias_atraso <= 0`, ENTÃO `juros = 0` e
`valor_atualizado = valor_original`.

**REQ-JUROS-03** (P0) — Para débitos do tipo `IPVA`: taxa de 0,33% ao dia,
com teto de 20% do valor original aplicado **ao juros** (não ao total).
`juros = min(valor_original × 0,0033 × dias_atraso, valor_original × 0,20)`.

**REQ-JUROS-04** (P0) — Para débitos do tipo `MULTA`: taxa de 1,00% ao dia,
sem teto. `juros = valor_original × 0,01 × dias_atraso`.

**REQ-JUROS-05** (P0) — `valor_atualizado = valor_original + juros`,
arredondado HALF_UP, 2 casas decimais.

**REQ-JUROS-06** (P0) — QUANDO um débito tiver um tipo não suportado pelas
regras de juros (diferente de `IPVA`/`MULTA`), O sistema DEVE responder
`HTTP 422 {"error":"unknown_debt_type","type":"<TIPO>"}`. O tipo NÃO deve
ser silenciado, ignorado ou convertido para "OUTROS".

**REQ-JUROS-07** (P0) — A arquitetura DEVE permitir adicionar uma nova
regra de juros (novo tipo de débito) implementando uma nova Strategy, sem
alterar as Strategies existentes (Open/Closed).

### 3.5 Simulação de Pagamento

**REQ-PAG-01** (P0) — O sistema DEVE gerar uma opção de pagamento `TOTAL`,
cujo `valor_base` é a soma de todos os `valor_atualizado`.

**REQ-PAG-02** (P0) — Para cada tipo de débito presente na consulta, o
sistema DEVE gerar uma opção `SOMENTE_<TIPO>` (sempre singular — ex:
`SOMENTE_IPVA`, mesmo que existam múltiplos débitos do mesmo tipo, cujos
valores devem ser somados em um único `valor_base`).

**REQ-PAG-03** (P0) — PIX: para CADA opção de pagamento (TOTAL e cada
parcial), `total_com_desconto = valor_base × 0,95`, arredondado HALF_UP,
2 casas.

**REQ-PAG-04** (P0) — Cartão de crédito: cada opção de pagamento DEVE
oferecer exatamente as parcelas `1x`, `6x` e `12x` — nenhuma outra
quantidade.

**REQ-PAG-05** (P0) — Cartão 1x: sem juros — `valor_parcela = valor_base`.

**REQ-PAG-06** (P0) — Cartão 6x e 12x: amortização Price/PMT a 2,5% a.m.
compostos — `valor_parcela = base × i × (1+i)^n / ((1+i)^n − 1)`, `i=0,025`.
Arredondado HALF_UP, 2 casas, tolerância ±R$0,02.

### 3.6 Resposta da API

**REQ-OUT-01** (P0) — A resposta DEVE seguir a estrutura:
`{ placa, debitos[], resumo: { total_original, total_atualizado },
pagamentos: { opcoes[] } }`, conforme exemplo do enunciado.

**REQ-OUT-02** (P0) — Todos os valores monetários DEVEM ser serializados
como **strings decimais** (não `number`), com 2 casas decimais.

**REQ-OUT-03** (P0) — Cada item de `debitos[]` DEVE conter: `tipo`,
`valor_original`, `valor_atualizado`, `vencimento` (`YYYY-MM-DD`),
`dias_atraso`.

## 4. Requisitos Não-Funcionais

**REQ-NFR-01** (P0) — Arquitetura DEVE isolar claramente: integração com
provedores / regras de domínio (juros) / regras de pagamento (Ports &
Adapters + Clean Architecture, conforme acordado).

**REQ-NFR-02** (P0) — O fluxo do endpoint DEVE ser orquestrado por um Use
Case na camada de Application, que depende apenas de interfaces (ports) do
Domain.

**REQ-NFR-03** (P2 — "seria bacana") — Logs estruturados, com a placa
mascarada (LGPD) em qualquer log.

**REQ-NFR-04** (P2 — "seria bacana") — Testes automatizados: unitários
(juros, pagamento, adapters) e de integração (fluxo completo + fallback),
usando Pest.

**REQ-NFR-05** (P2 — "seria bacana") — README deve explicar os padrões de
projeto utilizados (Strategy, Adapter, Ports & Adapters, etc.).

## 5. Cenários de Aceite / Casos de Borda

| ID | Cenário | Resultado esperado | Requisitos relacionados |
|---|---|---|---|
| CB-01 | Placa sem nenhum débito (`[]` ou `<debts/>`) | `debitos: []`, `resumo` com totais `"0.00"`, `pagamentos.opcoes` apenas com `TOTAL` (valor_base `0.00`) — ver 6.3 | REQ-PROV-04, REQ-OUT-01 |
| CB-02 | Todos os provedores falham | `HTTP 503 {"error":"all_providers_unavailable"}` | REQ-PROV-02, REQ-PROV-03 |
| CB-03 | Débito com tipo desconhecido (ex: `LICENCIAMENTO` sem regra) | `HTTP 422 {"error":"unknown_debt_type","type":"LICENCIAMENTO"}` | REQ-JUROS-06 |
| CB-04 | Débito não vencido (`dias_atraso <= 0`) | `valor_atualizado == valor_original`, `juros = 0` | REQ-JUROS-02 |
| CB-05 | Placa fora do padrão | `HTTP 400 {"error":"invalid_plate"}` | REQ-INPUT-02 |
| CB-06 | Provedores com dados divergentes para a mesma placa | Estratégia documentada no README (sem implementação obrigatória) | REQ-PROV-08 |
| CB-07 | Múltiplos débitos do mesmo tipo (ex: 2x `MULTA`) | Uma única opção `SOMENTE_MULTA` com `valor_base` = soma dos `valor_atualizado` dos dois | REQ-PAG-02 |

## 6. Ambiguidades do Enunciado e Decisões Adotadas

### 6.1 Escopo do erro `unknown_debt_type` (422)

O enunciado contém duas redações aparentemente conflitantes:

- Em **"Casos de borda" (regras de juros)**: *"Tipos de débito não previstos
  pelas regras acima devem causar erro HTTP 422 (...). Não silenciar, não
  converter para 'OUTROS'."* → sugere que **qualquer** débito de tipo
  desconhecido na lista já invalida a requisição inteira.
- Em **"Tratamento de erros estruturado"**: *"HTTP 422 (...) quando **todos**
  os débitos retornados são de tipo desconhecido."* → sugere que só falha
  se **nenhum** débito tiver tipo conhecido.

**Decisão adotada (REQ-JUROS-06):** adotar a redação da seção de regras de
juros — **qualquer** débito com tipo não suportado faz a requisição inteira
falhar com 422, citando o tipo problemático (fail-fast). É a leitura mais
explícita ("não silenciar, não converter") e mais segura para um sistema
financeiro (evita responder com totais parciais silenciosamente
incompletos).

> Ação obrigatória: esta divergência DEVE ser citada no README, na seção de
> "divergências do enunciado e justificativas" (REQ-DELIV-02), reaproveitando
> o texto desta seção 6.1 como base.

### 6.2 Cálculo de "dias de atraso"

Assumindo diferença simples de calendário (UTC, sem hora) entre Data de
Referência e `vencimento` — confirmado pelos exemplos do enunciado
(`2024-01-10` → `2024-05-10` = 121 dias).

### 6.3 Resposta quando não há débitos (CB-01)

O enunciado não exemplifica este caso. Decisão adotada: zero débitos é uma
resposta válida (REQ-PROV-04), mantendo a mesma estrutura de sempre —
`debitos: []`, `resumo: {"total_original":"0.00","total_atualizado":"0.00"}`,
e `pagamentos.opcoes` contendo **apenas** a opção `TOTAL` com
`valor_base:"0.00"` (sem opções `SOMENTE_<TIPO>`, já que nenhum tipo está
presente). Mantém o contrato de resposta estável independente do resultado.

## 7. Fora de Escopo

- Persistência / banco de dados.
- Autenticação / autorização.
- Interface gráfica.
- Implementação real de provedores externos (apenas simulação local).

## 8. Entregáveis

**REQ-DELIV-01** — Repositório Git.

**REQ-DELIV-02** — README contendo: como rodar, decisões técnicas,
trade-offs, melhorias futuras e divergências do enunciado com justificativa
(incluindo a Seção 6 deste documento).
