<?php

use App\Models\Notificacao;
use App\Models\Membro;
use App\Models\Ofx;
use App\Enums\TipoNotificacao;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Mensagens')] class extends Component {
    use WithPagination;

    public string $busca = '';

    // Bulk Message Form
    public string $bulkTarget = 'associados'; // associados, adimplentes, inadimplentes
    public array $bulkChannels = ['email']; // email, whatsapp
    public string $bulkSubject = '';
    public string $bulkMessage = '';

    // Generated WhatsApp Web Links to open manually
    public array $generatedWaLinks = [];

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function reprocessar(int $id): void
    {
        // Placeholder or actual logic for retrying notification if needed
        $this->dispatch('toast', message: 'Mensagem agendada para reenvio!', variant: 'success');
    }

    /**
     * Send bulk message
     */
    public function sendBulkMessage(): void
    {
        $this->validate([
            'bulkSubject' => 'required|string|max:150',
            'bulkMessage' => 'required|string|max:2000',
            'bulkTarget' => 'required|in:associados,adimplentes,inadimplentes',
            'bulkChannels' => 'required|array|min:1',
        ]);

        $selectedImport = Ofx::latest()->first();
        if (!$selectedImport && ($this->bulkTarget === 'adimplentes' || $this->bulkTarget === 'inadimplentes')) {
            $this->dispatch('toast', message: 'Selecione um extrato OFX para filtrar adimplência.', variant: 'danger');
            return;
        }

        $allMembers = Membro::all();
        $targetMembers = collect();

        if ($this->bulkTarget === 'associados') {
            $targetMembers = $allMembers;
        } else {
            $resumos = $selectedImport->resumos;
            $uniqueMonths = $resumos->unique(fn ($r) => $r->num_ano.'-'.$r->num_mes)->values();

            $adimplentes = collect();
            $inadimplentes = collect();

            foreach ($allMembers as $membro) {
                $nomeMatching = $membro->nomeParaMatchingOfx();
                $resumosPessoa = $resumos->where('nom_pessoa', $nomeMatching);
                $isAdimplente = true;

                if ($uniqueMonths->isEmpty()) {
                    $isAdimplente = false;
                } else {
                    foreach ($uniqueMonths as $mesRef) {
                        $resumoMes = $resumosPessoa->firstWhere(
                            fn ($r) => $r->num_ano == $mesRef->num_ano && $r->num_mes == $mesRef->num_mes
                        );
                        $valor = $resumoMes ? (float) $resumoMes->val_total : 0;
                        if ($valor <= 0) {
                            $isAdimplente = false;
                            break;
                        }
                    }
                }

                if ($isAdimplente) {
                    $adimplentes->push($membro);
                } else {
                    $inadimplentes->push($membro);
                }
            }

            $targetMembers = $this->bulkTarget === 'adimplentes' ? $adimplentes : $inadimplentes;
        }

        if ($targetMembers->isEmpty()) {
            $this->dispatch('toast', message: 'Nenhum membro encontrado no grupo selecionado.', variant: 'warning');
            return;
        }

        $this->generatedWaLinks = [];

        foreach ($targetMembers as $membro) {
            if (in_array('email', $this->bulkChannels) && filled($membro->eml_membro)) {
                $emailChannel = app(EmailChannel::class);
                $emailChannel->send($membro, $this->bulkMessage, TipoNotificacao::CUSTOM, ['subject' => $this->bulkSubject]);
            }

            if (in_array('whatsapp', $this->bulkChannels) && filled($membro->tel_membro)) {
                $waChannel = app(WhatsAppChannel::class);
                $res = $waChannel->send($membro, $this->bulkMessage, TipoNotificacao::CUSTOM);
                if ($res['success'] && isset($res['redirect_url'])) {
                    $this->generatedWaLinks[] = [
                        'name' => $membro->nom_membro,
                        'url' => $res['redirect_url']
                    ];
                }
            }
        }

        $this->dispatch('toast', message: 'Disparos em massa concluídos!', variant: 'success');
        
        if (count($this->generatedWaLinks) === 0) {
            $this->reset(['bulkSubject', 'bulkMessage']);
        }
    }

    /**
     * Send annual discharge email
     */
    public function sendAnnualDischarge(): void
    {
        $selectedImport = Ofx::latest()->first();
        if (!$selectedImport) {
            $this->dispatch('toast', message: 'Selecione um extrato OFX para identificar os adimplentes.', variant: 'danger');
            return;
        }

        $allMembers = Membro::all();
        $adimplentes = collect();

        $resumos = $selectedImport->resumos;
        $uniqueMonths = $resumos->unique(fn ($r) => $r->num_ano.'-'.$r->num_mes)->values();

        if ($uniqueMonths->isEmpty()) {
            $this->dispatch('toast', message: 'Nenhum mês de referência encontrado na importação.', variant: 'warning');
            return;
        }

        foreach ($allMembers as $membro) {
            $nomeMatching = $membro->nomeParaMatchingOfx();
            $resumosPessoa = $resumos->where('nom_pessoa', $nomeMatching);
            $isAdimplente = true;

            foreach ($uniqueMonths as $mesRef) {
                $resumoMes = $resumosPessoa->firstWhere(
                    fn ($r) => $r->num_ano == $mesRef->num_ano && $r->num_mes == $mesRef->num_mes
                );
                $valor = $resumoMes ? (float) $resumoMes->val_total : 0;
                if ($valor <= 0) {
                    $isAdimplente = false;
                    break;
                }
            }

            if ($isAdimplente) {
                $adimplentes->push($membro);
            }
        }

        if ($adimplentes->isEmpty()) {
            $this->dispatch('toast', message: 'Nenhum membro adimplente encontrado neste período.', variant: 'warning');
            return;
        }

        $anoRef = $uniqueMonths->first()?->num_ano ?? date('Y');
        $emailChannel = app(EmailChannel::class);

        foreach ($adimplentes as $membro) {
            $mensagem = app(\App\Services\Notifications\Messages\MensagemBuilder::class)->construir(
                TipoNotificacao::QUITACAO_ANUAL,
                $membro,
                ['ano' => $anoRef]
            );

            $emailChannel->send($membro, $mensagem, TipoNotificacao::QUITACAO_ANUAL);
        }

        $this->dispatch('toast', message: 'E-mails de quitação anual enviados com sucesso!', variant: 'success');
    }

    public function with(): array
    {
        $mensagens = Notificacao::with('membro')
            ->when($this->busca, function ($q) {
                $q->whereHas('membro', fn ($query) => $query->where('nom_membro', 'like', "%{$this->busca}%"))
                  ->orWhere('txt_conteudo', 'like', "%{$this->busca}%")
                  ->orWhere('tip_canal', 'like', "%{$this->busca}%");
            })
            ->latest()
            ->paginate(15);

        return [
            'mensagens' => $mensagens,
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6" x-data="{}" x-on:open-wa-link.window="window.open($event.detail.url, '_blank')">

    {{-- Toast alerts --}}
    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Mensagens</flux:heading>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Tabela de Histórico (Esquerda) --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Filtros --}}
            <flux:card>
                <flux:input
                    wire:model.live.debounce.300ms="busca"
                    placeholder="Buscar por membro, conteúdo ou canal..."
                    icon="magnifying-glass"
                    clearable
                />
            </flux:card>

            {{-- Tabela --}}
            <flux:card class="p-0 overflow-hidden">
                <div class="border-b border-neutral-100 px-5 py-4 dark:border-neutral-800 bg-neutral-50/50 dark:bg-zinc-800/20">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300">
                        Histórico de Mensagens Enviadas
                    </h3>
                </div>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Membro</flux:table.column>
                        <flux:table.column>Canal</flux:table.column>
                        <flux:table.column>Conteúdo</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Data / Hora</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($mensagens as $msg)
                            <flux:table.row wire:key="{{ $msg->idt_notificacao }}">

                                <flux:table.cell class="font-medium">
                                    {{ $msg->membro?->nom_membro ?? 'Membro Removido' }}
                                </flux:table.cell>

                                <flux:table.cell>
                                    <div class="flex items-center gap-1.5 capitalize text-xs">
                                        @if($msg->tip_canal === 'whatsapp')
                                            <span class="text-green-500"><i class="fa-brands fa-whatsapp text-sm mr-1"></i>WhatsApp</span>
                                        @elseif($msg->tip_canal === 'email')
                                            <span class="text-blue-500"><i class="fa-solid fa-envelope text-sm mr-1"></i>E-mail</span>
                                        @else
                                            <span class="text-sky-500"><i class="fa-brands fa-telegram text-sm mr-1"></i>Telegram</span>
                                        @endif
                                    </div>
                                </flux:table.cell>

                                <flux:table.cell class="max-w-xs truncate text-zinc-500" title="{{ $msg->txt_conteudo }}">
                                    {{ $msg->txt_conteudo }}
                                </flux:table.cell>

                                <flux:table.cell>
                                    @if ($msg->ind_enviada)
                                        <flux:badge color="green" size="sm" icon="check">Enviado</flux:badge>
                                    @else
                                        <div class="flex flex-col gap-0.5">
                                            <flux:badge color="red" size="sm" icon="x-mark" title="{{ $msg->msg_erro }}">Falha</flux:badge>
                                            @if($msg->msg_erro)
                                                <span class="text-[10px] text-red-500 max-w-xs truncate">{{ $msg->msg_erro }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell class="text-zinc-500">
                                    {{ $msg->created_at->format('d/m/Y H:i') }}
                                </flux:table.cell>

                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5" class="py-12 text-center text-zinc-400">
                                    Nenhuma mensagem enviada encontrada.
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

        {{-- Disparos de Mensagens (Direita) --}}
        <div class="space-y-6">
            {{-- Formulário de Disparo em Massa --}}
            <flux:card class="p-5">
                <h3 class="text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300 flex items-center gap-2 mb-4">
                    <flux:icon name="chat-bubble-left-right" class="size-4 text-blue-600" /> Disparo de Mensagem em Massa
                </h3>
                <form wire:submit="sendBulkMessage" class="space-y-4">
                    <flux:select label="Destinatários" wire:model="bulkTarget">
                        <flux:select.option value="associados">Todos os Associados (Base Geral)</flux:select.option>
                        <flux:select.option value="adimplentes">Todos os Adimplentes (Período Selecionado)</flux:select.option>
                        <flux:select.option value="inadimplentes">Todos os Inadimplentes (Período Selecionado)</flux:select.option>
                    </flux:select>

                    <div class="space-y-2">
                        <p class="text-xs font-semibold text-neutral-600 dark:text-neutral-400">Canais de Envio</p>
                        <div class="flex items-center gap-4">
                            <label class="inline-flex items-center text-sm font-medium text-neutral-700 dark:text-neutral-300 cursor-pointer">
                                <input type="checkbox" value="email" wire:model="bulkChannels" class="rounded border-neutral-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 mr-2">
                                E-mail (ascaje@gmail.com)
                            </label>
                            <label class="inline-flex items-center text-sm font-medium text-neutral-700 dark:text-neutral-300 cursor-pointer">
                                <input type="checkbox" value="whatsapp" wire:model="bulkChannels" class="rounded border-neutral-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 mr-2">
                                WhatsApp Web
                            </label>
                        </div>
                    </div>

                    <flux:input label="Assunto (Apenas para E-mail)" wire:model="bulkSubject" placeholder="Digite o assunto do e-mail..." />

                    <flux:textarea label="Mensagem" wire:model="bulkMessage" rows="5" placeholder="Escreva a mensagem aqui..." />

                    <flux:button type="submit" variant="primary" class="w-full" icon="paper-airplane">Enviar Mensagem</flux:button>
                </form>
            </flux:card>

            {{-- Links Gerados de WhatsApp (Exibe quando dispara para WhatsApp em massa) --}}
            @if(count($generatedWaLinks) > 0)
            <flux:card class="p-5 border-green-500/30 dark:border-green-500/20 bg-green-500/5">
                <h3 class="text-sm font-bold uppercase tracking-wider text-green-700 dark:text-green-400 flex items-center gap-2 mb-3">
                    <flux:icon name="phone" class="size-4" /> Links do WhatsApp Web Gerados
                </h3>
                <p class="text-xs text-neutral-500 mb-4">Clique nos botões abaixo para abrir cada conversa no WhatsApp Web e enviar a mensagem manualmente:</p>
                <div class="max-h-60 space-y-2 overflow-y-auto pr-1">
                    @foreach($generatedWaLinks as $waLink)
                        <div class="flex items-center justify-between p-2 rounded-lg bg-white dark:bg-zinc-800 border border-neutral-100 dark:border-neutral-700 shadow-sm text-xs">
                            <span class="font-semibold text-neutral-800 dark:text-neutral-200 truncate flex-1 mr-2">{{ $waLink['name'] }}</span>
                            <flux:button href="{{ $waLink['url'] }}" target="_blank" size="xs" variant="primary" icon="phone">Enviar</flux:button>
                        </div>
                    @endforeach
                </div>
                <flux:button wire:click="$set('generatedWaLinks', [])" size="xs" variant="ghost" class="mt-4 w-full">Limpar Lista</flux:button>
            </flux:card>
            @endif

            {{-- Seção de Quitação Anual --}}
            <flux:card class="p-5">
                <h3 class="text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300 flex items-center gap-2 mb-3">
                    <flux:icon name="document-text" class="size-4 text-blue-600" /> Quitação Anual de Débitos
                </h3>
                <p class="text-xs text-neutral-500 mb-4">
                    Envia por e-mail (usando <span class="font-semibold">ascaje@gmail.com</span>) a Declaração de Quitação Anual para todos os membros que estão com a situação de adimplência 100% regular.
                </p>
                <flux:button wire:click="sendAnnualDischarge" variant="primary" class="w-full" icon="envelope-open" wire:confirm="Confirma o envio do e-mail de quitação anual para todos os membros adimplentes?">
                    Enviar Quitação Anual
                </flux:button>
            </flux:card>
        </div>
    </div>
</div>
