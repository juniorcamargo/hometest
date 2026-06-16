<?php

namespace App\Application\UseCases;

use App\Domain\Contracts\DebtProviderInterface;
use App\Domain\Contracts\JurosStrategyResolverInterface;
use App\Domain\Contracts\PaymentSimulatorInterface;
use App\Domain\Contracts\ReferenceDateProviderInterface;
use App\Domain\ValueObjects\Debito;
use App\Domain\ValueObjects\Placa;
use App\Domain\ValueObjects\ResultadoConsulta;

final class ConsultarDebitosVeiculoUseCase
{
    public function __construct(
        private readonly DebtProviderInterface $provider,
        private readonly JurosStrategyResolverInterface $jurosResolver,
        private readonly PaymentSimulatorInterface $paymentSimulator,
        private readonly ReferenceDateProviderInterface $referenceDate,
    ) {}

    public function handle(string $placaInput): ResultadoConsulta
    {
        $placa = Placa::fromString($placaInput);
        $dataReferencia = $this->referenceDate->dataReferencia();
        $debitos = $this->provider->consultar($placa);

        $debitosAtualizados = array_map(
            fn (Debito $d) => $this->jurosResolver->resolve($d->tipo)->calcular($d, $dataReferencia),
            $debitos,
        );

        $opcoes = $this->paymentSimulator->gerarOpcoes($debitosAtualizados);

        return ResultadoConsulta::montar($placa, $debitosAtualizados, $opcoes);
    }
}
