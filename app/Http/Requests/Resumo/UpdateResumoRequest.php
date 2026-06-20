<?php

namespace App\Http\Requests\Resumo;

use Illuminate\Foundation\Http\FormRequest;

class UpdateResumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idt_ofx' => ['sometimes', 'required', 'integer', 'exists:ofx,idt_ofx'],
            'nom_pessoa' => ['sometimes', 'required', 'string', 'max:255'],
            'num_ano' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'num_mes' => ['sometimes', 'required', 'integer', 'min:1', 'max:12'],
            'nom_mes' => ['sometimes', 'required', 'string', 'max:10'],
            'val_total' => ['sometimes', 'required', 'numeric', 'min:0'],
            'num_transacao' => ['sometimes', 'required', 'integer', 'min:0'],
            'ind_pago' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'idt_ofx.exists' => 'A importação OFX informada não existe.',
            'nom_pessoa.required' => 'O nome da pessoa é obrigatório.',
            'num_ano.min' => 'O ano deve ser a partir de 2000.',
            'num_mes.min' => 'O mês deve ser entre 1 e 12.',
            'num_mes.max' => 'O mês deve ser entre 1 e 12.',
            'val_total.numeric' => 'O valor total deve ser numérico.',
        ];
    }
}
