<?php

use App\Models\Membro;
use App\Models\Resumo;
use App\Models\Ofx;
use Livewire\Volt\Component;
use Livewire\Attributes\Title;
use App\Services\Notifications\Channels\TelegramChannel;

new #[Title('Minha Associação')] class extends Component {
    public string $activeTab = 'pagamentos';

    public string $nom_membro = '';
    public ?string $nom_ofx = null;
    public ?string $tel_membro = null;
    public ?string $end_logradouro = null;
    public ?string $end_mumero = null;
    public ?string $end_complemento = null;
    public ?string $des_telegram_chat_id = null;
    public bool $ind_notificar_whatsapp = true;
    public bool $ind_notificar_email = true;
    public bool $ind_notificar_telegram = false;

    public string $contactName = '';
    public string $contactEmail = '';
    public string $contactMessage = '';

    public function mount(): void
    {
        $membro = Membro::where('eml_membro', auth()->user()->email)->first();
        if ($membro) {
            $this->nom_membro = $membro->nom_membro;
            $this->nom_ofx = $membro->nom_ofx;
            $this->tel_membro = $membro->tel_membro;
            $this->end_logradouro = $membro->end_logradouro;
            $this->end_mumero = $membro->end_mumero;
            $this->end_complemento = $membro->end_complemento;
            $this->des_telegram_chat_id = $membro->des_telegram_chat_id;
            $this->ind_notificar_whatsapp = (bool)$membro->ind_notificar_whatsapp;
            $this->ind_notificar_email = (bool)$membro->ind_notificar_email;
            $this->ind_notificar_telegram = (bool)$membro->ind_notificar_telegram;
            $this->contactName = $membro->nom_membro;
            $this->contactEmail = $membro->eml_membro;
        } else {
            $this->contactName = auth()->user()->name;
            $this->contactEmail = auth()->user()->email;
        }
    }

    public function updateProfile(): void
    {
        $membro = Membro::where('eml_membro', auth()->user()->email)->first();
        if (!$membro) {
            $this->dispatch('toast', message: 'Membro não cadastrado.', variant: 'danger');
            return;
        }
        $this->validate([
            'nom_membro' => 'required|string|max:255',
            'nom_ofx' => 'nullable|string|max:255',
            'tel_membro' => 'nullable|string|max:20',
            'end_logradouro' => 'nullable|string|max:150',
            'end_mumero' => 'nullable|string|max:20',
            'end_complemento' => 'nullable|string|max:150',
            'des_telegram_chat_id' => 'nullable|string|max:50',
            'ind_notificar_whatsapp' => 'required|boolean',
            'ind_notificar_email' => 'required|boolean',
            'ind_notificar_telegram' => 'required|boolean',
        ]);
        $membro->update([
            'nom_membro' => $this->nom_membro,
            'nom_ofx' => $this->nom_ofx ?: null,
            'tel_membro' => $this->tel_membro,
            'end_logradouro' => $this->end_logradouro,
            'end_mumero' => $this->end_mumero,
            'end_complemento' => $this->end_complemento,
            'des_telegram_chat_id' => $this->des_telegram_chat_id,
            'ind_notificar_whatsapp' => $this->ind_notificar_whatsapp,
            'ind_notificar_email' => $this->ind_notificar_email,
            'ind_notificar_telegram' => $this->ind_notificar_telegram,
        ]);
        $this->dispatch('toast', message: 'Dados atualizados com sucesso.', variant: 'success');
    }

    public function submitContact(): void
    {
        $this->validate([
            'contactName' => 'required|string|max:100',
            'contactEmail' => 'required|email|max:100',
            'contactMessage' => 'required|string|max:1000',
        ]);
        \App\Models\Contato::create([
            'nome' => $this->contactName,
            'email' => $this->contactEmail,
            'mensagem' => $this->contactMessage,
        ]);
        $telegram = app(TelegramChannel::class);
        $res = $telegram->sendContactRequest($this->contactName, $this->contactEmail, $this->contactMessage);
        if ($res['success']) {
            $this->dispatch('toast', message: 'Mensagem enviada com sucesso!', variant: 'success');
        } else {
            $this->dispatch('toast', message: 'Mensagem registrada. (Falha temporária ao enviar ao Telegram)', variant: 'warning');
        }
        $this->reset(['contactMessage']);
    }

    public function requestDischarge(int $resumoId): void
    {
        $membro = Membro::where('eml_membro', auth()->user()->email)->first();
        if (!$membro) { $this->dispatch('toast', message: 'Membro não cadastrado.', variant: 'danger'); return; }
        $resumo = Resumo::findOrFail($resumoId);
        if ($resumo->nom_pessoa !== $membro->nomeParaMatchingOfx()) { $this->dispatch('toast', message: 'Acesso negado.', variant: 'danger'); return; }
        $botToken = config('services.telegram.bot_token', '');
        $chatId = config('services.telegram.contact_chat_id') ?: env('TELEGRAM_CONTACT_CHAT_ID', '');
        if (empty($chatId)) { $this->dispatch('toast', message: 'Chat ID administrativo não configurado.', variant: 'danger'); return; }
        $texto = implode("\n", ["📄 <b>Solicitação de Quitação de Débito</b>", "👤 <b>Associado:</b> {$membro->nom_membro} ({$membro->eml_membro})", "📅 <b>Competência:</b> {$resumo->nom_mes}/{$resumo->num_ano}", "💰 <b>Valor:</b> R$ " . number_format($resumo->val_total, 2, ',', '.'), "✉️ Enviado via Minha Associação."]);
        try {
            $response = \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", ['chat_id' => $chatId, 'text' => $texto, 'parse_mode' => 'HTML']);
            if ($response->successful()) { $this->dispatch('toast', message: 'Solicitação enviada com sucesso!', variant: 'success'); }
            else { $this->dispatch('toast', message: 'Erro ao enviar ao Telegram.', variant: 'danger'); }
        } catch (\Exception $e) { $this->dispatch('toast', message: 'Erro: ' . $e->getMessage(), variant: 'danger'); }
    }

    public function with(): array
    {
        $membro = Membro::where('eml_membro', auth()->user()->email)->first();
        $resumosPendentes = collect();
        $resumosRegularizados = collect();
        if ($membro) {
            $nomeMatching = $membro->nomeParaMatchingOfx();
            $todosResumos = Resumo::where('nom_pessoa', $nomeMatching)->orderByDesc('num_ano')->orderByDesc('num_mes')->get();
            $resumosPendentes = $todosResumos->where('ind_pago', false);
            $resumosRegularizados = $todosResumos->where('ind_pago', true);
        }
        return ['membro' => $membro, 'resumosPendentes' => $resumosPendentes, 'resumosRegularizados' => $resumosRegularizados];
    }
}; ?>

<div class="pc-page">

    @if(!$membro)
        {{-- ── Membro não encontrado ── --}}
        <div class="pc-page-header">
            <div>
                <div class="pc-label" style="margin-bottom:0.4rem">Associação</div>
                <h1 class="pc-page-title">Minha Associação</h1>
            </div>
        </div>

        <div style="max-width:560px;margin:0 auto">
            <div class="pc-alert warning" style="margin-bottom:1.5rem">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>
                    <strong>Cadastro não localizado</strong><br>
                    <span style="font-size:0.8125rem">O e-mail <strong>{{ auth()->user()->email }}</strong> não está vinculado a nenhum membro. Envie uma solicitação abaixo.</span>
                </div>
            </div>

            <div class="pc-card">
                <div class="pc-card-header">
                    <span class="pc-card-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Solicitar registro de associado
                    </span>
                </div>
                <div class="pc-card-body">
                    <form wire:submit="submitContact" style="display:flex;flex-direction:column;gap:1rem">
                        <flux:input label="Seu nome" wire:model="contactName" />
                        <flux:input label="Seu e-mail" wire:model="contactEmail" disabled />
                        <flux:textarea label="Mensagem para a administração" wire:model="contactMessage" rows="4" placeholder="Olá, gostaria de solicitar a vinculação do meu e-mail à minha conta de associado..." />
                        <button type="submit" class="pc-btn pc-btn-primary" style="width:100%;justify-content:center">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            Enviar solicitação
                        </button>
                    </form>
                </div>
            </div>
        </div>

    @else
        {{-- ── Associado encontrado ── --}}
        <div class="pc-page-header">
            <div style="display:flex;align-items:center;gap:1rem">
                <div class="pc-avatar" style="width:44px;height:44px;font-size:1rem;background:var(--pc-accent-lt);color:var(--pc-accent)">
                    {{ strtoupper(substr($membro->nom_membro, 0, 1)) }}
                </div>
                <div>
                    <div class="pc-label" style="margin-bottom:0.2rem">Associação</div>
                    <h1 class="pc-page-title" style="font-size:1.4rem">{{ $membro->nom_membro }}</h1>
                    <p class="pc-page-subtitle">{{ $membro->eml_membro }} · Membro desde {{ $membro->created_at->format('M/Y') }}</p>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem">
                @if($resumosPendentes->count() > 0)
                    <span class="pc-badge red">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                        {{ $resumosPendentes->count() }} pendência(s)
                    </span>
                @else
                    <span class="pc-badge green">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                        Em dia
                    </span>
                @endif
                <span class="pc-badge muted">{{ $membro->tip_associado->label() }}</span>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="pc-tabs">
            <button class="pc-tab {{ $activeTab === 'pagamentos' ? 'active' : '' }}" wire:click="$set('activeTab','pagamentos')">Pagamentos</button>
            <button class="pc-tab {{ $activeTab === 'perfil' ? 'active' : '' }}" wire:click="$set('activeTab','perfil')">Meus Dados</button>
            <button class="pc-tab {{ $activeTab === 'contato' ? 'active' : '' }}" wire:click="$set('activeTab','contato')">Fale com a Administração</button>
        </div>

        <div style="display:grid;grid-template-columns:1fr 280px;gap:1.5rem;align-items:start" class="assoc-grid">

            {{-- ── Conteúdo principal ── --}}
            <div>

                @if($activeTab === 'pagamentos')

                    {{-- Pendentes --}}
                    @if($resumosPendentes->count() > 0)
                    <div class="pc-card" style="margin-bottom:1.25rem;border-color:#f5c6c2">
                        <div class="pc-card-header" style="background:var(--pc-red-lt)">
                            <span class="pc-card-title" style="color:var(--pc-red)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                Contribuições pendentes
                            </span>
                            <span class="pc-badge red">{{ $resumosPendentes->count() }}</span>
                        </div>
                        @foreach($resumosPendentes as $pendente)
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:0.875rem 1.25rem;border-bottom:1px solid var(--pc-border)" wire:key="p{{ $pendente->idt_resumo }}">
                            <div>
                                <div style="font-weight:600;color:var(--pc-text);font-size:0.9375rem">{{ $pendente->nom_mes }}/{{ $pendente->num_ano }}</div>
                                <div style="font-size:0.78rem;color:var(--pc-subtle);margin-top:0.1rem">Valor em aberto: R$ {{ number_format($pendente->val_total, 2, ',', '.') }}</div>
                            </div>
                            <button
                                class="pc-btn pc-btn-ghost pc-btn-sm"
                                wire:click="requestDischarge({{ $pendente->idt_resumo }})"
                                wire:confirm="Deseja solicitar a quitação deste débito para a administração?"
                                style="font-size:0.78rem"
                            >
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                Solicitar quitação
                            </button>
                        </div>
                        @endforeach
                        <div style="padding:0.875rem 1.25rem;font-size:0.78rem;color:var(--pc-subtle)">
                            Clique em "Solicitar quitação" para avisar a administração e regularizar sua situação.
                        </div>
                    </div>
                    @endif

                    {{-- Histórico --}}
                    <div class="pc-card">
                        <div class="pc-card-header">
                            <span class="pc-card-title">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--pc-green)"><polyline points="20 6 9 17 4 12"/></svg>
                                Histórico de contribuições pagas
                            </span>
                            <span style="font-size:0.78rem;color:var(--pc-subtle)">{{ $resumosRegularizados->count() }} registro(s)</span>
                        </div>
                        @if($resumosRegularizados->isEmpty())
                            <div class="pc-empty" style="padding:2.5rem 1.5rem">
                                <div class="pc-empty-title">Nenhum histórico encontrado</div>
                                <div class="pc-empty-desc">Contribuições pagas aparecerão aqui após a conciliação.</div>
                            </div>
                        @else
                        <div style="overflow-x:auto">
                            <table class="pc-table">
                                <thead>
                                    <tr>
                                        <th>Competência</th>
                                        <th>Valor pago</th>
                                        <th>Transações</th>
                                        <th>Situação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($resumosRegularizados as $pago)
                                    <tr wire:key="r{{ $pago->idt_resumo }}">
                                        <td class="cell-primary">{{ $pago->nom_mes }}/{{ $pago->num_ano }}</td>
                                        <td class="cell-mono" style="color:var(--pc-green);font-weight:600">R$ {{ number_format($pago->val_total, 2, ',', '.') }}</td>
                                        <td style="color:var(--pc-subtle)">{{ $pago->num_transacao }} depósito(s)</td>
                                        <td><span class="pc-badge green">Pago</span></td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </div>

                @endif

                @if($activeTab === 'perfil')
                <div class="pc-card">
                    <div class="pc-card-header">
                        <span class="pc-card-title">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            Atualizar dados cadastrais
                        </span>
                    </div>
                    <div class="pc-card-body">
                        <form wire:submit="updateProfile" style="display:flex;flex-direction:column;gap:0">

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
                                <div style="grid-column:1/-1">
                                    <flux:field>
                                        <flux:label required>Nome completo</flux:label>
                                        <flux:input wire:model="nom_membro" />
                                        <flux:error name="nom_membro" />
                                    </flux:field>
                                </div>
                                <div style="grid-column:1/-1">
                                    <flux:field>
                                        <flux:label>Nome no extrato bancário <span style="font-weight:400;color:var(--pc-subtle)">(se diferente do acima)</span></flux:label>
                                        <flux:input wire:model="nom_ofx" placeholder="Como aparece no OFX..." />
                                        <flux:error name="nom_ofx" />
                                    </flux:field>
                                </div>
                                <flux:field>
                                    <flux:label>Telefone / WhatsApp</flux:label>
                                    <flux:input wire:model="tel_membro" placeholder="(00) 00000-0000" />
                                    <flux:error name="tel_membro" />
                                </flux:field>
                                <flux:field>
                                    <flux:label>Telegram Chat ID</flux:label>
                                    <flux:input wire:model="des_telegram_chat_id" placeholder="Ex: 12345678" />
                                    <flux:error name="des_telegram_chat_id" />
                                </flux:field>
                                <div style="grid-column:1/-1">
                                    <flux:field>
                                        <flux:label>Logradouro</flux:label>
                                        <flux:input wire:model="end_logradouro" />
                                        <flux:error name="end_logradouro" />
                                    </flux:field>
                                </div>
                                <flux:field>
                                    <flux:label>Número</flux:label>
                                    <flux:input wire:model="end_mumero" />
                                    <flux:error name="end_mumero" />
                                </flux:field>
                                <flux:field>
                                    <flux:label>Bairro / Complemento</flux:label>
                                    <flux:input wire:model="end_complemento" />
                                    <flux:error name="end_complemento" />
                                </flux:field>
                            </div>

                            <hr class="pc-section-divider">

                            <div style="margin-bottom:1rem">
                                <div class="pc-label" style="margin-bottom:0.75rem">Preferências de notificação</div>
                                <div style="display:flex;gap:1.5rem;flex-wrap:wrap">
                                    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;font-weight:500;color:var(--pc-text);cursor:pointer">
                                        <input type="checkbox" wire:model="ind_notificar_whatsapp" style="accent-color:var(--pc-accent)"> WhatsApp
                                    </label>
                                    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;font-weight:500;color:var(--pc-text);cursor:pointer">
                                        <input type="checkbox" wire:model="ind_notificar_email" style="accent-color:var(--pc-accent)"> E-mail
                                    </label>
                                    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;font-weight:500;color:var(--pc-text);cursor:pointer">
                                        <input type="checkbox" wire:model="ind_notificar_telegram" style="accent-color:var(--pc-accent)"> Telegram
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="pc-btn pc-btn-primary" style="width:100%;justify-content:center">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                Salvar alterações
                            </button>
                        </form>
                    </div>
                </div>
                @endif

                @if($activeTab === 'contato')
                <div class="pc-card">
                    <div class="pc-card-header">
                        <span class="pc-card-title">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            Mensagem para a administração
                        </span>
                    </div>
                    <div class="pc-card-body">
                        <p style="font-size:0.8125rem;color:var(--pc-muted);margin-bottom:1.25rem;line-height:1.55">
                            Sua mensagem será enviada diretamente à diretoria via Telegram e registrada no painel administrativo.
                        </p>
                        <form wire:submit="submitContact" style="display:flex;flex-direction:column;gap:1rem">
                            <flux:input label="Nome" wire:model="contactName" disabled />
                            <flux:input label="E-mail" wire:model="contactEmail" disabled />
                            <flux:textarea label="Mensagem" wire:model="contactMessage" rows="5" placeholder="Escreva sua solicitação aqui..." />
                            <button type="submit" class="pc-btn pc-btn-primary" style="width:100%;justify-content:center">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                Enviar mensagem
                            </button>
                        </form>
                    </div>
                </div>
                @endif
            </div>

            {{-- ── Sidebar ── --}}
            <div style="display:flex;flex-direction:column;gap:1.25rem">

                {{-- Status financeiro --}}
                <div class="pc-card">
                    <div class="pc-card-header">
                        <span class="pc-card-title">Situação financeira</span>
                    </div>
                    <div class="pc-card-body">
                        @if($resumosPendentes->count() > 0)
                            <div style="display:flex;align-items:center;gap:0.875rem">
                                <div class="pc-stat-icon red" style="width:40px;height:40px;border-radius:9px">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                </div>
                                <div>
                                    <div style="font-size:0.7rem;color:var(--pc-subtle);font-weight:600;text-transform:uppercase;letter-spacing:0.08em">Status</div>
                                    <div style="font-size:1rem;font-weight:700;color:var(--pc-red)">Inadimplente</div>
                                    <div style="font-size:0.78rem;color:var(--pc-subtle)">{{ $resumosPendentes->count() }} pendência(s)</div>
                                </div>
                            </div>
                        @else
                            <div style="display:flex;align-items:center;gap:0.875rem">
                                <div class="pc-stat-icon green" style="width:40px;height:40px;border-radius:9px">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div>
                                    <div style="font-size:0.7rem;color:var(--pc-subtle);font-weight:600;text-transform:uppercase;letter-spacing:0.08em">Status</div>
                                    <div style="font-size:1rem;font-weight:700;color:var(--pc-green)">Regularizado</div>
                                    <div style="font-size:0.78rem;color:var(--pc-subtle)">Sem débitos pendentes</div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Perfil resumido --}}
                <div class="pc-card">
                    <div class="pc-card-header">
                        <span class="pc-card-title">Perfil</span>
                    </div>
                    <div class="pc-card-body" style="display:flex;flex-direction:column;gap:0.875rem">
                        <div>
                            <div style="font-size:0.7rem;color:var(--pc-subtle);font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.2rem">Tipo de associação</div>
                            <span class="pc-badge muted">{{ $membro->tip_associado->label() }}</span>
                        </div>
                        <div>
                            <div style="font-size:0.7rem;color:var(--pc-subtle);font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.2rem">Nome registrado</div>
                            <div style="font-size:0.875rem;font-weight:600;color:var(--pc-text)">{{ $membro->nom_membro }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.7rem;color:var(--pc-subtle);font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.2rem">E-mail</div>
                            <div style="font-size:0.8125rem;color:var(--pc-muted)">{{ $membro->eml_membro }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.7rem;color:var(--pc-subtle);font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.2rem">Membro desde</div>
                            <div style="font-size:0.8125rem;color:var(--pc-muted)">{{ $membro->created_at->format('d/m/Y') }}</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <style>
            @media(max-width:768px){
                .assoc-grid{ grid-template-columns:1fr !important; }
            }
        </style>

    @endif
</div>