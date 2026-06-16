<?php

use App\Http\Controllers\ConsultaVeiculoController;
use Illuminate\Support\Facades\Route;

Route::post('/veiculos/debitos', ConsultaVeiculoController::class);
