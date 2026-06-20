<?php

namespace App\Http\Requests\Transacao;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $transacaoId = $this->route('transacao')?->idt_transacao;

        return [
            'idt_ofx' => ['sometimes', 'required', 'integer', 'exists:ofx,idt_ofx'],
            'num_transacao' => ['nullable', 'string', 'max:255', Rule::unique('transacoes', 'num_transacao')->ignore($transacaoId)],
            'dat_transacao' => ['sometimes', 'required', 'date'],
            'tip_transacao' => ['nullable', 'string', 'in:CREDIT,DEBIT', 'max:20'],
            'val_transacao' => ['sometimes', 'required', 'numeric', 'min:-999999999.99', 'max:999999999.99'],
            'des_transacao' => ['nullable', 'string', 'max:255'],
            'num_check' => ['nullable', 'string', 'max:255'],
            'nom_pessoa' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'idt_ofx.exists' => 'A importação OFX informada não existe.',
            'dat_transacao.date' => 'Informe uma data válida.',
            'tip_transacao.in' => 'O tipo deve ser CREDIT ou DEBIT.',
            'val_transacao.numeric' => 'O valor deve ser numérico.',
            'num_transacao.unique' => 'Esta transação já foi importada.',
        ];
    }
}
