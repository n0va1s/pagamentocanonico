<?php

use App\Models\Associacao;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Cadastro de Associações')] class extends Component {
    use WithPagination;

    public string $novaAssociacaoNome = '';
    public ?int $editandoAssociacaoId = null;
    public string $editandoAssociacaoNome = '';
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

    public function cadastrarAssociacao(): void
    {
        $this->validate([
            'novaAssociacaoNome' => 'required|string|min:3|max:100|unique:associacoes,nom_associacao',
        ], [
            'novaAssociacaoNome.required' => 'O nome da associação é obrigatório.',
            'novaAssociacaoNome.unique' => 'Esta associação já está cadastrada.',
        ]);

        Associacao::create([
            'nom_associacao' => $this->novaAssociacaoNome,
        ]);

        $this->novaAssociacaoNome = '';
        $this->dispatch('close-modal', name: 'nova-associacao-modal');
        $this->dispatch('toast', message: 'Associação cadastrada com sucesso!', variant: 'success');
    }

    public function iniciarEdicao(int $id, string $nome): void
    {
        $this->editandoAssociacaoId = $id;
        $this->editandoAssociacaoNome = $nome;
    }

    public function salvarEdicao(): void
    {
        $this->validate([
            'editandoAssociacaoNome' => 'required|string|min:3|max:100|unique:associacoes,nom_associacao,' . $this->editandoAssociacaoId . ',idt_associacao',
        ], [
            'editandoAssociacaoNome.required' => 'O nome da associação é obrigatório.',
            'editandoAssociacaoNome.unique' => 'Esta associação já está cadastrada.',
        ]);

        $assoc = Associacao::findOrFail($this->editandoAssociacaoId);
        $assoc->update([
            'nom_associacao' => $this->editandoAssociacaoNome,
        ]);

        $this->editandoAssociacaoId = null;
        $this->editandoAssociacaoNome = '';
        $this->dispatch('toast', message: 'Associação atualizada com sucesso!', variant: 'success');
    }

    public function cancelarEdicao(): void
    {
        $this->editandoAssociacaoId = null;
        $this->editandoAssociacaoNome = '';
    }

    public function excluirAssociacao(int $id): void
    {
        $assoc = Associacao::findOrFail($id);
        $assoc->delete();

        $this->dispatch('toast', message: 'Associação excluída com sucesso!', variant: 'success');
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

<div class="space-y-6 p-6 max-w-7xl mx-auto">
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
            <flux:modal.trigger name="nova-associacao-modal">
                <flux:button variant="primary" icon="plus">
                    Nova Associação
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    {{-- Filtros --}}
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

    {{-- Tabela --}}
    <flux:card class="overflow-x-auto p-0">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Nome da Associação</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">Total de Membros</flux:table.column>
                <flux:table.column class="text-right">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($associacoes as $assoc)
                    <flux:table.row wire:key="assoc-{{ $assoc->idt_associacao }}">
                        <flux:table.cell class="font-medium w-full max-w-xs sm:max-w-none">
                            @if($editandoAssociacaoId === $assoc->idt_associacao)
                                <div class="flex items-center gap-2 w-full">
                                    <flux:input wire:model="editandoAssociacaoNome" size="sm" class="flex-1" aria-label="Editar nome da associação" />
                                    <flux:button wire:click="salvarEdicao" size="xs" variant="primary">Salvar</flux:button>
                                    <flux:button wire:click="cancelarEdicao" size="xs" variant="ghost">Cancelar</flux:button>
                                </div>
                            @else
                                <span class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $assoc->nom_associacao }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="hidden sm:table-cell">
                            <flux:badge size="sm" color="zinc" class="font-semibold">
                                {{ $assoc->membros_count }} {{ Str::plural('membro', $assoc->membros_count) }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            @if($editandoAssociacaoId !== $assoc->idt_associacao)
                                <div class="flex justify-end gap-1">
                                    <flux:button wire:click="iniciarEdicao({{ $assoc->idt_associacao }}, '{{ $assoc->nom_associacao }}')" size="sm" variant="ghost" icon="pencil" aria-label="Editar associação" />
                                    <flux:button wire:click="excluirAssociacao({{ $assoc->idt_associacao }})" wire:confirm="Deseja remover esta associação?" size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-700" aria-label="Remover associação" />
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3" class="py-12 text-center text-zinc-400">
                            Nenhuma associação encontrada.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if ($associacoes->hasPages())
            <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-700">
                {{ $associacoes->links() }}
            </div>
        @endif
    </flux:card>

    {{-- Modal de Criação --}}
    <flux:modal name="nova-associacao-modal" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Nova Associação</flux:heading>
                <flux:subheading>Informe o nome da nova associação para cadastrá-la no sistema.</flux:subheading>
            </div>

            <form wire:submit.prevent="cadastrarAssociacao" class="space-y-6">
                <flux:field>
                    <flux:label for="novaAssociacaoNome" required>Nome da Associação</flux:label>
                    <flux:input id="novaAssociacaoNome" wire:model="novaAssociacaoNome" placeholder="Ex: Associação Canônica..." required />
                    @error('novaAssociacaoNome')
                        <p class="text-xs text-red-500 mt-1 font-medium">{{ $message }}</p>
                    @enderror
                </flux:field>

                <div class="flex gap-2 justify-end">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Cadastrar</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
