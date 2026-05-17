<?php

namespace App\Http\Requests\Resumo;

use Illuminate\Foundation\Http\FormRequest;

class StoreResumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idt_ofx' => ['required', 'integer', 'exists:ofx,id'],
            'nom_pessoa' => ['required', 'string', 'max:255'],
            'num_ano' => ['required', 'integer', 'min:2000', 'max:2100'],
            'num_mes' => ['required', 'integer', 'min:1', 'max:12'],
            'nom_mes' => ['required', 'string', 'max:10'],
            'val_total' => ['required', 'numeric', 'min:0'],
            'num_transacao' => ['required', 'integer', 'min:0'],
            'ind_pago' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'idt_ofx.required' => 'A importação OFX é obrigatória.',
            'idt_ofx.exists' => 'A importação OFX informada não existe.',
            'nom_pessoa.required' => 'O nome da pessoa é obrigatório.',
            'num_ano.required' => 'O ano é obrigatório.',
            'num_ano.min' => 'O ano deve ser a partir de 2000.',
            'num_mes.required' => 'O mês é obrigatório.',
            'num_mes.min' => 'O mês deve ser entre 1 e 12.',
            'num_mes.max' => 'O mês deve ser entre 1 e 12.',
            'nom_mes.required' => 'O nome do mês é obrigatório.',
            'val_total.required' => 'O valor total é obrigatório.',
            'val_total.numeric' => 'O valor total deve ser numérico.',
            'num_transacao.required' => 'A quantidade de transações é obrigatória.',
        ];
    }
}
