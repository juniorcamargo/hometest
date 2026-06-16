<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConsultaVeiculoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['placa' => ['required', 'string']];
    }
}
