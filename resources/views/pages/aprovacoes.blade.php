<?php

use App\Models\Membro;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Aprovações Pendentes')] class extends Component {
    use WithPagination;

    public string $selectedAssociacaoId = '';

    public function updatedSelectedAssociacaoId(): void
    {
        $this->resetPage();
    }

    public function aprovarMembro(int $id): void
    {
        $membro = Membro::withoutGlobalScope('associacao')->findOrFail($id);

        // Check permission: director can paginonly approve members of their own association
        if (!auth()->user()->isAdmin() && $membro->idt_associacao !== auth()->user()->membro?->idt_associacao) {
            \Flux::toast(variant: 'danger', text: 'Acesso não autorizado.');
            return;
        }

        $membro->update([
            'ind_aprovado' => true,
            'usu_autorizador' => auth()->user()->email,
        ]);

        \Flux::toast(variant: 'success', text: __('messages.alerts.success.saved'));
    }

    public function with(): array
    {
        $membrosPendentes = Membro::withoutGlobalScope('associacao')
            ->where('ind_aprovado', false)
            ->when(!auth()->user()->isAdmin(), function ($q) {
                $q->where('idt_associacao', auth()->user()->membro?->idt_associacao);
            })
            ->when(auth()->user()->isAdmin() && $this->selectedAssociacaoId, function ($q) {
                $q->where('idt_associacao', $this->selectedAssociacaoId);
            })
            ->with('associacao')
            ->orderBy('nom_membro')
            ->paginate(15);

        return [
            'membrosPendentes' => $membrosPendentes,
            'associacoes' => auth()->user()->isAdmin() ? \App\Models\Associacao::orderBy('nom_associacao')->get() : collect(),
        ];
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto" x-data="{ showFilters: false }">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                <flux:icon name="check-badge" class="size-6 text-blue-600" /> Aprovações Pendentes
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                Aprove solicitações de adesão de novos usuários à sua associação.
            </p>
        </div>
    </div>

    @if(auth()->user()->isAdmin())
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
                    <div class="sm:w-72">
                        <x-select-associacao wire:model.live="selectedAssociacaoId" aria-label="Associação" :show-all-option="true" />
                    </div>
                </flux:card>
            </div>
        </div>
    @endif

    {{-- Lista/Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse ($membrosPendentes as $membro)
            <flux:card class="flex flex-col justify-between p-5 space-y-4" wire:key="pendente-{{ $membro->idt_membro }}">
                <div class="space-y-3">
                    {{-- Cabeçalho: Iniciais + Nome e E-mail --}}
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                            {{ strtoupper(substr($membro->nom_membro, 0, 1)) }}
                        </div>
                        <div class="overflow-hidden">
                            <h3 class="font-semibold text-neutral-800 dark:text-neutral-200 truncate" title="{{ $membro->nom_membro }}">
                                {{ $membro->nom_membro }}
                            </h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400 truncate" title="{{ $membro->eml_membro }}">
                                {{ $membro->eml_membro }}
                            </p>
                        </div>
                    </div>

                    {{-- Detalhes da Solicitação --}}
                    <div class="pt-2 border-t border-neutral-100 dark:border-neutral-800/60 space-y-2 text-xs">
                        <div class="flex justify-between items-center gap-2">
                            <span class="text-neutral-400 dark:text-neutral-500">Associação:</span>
                            <flux:badge size="sm" color="blue" class="uppercase truncate max-w-[180px]">
                                {{ $membro->associacao?->nom_associacao ?? 'Sem Associação' }}
                            </flux:badge>
                        </div>
                        <div class="flex justify-between items-start gap-2">
                            <span class="text-neutral-400 dark:text-neutral-500">Solicitado em:</span>
                            <div class="text-right">
                                <span class="text-neutral-600 dark:text-neutral-300 font-medium">{{ $membro->created_at->format('d/m/Y H:i') }}</span>
                                <span class="block text-[10px] text-neutral-400 dark:text-neutral-500 opacity-80">{{ $membro->created_at->diffForHumans() }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Botão de Ação --}}
                <div class="pt-2">
                    <flux:button 
                        wire:click="aprovarMembro({{ $membro->idt_membro }})" 
                        size="sm" 
                        variant="primary" 
                        icon="check"
                        wire:loading.attr="disabled"
                        class="w-full"
                    >
                        Aprovar Adesão
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <div class="col-span-full py-12 text-center text-zinc-400 bg-white border border-neutral-200 dark:border-neutral-700 dark:bg-zinc-900 rounded-xl shadow-xs">
                <flux:icon name="check-circle" class="mx-auto mb-2 size-8 text-green-500 opacity-60" />
                Nenhuma solicitação de adesão pendente no momento.
            </div>
        @endforelse
    </div>

    @if ($membrosPendentes->hasPages())
        <div class="mt-6">
            {{ $membrosPendentes->links() }}
        </div>
    @endif
</div>
