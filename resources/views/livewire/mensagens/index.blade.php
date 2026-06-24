<?php

use App\Models\Mensagem;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Mensagens')] class extends Component {
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'mensagens' => Mensagem::with(['associacao', 'usuario'])
                ->withCount([
                    'envios',
                    'envios as envios_sucesso_count' => fn($q) => $q->where('ind_enviado', true)
                ])
                ->when($this->search, function ($query) {
                    $query->where('nom_campanha', 'like', '%' . $this->search . '%')
                        ->orWhereHas('associacao', function ($q) {
                            $q->where('nom_associacao', 'like', '%' . $this->search . '%');
                        });
                })
                ->orderBy('created_at', 'desc')
                ->paginate(10),
        ];
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto">
    {{-- Cabeçalho --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                <flux:icon name="chat-bubble-left-right" class="size-6 text-blue-600" /> Mensagens
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                Campanhas via WhatsApp Web, taxas de impacto e histórico de disparos.
            </p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <flux:button :href="route('mensagens.create')" icon="plus" variant="primary" wire:navigate>
                Nova Mensagem / Campanha
            </flux:button>
        </div>
    </div>

    {{-- Filtros --}}
    <flux:card class="flex flex-col sm:flex-row gap-3">
        <div class="flex-1">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                placeholder="Buscar por campanha ou associação..."
                clearable
                aria-label="Buscar mensagens ou campanhas"
            />
        </div>
    </flux:card>

    {{-- Tabela --}}
    <flux:card class="overflow-x-auto p-0">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Campanha</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">Associação</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">Público-Alvo</flux:table.column>
                <flux:table.column class="hidden md:table-cell">Progresso</flux:table.column>
                <flux:table.column class="hidden lg:table-cell">Quem Enviou</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">Data de Criação</flux:table.column>
                <flux:table.column class="text-right">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($mensagens as $msg)
                    <flux:table.row :key="'msg-'.$msg->idt_mensagem">
                        <flux:table.cell>
                            <div class="font-semibold text-zinc-950 dark:text-white">
                                {{ $msg->nom_campanha }}
                            </div>
                            <div class="text-xs text-zinc-500 truncate max-w-xs" title="{{ $msg->txt_mensagem }}">
                                {{ Str::limit($msg->txt_mensagem, 60) }}
                            </div>
                            {{-- Mobile view subtext --}}
                            <div class="block sm:hidden text-2xs text-zinc-400 mt-1">
                                {{ $msg->associacao->nom_associacao }} • {{ $msg->created_at->format('d/m H:i') }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="hidden sm:table-cell">
                            <div class="font-medium text-zinc-800 dark:text-zinc-200">
                                {{ $msg->associacao->nom_associacao }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="hidden sm:table-cell">
                            @if ($msg->tip_destinatario === 'A')
                                <flux:badge color="blue" size="sm">Todos</flux:badge>
                            @elseif ($msg->tip_destinatario === 'D')
                                <flux:badge color="green" size="sm">Adimplentes</flux:badge>
                            @elseif ($msg->tip_destinatario === 'I')
                                <flux:badge color="red" size="sm">Inadimplentes</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="hidden md:table-cell">
                            <div class="flex flex-col gap-1 w-32">
                                <div class="flex justify-between text-2xs font-semibold text-zinc-600 dark:text-zinc-400">
                                    <span>{{ $msg->envios_sucesso_count }} / {{ $msg->envios_count }}</span>
                                    <span>{{ $msg->envios_count > 0 ? round(($msg->envios_sucesso_count / $msg->envios_count) * 100) : 0 }}%</span>
                                </div>
                                <div class="w-full bg-zinc-200 dark:bg-zinc-700 h-1.5 rounded-full overflow-hidden">
                                    @php
                                        $percent = $msg->envios_count > 0 ? ($msg->envios_sucesso_count / $msg->envios_count) * 100 : 0;
                                        $progressColor = $percent === 100 ? 'bg-green-500' : 'bg-blue-500';
                                    @endphp
                                    <div class="{{ $progressColor }} h-1.5 rounded-full" style="width: {{ $percent }}%"></div>
                                </div>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="hidden lg:table-cell">
                            <div class="flex items-center gap-2">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-zinc-200 dark:bg-zinc-700 text-3xs font-bold text-zinc-800 dark:text-zinc-200">
                                    {{ $msg->usuario->initials() }}
                                </span>
                                <span class="text-zinc-800 dark:text-zinc-200">{{ $msg->usuario->name }}</span>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="hidden sm:table-cell text-sm text-zinc-600 dark:text-zinc-400">
                            {{ $msg->created_at->format('d/m/Y H:i') }}
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <div class="flex justify-end gap-2">
                                <flux:button
                                    icon="eye"
                                    size="sm"
                                    variant="ghost"
                                    :href="route('mensagens.show', ['mensagem' => $msg->idt_mensagem])"
                                    wire:navigate
                                    aria-label="Ver detalhes"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="text-center py-12 text-zinc-500">
                            Nenhuma campanha registrada no sistema.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if ($mensagens->hasPages())
            <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-700">
                {{ $mensagens->links() }}
            </div>
        @endif
    </flux:card>
</div>
