<?php

use App\Enums\Perfil;
use App\Models\Membro;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {

    public ?Membro $membro = null;

    // Dados pessoais
    public ?int $idt_associacao          = null;
    public string $nom_membro            = '';
    public string $num_cpf_membro        = '';
    public string $nom_apelido           = '';
    public string $eml_membro            = '';
    public string $tel_membro            = '';

    // Endereço
    public string $end_logradouro        = '';
    public string $end_numero            = '';
    public string $end_complemento       = '';

    // Associação
    public string $tip_associado         = '';



    public function mount(?Membro $membro = null): void
    {
        $this->membro = $membro;

        if ($membro?->exists) {
            $this->idt_associacao        = $membro->idt_associacao;
            $this->nom_membro            = $membro->nom_membro;
            $this->num_cpf_membro        = $membro->num_cpf_membro ?? '';
            $this->nom_apelido           = $membro->nom_apelido ?? '';
            $this->eml_membro            = $membro->eml_membro;
            $this->tel_membro            = $membro->tel_membro ?? '';
            $this->end_logradouro        = $membro->end_logradouro ?? '';
            $this->end_numero            = $membro->end_numero ?? '';
            $this->end_complemento       = $membro->end_complemento ?? '';
            $this->tip_associado         = $membro->tip_associado->value ?? '';

        } else {
            $this->idt_associacao        = auth()->user()->membro?->idt_associacao;
            $this->tip_associado         = Perfil::MEMBRO->value;
        }

        // Auto-select association if it is empty and only 1 association exists
        if (empty($this->idt_associacao)) {
            $firstAssoc = \App\Models\Associacao::first();
            if ($firstAssoc && \App\Models\Associacao::count() === 1) {
                $this->idt_associacao = $firstAssoc->idt_associacao;
            }
        }
    }

    protected function regras(): array
    {
        $ignorarId = $this->membro?->idt_membro;

        return [
            'idt_associacao'         => ['required', 'exists:associacoes,idt_associacao'],
            'nom_membro'             => ['required', 'string', 'max:255'],
            'num_cpf_membro'         => ['required', 'string', 'max:14', new \App\Rules\Cpf, Rule::unique('membros', 'num_cpf_membro')->ignore($ignorarId, 'idt_membro')],
            'nom_apelido'            => ['nullable', 'string', 'max:100'],
            'eml_membro'             => ['required', 'email', 'max:255', Rule::unique('membros', 'eml_membro')->ignore($ignorarId, 'idt_membro')],
            'tel_membro'             => ['nullable', 'string', 'max:20', new \App\Rules\Telefone],
            'end_logradouro'         => ['nullable', 'string', 'max:150'],
            'end_numero'             => ['nullable', 'string', 'max:20'],
            'end_complemento'        => ['nullable', 'string', 'max:150'],
            'tip_associado'          => ['required', Rule::enum(Perfil::class)],
        ];
    }

    protected function mensagens(): array
    {
        return [
            'idt_associacao.required' => 'A associação é obrigatória.',
            'idt_associacao.exists'   => 'A associação selecionada é inválida.',
            'nom_membro.required'    => 'O nome do membro é obrigatório.',
            'num_cpf_membro.required' => 'O CPF do membro é obrigatório.',
            'num_cpf_membro.unique'   => 'Este CPF já está cadastrado.',
            'num_cpf_membro.max'      => 'O CPF não pode ultrapassar 14 caracteres.',
            'eml_membro.required'    => 'O e-mail é obrigatório.',
            'eml_membro.email'       => 'Informe um e-mail válido.',
            'eml_membro.unique'      => 'Este e-mail já está cadastrado.',
            'tip_associado.required' => 'O tipo de associação é obrigatório.',
            'tip_associado.enum'     => 'O tipo de associação selecionado é inválido.',
        ];
    }

    public function salvar(): void
    {
        if (!auth()->user()->isAdmin()) {
            $this->idt_associacao = auth()->user()->membro?->idt_associacao;
        }

        $this->num_cpf_membro = \App\Services\CpfService::format($this->num_cpf_membro) ?? '';
        $this->tel_membro = \App\Services\PhoneService::format($this->tel_membro) ?? '';

        $dados = $this->validate($this->regras(), $this->mensagens());

        if ($this->membro?->exists) {
            $this->membro->update($dados);
            \Flux::toast(variant: 'success', text: __('messages.alerts.success.saved'));
        } else {
            $dados['ind_aprovado'] = true; // Auto approve manually created members
            Membro::create($dados);
            \Flux::toast(variant: 'success', text: __('messages.alerts.success.saved'));
            $this->redirecionar();
        }
    }

    public function redirecionar(): void
    {
        $this->redirectRoute('membros.index', navigate: true);
    }

    public function with(): array
    {
        return [
            'tiposAssociado' => Perfil::cases(),
            'associacoes'    => \App\Models\Associacao::orderBy('nom_associacao')->get(),
            'editando'       => $this->membro?->exists ?? false,
        ];
    }
}; ?>

<div>
    <form wire:submit="salvar" class="space-y-6">

        {{-- Dados Pessoais --}}
        <flux:card class="space-y-6">
            <div>
                <flux:heading size="sm">Dados Pessoais</flux:heading>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <flux:field class="md:col-span-2">
                    <flux:label required for="nom_membro">Nome completo</flux:label>
                    <flux:input
                        id="nom_membro"
                        wire:model="nom_membro"
                        placeholder="Ex: João Paulo Silva"
                        autocomplete="off"
                    />
                    <flux:error name="nom_membro" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label for="nom_apelido">Apelido</flux:label>
                    <flux:input
                        id="nom_apelido"
                        wire:model="nom_apelido"
                        placeholder="Ex: Joãozinho"
                        autocomplete="off"
                    />
                    <flux:error name="nom_apelido" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label required for="num_cpf_membro">CPF do membro</flux:label>
                    <flux:input
                        id="num_cpf_membro"
                        wire:model="num_cpf_membro"
                        placeholder="Ex: 000.000.000-00"
                        autocomplete="off"
                    />
                    <flux:error name="num_cpf_membro" />
                </flux:field>

                <flux:field>
                    <flux:label required for="eml_membro">E-mail</flux:label>
                    <flux:input
                        id="eml_membro"
                        type="email"
                        wire:model="eml_membro"
                        placeholder="joao@email.com"
                        autocomplete="off"
                    />
                    <flux:error name="eml_membro" />
                </flux:field>

                <flux:field>
                    <flux:label for="tel_membro">Telefone</flux:label>
                    <flux:input
                        id="tel_membro"
                        wire:model="tel_membro"
                        placeholder="(11) 99999-9999"
                    />
                    <flux:error name="tel_membro" />
                </flux:field>

                @if(auth()->user()->isAdmin())
                <flux:field>
                    <flux:label required for="idt_associacao">Associação</flux:label>
                    <x-select-associacao id="idt_associacao" wire:model="idt_associacao" placeholder="Selecione..." />
                    <flux:error name="idt_associacao" />
                </flux:field>
                @endif

                @if($editando)
                <flux:field>
                    <flux:label required for="tip_associado">Tipo de associação</flux:label>
                    <flux:select id="tip_associado" wire:model="tip_associado">
                        <flux:select.option value="">Selecione...</flux:select.option>
                        @foreach ($tiposAssociado as $tipo)
                            <flux:select.option value="{{ $tipo->value }}">
                                {{ $tipo->label() }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="tip_associado" />
                </flux:field>
                @endif

            </div>
        </flux:card>

        {{-- Endereço --}}
        <flux:card class="space-y-6">
            <div>
                <flux:heading size="sm">Endereço</flux:heading>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <flux:field class="md:col-span-2">
                    <flux:label for="end_logradouro">Logradouro</flux:label>
                    <flux:input
                        id="end_logradouro"
                        wire:model="end_logradouro"
                        placeholder="Rua das Flores"
                    />
                    <flux:error name="end_logradouro" />
                </flux:field>

                <flux:field>
                    <flux:label for="end_numero">Número</flux:label>
                    <flux:input
                        id="end_numero"
                        wire:model="end_numero"
                        placeholder="123"
                    />
                    <flux:error name="end_numero" />
                </flux:field>

                <flux:field class="md:col-span-3">
                    <flux:label for="end_complemento">Complemento / Bairro</flux:label>
                    <flux:input
                        id="end_complemento"
                        wire:model="end_complemento"
                        placeholder="Apto 42 - Jardim Primavera"
                    />
                    <flux:error name="end_complemento" />
                </flux:field>

            </div>
        </flux:card>



        {{-- Ações --}}
        <div class="flex items-center justify-end gap-3">
            <flux:button
                type="button"
                variant="ghost"
                wire:click="redirecionar"
            >
                Cancelar
            </flux:button>

            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove>
                    {{ $editando ? 'Salvar alterações' : 'Cadastrar membro' }}
                </span>
                <span wire:loading>Salvando...</span>
            </flux:button>
        </div>

    </form>
</div>
