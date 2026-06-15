<?php

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

        ksort($porTipo);

        foreach ($porTipo as $tipo => $valorBase) {
            $opcoes[] = $this->montarOpcao(
                "SOMENTE_{$tipo}",
                $valorBase->toScale(2, RoundingMode::HalfUp),
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

        return $soma->toScale(2, RoundingMode::HalfUp);
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
