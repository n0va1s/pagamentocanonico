<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Title;

new #[Title('Nova Associação')] class extends Component {
    public function mount(): void
    {
        if (!auth()->user()->isAdmin()) {
            abort(403, 'Acesso não autorizado.');
        }
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">
                Nova Associação
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                Preencha os dados abaixo para cadastrar uma nova associação.
            </p>
        </div>
        <flux:button variant="ghost" icon="arrow-left" wire:navigate href="{{ route('associacoes.index') }}">
            Voltar
        </flux:button>
    </div>

    <livewire:pages.associacoes.form />
</div>
