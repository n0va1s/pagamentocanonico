<?php

use App\Models\Mensagem;
use App\Models\MensagemEnvio;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Mensagem $mensagem;
    public string $filter = 'all'; // all, pending, sent
    public string $search = '';

    public function mount(Mensagem $mensagem): void
    {
        $this->mensagem = $mensagem->load(['associacao', 'usuario']);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function marcarComoEnviado(int $envioId): void
    {
        $envio = MensagemEnvio::findOrFail($envioId);
        $envio->update([
            'ind_enviado' => true,
            'dat_envio' => now(),
        ]);
        
        $this->dispatch('toast', message: "Status de {$envio->nom_destinatario} atualizado!", variant: 'success');
    }

    public function resetarEnvio(int $envioId): void
    {
        $envio = MensagemEnvio::findOrFail($envioId);
        $envio->update([
            'ind_enviado' => false,
            'dat_envio' => null,
        ]);

        $this->dispatch('toast', message: "Envio de {$envio->nom_destinatario} resetado.", variant: 'success');
    }

    public function dispararProximoPendente(): void
    {
        $proximo = $this->mensagem->envios()
            ->where('ind_enviado', false)
            ->first();

        if (!$proximo) {
            $this->dispatch('toast', message: 'Todos os contatos desta campanha já foram enviados.', variant: 'success');
            return;
        }

        $url = $this->gerarUrlWhatsapp($proximo);

        $proximo->update([
            'ind_enviado' => true,
            'dat_envio' => now(),
        ]);

        $this->dispatch('abrir-whatsapp', url: $url);
        $this->dispatch('toast', message: "Abrindo chat de {$proximo->nom_destinatario}...", variant: 'success');
    }

    public function gerarUrlWhatsapp(MensagemEnvio $envio): string
    {
        $data = [
            'nome' => $envio->nom_destinatario,
            'apelido' => $envio->nom_destinatario,
            'associacao' => $this->mensagem->associacao->nom_associacao,
            'participante' => $envio->nom_destinatario,
            'responsavel_nome' => '',
        ];

        $textoFormatado = Mensagem::formatar($this->mensagem->txt_mensagem, $data);

        $phone = \App\Services\PhoneService::clean($envio->tel_destinatario);
        if ($phone && !str_starts_with($phone, '55')) {
            $phone = '55' . $phone;
        }

        return "https://wa.me/{$phone}?text=" . rawurlencode($textoFormatado);
    }

    public function with(): array
    {
        $envios = $this->mensagem->envios()
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('nom_destinatario', 'like', '%' . $this->search . '%')
                        ->orWhere('tel_destinatario', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->filter === 'pending', function ($query) {
                $query->where('ind_enviado', false);
            })
            ->when($this->filter === 'sent', function ($query) {
                $query->where('ind_enviado', true);
            })
            ->orderBy('nom_destinatario', 'asc')
            ->paginate(15);

        $totais = $this->mensagem->envios()
            ->selectRaw('count(*) as total, sum(case when ind_enviado = 1 then 1 else 0 end) as enviados')
            ->first();

        return [
            'envios' => $envios,
            'sucesso_count' => $totais->enviados ?? 0,
            'total_count' => $totais->total ?? 0,
        ];
    }
}; ?>

<div class="space-y-6 w-full max-w-7xl mx-auto p-4 md:p-8" x-data="{
    init() {
        $wire.on('abrir-whatsapp', (event) => {
            window.open(event.url, '_blank');
        });
    }
}">
    <div class="flex items-center gap-2 text-sm text-zinc-500">
        <a href="{{ route('mensagens.index') }}" class="hover:underline" wire:navigate>Mensagens</a>
        <span>/</span>
        <span>Detalhes do Envio</span>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <flux:card class="xl:col-span-2 space-y-4 border border-zinc-200 dark:border-zinc-700 shadow-sm rounded-xl">
            <div>
                <flux:heading size="lg">{{ $mensagem->nom_campanha }}</flux:heading>
                <flux:subheading class="mt-1">
                    Associação: <strong class="text-zinc-800 dark:text-zinc-200">{{ $mensagem->associacao->nom_associacao }}</strong>
                </flux:subheading>
            </div>

            <flux:separator variant="subtle" />

            <div>
                <span class="text-2xs font-bold text-zinc-400 uppercase">Mensagem Base (Template)</span>
                <div class="mt-1 bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 p-3 rounded-lg text-xs leading-relaxed text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $mensagem->txt_mensagem }}</div>
            </div>

            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-zinc-500">
                <div>Público-alvo: <strong>{{ $mensagem->tip_destinatario === 'A' ? 'Todos os Associados' : ($mensagem->tip_destinatario === 'D' ? 'Adimplentes' : 'Inadimplentes') }}</strong></div>
                <div>Criado por: <strong>{{ $mensagem->usuario->name }}</strong></div>
                <div>Criado em: <strong>{{ $mensagem->created_at->format('d/m/Y H:i') }}</strong></div>
            </div>
        </flux:card>

        <flux:card class="space-y-6 flex flex-col justify-between border border-zinc-200 dark:border-zinc-700 shadow-sm rounded-xl">
            <div class="space-y-4">
                <flux:heading size="md">Progresso dos Disparos</flux:heading>
                <div class="flex items-baseline justify-between">
                    <span class="text-3xl font-extrabold text-zinc-950 dark:text-white">
                        {{ $sucesso_count }} <span class="text-sm font-normal text-zinc-500">/ {{ $total_count }} enviados</span>
                    </span>
                    <span class="text-sm font-bold text-zinc-500">
                        {{ $total_count > 0 ? round(($sucesso_count / $total_count) * 100) : 0 }}%
                    </span>
                </div>

                <div class="w-full bg-zinc-100 dark:bg-zinc-700 h-2.5 rounded-full overflow-hidden">
                    @php
                        $percent = $total_count > 0 ? ($sucesso_count / $total_count) * 100 : 0;
                        $progressColor = $percent === 100 ? 'bg-green-500' : 'bg-blue-500';
                    @endphp
                    <div class="{{ $progressColor }} h-2.5 rounded-full transition-all duration-500" style="width: {{ $percent }}%"></div>
                </div>
            </div>

            @if ($sucesso_count < $total_count)
                <flux:button wire:click="dispararProximoPendente" variant="primary" icon="paper-airplane" class="w-full justify-center">
                    Enviar Próximo Pendente
                </flux:button>
            @else
                <div class="bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-lg p-3 text-xs text-center text-green-700 dark:text-green-400 font-medium flex items-center justify-center gap-1">
                    <flux:icon.check-circle class="size-4" /> Todos os envios foram finalizados!
                </div>
            @endif
        </flux:card>
    </div>

    {{-- Lista de Envios --}}
    <div class="space-y-4">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <flux:heading size="lg">Destinatários</flux:heading>

            <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto items-center">
                <flux:select wire:model.live="filter" class="w-full sm:w-40" size="sm">
                    <option value="all">Todos</option>
                    <option value="pending">Pendentes</option>
                    <option value="sent">Enviados</option>
                </flux:select>

                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar contato..." class="w-full sm:max-w-xs" size="sm" />
            </div>
        </div>

        <flux:card class="overflow-x-auto p-0 border border-zinc-200 dark:border-zinc-700 shadow-sm rounded-xl">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Destinatário</flux:table.column>
                    <flux:table.column>Telefone</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Último Clique</flux:table.column>
                    <flux:table.column align="end">Ações</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($envios as $envio)
                        <flux:table.row :key="'envio-'.$envio->idt_mensagem_envio">
                            <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                                {{ $envio->nom_destinatario }}
                            </flux:table.cell>

                            <flux:table.cell class="text-zinc-600 dark:text-zinc-400">
                                {{ \App\Services\PhoneService::format($envio->tel_destinatario) }}
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($envio->ind_enviado)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 dark:bg-green-950/20 px-2.5 py-0.5 text-xs font-semibold text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-600 dark:bg-green-400"></span>
                                        Enviado
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-yellow-50 dark:bg-yellow-950/20 px-2.5 py-0.5 text-xs font-semibold text-yellow-700 dark:text-yellow-400 border border-yellow-200 dark:border-yellow-800">
                                        <span class="h-1.5 w-1.5 rounded-full bg-yellow-600 dark:bg-yellow-400"></span>
                                        Pendente
                                    </span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="text-zinc-500 text-sm">
                                {{ $envio->dat_envio ? $envio->dat_envio->format('d/m/Y H:i:s') : '—' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <a
                                        href="{{ $this->gerarUrlWhatsapp($envio) }}"
                                        target="_blank"
                                        wire:click="marcarComoEnviado({{ $envio->idt_mensagem_envio }})"
                                        class="inline-flex items-center justify-center p-2 rounded-lg bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/20 dark:hover:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 transition-colors"
                                        title="Disparar no WhatsApp"
                                    >
                                        <flux:icon.chat-bubble-left class="size-4" />
                                    </a>

                                    @if ($envio->ind_enviado)
                                        <flux:button
                                            icon="arrow-path"
                                            size="sm"
                                            variant="ghost"
                                            wire:click="resetarEnvio({{ $envio->idt_mensagem_envio }})"
                                        />
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center py-10 text-zinc-500">
                                Nenhum destinatário encontrado.
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <div class="mt-4">
            {{ $envios->links(data: ['scrollTo' => false]) }}
        </div>
    </div>
</div>
