<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Perfil;
use App\Models\User;
use App\Rules\Cpf;
use App\Rules\Telefone;
use App\Services\CpfService;
use App\Services\PhoneService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (isset($input['num_cpf_membro'])) {
            $input['num_cpf_membro'] = CpfService::format($input['num_cpf_membro']);
        }
        if (isset($input['tel_membro'])) {
            $input['tel_membro'] = PhoneService::format($input['tel_membro']);
        }

        // Merge formatted values back to request helper so User model booted hook gets formatted values
        request()->merge([
            'num_cpf_membro' => $input['num_cpf_membro'] ?? null,
            'tel_membro' => $input['tel_membro'] ?? null,
            'nom_apelido' => $input['nom_apelido'] ?? null,
            'end_logradouro' => $input['end_logradouro'] ?? null,
            'end_numero' => $input['end_numero'] ?? null,
            'end_complemento' => $input['end_complemento'] ?? null,
            'idt_associacao' => $input['idt_associacao'] ?? null,
        ]);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'idt_associacao' => ['required', 'exists:associacoes,idt_associacao'],
            'num_cpf_membro' => ['required', 'string', 'max:14', new Cpf, Rule::unique('membros', 'num_cpf_membro')],
            'tel_membro' => ['nullable', 'string', 'max:20', new Telefone],
        ], [
            'idt_associacao.required' => 'A associação é obrigatória.',
            'idt_associacao.exists' => 'A associação selecionada é inválida.',
            'num_cpf_membro.required' => 'O CPF é obrigatório.',
            'num_cpf_membro.unique' => 'Este CPF já está cadastrado.',
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => Perfil::MEMBRO,
        ]);
    }
}
