<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ConsultarDebitosVeiculoUseCase;
use App\Http\Requests\ConsultaVeiculoRequest;
use App\Http\Resources\ResultadoConsultaResource;

final class ConsultaVeiculoController
{
    public function __construct(private readonly ConsultarDebitosVeiculoUseCase $useCase) {}

    public function __invoke(ConsultaVeiculoRequest $request): ResultadoConsultaResource
    {
        return new ResultadoConsultaResource(
            $this->useCase->handle($request->string('placa')->toString()),
        );
    }
}
