<?php

use App\Http\Controllers\ConsultaVeiculoController;
use App\Http\Middleware\RejectUnexpectedFieldsMiddleware;
use Illuminate\Support\Facades\Route;

Route::post('/veiculos/debitos', ConsultaVeiculoController::class)
    ->middleware(RejectUnexpectedFieldsMiddleware::class);
