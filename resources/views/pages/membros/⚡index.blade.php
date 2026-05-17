<?php

use App\Enums\TipoAssociado;
use App\Models\Membro;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $busca        = '';
    public string $tip_associado = '';

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function updatedTipAssociado(): void
    {
        $this->resetPage();
    }

    public function excluir(int $id): void
    {
        $membro = Membro::findOrFail($id);
        $membro->delete();

        $this->dispatch('toast', message: 'Membro removido com sucesso!', variant: 'success');
    }

    public function with(): array
    {
        $membros = Membro::query()
            ->when($this->busca, fn ($q) =>
                $q->where('nom_membro', 'like', "%{$this->busca}%")
                  ->orWhere('eml_membro', 'like', "%{$this->busca}%")
            )
            ->when($this->tip_associado, fn ($q) =>
                $q->where('tip_associado', $this->tip_associado)
            )
            ->orderBy('nom_membro')
            ->paginate(15);

        return [
            'membros'        => $membros,
            'tiposAssociado' => TipoAssociado::cases(),
        ];
    }
}; ?>

<div class="space-y-4">

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Membros</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('membros.create')" wire:navigate>
            Novo membro
        </flux:button>
    </div>

    {{-- Filtros --}}
    <flux:card>
        <flux:card.body class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="busca"
                    placeholder="Buscar por nome ou e-mail..."
                    icon="magnifying-glass"
                    clearable
                />
            </div>
            <div class="sm:w-52">
                <flux:select wire:model.live="tip_associado">
                    <flux:select.option value="">Todos os tipos</flux:select.option>
                    @foreach ($tiposAssociado as $tipo)
                        <flux:select.option value="{{ $tipo->value }}">
                            {{ $tipo->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:card.body>
    </flux:card>

    {{-- Tabela --}}
    <flux:card>
        <flux:table>
            <flux:table.head>
                <flux:table.row>
                    <flux:table.heading>Nome</flux:table.heading>
                    <flux:table.heading>E-mail</flux:table.heading>
                    <flux:table.heading>Telefone</flux:table.heading>
                    <flux:table.heading>Tipo</flux:table.heading>
                    <flux:table.heading>Notificações</flux:table.heading>
                    <flux:table.heading class="text-right">Ações</flux:table.heading>
                </flux:table.row>
            </flux:table.head>

            <flux:table.body>
                @forelse ($membros as $membro)
                    <flux:table.row wire:key="{{ $membro->idt_membro }}">

                        <flux:table.cell class="font-medium">
                            {{ $membro->nom_membro }}
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-500">
                            {{ $membro->eml_membro }}
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-500">
                            {{ $membro->tel_membro ?? '—' }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge
                                variant="pill"
                                color="{{ match($membro->tip_associado) {
                                    \App\Enums\TipoAssociado::ASSOCIADO => 'blue',
                                    \App\Enums\TipoAssociado::DIRETOR   => 'purple',
                                    \App\Enums\TipoAssociado::HONORARIO => 'amber',
                                } }}"
                            >
                                {{ $membro->tip_associado->label() }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex gap-1.5">
                                @if ($membro->ind_notificar_whatsapp)
                                    <flux:tooltip content="WhatsApp">
                                        <flux:icon.phone class="size-4 text-green-500" />
                                    </flux:tooltip>
                                @endif
                                @if ($membro->ind_notificar_email)
                                    <flux:tooltip content="E-mail">
                                        <flux:icon.envelope class="size-4 text-blue-500" />
                                    </flux:tooltip>
                                @endif
                                @if ($membro->ind_notificar_telegram)
                                    <flux:tooltip content="Telegram">
                                        <flux:icon.paper-airplane class="size-4 text-sky-500" />
                                    </flux:tooltip>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <flux:button.group>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil"
                                    :href="route('membros.edit', $membro)"
                                    wire:navigate
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="excluir({{ $membro->idt_membro }})"
                                    wire:confirm="Tem certeza que deseja remover {{ $membro->nom_membro }}?"
                                />
                            </flux:button.group>
                        </flux:table.cell>

                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="py-12 text-center text-zinc-400">
                            Nenhum membro encontrado.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.body>
        </flux:table>

        @if ($membros->hasPages())
            <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-700">
                {{ $membros->links() }}
            </div>
        @endif
    </flux:card>

</div>
