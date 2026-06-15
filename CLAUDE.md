# CLAUDE.md — Serviço de Consulta e Pagamento de Débitos Veiculares

## Documentação do Projeto

Leia estes documentos antes de qualquer implementação:

- [`docs/requirements.md`](docs/requirements.md) — requisitos funcionais e não-funcionais
- [`docs/tasks.md`](docs/tasks.md) — plano de implementação por fases
- [`docs/design/00-architecture.md`](docs/design/00-architecture.md) — arquitetura, camadas, fluxo principal
- [`docs/design/01-domain-model.md`](docs/design/01-domain-model.md) — Value Objects, Contracts, Strategies de juros
- [`docs/design/02-payment-simulation.md`](docs/design/02-payment-simulation.md) — PIX, Cartão (PMT), PagamentoSimulator
- [`docs/design/03-provider-adapters.md`](docs/design/03-provider-adapters.md) — Adapters JSON/XML, ProviderClientInterface

## Arquitetura

Ports & Adapters + Clean Architecture. Regra de dependência: tudo aponta para o Domain.

```
app/
  Domain/          # PHP puro — sem Illuminate\*
  Application/     # Use Cases — depende apenas de Domain/Contracts
  Integrations/    # Adapters de provedores + resiliência + clock
  Http/            # Controllers, Requests, Resources
  Providers/       # Service Providers do Laravel (wiring/DI)
```

## Convenções

- Nenhum `float` para dinheiro — usar `Brick\Math\BigDecimal` em todo o Domain
- Arredondamento HALF_UP, 2 casas, apenas na borda (Http/Resources)
- Testes Pest: unitários em `tests/Unit/`, feature em `tests/Feature/`
- Teste de **estado** (resultado), não de interação (mocks/spies)

## Comandos

```bash
php artisan test              # todos os testes
php artisan test --filter=Domain   # só unitários do Domain
./vendor/bin/pest             # alternativa
```
