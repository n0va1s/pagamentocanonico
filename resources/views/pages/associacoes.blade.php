<?php

use App\Models\Associacao;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Cadastro de Associações')] class extends Component {
    use WithPagination;

    public string $novaAssociacaoNome = '';
    public string $novaAssociacaoTelefone = '';
    public string $novaAssociacaoPix = '';
    public ?string $novaAssociacaoTaxa = null;
    public ?string $novaAssociacaoAnual = null;
    public ?int $editandoAssociacaoId = null;
    public string $editandoAssociacaoNome = '';
    public string $editandoAssociacaoTelefone = '';
    public string $editandoAssociacaoPix = '';
    public ?string $editandoAssociacaoTaxa = null;
    public ?string $editandoAssociacaoAnual = null;
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
            'novaAssociacaoTelefone' => 'nullable|string|max:20',
            'novaAssociacaoPix' => 'nullable|string|max:100',
            'novaAssociacaoTaxa' => 'nullable|numeric|min:0',
            'novaAssociacaoAnual' => 'nullable|numeric|min:0',
        ], [
            'novaAssociacaoNome.required' => 'O nome da associação é obrigatório.',
            'novaAssociacaoNome.unique' => 'Esta associação já está cadastrada.',
        ]);

        Associacao::create([
            'nom_associacao' => $this->novaAssociacaoNome,
            'tel_contato' => $this->novaAssociacaoTelefone,
            'chave_pix' => $this->novaAssociacaoPix,
            'val_taxa' => $this->novaAssociacaoTaxa ?: null,
            'val_anual' => $this->novaAssociacaoAnual ?: null,
        ]);

        $this->novaAssociacaoNome = '';
        $this->novaAssociacaoTelefone = '';
        $this->novaAssociacaoPix = '';
        $this->novaAssociacaoTaxa = null;
        $this->novaAssociacaoAnual = null;
        $this->dispatch('close-modal', name: 'nova-associacao-modal');
        $this->dispatch('toast', message: 'Associação cadastrada com sucesso!', variant: 'success');
    }

    public function iniciarEdicao(int $id, string $nome, ?string $telefone = null, ?string $pix = null, ?float $taxa = null, ?float $anual = null): void
    {
        $this->editandoAssociacaoId = $id;
        $this->editandoAssociacaoNome = $nome;
        $this->editandoAssociacaoTelefone = $telefone ?? '';
        $this->editandoAssociacaoPix = $pix ?? '';
        $this->editandoAssociacaoTaxa = $taxa;
        $this->editandoAssociacaoAnual = $anual;
    }

    public function salvarEdicao(): void
    {
        $this->validate([
            'editandoAssociacaoNome' => 'required|string|min:3|max:100|unique:associacoes,nom_associacao,' . $this->editandoAssociacaoId . ',idt_associacao',
            'editandoAssociacaoTelefone' => 'nullable|string|max:20',
            'editandoAssociacaoPix' => 'nullable|string|max:100',
            'editandoAssociacaoTaxa' => 'nullable|numeric|min:0',
            'editandoAssociacaoAnual' => 'nullable|numeric|min:0',
        ], [
            'editandoAssociacaoNome.required' => 'O nome da associação é obrigatório.',
            'editandoAssociacaoNome.unique' => 'Esta associação já está cadastrada.',
        ]);

        $assoc = Associacao::findOrFail($this->editandoAssociacaoId);
        $assoc->update([
            'nom_associacao' => $this->editandoAssociacaoNome,
            'tel_contato' => $this->editandoAssociacaoTelefone,
            'chave_pix' => $this->editandoAssociacaoPix,
            'val_taxa' => $this->editandoAssociacaoTaxa ?: null,
            'val_anual' => $this->editandoAssociacaoAnual ?: null,
        ]);

        $this->editandoAssociacaoId = null;
        $this->editandoAssociacaoNome = '';
        $this->editandoAssociacaoTelefone = '';
        $this->editandoAssociacaoPix = '';
        $this->editandoAssociacaoTaxa = null;
        $this->editandoAssociacaoAnual = null;
        $this->dispatch('toast', message: 'Associação atualizada com sucesso!', variant: 'success');
    }

    public function cancelarEdicao(): void
    {
        $this->editandoAssociacaoId = null;
        $this->editandoAssociacaoNome = '';
        $this->editandoAssociacaoTelefone = '';
        $this->editandoAssociacaoPix = '';
        $this->editandoAssociacaoTaxa = null;
        $this->editandoAssociacaoAnual = null;
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
                                <div class="flex flex-col gap-2 w-full">
                                    <flux:input wire:model="editandoAssociacaoNome" size="sm" aria-label="Editar nome da associação" placeholder="Nome" />
                                    <flux:input wire:model="editandoAssociacaoTelefone" size="sm" aria-label="Editar telefone" placeholder="Telefone" />
                                    <flux:input wire:model="editandoAssociacaoPix" size="sm" aria-label="Editar PIX" placeholder="Chave PIX" />
                                    <flux:input wire:model="editandoAssociacaoTaxa" size="sm" aria-label="Editar Mensalidade" placeholder="Mensalidade" type="number" step="0.01" />
                                    <flux:input wire:model="editandoAssociacaoAnual" size="sm" aria-label="Editar Anuidade" placeholder="Anuidade" type="number" step="0.01" />
                                    <div class="flex gap-2">
                                        <flux:button wire:click="salvarEdicao" size="xs" variant="primary">Salvar</flux:button>
                                        <flux:button wire:click="cancelarEdicao" size="xs" variant="ghost">Cancelar</flux:button>
                                    </div>
                                </div>
                            @else
                                <div class="flex flex-col">
                                    <span class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $assoc->nom_associacao }}</span>
                                    @if($assoc->tel_contato || $assoc->chave_pix || $assoc->val_taxa || $assoc->val_anual)
                                        <span class="text-xs text-neutral-500 mt-1">
                                            @if($assoc->tel_contato) Tel: {{ $assoc->tel_contato }} @endif
                                            @if($assoc->tel_contato && $assoc->chave_pix) | @endif
                                            @if($assoc->chave_pix) PIX: {{ $assoc->chave_pix }} @endif
                                            @if($assoc->chave_pix && $assoc->val_taxa) | @endif
                                            @if($assoc->val_taxa) Mensalidade: R$ {{ number_format($assoc->val_taxa, 2, ',', '.') }} @endif
                                            @if($assoc->val_taxa && $assoc->val_anual) | @endif
                                            @if($assoc->val_anual) Anuidade: R$ {{ number_format($assoc->val_anual, 2, ',', '.') }} @endif
                                        </span>
                                    @endif
                                </div>
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
                                    <flux:button wire:click="iniciarEdicao({{ $assoc->idt_associacao }}, '{{ addslashes($assoc->nom_associacao) }}', '{{ addslashes($assoc->tel_contato ?? '') }}', '{{ addslashes($assoc->chave_pix ?? '') }}', {{ $assoc->val_taxa ? "'".$assoc->val_taxa."'" : 'null' }}, {{ $assoc->val_anual ? "'".$assoc->val_anual."'" : 'null' }})" size="sm" variant="ghost" icon="pencil" aria-label="Editar associação" />
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

                <flux:field>
                    <flux:label for="novaAssociacaoTelefone">Telefone de Contato</flux:label>
                    <flux:input id="novaAssociacaoTelefone" wire:model="novaAssociacaoTelefone" placeholder="Ex: (11) 99999-9999" />
                    @error('novaAssociacaoTelefone')
                        <p class="text-xs text-red-500 mt-1 font-medium">{{ $message }}</p>
                    @enderror
                </flux:field>

                <flux:field>
                    <flux:label for="novaAssociacaoPix">Chave PIX</flux:label>
                    <flux:input id="novaAssociacaoPix" wire:model="novaAssociacaoPix" placeholder="Ex: email@dominio.com" />
                    @error('novaAssociacaoPix')
                        <p class="text-xs text-red-500 mt-1 font-medium">{{ $message }}</p>
                    @enderror
                </flux:field>

                <flux:field>
                    <flux:label for="novaAssociacaoTaxa">Valor da Mensalidade (R$)</flux:label>
                    <flux:input id="novaAssociacaoTaxa" wire:model="novaAssociacaoTaxa" type="number" step="0.01" placeholder="Ex: 50.00" />
                    @error('novaAssociacaoTaxa')
                        <p class="text-xs text-red-500 mt-1 font-medium">{{ $message }}</p>
                    @enderror
                </flux:field>

                <flux:field>
                    <flux:label for="novaAssociacaoAnual">Valor da Anuidade (R$)</flux:label>
                    <flux:input id="novaAssociacaoAnual" wire:model="novaAssociacaoAnual" type="number" step="0.01" placeholder="Ex: 500.00" />
                    @error('novaAssociacaoAnual')
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
