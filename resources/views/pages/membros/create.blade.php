<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<x-layouts.app>
    <div class="max-w-3xl mx-auto py-6 px-4">

        <div class="flex items-center gap-2 mb-6 text-sm text-zinc-500">
            <flux:link :href="route('membros.index')" wire:navigate>Membros</flux:link>
            <flux:icon.chevron-right class="size-3" />
            <span>Novo membro</span>
        </div>

        <flux:heading size="xl" class="mb-6">Novo Membro</flux:heading>

        <livewire:pages.membros.form />

    </div>
</x-layouts.app>
