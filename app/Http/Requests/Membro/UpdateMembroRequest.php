<?php

namespace App\Http\Requests\Membro;

use App\Enums\Perfil;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMembroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $membroId = $this->route('membro')?->idt_membro;

        return [
            'nom_membro' => ['sometimes', 'required', 'string', 'max:255'],
            'nom_apelido' => ['nullable', 'string', 'max:100'],
            'eml_membro' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('membros', 'eml_membro')->ignore($membroId)],
            'tel_membro' => ['nullable', 'string', 'max:20'],
            'end_logradouro' => ['nullable', 'string', 'max:150'],
            'end_numero' => ['nullable', 'string', 'max:20'],
            'end_complemento' => ['nullable', 'string', 'max:150'],
            'tip_associado' => ['sometimes', 'required', 'string', Rule::enum(Perfil::class)],
            'des_telegram_chat_id' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom_membro.required' => 'O nome do membro é obrigatório.',
            'nom_membro.max' => 'O nome não pode ultrapassar 255 caracteres.',
            'eml_membro.required' => 'O e-mail é obrigatório.',
            'eml_membro.email' => 'Informe um e-mail válido.',
            'eml_membro.unique' => 'Este e-mail já está cadastrado.',
            'tip_associado.required' => 'O tipo de associação é obrigatório.',
            'tip_associado.in' => 'O tipo de associação selecionado é inválido.',
        ];
    }
}
