# Hometest — API de Consulta de Débitos Veiculares

API REST em Laravel 12 para consulta de débitos de veículos com cálculo de
juros e simulação de pagamento.

> **Fase 8** (em andamento) completará este README com instruções de setup,
> decisões técnicas, trade-offs e divergências do enunciado.

---

## Estratégia para Provedores com Dados Divergentes (CB-06 / REQ-PROV-08)

### Comportamento atual

O `ProviderFallbackOrchestrator` usa o **primeiro provedor que responder com
sucesso**. A ordem atual é Provider A → Provider B. Se o Provider A retornar
dados, eles são usados integralmente; o Provider B nem é consultado. Se o
Provider A falhar (timeout, 5xx, circuit breaker aberto), a consulta é
repassada ao Provider B.

Isso significa que, em operação normal, apenas um provedor fornece a resposta
— não há situação em que os dois retornam dados divergentes simultaneamente.
O cenário de divergência só ocorre se os dois provedores forem consultados de
forma paralela (o que não é o caso hoje).

### Por que não reconciliamos os dados entre provedores

O enunciado (REQ-PROV-08 / §3.2) reconhece que provedores distintos podem
retornar dados ligeiramente diferentes para a mesma placa, mas não especifica
o comportamento esperado nesse caso. Adotar reconciliação sem requisito
definido adicionaria complexidade sem garantia de corretude.

### Estratégias possíveis para uma versão futura

| Estratégia | Descrição | Quando usar |
|---|---|---|
| **Provider primário fixo** (atual) | A tem precedência, B é fallback puro | Dados dos provedores são confiáveis individualmente e a prioridade é estabilidade |
| **Reconciliação por campo** | Para cada débito, usar o valor mais conservador (maior valor, vencimento mais recente) | Contexto financeiro onde subestimar o débito é pior que superestimar |
| **Interseção** | Retornar apenas débitos presentes nos dois provedores | Quando falsos positivos são mais problemáticos que falsos negativos |
| **União com deduplicação** | Retornar todos os débitos de ambos, deduplicando por chave de negócio | Quando cada provedor pode ter débitos que o outro não tem |
| **Divergência como erro** | Retornar `409 Conflict` se os dois diferirem acima de uma tolerância | Quando divergência indica problema de consistência que deve ser tratado upstream |

### Decisão documentada

A estratégia atual ("primeiro que responde vence") é intencional e defensável
para v1: é simples, previsível e sem risco de introduzir dados incorretos por
mescla indevida. A ordem dos provedores (A antes de B) está hardcoded no
`DomainServiceProvider` — uma melhoria futura seria torná-la configurável via
`config/debitos.php`, permitindo ajuste operacional sem mudança de código.
