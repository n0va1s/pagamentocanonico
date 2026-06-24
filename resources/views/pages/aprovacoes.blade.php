<?php

use App\Models\Membro;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Aprovações Pendentes')] class extends Component {
    use WithPagination;

    public function aprovarMembro(int $id): void
    {
        $membro = Membro::withoutGlobalScope('associacao')->findOrFail($id);

        // Check permission: director can paginonly approve members of their own association
        if (!auth()->user()->isAdmin() && $membro->idt_associacao !== auth()->user()->membro?->idt_associacao) {
            $this->dispatch('toast', message: 'Acesso não autorizado.', variant: 'danger');
            return;
        }

        $membro->update([
            'ind_aprovado' => true,
            'usu_autorizador' => auth()->user()->email,
        ]);

        $this->dispatch('toast', message: 'Vinculação aprovada com sucesso!', variant: 'success');
    }

    public function with(): array
    {
        $membrosPendentes = Membro::withoutGlobalScope('associacao')
            ->where('ind_aprovado', false)
            ->when(!auth()->user()->isAdmin(), function ($q) {
                $q->where('idt_associacao', auth()->user()->membro?->idt_associacao);
            })
            ->with('associacao')
            ->orderBy('nom_membro')
            ->paginate(15);

        return [
            'membrosPendentes' => $membrosPendentes,
        ];
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto">
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

    {{-- Lista/Tabela --}}
    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Nome</flux:table.column>
                <flux:table.column>E-mail</flux:table.column>
                <flux:table.column>Associação</flux:table.column>
                <flux:table.column>Solicitado em</flux:table.column>
                <flux:table.column class="text-right">Ação</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($membrosPendentes as $membro)
                    <flux:table.row wire:key="pendente-{{ $membro->idt_membro }}">
                        <flux:table.cell class="font-medium">
                            <div class="flex items-center gap-2">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-200 text-xs font-bold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                    {{ strtoupper(substr($membro->nom_membro, 0, 1)) }}
                                </div>
                                <span class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $membro->nom_membro }}</span>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="text-neutral-600 dark:text-neutral-400">
                            {{ $membro->eml_membro }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" color="blue" class="uppercase">
                                {{ $membro->associacao?->nom_associacao ?? 'Sem Associação' }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell class="text-xs text-neutral-500 dark:text-neutral-400">
                            {{ $membro->created_at->format('d/m/Y H:i') }}
                            <span class="block text-[10px] opacity-60">{{ $membro->created_at->diffForHumans() }}</span>
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <flux:button 
                                wire:click="aprovarMembro({{ $membro->idt_membro }})" 
                                size="xs" 
                                variant="primary" 
                                icon="check"
                                wire:loading.attr="disabled"
                            >
                                Aprovar Adesão
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-12 text-center text-zinc-400">
                            <flux:icon name="check-circle" class="mx-auto mb-2 size-8 text-green-500 opacity-60" />
                            Nenhuma solicitação de adesão pendente no momento.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if ($membrosPendentes->hasPages())
            <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-700">
                {{ $membrosPendentes->links() }}
            </div>
        @endif
    </flux:card>
</div>
