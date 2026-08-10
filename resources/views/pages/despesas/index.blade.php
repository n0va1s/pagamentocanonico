<?php

use App\Models\Transacao;
use App\Models\Despesa;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

new class extends Component {
    use WithPagination;
    use WithFileUploads;

    public $selectedTransacaoId = null;
    public string $selectedAssociacaoId = '';
    public ?int $selectedYear = null;
    public ?int $selectedMonth = null;
    public $file1;
    public $file2;
    public $file3;
    public $filePagamento;

    public function mount(): void
    {
        if (!auth()->user()->isAdmin()) {
            $this->selectedAssociacaoId = (string) (auth()->user()->membro?->idt_associacao ?? '');
        }
    }

    public function updatedSelectedAssociacaoId(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedYear(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedMonth(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $user = auth()->user();
        $isAdmin = $user->isAdmin();

        if (!$isAdmin) {
            $this->selectedAssociacaoId = (string) ($user->membro?->idt_associacao ?? '');
        }

        // Anos disponíveis em despesas/transações de débito
        $yearsQuery = Transacao::where('tip_transacao', 'DEBIT');
        if ($this->selectedAssociacaoId) {
            $yearsQuery->whereHas('ofx', function ($sub) {
                $sub->where('idt_associacao', $this->selectedAssociacaoId);
            });
        }
        $availableYears = $yearsQuery->selectRaw('YEAR(dat_transacao) as year')
            ->distinct()
            ->whereNotNull('dat_transacao')
            ->pluck('year')
            ->map(fn($y) => (int) $y)
            ->sortDesc()
            ->values();

        if ($availableYears->isEmpty()) {
            $availableYears = collect([(int) date('Y')]);
        }

        if ($this->selectedYear && !$availableYears->contains($this->selectedYear)) {
            $this->selectedYear = null;
        }

        // Meses disponíveis para o ano selecionado
        $availableMonths = collect();
        if ($this->selectedYear) {
            $monthsQuery = Transacao::where('tip_transacao', 'DEBIT')->whereYear('dat_transacao', $this->selectedYear);
            if ($this->selectedAssociacaoId) {
                $monthsQuery->whereHas('ofx', function ($sub) {
                    $sub->where('idt_associacao', $this->selectedAssociacaoId);
                });
            }
            $availableMonths = $monthsQuery->selectRaw('MONTH(dat_transacao) as month')
                ->distinct()
                ->whereNotNull('dat_transacao')
                ->pluck('month')
                ->map(fn($m) => (int) $m)
                ->sort()
                ->values();

            if ($this->selectedMonth && !$availableMonths->contains($this->selectedMonth)) {
                $this->selectedMonth = null;
            }
        } else {
            $this->selectedMonth = null;
        }

        $transacoes = Transacao::with('despesa')
            ->where('tip_transacao', 'DEBIT')
            ->when($this->selectedAssociacaoId, function ($q) {
                $q->whereHas('ofx', function ($sub) {
                    $sub->where('idt_associacao', $this->selectedAssociacaoId);
                });
            })
            ->when($this->selectedYear, function ($q) {
                $q->whereYear('dat_transacao', $this->selectedYear);
            })
            ->when($this->selectedMonth, function ($q) {
                $q->whereMonth('dat_transacao', $this->selectedMonth);
            })
            ->orderByDesc('dat_transacao')
            ->paginate(20);

        $nomesMeses = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
        ];

        return [
            'transacoes' => $transacoes,
            'availableYears' => $availableYears,
            'availableMonths' => $availableMonths,
            'nomesMeses' => $nomesMeses,
            'isAdmin' => $isAdmin,
        ];
    }

    public function openModal($transacaoId)
    {
        $this->resetFiles();
        $this->selectedTransacaoId = $transacaoId;
        $this->dispatch('open-modal', name: 'upload-despesas');
    }

    public function closeModal()
    {
        $this->dispatch('close-modal', name: 'upload-despesas');
        $this->resetFiles();
        $this->selectedTransacaoId = null;
    }

    private function resetFiles()
    {
        $this->file1 = null;
        $this->file2 = null;
        $this->file3 = null;
        $this->filePagamento = null;
    }

    public function salvar()
    {
        $transacao = Transacao::findOrFail($this->selectedTransacaoId);
        $existing = Despesa::where('idt_transacao', $transacao->idt_transacao)->first();

        $this->validate([
            'file1' => ($existing ? 'nullable' : 'required') . '|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'file2' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'file3' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'filePagamento' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'selectedTransacaoId' => 'required|exists:transacoes,idt_transacao'
        ], [
            'file1.required' => 'O primeiro arquivo de despesa é obrigatório.',
            'file1.mimes' => 'O arquivo deve ser PDF, JPG ou PNG.',
            'file1.max' => 'O arquivo deve ter no máximo 5MB.',
        ]);

        $path1 = $this->file1 ? $this->file1->store('public/despesas') : $existing?->arq_despesa_1;
        $path2 = $this->file2 ? $this->file2->store('public/despesas') : $existing?->arq_despesa_2;
        $path3 = $this->file3 ? $this->file3->store('public/despesas') : $existing?->arq_despesa_3;
        $pathPagamento = $this->filePagamento ? $this->filePagamento->store('public/despesas') : $existing?->arq_pagamento;

        Despesa::updateOrCreate(
            ['idt_transacao' => $transacao->idt_transacao],
            [
                'arq_despesa_1' => $path1,
                'arq_despesa_2' => $path2,
                'arq_despesa_3' => $path3,
                'arq_pagamento' => $pathPagamento,
            ]
        );

        $this->closeModal();
        \Flux::toast(variant: 'success', text: 'Anexos de despesas salvos com sucesso!');
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto">
    <div class="pc-page-header">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                <flux:icon name="document-duplicate" class="size-6 text-blue-600" /> Despesas
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Gerencie e anexe os comprovantes e notas fiscais que justificam as transações de débito.</p>
        </div>
    </div>

    {{-- Filtros Padronizados --}}
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-zinc-900 mb-6">
        <div class="flex flex-wrap items-center gap-4 w-full sm:w-auto">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                    <flux:icon name="building-office-2" class="size-5" />
                </div>
                <div class="flex flex-col gap-1 flex-1 sm:flex-initial">
                    <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Associação Selecionada</p>
                    <x-select-associacao wire:model.live="selectedAssociacaoId" class="w-full sm:w-64" :show-all-option="$isAdmin" :disabled="!$isAdmin" />
                </div>
            </div>

            <div class="flex items-center gap-3 border-t sm:border-t-0 sm:border-l border-neutral-200 dark:border-neutral-700 pt-4 sm:pt-0 sm:pl-4">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                    <flux:icon name="calendar" class="size-5" />
                </div>
                <div class="flex flex-col gap-1 w-full sm:w-36">
                    <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Ano</p>
                    <flux:select wire:model.live="selectedYear" class="w-full">
                        <flux:select.option value="">Todos os Anos</flux:select.option>
                        @foreach($availableYears as $y)
                            <flux:select.option value="{{ $y }}">{{ $y }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <div class="flex items-center gap-3 border-t sm:border-t-0 sm:border-l border-neutral-200 dark:border-neutral-700 pt-4 sm:pt-0 sm:pl-4">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                    <flux:icon name="calendar-days" class="size-5" />
                </div>
                <div class="flex flex-col gap-1 w-full sm:w-40">
                    <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Mês</p>
                    <flux:select wire:model.live="selectedMonth" class="w-full" :disabled="!$selectedYear">
                        <flux:select.option value="">Todos os Meses</flux:select.option>
                        @foreach($availableMonths as $m)
                            <flux:select.option value="{{ $m }}">{{ $nomesMeses[$m] ?? $m }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>
        </div>
    </div>

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
                            @if($t->despesa)
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <flux:badge color="green" size="sm" icon="check-circle">Anexados</flux:badge>
                                    @if($t->despesa->arq_despesa_1) <a href="{{ Storage::url($t->despesa->arq_despesa_1) }}" target="_blank" class="text-blue-600 hover:underline text-xs font-medium">D1</a> @endif
                                    @if($t->despesa->arq_despesa_2) <a href="{{ Storage::url($t->despesa->arq_despesa_2) }}" target="_blank" class="text-blue-600 hover:underline text-xs font-medium">D2</a> @endif
                                    @if($t->despesa->arq_despesa_3) <a href="{{ Storage::url($t->despesa->arq_despesa_3) }}" target="_blank" class="text-blue-600 hover:underline text-xs font-medium">D3</a> @endif
                                    @if($t->despesa->arq_pagamento) <a href="{{ Storage::url($t->despesa->arq_pagamento) }}" target="_blank" class="text-emerald-600 hover:underline text-xs font-semibold" title="Comprovante de Pagamento">PAG</a> @endif
                                </div>
                            @else
                                <flux:badge color="zinc" size="sm">Pendente</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" wire:click="openModal({{ $t->idt_transacao }})">
                                {{ $t->despesa ? 'Atualizar Anexos' : 'Anexar Documentos' }}
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

    <flux:modal name="upload-despesas" class="min-w-[400px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Anexar Documentos da Despesa</flux:heading>
                <flux:subheading>Envie até 3 comprovantes/notas de despesa e o comprovante de pagamento (PDF ou Imagem) para esta transação.</flux:subheading>
            </div>

            <form wire:submit="salvar" class="space-y-4">
                <flux:input type="file" label="Despesa 1 (Obrigatório para novo anexo)" wire:model="file1" accept=".pdf,.png,.jpg,.jpeg" />
                <flux:input type="file" label="Despesa 2 (Opcional)" wire:model="file2" accept=".pdf,.png,.jpg,.jpeg" />
                <flux:input type="file" label="Despesa 3 (Opcional)" wire:model="file3" accept=".pdf,.png,.jpg,.jpeg" />
                <flux:input type="file" label="Comprovante de Pagamento (Opcional)" wire:model="filePagamento" accept=".pdf,.png,.jpg,.jpeg" />

                <div class="flex items-center justify-end gap-3 mt-6">
                    <flux:button wire:click="closeModal" type="button" variant="ghost">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Salvar Anexos</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
