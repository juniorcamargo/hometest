# Design 02 — Simulação de Pagamento

**Status:** Final.
**Depende de:** `01-domain-model.md` (Value Objects `OpcaoPagamento`, `PixOpcao`,
`CartaoCreditoOpcao`, `ParcelaCartao`, `DebitoAtualizado`, e o port
`PaymentSimulatorInterface`).
**Requisitos relacionados:** REQ-PAG-01..06.

## 1. Visão Geral

Implementação concreta de `PaymentSimulatorInterface`: gera as opções
`TOTAL` e `SOMENTE_<TIPO>` (REQ-PAG-01/02), e para cada uma calcula PIX
(REQ-PAG-03) e Cartão de Crédito 1x/6x/12x (REQ-PAG-04..06).

Três classes, cada uma com responsabilidade única (SRP):

- `PixCalculator` — desconto de 5%.
- `CartaoCreditoCalculator` — parcelas 1x/6x/12x (PMT/Price para 6x e 12x).
- `PagamentoSimulator` — orquestra as duas acima + agrupamento por tipo.

## 2. `PixCalculator`

REQ-PAG-03: `total_com_desconto = valor_base × 0,95`, HALF_UP, 2 casas.

```php
namespace App\Domain\Services\Pagamento;

use App\Domain\ValueObjects\PixOpcao;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PixCalculator
{
    private const FATOR_DESCONTO = '0.95'; // 5% de desconto

    public function calcular(BigDecimal $valorBase): PixOpcao
    {
        $totalComDesconto = $valorBase
            ->multipliedBy(self::FATOR_DESCONTO)
            ->toScale(2, RoundingMode::HALF_UP);

        return new PixOpcao($totalComDesconto);
    }
}
```

> Exemplo: `2355.93 × 0,95 = 2238.1335 → 2238.13` (HALF_UP). ✅

## 3. `CartaoCreditoCalculator`

REQ-PAG-04/05/06: exatamente as parcelas `1x`, `6x`, `12x`.

- **1x**: `valor_parcela = valor_base` (sem juros).
- **6x/12x**: Price/PMT — `valor_parcela = base × i × (1+i)^n / ((1+i)^n − 1)`,
  `i = 0,025`.

`BigDecimal::power()` é exato para `1.025` (decimal finito), então
`(1+i)^n` não perde precisão. Usamos escala interna alta (10 casas) só na
divisão final do fator PMT, e arredondamos para 2 casas apenas no resultado
monetário final.

```php
namespace App\Domain\Services\Pagamento;

use App\Domain\ValueObjects\CartaoCreditoOpcao;
use App\Domain\ValueObjects\ParcelaCartao;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class CartaoCreditoCalculator
{
    private const TAXA_MENSAL = '0.025'; // 2,5% a.m.
    private const QUANTIDADES = [1, 6, 12];
    private const ESCALA_INTERNA = 10; // precisão intermediária do fator PMT

    public function calcular(BigDecimal $valorBase): CartaoCreditoOpcao
    {
        $parcelas = array_map(
            fn (int $n) => new ParcelaCartao($n, $this->valorParcela($valorBase, $n)),
            self::QUANTIDADES,
        );

        return new CartaoCreditoOpcao($parcelas);
    }

    private function valorParcela(BigDecimal $valorBase, int $n): BigDecimal
    {
        if ($n === 1) {
            return $valorBase->toScale(2, RoundingMode::HALF_UP);
        }

        $i = BigDecimal::of(self::TAXA_MENSAL);
        $umMaisI = $i->plus(1);          // 1.025
        $potencia = $umMaisI->power($n); // (1.025)^n — exato

        $numerador = $i->multipliedBy($potencia);
        $denominador = $potencia->minus(1);

        $fator = $numerador->dividedBy($denominador, self::ESCALA_INTERNA, RoundingMode::HALF_UP);

        return $valorBase->multipliedBy($fator)->toScale(2, RoundingMode::HALF_UP);
    }
}
```

> Exemplos (`valor_base = 2355.93`):
> - `(1.025)^6 = 1.159693419...` → fator ≈ `0.18155` → `427.72` ✅
> - `(1.025)^12 = 1.344888...`   → fator ≈ `0.09749` → `229.67` ✅ (tolerância ±0,02)

## 4. `PagamentoSimulator`

REQ-PAG-01/02: `TOTAL` soma todos os `valor_atualizado`; uma `SOMENTE_<TIPO>`
por tipo presente, somando os `valor_atualizado` daquele tipo (cobre CB-07 —
múltiplos débitos do mesmo tipo).

```php
namespace App\Domain\Services\Pagamento;

use App\Domain\Contracts\PaymentSimulatorInterface;
use App\Domain\ValueObjects\DebitoAtualizado;
use App\Domain\ValueObjects\OpcaoPagamento;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PagamentoSimulator implements PaymentSimulatorInterface
{
    public function __construct(
        private readonly PixCalculator $pix,
        private readonly CartaoCreditoCalculator $cartao,
    ) {}

    /** @param DebitoAtualizado[] $debitos */
    public function gerarOpcoes(array $debitos): array
    {
        $opcoes = [];

        $totalBase = $this->somar($debitos, fn (DebitoAtualizado $d) => $d->valorAtualizado);
        $opcoes[] = $this->montarOpcao('TOTAL', $totalBase);

        /** @var array<string, BigDecimal> $porTipo */
        $porTipo = [];
        foreach ($debitos as $debito) {
            $tipo = $debito->original->tipo;
            $porTipo[$tipo] = ($porTipo[$tipo] ?? BigDecimal::zero())
                ->plus($debito->valorAtualizado);
        }

        foreach ($porTipo as $tipo => $valorBase) {
            $opcoes[] = $this->montarOpcao(
                "SOMENTE_{$tipo}",
                $valorBase->toScale(2, RoundingMode::HALF_UP),
            );
        }

        return $opcoes;
    }

    /**
     * @param DebitoAtualizado[] $debitos
     * @param callable(DebitoAtualizado): BigDecimal $extrator
     */
    private function somar(array $debitos, callable $extrator): BigDecimal
    {
        $soma = array_reduce(
            $debitos,
            fn (BigDecimal $acc, DebitoAtualizado $d) => $acc->plus($extrator($d)),
            BigDecimal::zero(),
        );

        return $soma->toScale(2, RoundingMode::HALF_UP);
    }

    private function montarOpcao(string $tipo, BigDecimal $valorBase): OpcaoPagamento
    {
        return new OpcaoPagamento(
            tipo: $tipo,
            valorBase: $valorBase,
            pix: $this->pix->calcular($valorBase),
            cartaoCredito: $this->cartao->calcular($valorBase),
        );
    }
}
```

Com `$debitos = []` (CB-01 / requirements §6.3): `$totalBase = 0.00`,
`$porTipo` fica vazio → resultado é `[OpcaoPagamento('TOTAL', 0.00, ...)]`,
exatamente como decidido.

## 5. Validação Numérica — Exemplo Completo do Enunciado

Tabela de referência para o teste de integração "golden" (placa `ABC1234`).
Tolerância ±R$0,02 nos valores de cartão.

| Opção | `valor_base` | PIX (`× 0,95`) | Cartão 1x | Cartão 6x | Cartão 12x |
|---|---|---|---|---|---|
| `TOTAL` | `2355.93` | `2238.13` | `2355.93` | `427.72` | `229.67` |
| `SOMENTE_IPVA` | `1800.00` | `1710.00` | `1800.00` | `326.79` | `175.48` |
| `SOMENTE_MULTA` | `555.93` | `528.13` | `555.93` | `100.93` | `54.20` |

## 6. Binding (Composition Root)

```php
// DomainServiceProvider (00-architecture.md §5.3)
$this->app->bind(PaymentSimulatorInterface::class, PagamentoSimulator::class);
```

`PixCalculator` e `CartaoCreditoCalculator` são classes concretas sem
dependências externas — o container do Laravel resolve automaticamente os
construtores de `PagamentoSimulator`, sem binding adicional.

## 7. Casos de Teste (Pest)

| ID | Descrição | Entrada | Esperado | Requisito |
|---|---|---|---|---|
| UT-PIX-01 | Desconto de 5% | `valor_base=2355.93` | `total_com_desconto=2238.13` | REQ-PAG-03 |
| UT-CARTAO-01 | 1x sem juros | qualquer `valor_base` | `valor_parcela(1) == valor_base` | REQ-PAG-05 |
| UT-CARTAO-02 | 6x PMT | `valor_base=2355.93` | `valor_parcela(6)=427.72` (±0,02) | REQ-PAG-06 |
| UT-CARTAO-03 | 12x PMT | `valor_base=2355.93` | `valor_parcela(12)=229.67` (±0,02) | REQ-PAG-06 |
| UT-CARTAO-04 | Somente 1/6/12 | qualquer `valor_base` | exatamente 3 parcelas, quantidades `[1,6,12]` | REQ-PAG-04 |
| UT-SIM-01 | `TOTAL` agrega todos | IPVA `1800.00` + MULTA `555.93` | `TOTAL.valor_base=2355.93` | REQ-PAG-01 |
| UT-SIM-02 | `SOMENTE_<TIPO>` por tipo | mesmos débitos | `SOMENTE_IPVA.valor_base=1800.00`, `SOMENTE_MULTA.valor_base=555.93` | REQ-PAG-02 |
| UT-SIM-03 | Múltiplos débitos do mesmo tipo | 2× `MULTA` | uma única `SOMENTE_MULTA` somando ambos | REQ-PAG-02, CB-07 |
| UT-SIM-04 | Zero débitos | `[]` | apenas `OpcaoPagamento('TOTAL', 0.00, ...)` | CB-01 (req. §6.3) |
| UT-GOLDEN-01 | Exemplo completo do enunciado | débitos IPVA+MULTA do exemplo | tabela da Seção 5, valor a valor | Todos REQ-PAG-* |

## 8. Critérios de Aceite

- [ ] Nenhuma classe usa `float` — apenas `BigDecimal`.
- [ ] `CartaoCreditoCalculator` nunca retorna parcelas além de `[1, 6, 12]`.
- [ ] `PagamentoSimulator::gerarOpcoes([])` retorna array com **apenas** a
      opção `TOTAL`, `valor_base="0.00"`.
- [ ] UT-GOLDEN-01 passa reproduzindo a tabela da Seção 5 inteira.

## 9. Fora de Escopo

- Como os `DebitoAtualizado[]` chegam até aqui (Adapters/Use Case) →
  **Design 03** e `00-architecture.md` §4.
