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
    public string $bulkTarget = 'associados';
    public array $bulkChannels = ['email'];
    public string $bulkSubject = '';
    public string $bulkMessage = '';
    public array $generatedWaLinks = [];

    public function updatedBusca(): void { $this->resetPage(); }

    public function sendBulkMessage(): void
    {
        $this->validate([
            'bulkSubject' => 'required|string|max:150',
            'bulkMessage' => 'required|string|max:2000',
            'bulkTarget' => 'required|in:associados,adimplentes,inadimplentes',
            'bulkChannels' => 'required|array|min:1',
        ]);
        $selectedImport = Ofx::latest()->first();
        if (!$selectedImport && in_array($this->bulkTarget, ['adimplentes','inadimplentes'])) {
            $this->dispatch('toast', message: 'Nenhum extrato OFX encontrado.', variant: 'danger'); return;
        }
        $allMembers = Membro::all();
        $targetMembers = collect();
        if ($this->bulkTarget === 'associados') {
            $targetMembers = $allMembers;
        } else {
            $resumos = $selectedImport->resumos;
            $uniqueMonths = $resumos->unique(fn ($r) => $r->num_ano.'-'.$r->num_mes)->values();
            $adimplentes = collect(); $inadimplentes = collect();
            foreach ($allMembers as $membro) {
                $nomeMatching = $membro->nomeParaMatchingOfx();
                $resumosPessoa = $resumos->where('nom_pessoa', $nomeMatching);
                $isAdimplente = !$uniqueMonths->isEmpty();
                if ($isAdimplente) {
                    foreach ($uniqueMonths as $mesRef) {
                        $resumoMes = $resumosPessoa->firstWhere(fn ($r) => $r->num_ano == $mesRef->num_ano && $r->num_mes == $mesRef->num_mes);
                        if (!$resumoMes || (float)$resumoMes->val_total <= 0) { $isAdimplente = false; break; }
                    }
                }
                $isAdimplente ? $adimplentes->push($membro) : $inadimplentes->push($membro);
            }
            $targetMembers = $this->bulkTarget === 'adimplentes' ? $adimplentes : $inadimplentes;
        }
        if ($targetMembers->isEmpty()) { $this->dispatch('toast', message: 'Nenhum membro no grupo selecionado.', variant: 'warning'); return; }
        $this->generatedWaLinks = [];
        foreach ($targetMembers as $membro) {
            if (in_array('email', $this->bulkChannels) && filled($membro->eml_membro)) {
                app(EmailChannel::class)->send($membro, $this->bulkMessage, TipoNotificacao::CUSTOM, ['subject' => $this->bulkSubject]);
            }
            if (in_array('whatsapp', $this->bulkChannels) && filled($membro->tel_membro)) {
                $res = app(WhatsAppChannel::class)->send($membro, $this->bulkMessage, TipoNotificacao::CUSTOM);
                if ($res['success'] && isset($res['redirect_url'])) {
                    $this->generatedWaLinks[] = ['name' => $membro->nom_membro, 'url' => $res['redirect_url']];
                }
            }
        }
        $this->dispatch('toast', message: 'Disparos concluídos com sucesso!', variant: 'success');
        if (count($this->generatedWaLinks) === 0) { $this->reset(['bulkSubject', 'bulkMessage']); }
    }

    public function sendAnnualDischarge(): void
    {
        $selectedImport = Ofx::latest()->first();
        if (!$selectedImport) { $this->dispatch('toast', message: 'Nenhum extrato OFX disponível.', variant: 'danger'); return; }
        $resumos = $selectedImport->resumos;
        $uniqueMonths = $resumos->unique(fn ($r) => $r->num_ano.'-'.$r->num_mes)->values();
        if ($uniqueMonths->isEmpty()) { $this->dispatch('toast', message: 'Nenhum mês de referência na importação.', variant: 'warning'); return; }
        $adimplentes = collect();
        foreach (Membro::all() as $membro) {
            $nomeMatching = $membro->nomeParaMatchingOfx();
            $resumosPessoa = $resumos->where('nom_pessoa', $nomeMatching);
            $isAdimplente = true;
            foreach ($uniqueMonths as $mesRef) {
                $resumoMes = $resumosPessoa->firstWhere(fn ($r) => $r->num_ano == $mesRef->num_ano && $r->num_mes == $mesRef->num_mes);
                if (!$resumoMes || (float)$resumoMes->val_total <= 0) { $isAdimplente = false; break; }
            }
            if ($isAdimplente) $adimplentes->push($membro);
        }
        if ($adimplentes->isEmpty()) { $this->dispatch('toast', message: 'Nenhum membro adimplente encontrado.', variant: 'warning'); return; }
        $anoRef = $uniqueMonths->first()?->num_ano ?? date('Y');
        $emailChannel = app(EmailChannel::class);
        foreach ($adimplentes as $membro) {
            $mensagem = app(\App\Services\Notifications\Messages\MensagemBuilder::class)->construir(TipoNotificacao::QUITACAO_ANUAL, $membro, ['ano' => $anoRef]);
            $emailChannel->send($membro, $mensagem, TipoNotificacao::QUITACAO_ANUAL);
        }
        $this->dispatch('toast', message: 'E-mails de quitação anual enviados!', variant: 'success');
    }

    public function with(): array
    {
        $mensagens = Notificacao::with('membro')
            ->when($this->busca, function ($q) {
                $q->whereHas('membro', fn ($query) => $query->where('nom_membro', 'like', "%{$this->busca}%"))
                  ->orWhere('txt_conteudo', 'like', "%{$this->busca}%")
                  ->orWhere('tip_canal', 'like', "%{$this->busca}%");
            })
            ->latest()->paginate(15);
        return ['mensagens' => $mensagens];
    }
}; ?>

<div class="pc-page" x-data="{}" x-on:open-wa-link.window="window.open($event.detail.url,'_blank')">

    <div class="pc-page-header">
        <div>
            <div class="pc-label" style="margin-bottom:0.4rem">Comunicação</div>
            <h1 class="pc-page-title">Mensagens</h1>
            <p class="pc-page-subtitle">Histórico de envios e disparo de notificações em massa.</p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start" class="msg-grid">

        {{-- ── Histórico ── --}}
        <div style="display:flex;flex-direction:column;gap:1.25rem">

            {{-- Busca --}}
            <div class="pc-card">
                <div class="pc-card-body" style="padding:0.875rem 1.25rem">
                    <flux:input
                        wire:model.live.debounce.300ms="busca"
                        placeholder="Buscar por membro, canal ou conteúdo..."
                        icon="magnifying-glass"
                        clearable
                    />
                </div>
            </div>

            {{-- Tabela --}}
            <div class="pc-card">
                <div class="pc-card-header">
                    <span class="pc-card-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Histórico de envios
                    </span>
                    <span style="font-size:0.78rem;color:var(--pc-subtle)">{{ $mensagens->total() }} registros</span>
                </div>

                @if($mensagens->isEmpty())
                    <div class="pc-empty">
                        <div class="pc-empty-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </div>
                        <div class="pc-empty-title">Nenhuma mensagem</div>
                        <div class="pc-empty-desc">O histórico de envios aparecerá aqui.</div>
                    </div>
                @else
                <div style="overflow-x:auto">
                    <table class="pc-table">
                        <thead>
                            <tr>
                                <th>Membro</th>
                                <th>Canal</th>
                                <th>Mensagem</th>
                                <th>Status</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($mensagens as $msg)
                            <tr wire:key="{{ $msg->idt_notificacao }}">
                                <td class="cell-primary" style="white-space:nowrap">{{ $msg->membro?->nom_membro ?? '—' }}</td>
                                <td style="white-space:nowrap">
                                    @if($msg->tip_canal === 'whatsapp')
                                        <span class="pc-badge green">WhatsApp</span>
                                    @elseif($msg->tip_canal === 'email')
                                        <span class="pc-badge blue">E-mail</span>
                                    @else
                                        <span class="pc-badge muted">Telegram</span>
                                    @endif
                                </td>
                                <td style="max-width:300px">
                                    <p style="font-size:0.8rem;color:var(--pc-muted);line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical" title="{{ $msg->txt_conteudo }}">{{ $msg->txt_conteudo }}</p>
                                </td>
                                <td style="white-space:nowrap">
                                    @if($msg->ind_enviada)
                                        <span class="pc-badge green">Enviado</span>
                                    @else
                                        <div>
                                            <span class="pc-badge red">Falha</span>
                                            @if($msg->msg_erro)
                                                <div style="font-size:0.7rem;color:var(--pc-red);margin-top:0.2rem;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $msg->msg_erro }}">{{ $msg->msg_erro }}</div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="cell-mono" style="white-space:nowrap">
                                    <span style="font-size:0.8125rem;color:var(--pc-text)">{{ $msg->created_at->format('d/m/Y') }}</span>
                                    <span style="display:block;font-size:0.72rem;color:var(--pc-subtle)">{{ $msg->created_at->format('H:i') }}</span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($mensagens->hasPages())
                    <div style="padding:0.875rem 1.25rem;border-top:1px solid var(--pc-border)">{{ $mensagens->links() }}</div>
                @endif
                @endif
            </div>
        </div>

        {{-- ── Painel lateral ── --}}
        <div style="display:flex;flex-direction:column;gap:1.25rem">

            {{-- Disparo em massa --}}
            <div class="pc-card">
                <div class="pc-card-header">
                    <span class="pc-card-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Disparo em massa
                    </span>
                </div>
                <div class="pc-card-body">
                    <form wire:submit="sendBulkMessage" style="display:flex;flex-direction:column;gap:1rem">

                        <flux:select label="Destinatários" wire:model="bulkTarget">
                            <flux:select.option value="associados">Todos os associados</flux:select.option>
                            <flux:select.option value="adimplentes">Apenas adimplentes</flux:select.option>
                            <flux:select.option value="inadimplentes">Apenas inadimplentes</flux:select.option>
                        </flux:select>

                        <div>
                            <div class="pc-label" style="margin-bottom:0.625rem">Canais de envio</div>
                            <div style="display:flex;flex-direction:column;gap:0.5rem">
                                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;font-weight:500;color:var(--pc-text);cursor:pointer">
                                    <input type="checkbox" value="email" wire:model="bulkChannels" style="accent-color:var(--pc-accent)"> E-mail
                                </label>
                                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;font-weight:500;color:var(--pc-text);cursor:pointer">
                                    <input type="checkbox" value="whatsapp" wire:model="bulkChannels" style="accent-color:var(--pc-accent)"> WhatsApp Web
                                </label>
                            </div>
                        </div>

                        <flux:input label="Assunto (e-mail)" wire:model="bulkSubject" placeholder="Assunto da mensagem..." />
                        <flux:textarea label="Mensagem" wire:model="bulkMessage" rows="4" placeholder="Conteúdo da mensagem..." />

                        <button type="submit" class="pc-btn pc-btn-primary" style="width:100%;justify-content:center">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            Enviar mensagem
                        </button>
                    </form>
                </div>
            </div>

            {{-- Links WhatsApp gerados --}}
            @if(count($generatedWaLinks) > 0)
            <div class="pc-card" style="border-color:#a8d9be">
                <div class="pc-card-header" style="background:var(--pc-green-lt)">
                    <span class="pc-card-title" style="color:var(--pc-green)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.43 2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Links gerados ({{ count($generatedWaLinks) }})
                    </span>
                </div>
                <div class="pc-card-body">
                    <p style="font-size:0.78rem;color:var(--pc-muted);margin-bottom:0.875rem">Clique para abrir cada conversa no WhatsApp Web:</p>
                    <div style="display:flex;flex-direction:column;gap:0.5rem;max-height:240px;overflow-y:auto">
                        @foreach($generatedWaLinks as $waLink)
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;background:var(--pc-bg);border:1px solid var(--pc-border);border-radius:7px;padding:0.5rem 0.75rem">
                            <span style="font-size:0.8125rem;font-weight:600;color:var(--pc-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $waLink['name'] }}</span>
                            <a href="{{ $waLink['url'] }}" target="_blank" class="pc-btn pc-btn-ghost pc-btn-sm" style="font-size:0.75rem;flex-shrink:0">Abrir</a>
                        </div>
                        @endforeach
                    </div>
                    <button wire:click="$set('generatedWaLinks',[])" class="pc-btn pc-btn-ghost pc-btn-sm" style="width:100%;justify-content:center;margin-top:0.75rem">Limpar lista</button>
                </div>
            </div>
            @endif

            {{-- Quitação anual --}}
            <div class="pc-card">
                <div class="pc-card-header">
                    <span class="pc-card-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        Quitação anual
                    </span>
                </div>
                <div class="pc-card-body">
                    <p style="font-size:0.8125rem;color:var(--pc-muted);line-height:1.55;margin-bottom:1rem">
                        Envia por e-mail a Declaração de Quitação Anual para todos os membros <strong>100% adimplentes</strong> no extrato mais recente.
                    </p>
                    <button
                        class="pc-btn pc-btn-primary"
                        style="width:100%;justify-content:center"
                        wire:click="sendAnnualDischarge"
                        wire:confirm="Confirmar envio do e-mail de quitação anual para todos os adimplentes?"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Enviar quitação anual
                    </button>
                </div>
            </div>

        </div>
    </div>

    <style>
        @media(max-width:900px){
            .msg-grid{ grid-template-columns:1fr !important; }
        }
    </style>
</div>