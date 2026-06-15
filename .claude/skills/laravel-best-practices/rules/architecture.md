# Architecture Best Practices

## Single-Purpose Action Classes

Extract discrete business operations into invokable Action classes.

```php
class CreateOrderAction
{
    public function __construct(private InventoryService $inventory) {}

    public function execute(array $data): Order
    {
        $order = Order::create($data);
        $this->inventory->reserve($order);

        return $order;
    }
}
```

## Use Dependency Injection

Always use constructor injection. Avoid `app()` or `resolve()` inside classes.

Incorrect:
```php
class OrderController extends Controller
{
    public function store(StoreOrderRequest $request)
    {
        $service = app(OrderService::class);

        return $service->create($request->validated());
    }
}
```

Correct:
```php
class OrderController extends Controller
{
    public function __construct(private OrderService $service) {}

    public function store(StoreOrderRequest $request)
    {
        return $this->service->create($request->validated());
    }
}
```

## Code to Interfaces

Depend on contracts at system boundaries (payment gateways, notification channels, external APIs) for testability and swappability.

Incorrect (concrete dependency):
```php
class OrderService
{
    public function __construct(private StripeGateway $gateway) {}
}
```

Correct (interface dependency):
```php
interface PaymentGateway
{
    public function charge(int $amount, string $customerId): PaymentResult;
}

class OrderService
{
    public function __construct(private PaymentGateway $gateway) {}
}
```

Bind in a service provider:

```php
$this->app->bind(PaymentGateway::class, StripeGateway::class);
```

## Default Sort by Descending

When no explicit order is specified, sort by `id` or `created_at` descending. Without an explicit `ORDER BY`, row order is undefined.

Incorrect:
```php
$posts = Post::paginate();
```

Correct:
```php
$posts = Post::latest()->paginate();
```

## Use Atomic Locks for Race Conditions

Prevent race conditions with `Cache::lock()` or `lockForUpdate()`.

```php
Cache::lock('order-processing-'.$order->id, 10)->block(5, function () use ($order) {
    $order->process();
});

// Or at query level
$product = Product::where('id', $id)->lockForUpdate()->first();
```

## Use `mb_*` String Functions

When no Laravel helper exists, prefer `mb_strlen`, `mb_strtolower`, etc. for UTF-8 safety. Standard PHP string functions count bytes, not characters.

Incorrect:
```php
strlen('José');          // 5 (bytes, not characters)
strtolower('MÜNCHEN');  // 'mÜnchen' — fails on multibyte
```

Correct:
```php
mb_strlen('José');             // 4 (characters)
mb_strtolower('MÜNCHEN');     // 'münchen'

// Prefer Laravel's Str helpers when available
Str::length('José');          // 4
Str::lower('MÜNCHEN');        // 'münchen'
```

## Use `defer()` for Post-Response Work

For lightweight tasks that don't need to survive a crash (logging, analytics, cleanup), use `defer()` instead of dispatching a job. The callback runs after the HTTP response is sent — no queue overhead.

Incorrect (job overhead for trivial work):
```php
dispatch(new LogPageView($page));
```

Correct (runs after response, same process):
```php
defer(fn () => PageView::create(['page_id' => $page->id, 'user_id' => auth()->id()]));
```

Use jobs when the work must survive process crashes or needs retry logic. Use `defer()` for fire-and-forget work.

## Use `Context` for Request-Scoped Data

The `Context` facade passes data through the entire request lifecycle — middleware, controllers, jobs, logs — without passing arguments manually.

```php
// In middleware
Context::add('tenant_id', $request->header('X-Tenant-ID'));

// Anywhere later — controllers, jobs, log context
$tenantId = Context::get('tenant_id');
```

Context data automatically propagates to queued jobs and is included in log entries. Use `Context::addHidden()` for sensitive data that should be available in queued jobs but excluded from log context. If data must not leave the current process, do not store it in `Context`.

## Use `Concurrency::run()` for Parallel Execution

Run independent operations in parallel using child processes — no async libraries needed.

```php
use Illuminate\Support\Facades\Concurrency;

[$users, $orders] = Concurrency::run([
    fn () => User::count(),
    fn () => Order::where('status', 'pending')->count(),
]);
```

Each closure runs in a separate process with full Laravel access. Use for independent database queries, API calls, or computations that would otherwise run sequentially.

## Convention Over Configuration

Follow Laravel conventions. Don't override defaults unnecessarily.

Incorrect:
```php
class Customer extends Model
{
    protected $table = 'Customer';
    protected $primaryKey = 'customer_id';

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_customer', 'customer_id', 'role_id');
    }
}
```

Correct:
```php
class Customer extends Model
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
```

---

## Domain-Driven Design (DDD)

DDD é uma abordagem onde o **modelo de negócio** (o domínio) é o centro da arquitetura. Em vez de começar pelo banco de dados ou pelo framework, começa-se pelo problema real.

**Quando usar:** sistemas com regras de negócio complexas e específicas — jogos, finanças, logística. Para CRUDs simples, DDD é excessivo.

**Ubiquitous Language:** use a mesma linguagem do negócio no código. Se o especialista fala "carta Rested", o código tem `CardStatus::RESTED`. Nunca mapeie mentalmente entre terminologia do negócio e terminologia técnica.

```php
// Errado — linguagem técnica
$card->status = 'inactive';

// Certo — linguagem do domínio
$card->rest(); // o token repousa, como no jogo
```

O DDD organiza o código em camadas: **Domain** (regras puras) → **Application** (orquestração) → **Infrastructure** (banco, HTTP, frameworks).

---

## Bounded Contexts

Um Bounded Context é uma área do sistema com linguagem e responsabilidades bem definidas. Identifique-os quando o mesmo conceito tem semânticas diferentes em áreas distintas.

**Exemplo:** a palavra "carta" em um TCG significa coisas diferentes dependendo do contexto:

| Contexto | O que "carta" significa |
|---|---|
| Construindo deck | Definição estática: código, custo, poder, cores — **imutável** |
| Durante uma partida | Token com estado: Active/Rested, Dons anexados — **mutável** |
| Histórico | Entrada em log de sessão — **persistida** |

Quando o mesmo conceito tem semânticas diferentes, há contextos diferentes. Cada contexto tem sua própria pasta com `Domain/` e `Application/`:

```
src/
├── Catalog/          ← o que as cartas são (definições estáticas)
│   ├── Domain/
│   └── Application/
├── GameEngine/       ← como o jogo funciona (motor em memória)
│   ├── Domain/
│   └── Application/
└── MatchManagement/  ← histórico e persistência de partidas
    ├── Domain/
    └── Application/
```

Contextos **não importam classes uns dos outros**. A comunicação entre eles passa por interfaces (Ports) e objetos de tradução (Anti-Corruption Layer).

---

## Clean Architecture — A Regra de Dependência

A Clean Architecture impõe uma regra: **dependências sempre apontam para dentro**. O círculo mais interno (Domain) não conhece nada dos círculos externos.

```
┌────────────────────────────────────────┐
│  app/ (Controllers, Providers)         │  ← conhece tudo
├────────────────────────────────────────┤
│  Application (Use Cases, Ports)        │  ← conhece o Domain, não conhece app/
├────────────────────────────────────────┤
│  Domain (Entities, Aggregates,         │  ← não conhece nada externo
│          Services, Ports interfaces)   │
└────────────────────────────────────────┘
```

Na prática:
- `Domain` não tem `use Illuminate\...` (exceto utilitários puros como `Str::uuid()`)
- `Application/UseCases` não têm `use App\...` — só importam de `Domain`
- Controllers em `app/` invocam Use Cases, mas Use Cases não conhecem `Request` nem `Response`

**Por que isso importa:**
1. **Testabilidade** — Domain e Application testam sem subir o Laravel, sem banco. Os testes rodam em milissegundos.
2. **Portabilidade** — se o framework mudar, o domínio e os use cases não mudam.
3. **Clareza** — as regras de negócio estão separadas dos detalhes de infraestrutura.

---

## Ports & Adapters (Arquitetura Hexagonal)

**O problema:** quando o domínio precisa de algo externo (banco de dados, API, serviço de pagamento), se ele importar a implementação concreta, fica acoplado — uma mudança na infraestrutura quebra o domínio.

**A solução:** o Domain define o **contrato** (Port = interface) de que precisa. A infraestrutura implementa (Adapter) esse contrato. O domínio nunca sabe qual implementação está em uso.

```php
// Port — definido pelo domínio, no pacote do domínio
// src/GameEngine/Domain/Ports/CardDefinitionProvider.php
interface CardDefinitionProvider
{
    public function resolveCard(string $cardCode): CardSnapshot;
}
```

```php
// Adapter — implementação concreta, em app/Adapters/
// app/Adapters/InMemoryCardDefinitionProvider.php
final class InMemoryCardDefinitionProvider implements CardDefinitionProvider
{
    public function resolveCard(string $cardCode): CardSnapshot
    {
        // retorna CardSnapshot com dados hardcoded para dev/testes
    }
}
```

```php
// Binding no Service Provider — única linha que conecta Port ao Adapter
$this->app->bind(CardDefinitionProvider::class, InMemoryCardDefinitionProvider::class);
```

Quando a implementação real (banco de dados) for criada, **apenas o binding muda**. O domínio e os use cases não tocam.

---

## Aggregates & Aggregate Roots

Um **Aggregate** é um cluster de entidades tratadas como uma unidade. O **Aggregate Root** é a única porta de entrada para modificar qualquer coisa dentro do cluster.

**Por que existem:** sem um Aggregate Root, qualquer código externo pode manipular entidades internas sem validar invariantes de negócio.

```php
// Sem Aggregate Root — qualquer código pode fazer isso (incorreto):
$characterArea->add($token);  // pagou o custo em Don? removeu da Hand? não sabemos.

// Com Aggregate Root Player — a invariante é garantida:
$player->playCharacter($tokenId, donCost: 3);
// Player internamente: verifica Hand, verifica DonArea, paga custo, move para CharacterArea
```

O Aggregate Root `Player` é o **guardião das invariantes entre zonas**. As coleções internas (`Hand`, `CharacterArea`, `DonArea`) são privadas — código externo não as manipula diretamente.

```php
class Player
{
    private array $hand = [];
    private array $characterArea = [];
    private array $donArea = [];

    public function playCharacter(string $tokenId, int $donCost): void
    {
        // Valida que o token está na Hand
        // Valida que há Don suficiente
        // Repousa os Don para pagar o custo
        // Move o token da Hand para CharacterArea
    }
}
```

**Regra:** Aggregate Roots são recuperados do repositório como unidade. Nunca salve ou busque entidades internas de um Aggregate diretamente.

---

## Use Cases (Camada de Application)

Um Use Case orquestra o domínio para realizar uma operação de negócio. Recebe um **DTO de entrada**, coordena entidades e domain services, retorna um **DTO de saída**. Não conhece HTTP, banco de dados ou qualquer infraestrutura.

```
Controller → (Input DTO) → UseCase → Domain → (Output DTO) → Controller → HTTP Response
```

```php
final class StartMatchUseCase
{
    public function __construct(
        private readonly CardDefinitionProvider $cardProvider,
        private readonly MatchRepository $matchRepository,
    ) {}

    public function execute(StartMatchInput $input): MatchStateOutput
    {
        $playerOne = $this->buildPlayer($input->playerOneId, $input->playerOneDeck);
        $playerTwo = $this->buildPlayer($input->playerTwoId, $input->playerTwoDeck);

        $match = GameMatch::start(MatchId::generate(), $playerOne, $playerTwo);

        $this->matchRepository->save($match);

        return MatchStateOutput::fromMatch($match);
    }
}
```

**Diferença entre Use Case e Domain Service:**

| | Use Case | Domain Service |
|---|---|---|
| **Camada** | Application | Domain |
| **Conhece** | Domain + Ports | Apenas entidades do domínio |
| **Coordena** | Múltiplos agregados + repositórios | Lógica que não pertence a uma entidade |
| **Exemplo** | `StartMatchUseCase` | `CombatResolver` |

---

## Value Objects

Value Objects representam conceitos do domínio **definidos pelos seus valores, não por identidade**. São imutáveis: uma vez criados, não mudam.

**Enums como Value Objects:**

```php
enum CardStatus: string
{
    case ACTIVE = 'Active';
    case RESTED = 'Rested';

    public function isActive(): bool { return $this === self::ACTIVE; }
    public function isRested(): bool  { return $this === self::RESTED; }
}
```

Por que enum em vez de string? O PHP garante que só valores válidos existem. `CardStatus::RESTED` é impossível de errar com typo. A string `'rested'` não é.

**Classes finais imutáveis:**

```php
final class CardColors
{
    /** @param CardColor[] $colors */
    public function __construct(array $colors)
    {
        // valida: 1-2 cores, sem duplicatas — lança exceção se inválido
        // invariante garantida na construção: um CardColors inválido nunca existe
    }
}
```

`final` impede herança que poderia introduzir mutabilidade. Validar no construtor garante que o objeto nasce consistente.

**`readonly` para objetos de tradução entre contextos:**

```php
final readonly class CardSnapshot
{
    public function __construct(
        public string $cardCode,
        public int $power,
        public int $cost,
        public CardColors $colors,
    ) {}
}
```

---

## Domain Collections

Arrays não conhecem regras de negócio. Domain Collections encapsulam as **invariantes de cada zona**.

```php
// Sem Domain Collection — qualquer código pode fazer isso:
$characters[] = $token; // ultrapassou o limite de 5? não sabemos.

// Com Domain Collection — invariante garantida:
class CharacterArea
{
    private const MAX = 5;
    private array $characters = [];

    public function add(CharacterToken $character): void
    {
        if (count($this->characters) >= self::MAX) {
            throw new CharacterAreaFullException();
        }
        $this->characters[] = $character;
    }
}
```

É impossível ter mais de 5 personagens no `CharacterArea` — a invariante é garantida por design, não por disciplina de quem chama o código.

---

## Domain Services

Quando uma operação de negócio envolve múltiplas entidades mas **não pertence naturalmente a nenhuma delas**, use um Domain Service.

```php
// CombatResolver não pertence ao atacante nem ao defensor — resolve a interação entre os dois
final class CombatResolver
{
    public function resolve(
        Player $attackingPlayer,
        Player $defendingPlayer,
        string $attackerTokenId,
        string $defenderTokenId,
    ): CombatResult {
        $attacker = $this->findAttacker($attackingPlayer, $attackerTokenId);
        $defender = $this->findDefender($defendingPlayer, $defenderTokenId);

        if (! $attacker->canAttack()) {
            throw new InvalidAttackTargetException('Attacker is not Active.');
        }

        $attackerPower = $this->calculatePower($attacker, $attackingPlayer);
        $defenderPower = $this->calculatePower($defender, $defendingPlayer);

        $attacker->rest();

        if ($attackerPower >= $defenderPower) {
            $defendingPlayer->receiveLifeDamage();
        }

        return new CombatResult(defenderTookDamage: $attackerPower >= $defenderPower);
    }
}
```

Domain Services vivem em `Domain/Services/`, não têm estado próprio e não conhecem infraestrutura.

---

## Rich Domain Model vs Modelo Anêmico

O modelo **anêmico** coloca regras em services externos — a entidade é só um saco de dados:

```php
// ANÊMICO — evite
class CharacterToken { public string $status; }
class CombatService  { public function canAttack($c): bool { return $c->status === 'Active'; } }
```

O modelo **rico** coloca o comportamento dentro da entidade:

```php
// RICO — o que fazer
abstract class GameToken
{
    public function canAttack(): bool  { return $this->status->isActive(); }
    public function rest(): void       { $this->status = CardStatus::RESTED; }
    public function activate(): void   { $this->status = CardStatus::ACTIVE; }
}

class CharacterToken extends GameToken
{
    public function canBeTargeted(): bool { return $this->status->isRested(); }
}

class LeaderToken extends GameToken
{
    public function canBeTargeted(): bool { return true; } // Leader sempre é alvo
}
```

**Vantagens do modelo rico:**
- A regra e os dados estão no mesmo lugar — coesão máxima
- Polimorfismo elimina `if ($token instanceof LeaderToken)` espalhado pelo código
- Impossível chamar `canBeTargeted()` sem ter o tipo certo

---

## Anti-Corruption Layer (ACL)

Quando dois Bounded Contexts precisam se comunicar, nunca importe uma classe de um contexto diretamente no outro. Isso cria acoplamento — uma mudança em `Catalog` quebraria `GameEngine`.

**A solução:** o contexto receptor define seu próprio Value Object de tradução. O Adapter faz a conversão.

```php
// GameEngine define o que precisa — sem depender do Catalog
final readonly class CardSnapshot
{
    public function __construct(
        public string $cardCode,
        public int $power,
        public int $cost,
        public CardColors $colors,
    ) {}
}
```

```php
// O Adapter (em app/) traduz CardDefinition (Catalog) → CardSnapshot (GameEngine)
final class CatalogCardDefinitionProvider implements CardDefinitionProvider
{
    public function resolveCard(string $cardCode): CardSnapshot
    {
        $definition = $this->repository->findByCode($cardCode);

        return new CardSnapshot(
            cardCode: $definition->getCardCode(),
            power: $definition->getPower(),
            cost: $definition->getCost(),
            colors: $definition->getColors(),
        );
    }
}
```

O `GameEngine` nunca importa `CardDefinition`. Se o `Catalog` mudar sua estrutura interna, só o Adapter precisa ser atualizado.

---

## Domain Exceptions

Exceções tipadas expressam o vocabulário do negócio e permitem captura seletiva no controller.

```php
// Errado — exceção genérica sem semântica de domínio
throw new \Exception('Cannot do that');

// Certo — exceção que documenta o que pode dar errado no domínio
throw new CharacterAreaFullException();
throw new IllegalPhaseActionException($action, $currentPhase->name);
throw new NotYourTurnException();
throw new InvalidAttackTargetException('Attacker is not Active.');
```

Domain Exceptions vivem em `Domain/Exceptions/`. Controllers capturam essas exceções e as mapeiam para respostas HTTP adequadas (422, 403, etc.).

---

## InMemory Adapters

Para desenvolvimento sem banco de dados e para testes rápidos de Use Cases, implemente os Ports com repositórios em memória.

```php
final class InMemoryMatchRepository implements MatchRepository
{
    /** @var array<string, GameMatch> */
    private array $matches = [];

    public function save(GameMatch $match): void
    {
        $this->matches[$match->getId()->value] = $match;
    }

    public function findById(MatchId $id): GameMatch
    {
        if (! isset($this->matches[$id->value])) {
            throw new RuntimeException("Match '{$id->value}' not found.");
        }

        return $this->matches[$id->value];
    }
}
```

Use InMemory Adapters nos testes de Use Cases — sem HTTP, sem banco, sem filesystem:

```php
test('StartMatchUseCase cria partida com dois jogadores', function () {
    $useCase = new StartMatchUseCase(
        new InMemoryCardDefinitionProvider(),
        new InMemoryMatchRepository(),
    );

    $output = $useCase->execute(StartMatchInput::fake());

    expect($output->status)->toBe(MatchStatus::InProgress)
        ->and($output->currentPhase)->toBe(TurnPhase::REFRESH)
        ->and($output->activePlayerId)->not->toBeNull();
});
```

---

## Teste a Camada de Application, Não Só o Domain

Testes de entidades e Value Objects isolados são rápidos e valiosos, mas **não garantem que a orquestração funciona**. Um bug na camada de Application (Use Cases) não será capturado se só o Domain for testado.

**O que acontece sem testes de Use Cases:** erros como chamar `Match::start()` — onde `Match` é uma palavra reservada do PHP — passam despercebidos até a execução manual.

Crie testes para cada Use Case usando InMemory Adapters:

```php
// tests/Unit/GameEngine/Application/StartMatchUseCaseTest.php
test('StartMatchUseCase inicia partida na fase REFRESH', function () {
    $useCase = new StartMatchUseCase(
        new InMemoryCardDefinitionProvider(),
        new InMemoryMatchRepository(),
    );

    $output = $useCase->execute(/* input válido */);

    expect($output->currentPhase)->toBe(TurnPhase::REFRESH)
        ->and($output->turnNumber)->toBe(1)
        ->and($output->winnerId)->toBeNull();
});
```

---

## Evite Palavras Reservadas do PHP como Nomes de Classe

O PHP 8 adicionou `match` como palavra reservada para a expressão `match()`. Mesmo que `class Match {}` seja válido em contexto de declaração dentro de um namespace, ao importar via `use` e chamar `Match::method()`, o parser interpreta `Match` como a keyword e lança `ParseError: unexpected token "::", expecting "("`.

```php
// ERRO — Match é palavra reservada no PHP 8
use App\Domain\Match\Match;
$match = Match::start(...); // ParseError: unexpected token "::"

// CERTO — use um nome que não conflita
use App\Domain\Match\GameMatch;
$match = GameMatch::start(...); // ok
```

Palavras reservadas a evitar como nomes de classe: `match`, `fn`, `enum`, `readonly`, `never`, `true`, `false`, `null`, `static`, `abstract`, `class`, `interface`, `trait`, `extends`, `implements`, `new`, `return`, `throw`, `catch`, `finally`.
