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

    protected function prepareForValidation(): void
    {
        if ($this->has('num_cpf_membro')) {
            $this->merge([
                'num_cpf_membro' => \App\Services\CpfService::format($this->num_cpf_membro),
            ]);
        }
        if ($this->has('tel_membro')) {
            $this->merge([
                'tel_membro' => \App\Services\PhoneService::format($this->tel_membro),
            ]);
        }
    }

    public function rules(): array
    {
        $membroId = $this->route('membro')?->idt_membro;

        return [
            'nom_membro' => ['sometimes', 'required', 'string', 'max:255'],
            'num_cpf_membro' => ['sometimes', 'required', 'string', 'max:14', new \App\Rules\Cpf, Rule::unique('membros', 'num_cpf_membro')->ignore($membroId, 'idt_membro')],
            'nom_apelido' => ['nullable', 'string', 'max:100'],
            'eml_membro' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('membros', 'eml_membro')->ignore($membroId, 'idt_membro')],
            'tel_membro' => ['nullable', 'string', 'max:20', new \App\Rules\Telefone],
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
            'num_cpf_membro.required' => 'O CPF do membro é obrigatório.',
            'num_cpf_membro.unique' => 'Este CPF já está cadastrado.',
            'num_cpf_membro.max' => 'O CPF não pode ultrapassar 14 caracteres.',
            'eml_membro.required' => 'O e-mail é obrigatório.',
            'eml_membro.email' => 'Informe um e-mail válido.',
            'eml_membro.unique' => 'Este e-mail já está cadastrado.',
            'tip_associado.required' => 'O tipo de associação é obrigatório.',
            'tip_associado.in' => 'O tipo de associação selecionado é inválido.',
        ];
    }
}
