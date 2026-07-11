<?php

use App\Enums\Perfil;
use App\Models\Membro;
use App\Models\Ofx;
use App\Models\Resumo;

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Membros')] class extends Component {
    use WithPagination;

    public string $busca        = '';
    public string $tip_associado = '';
    public string $selectedAssociacaoId = '';

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function updatedTipAssociado(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedAssociacaoId(): void
    {
        $this->resetPage();
    }

    public function excluir(int $id): void
    {
        $membro = Membro::findOrFail($id);
        $membro->delete();

        \Flux::toast(variant: 'success', text: __('messages.alerts.success.deleted'));
    }

    public function with(): array
    {
        $latestOfx = Ofx::latest()->first();

        $membros = Membro::query()
            ->when($this->busca, fn ($q) =>
                $q->where(fn($sub) => $sub->where('nom_membro', 'like', "%{$this->busca}%")
                  ->orWhere('eml_membro', 'like', "%{$this->busca}%"))
            )
            ->when($this->tip_associado, fn ($q) =>
                $q->where('tip_associado', $this->tip_associado)
            )
            ->when(auth()->user()->isAdmin() && $this->selectedAssociacaoId, fn ($q) =>
                $q->where('idt_associacao', $this->selectedAssociacaoId)
            )
            ->with('associacao')
            ->orderBy('nom_membro')
            ->paginate(15);

        $membros->getCollection()->transform(function ($m) use ($latestOfx) {
            $m->overdue = false;
            if ($latestOfx) {
                $m->overdue = Resumo::where('idt_ofx', $latestOfx->idt_ofx)
                    ->where('ind_pago', false)
                    ->where(function($query) use ($m) {
                        $query->where(function($q) use ($m) {
                            if ($m->num_cpf_membro) {
                                $cleanCpf = preg_replace('/\D/', '', $m->num_cpf_membro);
                                $q->whereRaw("REPLACE(REPLACE(num_cpf_pagador, '.', ''), '-', '') = ?", [$cleanCpf]);
                                if (strlen($cleanCpf) === 14 && str_starts_with($cleanCpf, '000')) {
                                    $q->orWhereRaw("REPLACE(REPLACE(num_cpf_pagador, '.', ''), '-', '') = ?", [substr($cleanCpf, 3)]);
                                }
                            } else {
                                $q->whereRaw("1 = 0");
                            }
                        })
                        ->orWhere('nom_pessoa', $m->nom_membro);
                    })
                    ->exists();
            }
            return $m;
        });

        return [
            'membros'        => $membros,
            'tiposAssociado' => Perfil::cases(),
            'associacoes'    => auth()->user()->isAdmin() ? \App\Models\Associacao::orderBy('nom_associacao')->get() : collect(),
        ];
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto" x-data="{}" x-on:open-wa-link.window="window.open($event.detail.url, '_blank')">

    {{-- Cabeçalho --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                <flux:icon name="users" class="size-6 text-blue-600" /> Membros
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                Gerencie os membros cadastrados na associação, visualize seus status de pagamento e execute ações.
            </p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <flux:button variant="primary" icon="plus" :href="route('membros.create')" wire:navigate>
                Novo membro
            </flux:button>
        </div>
    </div>

    {{-- Filtros --}}
    <flux:card class="flex flex-col sm:flex-row gap-3">
        <div class="flex-1">
            <flux:input
                 wire:model.live.debounce.300ms="busca"
                 placeholder="Buscar por nome ou e-mail..."
                 icon="magnifying-glass"
                 clearable
                 aria-label="Buscar membros"
            />
        </div>
        @if(auth()->user()->isAdmin())
            <div class="sm:w-60">
                <flux:select wire:model.live="selectedAssociacaoId" aria-label="Associação">
                    <flux:select.option value="">Todas as Associações</flux:select.option>
                    @foreach ($associacoes as $assoc)
                        <flux:select.option value="{{ $assoc->idt_associacao }}">
                            {{ $assoc->nom_associacao }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
        <div class="sm:w-52">
            <flux:select wire:model.live="tip_associado" aria-label="Tipo de associado">
                <flux:select.option value="">Todos os tipos</flux:select.option>
                @foreach ($tiposAssociado as $tipo)
                    <flux:select.option value="{{ $tipo->value }}">
                        {{ $tipo->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </flux:card>

    {{-- Lista/Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse ($membros as $membro)
            <flux:card class="flex flex-col justify-between p-5 space-y-4" wire:key="membro-{{ $membro->idt_membro }}">
                <div class="space-y-3">
                    {{-- Cabeçalho: Iniciais + Nome e Endereço --}}
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                {{ strtoupper(substr($membro->nom_membro, 0, 1)) }}
                            </div>
                            <div class="overflow-hidden">
                                <h3 class="font-bold text-base text-neutral-800 dark:text-neutral-200 truncate" title="{{ $membro->nom_membro }}">
                                    {{ $membro->nom_membro }}
                                </h3>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400 truncate" title="{{ $membro->end_logradouro }}">
                                    {{ $membro->end_logradouro ?: 'Sem endereço cadastrado' }}
                                </p>
                            </div>
                        </div>
                        <div class="flex-shrink-0">
                            <flux:badge size="sm" class="uppercase">
                                {{ $membro->tip_associado->label() }}
                            </flux:badge>
                        </div>
                    </div>

                    {{-- Detalhes/Metadata --}}
                    <div class="pt-2 border-t border-neutral-100 dark:border-neutral-800/60 space-y-2 text-xs">
                        {{-- Chave OFX status --}}
                        <div class="flex justify-between items-center">
                            <span class="text-neutral-400 dark:text-neutral-500">CPF:</span>
                            <span class="font-medium text-neutral-700 dark:text-neutral-300">
                                {{ $membro->num_cpf_membro }}
                            </span>
                        </div>

                        @if(auth()->user()->isAdmin())
                            <div class="flex justify-between items-center gap-2">
                                <span class="text-neutral-400 dark:text-neutral-500">Associação:</span>
                                <span class="font-medium text-neutral-700 dark:text-neutral-300 truncate max-w-[180px]">
                                    {{ $membro->associacao?->nom_associacao ?? 'Sem Associação' }}
                                </span>
                            </div>
                        @endif

                        {{-- Contatos --}}
                        <div class="space-y-1.5 pt-1 text-neutral-600 dark:text-neutral-400">
                            @if($membro->eml_membro)
                                <div class="flex items-center gap-2 truncate">
                                    <flux:icon name="envelope" class="size-3.5 text-neutral-400 flex-shrink-0" />
                                    <span class="truncate" title="{{ $membro->eml_membro }}">{{ $membro->eml_membro }}</span>
                                </div>
                            @endif
                            @if($membro->tel_membro)
                                <div class="flex items-center gap-2">
                                    <flux:icon name="phone" class="size-3.5 text-neutral-400 flex-shrink-0" />
                                    <span>{{ $membro->tel_membro }}</span>
                                </div>
                            @endif
                            @if($membro->des_telegram_chat_id)
                                <div class="flex items-center gap-2">
                                    <flux:icon name="paper-airplane" class="size-3.5 text-neutral-400 flex-shrink-0" />
                                    <span>{{ $membro->des_telegram_chat_id }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Ações --}}
                <div class="flex gap-2 pt-2 border-t border-neutral-100 dark:border-neutral-800/60">
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="pencil"
                        :href="route('membros.edit', $membro)"
                        wire:navigate
                        class="flex-1"
                    >
                        Editar
                    </flux:button>
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="trash"
                        wire:click="excluir({{ $membro->idt_membro }})"
                        wire:confirm="Tem certeza que deseja remover {{ $membro->nom_membro }}?"
                        class="text-red-500 hover:text-red-700"
                    >
                        Remover
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <div class="col-span-full py-12 text-center text-zinc-400 bg-white border border-neutral-200 dark:border-neutral-700 dark:bg-zinc-900 rounded-xl shadow-xs">
                Nenhum membro encontrado.
            </div>
        @endforelse
    </div>

    @if ($membros->hasPages())
        <div class="mt-6">
            {{ $membros->links() }}
        </div>
    @endif

</div>
