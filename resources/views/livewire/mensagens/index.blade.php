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

    {{-- Lista/Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse ($mensagens as $msg)
            <flux:card class="flex flex-col justify-between p-5 space-y-4" wire:key="msg-{{ $msg->idt_mensagem }}">
                <div class="space-y-3">
                    {{-- Cabeçalho da Campanha --}}
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-bold text-base text-neutral-800 dark:text-neutral-200 truncate" title="{{ $msg->nom_campanha }}">
                                {{ $msg->nom_campanha }}
                            </h3>
                            @if ($msg->tip_destinatario === 'A')
                                <flux:badge color="blue" size="sm" class="flex-shrink-0">Todos</flux:badge>
                            @elseif ($msg->tip_destinatario === 'D')
                                <flux:badge color="green" size="sm" class="flex-shrink-0">Adimplentes</flux:badge>
                            @elseif ($msg->tip_destinatario === 'I')
                                <flux:badge color="red" size="sm" class="flex-shrink-0">Inadimplentes</flux:badge>
                            @endif
                        </div>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1 line-clamp-2" title="{{ $msg->txt_mensagem }}">
                            {{ $msg->txt_mensagem }}
                        </p>
                    </div>

                    {{-- Detalhes/Metadata --}}
                    <div class="pt-2 border-t border-neutral-100 dark:border-neutral-800/60 space-y-2.5 text-xs">
                        <div class="flex justify-between items-center gap-2">
                            <span class="text-neutral-400 dark:text-neutral-500">Associação:</span>
                            <span class="font-medium text-neutral-700 dark:text-neutral-300 truncate max-w-[180px]">
                                {{ $msg->associacao->nom_associacao }}
                            </span>
                        </div>

                        {{-- Progresso de Envios --}}
                        <div class="space-y-1">
                            <div class="flex justify-between text-[11px] font-semibold text-neutral-600 dark:text-neutral-400">
                                <span class="text-neutral-400 dark:text-neutral-500 font-normal">Progresso:</span>
                                <span>{{ $msg->envios_sucesso_count }} / {{ $msg->envios_count }} ({{ $msg->envios_count > 0 ? round(($msg->envios_sucesso_count / $msg->envios_count) * 100) : 0 }}%)</span>
                            </div>
                            <div class="w-full bg-neutral-100 dark:bg-neutral-800 h-2 rounded-full overflow-hidden">
                                @php
                                    $percent = $msg->envios_count > 0 ? ($msg->envios_sucesso_count / $msg->envios_count) * 100 : 0;
                                    $progressColor = $percent === 100 ? 'bg-green-500' : 'bg-blue-500';
                                @endphp
                                <div class="{{ $progressColor }} h-2 rounded-full transition-all duration-300" style="width: {{ $percent }}%"></div>
                            </div>
                        </div>

                        {{-- Quem Enviou --}}
                        <div class="flex justify-between items-center gap-2">
                            <span class="text-neutral-400 dark:text-neutral-500">Quem enviou:</span>
                            <div class="flex items-center gap-1.5 overflow-hidden">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-neutral-200 dark:bg-neutral-800 text-[9px] font-bold text-neutral-700 dark:text-neutral-300">
                                    {{ $msg->usuario->initials() }}
                                </span>
                                <span class="text-neutral-700 dark:text-neutral-300 truncate max-w-[120px]">{{ $msg->usuario->name }}</span>
                            </div>
                        </div>

                        {{-- Data de Criação --}}
                        <div class="flex justify-between">
                            <span class="text-neutral-400 dark:text-neutral-500">Criada em:</span>
                            <span class="font-medium text-neutral-700 dark:text-neutral-300">{{ $msg->created_at->format('d/m/Y H:i') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Ação --}}
                <div class="pt-2">
                    <flux:button
                        icon="eye"
                        size="sm"
                        variant="primary"
                        :href="route('mensagens.show', ['mensagem' => $msg->idt_mensagem])"
                        wire:navigate
                        class="w-full"
                    >
                        Visualizar Detalhes
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <div class="col-span-full py-12 text-center text-zinc-400 bg-white border border-neutral-200 dark:border-neutral-700 dark:bg-zinc-900 rounded-xl shadow-xs">
                Nenhuma campanha registrada no sistema.
            </div>
        @endforelse
    </div>

    @if ($mensagens->hasPages())
        <div class="mt-6">
            {{ $mensagens->links() }}
        </div>
    @endif
</div>
