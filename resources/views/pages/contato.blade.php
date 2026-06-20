<?php

use App\Models\Contato;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Contatos Recebidos')] class extends Component {
    use WithPagination;

    public function excluir(int $id): void
    {
        $contato = Contato::findOrFail($id);
        $contato->delete();

        $this->dispatch('toast', message: 'Contato marcado como resolvido e removido com sucesso!', variant: 'success');
    }

    public function with(): array
    {
        return [
            'contatos' => Contato::latest()->paginate(15),
        ];
    }
}; ?>

<div class="space-y-4">
    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Contatos Recebidos</flux:heading>
    </div>

    {{-- Tabela de Contatos --}}
    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Nome</flux:table.column>
                <flux:table.column>E-mail</flux:table.column>
                <flux:table.column>Mensagem</flux:table.column>
                <flux:table.column>Enviado em</flux:table.column>
                <flux:table.column class="text-right">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($contatos as $contato)
                    <flux:table.row wire:key="{{ $contato->id }}">
                        
                        <flux:table.cell class="font-medium text-neutral-800 dark:text-neutral-200">
                            {{ $contato->nome }}
                        </flux:table.cell>

                        <flux:table.cell class="text-neutral-600 dark:text-neutral-400">
                            {{ $contato->email }}
                        </flux:table.cell>

                        <flux:table.cell class="text-xs text-neutral-600 dark:text-neutral-400 max-w-md break-words whitespace-normal">
                            {{ $contato->mensagem }}
                        </flux:table.cell>

                        <flux:table.cell class="text-xs text-neutral-500">
                            {{ $contato->created_at->format('d/m/Y H:i') }}
                            <span class="block text-[10px] text-neutral-400">
                                {{ $contato->created_at->diffForHumans() }}
                            </span>
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                wire:click="excluir({{ $contato->id }})"
                                wire:confirm="Tem certeza de que deseja marcar este contato como resolvido? Ele será removido da lista."
                                title="Excluir / Marcar como Resolvido"
                            />
                        </flux:table.cell>

                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-12 text-center text-zinc-400">
                            Nenhum contato pendente recebido.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if ($contatos->hasPages())
            <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-700">
                {{ $contatos->links() }}
            </div>
        @endif
    </flux:card>
</div>
