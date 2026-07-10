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
        \Flux::toast(variant: 'success', text: __('messages.alerts.success.saved'));
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
        \Flux::toast(variant: 'success', text: __('messages.alerts.success.saved'));
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

    {{-- Lista/Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($associacoes as $assoc)
            <flux:card class="flex flex-col justify-between p-5 space-y-4" wire:key="assoc-{{ $assoc->idt_associacao }}">
                @if($editandoAssociacaoId === $assoc->idt_associacao)
                    {{-- Modo de Edição --}}
                    <div class="flex flex-col gap-3 w-full">
                        <flux:field>
                            <flux:label>Nome da Associação</flux:label>
                            <flux:input wire:model="editandoAssociacaoNome" size="sm" placeholder="Nome" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Telefone</flux:label>
                            <flux:input wire:model="editandoAssociacaoTelefone" size="sm" placeholder="Telefone" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Chave PIX</flux:label>
                            <flux:input wire:model="editandoAssociacaoPix" size="sm" placeholder="Chave PIX" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Mensalidade</flux:label>
                            <flux:input wire:model="editandoAssociacaoTaxa" size="sm" placeholder="Mensalidade" type="number" step="0.01" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Anuidade</flux:label>
                            <flux:input wire:model="editandoAssociacaoAnual" size="sm" placeholder="Anuidade" type="number" step="0.01" />
                        </flux:field>
                        <div class="flex gap-2 pt-2">
                            <flux:button wire:click="salvarEdicao" size="sm" variant="primary">Salvar</flux:button>
                            <flux:button wire:click="cancelarEdicao" size="sm" variant="ghost">Cancelar</flux:button>
                        </div>
                    </div>
                @else
                    {{-- Modo de Exibição --}}
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
                                <flux:button wire:click="iniciarEdicao({{ $assoc->idt_associacao }}, '{{ addslashes($assoc->nom_associacao) }}', '{{ addslashes($assoc->tel_contato ?? '') }}', '{{ addslashes($assoc->chave_pix ?? '') }}', {{ $assoc->val_taxa ? "'".$assoc->val_taxa."'" : 'null' }}, {{ $assoc->val_anual ? "'".$assoc->val_anual."'" : 'null' }})" size="sm" variant="ghost" icon="pencil" aria-label="Editar associação" />
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
                            @if($assoc->val_taxa)
                                <div class="flex justify-between">
                                    <span class="text-neutral-400 dark:text-neutral-500">Mensalidade:</span>
                                    <span class="font-semibold text-neutral-800 dark:text-neutral-200">R$ {{ number_format($assoc->val_taxa, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            @if($assoc->val_anual)
                                <div class="flex justify-between">
                                    <span class="text-neutral-400 dark:text-neutral-500">Anuidade:</span>
                                    <span class="font-semibold text-neutral-800 dark:text-neutral-200">R$ {{ number_format($assoc->val_anual, 2, ',', '.') }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
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
