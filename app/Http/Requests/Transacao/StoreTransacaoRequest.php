<?php

namespace App\Http\Requests\Transacao;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idt_ofx' => ['required', 'integer', 'exists:ofx,idt_ofx'],
            'num_transacao' => ['nullable', 'string', 'max:255', 'unique:transacoes,num_transacao'],
            'dat_transacao' => ['required', 'date'],
            'tip_transacao' => ['nullable', 'string', 'in:CREDIT,DEBIT', 'max:20'],
            'val_transacao' => ['required', 'numeric', 'min:-999999999.99', 'max:999999999.99'],
            'des_transacao' => ['nullable', 'string', 'max:255'],
            'num_check' => ['nullable', 'string', 'max:255'],
            'nom_pessoa' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'idt_ofx.required' => 'A importação OFX é obrigatória.',
            'idt_ofx.exists' => 'A importação OFX informada não existe.',
            'dat_transacao.required' => 'A data da transação é obrigatória.',
            'dat_transacao.date' => 'Informe uma data válida.',
            'tip_transacao.in' => 'O tipo deve ser CREDIT ou DEBIT.',
            'val_transacao.required' => 'O valor da transação é obrigatório.',
            'val_transacao.numeric' => 'O valor deve ser numérico.',
            'num_transacao.unique' => 'Esta transação já foi importada.',
        ];
    }
}
