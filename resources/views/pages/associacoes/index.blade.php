<?php

use App\Models\Associacao;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Cadastro de Associações')] class extends Component {
    use WithPagination;

    public string $busca = '';

    public function mount(): void
    {
        if (!auth()->user()->isAdmin()) {
            abort(403, 'Acesso não autorizado.');
        }
    }

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function excluirAssociacao(int $id): void
    {
        $assoc = Associacao::findOrFail($id);
        $assoc->delete();

        \Flux::toast(variant: 'success', text: __('messages.alerts.success.deleted'));
    }

    public function with(): array
    {
        $associacoes = Associacao::query()
            ->withCount('membros')
            ->when($this->busca, fn ($q) =>
                $q->where('nom_associacao', 'like', "%{$this->busca}%")
            )
            ->orderBy('nom_associacao')
            ->paginate(10);

        return [
            'associacoes' => $associacoes,
        ];
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto" x-data="{ showFilters: false }">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                <flux:icon name="building-office-2" class="size-6 text-blue-600" /> Cadastro de Associações
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                Gerencie as associações registradas no sistema.
            </p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <flux:button variant="primary" icon="plus" wire:navigate href="{{ route('associacoes.create') }}">
                Nova Associação
            </flux:button>
        </div>
    </div>

    {{-- Filtros --}}
    <div>
        <div class="flex gap-4 mb-4">
            <button @click="showFilters = !showFilters" class="flex items-center gap-2 text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100 transition-colors">
                <flux:icon name="funnel" class="size-4" />
                <span x-text="showFilters ? 'Ocultar Filtros' : 'Mostrar Filtros'">Mostrar Filtros</span>
            </button>
        </div>
        
        <div x-show="showFilters" style="display: none;">
            <flux:card class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <flux:input
                        wire:model.live.debounce.300ms="busca"
                        placeholder="Buscar por nome da associação..."
                        icon="magnifying-glass"
                        clearable
                        aria-label="Buscar associações"
                    />
                </div>
            </flux:card>
        </div>
    </div>

    {{-- Lista/Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($associacoes as $assoc)
            <flux:card class="flex flex-col justify-between p-5 space-y-4" wire:key="assoc-{{ $assoc->idt_associacao }}">
                <div class="space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-bold text-lg text-neutral-800 dark:text-neutral-200">
                                {{ $assoc->nom_associacao }}
                            </h3>
                            <div class="mt-1">
                                <flux:badge size="sm" color="zinc" class="font-semibold">
                                    {{ $assoc->membros_count }} {{ Str::plural('membro', $assoc->membros_count) }}
                                </flux:badge>
                            </div>
                        </div>
                        <div class="flex gap-1 flex-shrink-0">
                            <flux:button wire:navigate href="{{ route('associacoes.edit', $assoc) }}" size="sm" variant="ghost" icon="pencil" aria-label="Editar associação" />
                            <flux:button wire:click="excluirAssociacao({{ $assoc->idt_associacao }})" wire:confirm="Deseja remover esta associação?" size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-700" aria-label="Remover associação" />
                        </div>
                    </div>

                        <div class="pt-2 border-t border-neutral-100 dark:border-neutral-800/60 space-y-2 text-xs text-neutral-600 dark:text-neutral-400">
                            @if($assoc->tel_contato)
                                <div class="flex justify-between">
                                    <span class="text-neutral-400 dark:text-neutral-500">Telefone:</span>
                                    <span class="font-medium text-neutral-800 dark:text-neutral-200">{{ $assoc->tel_contato }}</span>
                                </div>
                            @endif
                            @if($assoc->chave_pix)
                                <div class="flex justify-between items-center gap-2">
                                    <span class="text-neutral-400 dark:text-neutral-500">Chave PIX:</span>
                                    <span class="font-mono text-neutral-800 dark:text-neutral-200 truncate select-all">{{ $assoc->chave_pix }}</span>
                                </div>
                            @endif
                            @php $taxaVigenteAssoc = $assoc->getTaxaVigenteEm(); @endphp
                            @if($taxaVigenteAssoc?->val_taxa)
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-neutral-500">Taxa Mensal</span>
                                    <span class="font-semibold text-neutral-800 dark:text-neutral-200">R$ {{ number_format($taxaVigenteAssoc->val_taxa, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            @if($taxaVigenteAssoc?->val_anual)
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-neutral-500">Anuidade</span>
                                    <span class="font-semibold text-neutral-800 dark:text-neutral-200">R$ {{ number_format($taxaVigenteAssoc->val_anual, 2, ',', '.') }}</span>
                                </div>
                            @endif
                        </div>
                </div>
            </flux:card>
        @empty
            <div class="col-span-full py-12 text-center text-zinc-400 bg-white border border-neutral-200 dark:border-neutral-700 dark:bg-zinc-900 rounded-xl shadow-xs">
                Nenhuma associação encontrada.
            </div>
        @endforelse
    </div>

    @if ($associacoes->hasPages())
        <div class="mt-6">
            {{ $associacoes->links() }}
        </div>
    @endif

</div>
