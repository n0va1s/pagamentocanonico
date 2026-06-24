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

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function updatedTipAssociado(): void
    {
        $this->resetPage();
    }

    public function excluir(int $id): void
    {
        $membro = Membro::findOrFail($id);
        $membro->delete();

        $this->dispatch('toast', message: 'Membro removido com sucesso!', variant: 'success');
    }

    public function with(): array
    {
        $latestOfx = Ofx::latest()->first();

        $membros = Membro::query()
            ->when($this->busca, fn ($q) =>
                $q->where('nom_membro', 'like', "%{$this->busca}%")
                  ->orWhere('eml_membro', 'like', "%{$this->busca}%")
            )
            ->when($this->tip_associado, fn ($q) =>
                $q->where('tip_associado', $this->tip_associado)
            )
            ->orderBy('nom_membro')
            ->paginate(15);

        $membros->getCollection()->transform(function ($m) use ($latestOfx) {
            $m->overdue = false;
            if ($latestOfx) {
                $m->overdue = Resumo::where('idt_ofx', $latestOfx->idt_ofx)
                    ->where('nom_pessoa', $m->nomeParaMatchingOfx())
                    ->where('ind_pago', false)
                    ->exists();
            }
            return $m;
        });

        return [
            'membros'        => $membros,
            'tiposAssociado' => Perfil::cases(),
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

    {{-- Tabela --}}
    <flux:card class="overflow-x-auto p-0">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Membro</flux:table.column>
                <flux:table.column class="hidden md:table-cell">Contato</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">Tipo</flux:table.column>
                <flux:table.column>Status OFX</flux:table.column>
                <flux:table.column class="text-right">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($membros as $membro)
                    <flux:table.row wire:key="{{ $membro->idt_membro }}">

                        <flux:table.cell class="font-medium">
                            <p class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $membro->nom_membro }}</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ Str::limit($membro->end_logradouro, 35) }}</p>
                        </flux:table.cell>

                        <flux:table.cell class="text-xs text-neutral-600 dark:text-neutral-400 hidden md:table-cell">
                            <div class="space-y-0.5">
                                @if($membro->eml_membro)<div><i class="fa-solid fa-envelope mr-1 w-4 text-neutral-400"></i>{{ $membro->eml_membro }}</div>@endif
                                @if($membro->tel_membro)<div><i class="fa-brands fa-whatsapp mr-1 w-4 text-green-500"></i>{{ $membro->tel_membro }}</div>@endif
                                @if($membro->des_telegram_chat_id)<div><i class="fa-brands fa-telegram mr-1 w-4 text-sky-500"></i>{{ $membro->des_telegram_chat_id }}</div>@endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="hidden sm:table-cell">
                            <flux:badge size="sm" class="uppercase">
                                {{ $membro->tip_associado->label() }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($membro->overdue)
                                <span class="text-xs font-bold text-red-600 flex items-center gap-1.5">
                                    <span class="inline-block h-2 w-2 rounded-full bg-red-600"></span>
                                    Inadimplente
                                </span>
                            @else
                                <span class="text-xs font-bold text-green-600 flex items-center gap-1.5">
                                    <span class="inline-block h-2 w-2 rounded-full bg-green-600"></span>
                                    Regular
                                </span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <div class="flex justify-end gap-1">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil"
                                    :href="route('membros.edit', $membro)"
                                    wire:navigate
                                    aria-label="Editar membro"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="excluir({{ $membro->idt_membro }})"
                                    wire:confirm="Tem certeza que deseja remover {{ $membro->nom_membro }}?"
                                    aria-label="Remover membro"
                                />
                            </div>
                        </flux:table.cell>

                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-12 text-center text-zinc-400">
                            Nenhum membro encontrado.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if ($membros->hasPages())
            <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-700">
                {{ $membros->links() }}
            </div>
        @endif
    </flux:card>

</div>
