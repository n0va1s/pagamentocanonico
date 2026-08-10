<?php

use App\Models\Transacao;
use App\Models\Orcamento;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

new class extends Component {
    use WithPagination;
    use WithFileUploads;

    public $selectedTransacaoId = null;
    public string $selectedAssociacaoId = '';
    public $file1;
    public $file2;
    public $file3;

    public function updatedSelectedAssociacaoId(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $transacoes = Transacao::with('orcamento')
            ->where('tip_transacao', 'DEBIT')
            ->when(auth()->user()->isAdmin() && $this->selectedAssociacaoId, function ($q) {
                $q->whereHas('ofx', function ($sub) {
                    $sub->where('idt_associacao', $this->selectedAssociacaoId);
                });
            })
            ->orderByDesc('dat_transacao')
            ->paginate(20);

        return [
            'transacoes' => $transacoes,
        ];
    }

    public function openModal($transacaoId)
    {
        $this->resetFiles();
        $this->selectedTransacaoId = $transacaoId;
        $this->dispatch('open-modal', name: 'upload-orcamentos');
    }

    public function closeModal()
    {
        $this->dispatch('close-modal', name: 'upload-orcamentos');
        $this->resetFiles();
        $this->selectedTransacaoId = null;
    }

    private function resetFiles()
    {
        $this->file1 = null;
        $this->file2 = null;
        $this->file3 = null;
    }

    public function salvar()
    {
        $this->validate([
            'file1' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'file2' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'file3' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'selectedTransacaoId' => 'required|exists:transacoes,idt_transacao'
        ], [
            'file1.required' => 'O primeiro arquivo de orçamento é obrigatório.',
            'file1.mimes' => 'O arquivo deve ser PDF, JPG ou PNG.',
            'file1.max' => 'O arquivo deve ter no máximo 5MB.',
        ]);

        $transacao = Transacao::findOrFail($this->selectedTransacaoId);

        $path1 = $this->file1->store('public/orcamentos');
        $path2 = $this->file2 ? $this->file2->store('public/orcamentos') : null;
        $path3 = $this->file3 ? $this->file3->store('public/orcamentos') : null;

        Orcamento::updateOrCreate(
            ['idt_transacao' => $transacao->idt_transacao],
            [
                'arq_orcamento_1' => $path1,
                'arq_orcamento_2' => $path2,
                'arq_orcamento_3' => $path3,
            ]
        );

        $this->closeModal();
        \Flux::toast(variant: 'success', text: ' anexados com sucesso!');
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto">
    <div class="pc-page-header">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                <flux:icon name="document-duplicate" class="size-6 text-blue-600" /> Despesas
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Gerencie e anexe as notas fiscais que justificam as transações de débito.</p>
        </div>
    </div>

    @if(auth()->user()->isAdmin())
        {{-- Filtros --}}
        <flux:card class="flex flex-col sm:flex-row gap-3">
            <div class="sm:w-72">
                <x-select-associacao wire:model.live="selectedAssociacaoId" aria-label="Associação" :show-all-option="true" />
            </div>
        </flux:card>
    @endif

    <flux:card class="overflow-x-auto p-0">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Data</flux:table.column>
                <flux:table.column>Valor</flux:table.column>
                <flux:table.column>Descrição / Pagador</flux:table.column>
                <flux:table.column>Anexos</flux:table.column>
                <flux:table.column>Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($transacoes as $t)
                    <flux:table.row wire:key="t-{{ $t->idt_transacao }}">
                        <flux:table.cell>{{ $t->dat_transacao->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell class="text-red-600 font-medium">
                            R$ {{ number_format(abs($t->val_transacao), 2, ',', '.') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="block font-medium truncate max-w-xs" title="{{ $t->nom_pessoa }}">{{ $t->nom_pessoa ?? 'N/A' }}</span>
                            <span class="block text-xs text-neutral-500 truncate max-w-xs" title="{{ $t->des_transacao }}">{{ $t->des_transacao }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($t->orcamento)
                                <div class="flex items-center gap-2">
                                    <flux:badge color="green" size="sm" icon="check-circle">Anexados</flux:badge>
                                    @if($t->orcamento->arq_orcamento_1) <a href="{{ Storage::url($t->orcamento->arq_orcamento_1) }}" target="_blank" class="text-blue-600 hover:underline text-xs">A1</a> @endif
                                    @if($t->orcamento->arq_orcamento_2) <a href="{{ Storage::url($t->orcamento->arq_orcamento_2) }}" target="_blank" class="text-blue-600 hover:underline text-xs">A2</a> @endif
                                    @if($t->orcamento->arq_orcamento_3) <a href="{{ Storage::url($t->orcamento->arq_orcamento_3) }}" target="_blank" class="text-blue-600 hover:underline text-xs">A3</a> @endif
                                </div>
                            @else
                                <flux:badge color="zinc" size="sm">Pendente</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" wire:click="openModal({{ $t->idt_transacao }})">
                                {{ $t->orcamento ? 'Atualizar Notas' : 'Anexar Notas' }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-12 text-center text-zinc-400">
                            Nenhuma transação de débito encontrada.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/50">
            {{ $transacoes->links() }}
        </div>
    </flux:card>

    <flux:modal name="upload-orcamentos" class="min-w-[400px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Anexar Notas</flux:heading>
                <flux:subheading>Envie até 3 arquivos de despesas (PDF ou Imagem) para esta transação.</flux:subheading>
            </div>

            <form wire:submit="salvar" class="space-y-4">
                <flux:input type="file" label="Despesa 1 (Obrigatório)" wire:model="file1" accept=".pdf,.png,.jpg,.jpeg" required />
                <flux:input type="file" label="Orçamento 2 (Opcional)" wire:model="file2" accept=".pdf,.png,.jpg,.jpeg" />
                <flux:input type="file" label="Orçamento 3 (Opcional)" wire:model="file3" accept=".pdf,.png,.jpg,.jpeg" />

                <div class="flex items-center justify-end gap-3 mt-6">
                    <flux:button wire:click="closeModal" variant="ghost">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Salvar Anexos</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
