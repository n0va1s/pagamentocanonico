<?php

use App\Enums\TipoAssociado;
use App\Models\Membro;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {

    public ?Membro $membro = null;

    // Dados pessoais
    public string $nom_membro            = '';
    public string $nom_ofx               = '';
    public string $eml_membro            = '';
    public string $tel_membro            = '';

    // Endereço
    public string $end_logradouro        = '';
    public string $end_mumero            = '';
    public string $end_complemento       = '';

    // Associação
    public string $tip_associado         = '';

    // Notificações
    public string $des_telegram_chat_id  = '';
    public bool   $ind_notificar_whatsapp = true;
    public bool   $ind_notificar_email    = true;
    public bool   $ind_notificar_telegram = false;

    public function mount(?Membro $membro = null): void
    {
        $this->membro = $membro;

        if ($membro?->exists) {
            $this->nom_membro            = $membro->nom_membro;
            $this->nom_ofx               = $membro->nom_ofx ?? '';
            $this->eml_membro            = $membro->eml_membro;
            $this->tel_membro            = $membro->tel_membro ?? '';
            $this->end_logradouro        = $membro->end_logradouro ?? '';
            $this->end_mumero            = $membro->end_mumero ?? '';
            $this->end_complemento       = $membro->end_complemento ?? '';
            $this->tip_associado         = $membro->tip_associado->value ?? '';
            $this->des_telegram_chat_id  = $membro->des_telegram_chat_id ?? '';
            $this->ind_notificar_whatsapp = $membro->ind_notificar_whatsapp;
            $this->ind_notificar_email    = $membro->ind_notificar_email;
            $this->ind_notificar_telegram = $membro->ind_notificar_telegram;
        }
    }

    protected function regras(): array
    {
        $ignorarId = $this->membro?->idt_membro;

        return [
            'nom_membro'             => ['required', 'string', 'max:255'],
            'nom_ofx'                => ['nullable', 'string', 'max:255'],
            'eml_membro'             => ['required', 'email', 'max:255', Rule::unique('membros', 'eml_membro')->ignore($ignorarId, 'idt_membro')],
            'tel_membro'             => ['nullable', 'string', 'max:20'],
            'end_logradouro'         => ['nullable', 'string', 'max:150'],
            'end_mumero'             => ['nullable', 'string', 'max:20'],
            'end_complemento'        => ['nullable', 'string', 'max:150'],
            'tip_associado'          => ['required', Rule::enum(TipoAssociado::class)],
            'des_telegram_chat_id'   => ['nullable', 'string', 'max:50'],
            'ind_notificar_whatsapp' => ['boolean'],
            'ind_notificar_email'    => ['boolean'],
            'ind_notificar_telegram' => ['boolean'],
        ];
    }

    protected function mensagens(): array
    {
        return [
            'nom_membro.required'    => 'O nome do membro é obrigatório.',
            'eml_membro.required'    => 'O e-mail é obrigatório.',
            'eml_membro.email'       => 'Informe um e-mail válido.',
            'eml_membro.unique'      => 'Este e-mail já está cadastrado.',
            'tip_associado.required' => 'O tipo de associação é obrigatório.',
            'tip_associado.enum'     => 'O tipo de associação selecionado é inválido.',
        ];
    }

    public function salvar(): void
    {
        $dados = $this->validate($this->regras(), $this->mensagens());

        if ($this->membro?->exists) {
            $this->membro->update($dados);
            $this->dispatch('toast', message: 'Membro atualizado com sucesso!', variant: 'success');
        } else {
            Membro::create($dados);
            $this->dispatch('toast', message: 'Membro cadastrado com sucesso!', variant: 'success');
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
            'tiposAssociado' => TipoAssociado::cases(),
            'editando'       => $this->membro?->exists ?? false,
        ];
    }
}; ?>

<div>
    <form wire:submit="salvar" class="space-y-6">

        {{-- Dados Pessoais --}}
        <flux:card>
            <flux:card.header>
                <flux:heading size="sm">Dados Pessoais</flux:heading>
            </flux:card.header>

            <flux:card.body class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <flux:field class="md:col-span-2">
                    <flux:label for="nom_membro">Nome completo <flux:required /></flux:label>
                    <flux:input
                        id="nom_membro"
                        wire:model="nom_membro"
                        placeholder="Ex: João Paulo Silva"
                        autocomplete="off"
                    />
                    <flux:error name="nom_membro" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label for="nom_ofx">Nome no extrato bancário</flux:label>
                    <flux:input
                        id="nom_ofx"
                        wire:model="nom_ofx"
                        placeholder="Ex: JOAO PAULO SILVA"
                        autocomplete="off"
                    />
                    <flux:description>
                        Preencha somente se o nome no extrato OFX for diferente do nome cadastrado acima.
                        Usado para identificar pagamentos automaticamente.
                    </flux:description>
                    <flux:error name="nom_ofx" />
                </flux:field>

                <flux:field>
                    <flux:label for="eml_membro">E-mail <flux:required /></flux:label>
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

                <flux:field>
                    <flux:label for="tip_associado">Tipo de associação <flux:required /></flux:label>
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

            </flux:card.body>
        </flux:card>

        {{-- Endereço --}}
        <flux:card>
            <flux:card.header>
                <flux:heading size="sm">Endereço</flux:heading>
            </flux:card.header>

            <flux:card.body class="grid grid-cols-1 md:grid-cols-3 gap-4">

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
                    <flux:label for="end_mumero">Número</flux:label>
                    <flux:input
                        id="end_mumero"
                        wire:model="end_mumero"
                        placeholder="123"
                    />
                    <flux:error name="end_mumero" />
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

            </flux:card.body>
        </flux:card>

        {{-- Notificações --}}
        <flux:card>
            <flux:card.header>
                <flux:heading size="sm">Notificações</flux:heading>
                <flux:subheading>Canais por onde o membro receberá alertas de inadimplência.</flux:subheading>
            </flux:card.header>

            <flux:card.body class="space-y-4">

                <flux:field variant="inline">
                    <flux:checkbox id="ind_notificar_whatsapp" wire:model="ind_notificar_whatsapp" />
                    <flux:label for="ind_notificar_whatsapp">Notificar por WhatsApp</flux:label>
                </flux:field>

                <flux:field variant="inline">
                    <flux:checkbox id="ind_notificar_email" wire:model="ind_notificar_email" />
                    <flux:label for="ind_notificar_email">Notificar por E-mail</flux:label>
                </flux:field>

                <flux:field variant="inline">
                    <flux:checkbox id="ind_notificar_telegram" wire:model="ind_notificar_telegram" />
                    <flux:label for="ind_notificar_telegram">Notificar por Telegram</flux:label>
                </flux:field>

                <flux:field x-show="$wire.ind_notificar_telegram" x-cloak>
                    <flux:label for="des_telegram_chat_id">Chat ID do Telegram</flux:label>
                    <flux:input
                        id="des_telegram_chat_id"
                        wire:model="des_telegram_chat_id"
                        placeholder="Ex: 123456789"
                    />
                    <flux:description>Obtido ao iniciar conversa com o bot.</flux:description>
                    <flux:error name="des_telegram_chat_id" />
                </flux:field>

            </flux:card.body>
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
