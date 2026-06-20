<?php

use App\Models\Membro;
use Livewire\Volt\Component;
use Livewire\Attributes\Title;

new #[Title('Editar Membro')] class extends Component {

    public Membro $membro;

    public function mount(Membro $membro): void
    {
        $this->membro = $membro;
    }
}; ?>

<div class="max-w-3xl mx-auto py-6 px-4">

    <div class="flex items-center gap-2 mb-6 text-sm text-zinc-500">
        <flux:link :href="route('membros.index')" wire:navigate>Membros</flux:link>
        <flux:icon.chevron-right class="size-3" />
        <span>{{ $membro->nom_membro }}</span>
    </div>

    <flux:heading size="xl" class="mb-6">Editar Membro</flux:heading>

    <livewire:pages.membros.form :membro="$membro" />

</div>
