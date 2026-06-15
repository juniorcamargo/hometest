# Design 01 — Modelo de Domínio

**Status:** Substitui `spec-01-dominio-e-contratos.md`.
**Depende de:** `00-architecture.md`.
**Requisitos relacionados:** REQ-JUROS-01..07, REQ-INPUT-02, REQ-NORM-01/02,
REQ-PROV-04, REQ-OUT-03.

## 1. Visão Geral

Este documento define, de forma completa: os Value Objects do domínio, os
Ports (Contracts), as exceções de negócio, e os Domain Services responsáveis
pelo cálculo de juros (Strategies IPVA/MULTA + Resolver). Tudo aqui é PHP
puro — sem `Illuminate\*`.

Os cálculos de PIX/Cartão (`PaymentSimulatorInterface`) e os adapters de
provedores ficam nos Design 02 e 03 — aqui só definimos as estruturas de
dados que eles produzem/consomem.

## 2. Value Objects

### 2.1 `Placa`

Sem alteração em relação ao spec-01: valida formato antigo (`ABC1234`) e
Mercosul (`ABC1D23`), normaliza para uppercase, e oferece `mascarada()` para
logs (Design 06).

```php
namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\InvalidPlateException;

final class Placa
{
    private function __construct(public readonly string $valor) {}

    public static function fromString(string $valor): self
    {
        $normalizado = strtoupper(trim($valor));

        if (!self::isValid($normalizado)) {
            throw new InvalidPlateException($valor);
        }

        return new self($normalizado);
    }

    public static function isValid(string $valor): bool
    {
        return (bool) preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $valor);
    }

    public function mascarada(): string
    {
        return substr($this->valor, 0, 3) . '**' . substr($this->valor, -2);
    }
}
```

### 2.2 `Debito`

**Novidade em relação ao spec-01:** o cálculo de `dias_atraso` (REQ-JUROS-01)
agora é um método do próprio VO — evita duplicar a lógica entre
`IpvaJurosStrategy` e `MultaJurosStrategy` (DRY), já que depende apenas do
`vencimento` deste débito e da Data de Referência recebida.

```php
namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Debito
{
    public readonly \DateTimeImmutable $vencimento;
    public readonly BigDecimal $valorOriginal;

    public function __construct(
        public readonly string $tipo,
        BigDecimal $valorOriginal,
        \DateTimeImmutable $vencimento,
    ) {
        // Normaliza para 2 casas (HALF_UP): adapters de origens diferentes
        // (JSON numérico vs XML textual) podem entregar escalas diferentes
        // — ver Design 03 §6.
        $this->valorOriginal = $valorOriginal->toScale(2, RoundingMode::HALF_UP);

        // Normaliza para meia-noite UTC: vencimento não tem componente de
        // hora (REQ-JUROS-01).
        $this->vencimento = $vencimento->setTime(0, 0, 0);
    }

    /**
     * Dias entre o vencimento e a Data de Referência.
     * > 0  => em atraso.
     * <= 0 => ainda não vencido (REQ-JUROS-02).
     */
    public function diasAtraso(\DateTimeImmutable $dataReferencia): int
    {
        $referencia = $dataReferencia->setTime(0, 0, 0);
        $segundos = $referencia->getTimestamp() - $this->vencimento->getTimestamp();

        return intdiv($segundos, 86_400);
    }
}
```

### 2.3 `DebitoAtualizado`

Sem alteração — saída das Strategies de juros (Seção 5).

```php
namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;

final class DebitoAtualizado
{
    public function __construct(
        public readonly Debito $original,
        public readonly BigDecimal $valorAtualizado,
        public readonly BigDecimal $juros,
        public readonly int $diasAtraso,
    ) {}
}
```

### 2.4 Estruturas de Pagamento

Apenas as *estruturas de dados* — quem as constrói é o `PagamentoSimulator`
(Design 02).

```php
namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;

final class ParcelaCartao
{
    public function __construct(
        public readonly int $quantidade,        // 1, 6 ou 12
        public readonly BigDecimal $valorParcela,
    ) {}
}

final class CartaoCreditoOpcao
{
    /** @param ParcelaCartao[] $parcelas */
    public function __construct(public readonly array $parcelas) {}
}

final class PixOpcao
{
    public function __construct(public readonly BigDecimal $totalComDesconto) {}
}

final class OpcaoPagamento
{
    public function __construct(
        public readonly string $tipo,           // "TOTAL" | "SOMENTE_<TIPO>"
        public readonly BigDecimal $valorBase,
        public readonly PixOpcao $pix,
        public readonly CartaoCreditoOpcao $cartaoCredito,
    ) {}
}
```

### 2.5 `ResultadoConsulta`

**Novidade em relação ao spec-01:** factory estático `montar()`, que soma
`total_original`/`total_atualizado` a partir de `DebitoAtualizado[]`. Cobre
naturalmente o caso de zero débitos (CB-01 / requirements §6.3): com array
vazio, `array_reduce` retorna o valor inicial `BigDecimal::zero()`, então
ambos os totais saem como `"0.00"`.

```php
namespace App\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ResultadoConsulta
{
    private function __construct(
        public readonly Placa $placa,
        public readonly array $debitos,          // DebitoAtualizado[]
        public readonly BigDecimal $totalOriginal,
        public readonly BigDecimal $totalAtualizado,
        public readonly array $opcoesPagamento,  // OpcaoPagamento[]
    ) {}

    /**
     * @param DebitoAtualizado[] $debitos
     * @param OpcaoPagamento[] $opcoesPagamento
     */
    public static function montar(Placa $placa, array $debitos, array $opcoesPagamento): self
    {
        $totalOriginal = array_reduce(
            $debitos,
            fn (BigDecimal $acc, DebitoAtualizado $d) => $acc->plus($d->original->valorOriginal),
            BigDecimal::zero(),
        );

        $totalAtualizado = array_reduce(
            $debitos,
            fn (BigDecimal $acc, DebitoAtualizado $d) => $acc->plus($d->valorAtualizado),
            BigDecimal::zero(),
        );

        return new self(
            placa: $placa,
            debitos: $debitos,
            totalOriginal: $totalOriginal->toScale(2, RoundingMode::HALF_UP),
            totalAtualizado: $totalAtualizado->toScale(2, RoundingMode::HALF_UP),
            opcoesPagamento: $opcoesPagamento,
        );
    }
}
```

## 3. Contracts (Ports)

### 3.1 `DebtProviderInterface`

```php
namespace App\Domain\Contracts;

use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\Placa;

interface DebtProviderInterface
{
    /**
     * @return Debito[]  Lista vazia é resposta VÁLIDA (REQ-PROV-04).
     * @throws \App\Domain\Exceptions\ProviderUnavailableException
     */
    public function consultar(Placa $placa): array;

    /** Identificador curto para logs/circuit breaker (ex: "provider_a"). */
    public function nome(): string;
}
```

### 3.2 `JurosStrategyInterface` e Resolver

```php
namespace App\Domain\Contracts;

use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\DebitoAtualizado;

interface JurosStrategyInterface
{
    public function suporta(string $tipoDebito): bool;

    public function calcular(
        Debito $debito,
        \DateTimeImmutable $dataReferencia,
    ): DebitoAtualizado;
}

interface JurosStrategyResolverInterface
{
    /** @throws \App\Domain\Exceptions\UnknownDebtTypeException */
    public function resolve(string $tipoDebito): JurosStrategyInterface;
}
```

### 3.3 `PaymentSimulatorInterface`

```php
namespace App\Domain\Contracts;

use App\Domain\ValueObjects\DebitoAtualizado;
use App\Domain\ValueObjects\OpcaoPagamento;

interface PaymentSimulatorInterface
{
    /**
     * @param DebitoAtualizado[] $debitos
     * @return OpcaoPagamento[]  TOTAL + uma SOMENTE_<TIPO> por tipo presente.
     */
    public function gerarOpcoes(array $debitos): array;
}
```

### 3.4 `ReferenceDateProviderInterface`

Introduzido em `00-architecture.md` §5.2 — formalizado aqui como parte do
modelo de domínio.

```php
namespace App\Domain\Contracts;

interface ReferenceDateProviderInterface
{
    /** Data de referência (UTC, sem hora) usada em todos os cálculos. */
    public function dataReferencia(): \DateTimeImmutable;
}
```

## 4. Exceções de Domínio

Sem alteração em relação ao spec-01.

```php
namespace App\Domain\Exceptions;

abstract class DomainException extends \Exception {}

/** -> HTTP 400 {"error":"invalid_plate"} */
final class InvalidPlateException extends DomainException
{
    public function __construct(public readonly string $placaOriginal)
    {
        parent::__construct("Placa inválida: {$placaOriginal}");
    }
}

/** -> HTTP 422 {"error":"unknown_debt_type","type":"<TIPO>"} */
final class UnknownDebtTypeException extends DomainException
{
    public function __construct(public readonly string $tipo)
    {
        parent::__construct("Tipo de débito desconhecido: {$tipo}");
    }
}

/**
 * Erro de UM provedor específico — capturado pelo orquestrador (Design 04)
 * para acionar o fallback. NÃO deve vazar para o controller diretamente.
 */
final class ProviderUnavailableException extends \RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $motivo,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("Provider {$provider} indisponível: {$motivo}", 0, $previous);
    }
}

/** -> HTTP 503 {"error":"all_providers_unavailable"} */
final class AllProvidersUnavailableException extends DomainException
{
    /** @param array<string,string> $tentativas provider => motivo da falha */
    public function __construct(public readonly array $tentativas)
    {
        parent::__construct('Todos os provedores estão indisponíveis');
    }
}
```

## 5. Domain Services — Strategies de Juros

### 5.1 `JurosStrategyResolver` (OCP-compliant)

Implementação detalhada em `00-architecture.md` §5.6 — repetida aqui por
completude. Recebe a lista de Strategies via injeção e itera `suporta()`;
nunca usa `match`/`switch` por tipo.

```php
namespace App\Domain\Services\Juros;

use App\Domain\Contracts\JurosStrategyInterface;
use App\Domain\Contracts\JurosStrategyResolverInterface;
use App\Domain\Exceptions\UnknownDebtTypeException;

final class JurosStrategyResolver implements JurosStrategyResolverInterface
{
    /** @param JurosStrategyInterface[] $strategies */
    public function __construct(private readonly array $strategies) {}

    public function resolve(string $tipoDebito): JurosStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->suporta($tipoDebito)) {
                return $strategy;
            }
        }

        throw new UnknownDebtTypeException($tipoDebito);
    }
}
```

A lista de Strategies (`IpvaJurosStrategy`, `MultaJurosStrategy`, ...) é
montada no `DomainServiceProvider` — ver `00-architecture.md` §5.6.

### 5.2 `IpvaJurosStrategy`

REQ-JUROS-03: taxa 0,33%/dia, teto de 20% do valor original **aplicado ao
juros**.

```php
namespace App\Domain\Services\Juros;

use App\Domain\Contracts\JurosStrategyInterface;
use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\DebitoAtualizado;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class IpvaJurosStrategy implements JurosStrategyInterface
{
    private const TIPO = 'IPVA';
    private const TAXA_DIARIA = '0.0033';   // 0,33%
    private const TETO_PERCENTUAL = '0.20'; // 20% do valor original

    public function suporta(string $tipoDebito): bool
    {
        return $tipoDebito === self::TIPO;
    }

    public function calcular(Debito $debito, \DateTimeImmutable $dataReferencia): DebitoAtualizado
    {
        $diasAtraso = $debito->diasAtraso($dataReferencia);

        if ($diasAtraso <= 0) {
            return new DebitoAtualizado(
                original: $debito,
                valorAtualizado: $debito->valorOriginal,
                juros: BigDecimal::zero(),
                diasAtraso: $diasAtraso,
            );
        }

        $jurosCalculado = $debito->valorOriginal
            ->multipliedBy(self::TAXA_DIARIA)
            ->multipliedBy($diasAtraso);

        $teto = $debito->valorOriginal->multipliedBy(self::TETO_PERCENTUAL);

        $juros = $jurosCalculado->isGreaterThan($teto)
            ? $teto
            : $jurosCalculado;

        $juros = $juros->toScale(2, RoundingMode::HALF_UP);
        $valorAtualizado = $debito->valorOriginal->plus($juros);

        return new DebitoAtualizado($debito, $valorAtualizado, $juros, $diasAtraso);
    }
}
```

> Exemplo do enunciado: `1500.00`, 121 dias →
> `min(1500×0,0033×121, 1500×0,20) = min(598,95, 300,00) = 300,00` →
> `valor_atualizado = 1800,00`. ✅

### 5.3 `MultaJurosStrategy`

REQ-JUROS-04: taxa 1,00%/dia, sem teto.

```php
namespace App\Domain\Services\Juros;

use App\Domain\Contracts\JurosStrategyInterface;
use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\DebitoAtualizado;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class MultaJurosStrategy implements JurosStrategyInterface
{
    private const TIPO = 'MULTA';
    private const TAXA_DIARIA = '0.01'; // 1,00%

    public function suporta(string $tipoDebito): bool
    {
        return $tipoDebito === self::TIPO;
    }

    public function calcular(Debito $debito, \DateTimeImmutable $dataReferencia): DebitoAtualizado
    {
        $diasAtraso = $debito->diasAtraso($dataReferencia);

        if ($diasAtraso <= 0) {
            return new DebitoAtualizado(
                original: $debito,
                valorAtualizado: $debito->valorOriginal,
                juros: BigDecimal::zero(),
                diasAtraso: $diasAtraso,
            );
        }

        $juros = $debito->valorOriginal
            ->multipliedBy(self::TAXA_DIARIA)
            ->multipliedBy($diasAtraso)
            ->toScale(2, RoundingMode::HALF_UP);

        $valorAtualizado = $debito->valorOriginal->plus($juros);

        return new DebitoAtualizado($debito, $valorAtualizado, $juros, $diasAtraso);
    }
}
```

> Exemplo do enunciado: `300.50`, 85 dias →
> `juros = 300,50×0,01×85 = 255,425 → 255,43` (HALF_UP) →
> `valor_atualizado = 555,93`. ✅

## 6. Casos de Teste (Pest) — Mapeamento

| ID | Descrição | Entrada | Esperado | Requisito | Arquivo |
|---|---|---|---|---|---|
| UT-PLACA-01 | Placa formato antigo válida | `ABC1234` | `Placa::fromString` retorna sem erro | REQ-INPUT-02 | `PlacaTest.php` |
| UT-PLACA-02 | Placa formato Mercosul válida | `ABC1D23` | idem | REQ-INPUT-02 | `PlacaTest.php` |
| UT-PLACA-03 | Placa inválida | `AB123` | `InvalidPlateException` | REQ-INPUT-02, CB-05 | `PlacaTest.php` |
| UT-IPVA-01 | IPVA com teto aplicado | `1500.00`, venc. `2024-01-10`, ref. `2024-05-10` | `dias_atraso=121`, `juros=300.00`, `valor_atualizado=1800.00` | REQ-JUROS-03 | `IpvaJurosStrategyTest.php` |
| UT-MULTA-01 | MULTA sem teto | `300.50`, venc. `2024-02-15`, ref. `2024-05-10` | `dias_atraso=85`, `juros=255.43`, `valor_atualizado=555.93` | REQ-JUROS-04 | `MultaJurosStrategyTest.php` |
| UT-EDGE-01 | Débito não vencido | `dias_atraso <= 0` | `juros=0.00`, `valor_atualizado=valor_original` | REQ-JUROS-02, CB-04 | dentro de cada `*JurosStrategyTest.php` |
| UT-RESOLVER-01 | Tipo desconhecido | `tipo="LICENCIAMENTO"` | `UnknownDebtTypeException` com `tipo="LICENCIAMENTO"` | REQ-JUROS-06, CB-03 | `JurosStrategyResolverTest.php` |
| UT-RESOLVER-02 | Extensibilidade (OCP) | Strategy fake adicional registrada | Resolver encontra sem editar Resolver/Strategies existentes | REQ-JUROS-07 | `JurosStrategyResolverTest.php` |
| UT-RESULTADO-01 | Zero débitos | `[]` | `total_original="0.00"`, `total_atualizado="0.00"` | REQ-PROV-04, CB-01 (req. §6.3) | `ResultadoConsultaTest.php` |

> **Regra de organização dos testes:** um ID de caso de teste não implica um
> arquivo separado. Casos de borda de uma Strategy (ex: `UT-EDGE-01`) vivem
> dentro do arquivo de teste da própria Strategy, não em arquivos `*Edge*` ou
> `*Border*` isolados. Criar um arquivo de teste separado só se justifica quando
> o comportamento testado pertence a uma classe própria.

## 7. Critérios de Aceite

- [ ] Nenhuma classe em `Domain/` usa `float` para dinheiro — apenas
      `Brick\Math\BigDecimal`.
- [ ] `Debito::diasAtraso()` é a única fonte de cálculo de dias de atraso —
      `IpvaJurosStrategy` e `MultaJurosStrategy` não duplicam essa lógica.
- [ ] `JurosStrategyResolver` não contém `match`/`switch` por tipo de
      débito — apenas itera `suporta()`.
- [ ] `ResultadoConsulta::montar([])` retorna totais `"0.00"` (cobre CB-01).
- [ ] Os 9 casos da Seção 6 implementados nos arquivos mapeados pela coluna
      "Arquivo" da tabela acima — um arquivo por classe testada, sem arquivos
      temáticos (`*EdgeTest`, `*BorderTest`, etc.).

## 8. Fora de Escopo

- Cálculo de PIX/Cartão e agrupamento `TOTAL`/`SOMENTE_<TIPO>` →
  **Design 02**.
- Implementação dos Adapters (Provedor A/B) e parsing JSON/XML →
  **Design 03**.
- `ConfigReferenceDateProvider` (implementação concreta do port 3.4) →
  **Design 04/05** (Infrastructure).
