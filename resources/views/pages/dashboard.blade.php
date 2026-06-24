<?php

use App\Models\Membro;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;

use Livewire\Volt\Component;
use Livewire\Attributes\Title;

new #[Title('Dashboard')] class extends Component {
    // Admin/Director properties
    public ?int $selectedImportId = null;

    // Member properties
    public string $activeTab = 'pagamentos';
    public string $nom_membro = '';
    public ?string $nom_ofx = null;
    public ?string $tel_membro = null;
    public ?string $end_logradouro = null;
    public ?string $end_mumero = null;
    public ?string $end_complemento = null;
    public ?string $des_telegram_chat_id = null;

    public string $contactName = '';
    public string $contactEmail = '';
    public string $contactMessage = '';
    public ?int $contactAssociacaoId = null;

    public function mount(): void
    {
        $user = auth()->user();
        if ($user->isMembro()) {
            $membro = $user->membro;
            if ($membro) {
                $this->nom_membro = $membro->nom_membro;
                $this->nom_ofx = $membro->nom_ofx;
                $this->tel_membro = $membro->tel_membro;
                $this->end_logradouro = $membro->end_logradouro;
                $this->end_mumero = $membro->end_mumero;
                $this->end_complemento = $membro->end_complemento;
                $this->des_telegram_chat_id = $membro->des_telegram_chat_id;

                $this->contactName = $membro->nom_membro;
                $this->contactEmail = $membro->eml_membro;
                $this->contactAssociacaoId = $membro->idt_associacao;
            } else {
                $this->contactName = $user->name;
                $this->contactEmail = $user->email;
            }
        } else {
            $importId = request()->query('import') ?? request()->query('ofx');
            if ($importId) {
                $this->selectedImportId = (int) $importId;
            } else {
                $latest = Ofx::withoutGlobalScope('associacao')->latest()->first();
                $this->selectedImportId = $latest ? $latest->idt_ofx : null;
            }
        }
    }

    public function updatedSelectedImportId(): void
    {
        $this->redirect(route('dashboard', ['import' => $this->selectedImportId]), navigate: true);
    }



    public function updateProfile(): void
    {
        $membro = auth()->user()->membro;
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
        ]);
        $membro->update([
            'nom_membro' => $this->nom_membro,
            'nom_ofx' => $this->nom_ofx ?: null,
            'tel_membro' => $this->tel_membro,
            'end_logradouro' => $this->end_logradouro,
            'end_mumero' => $this->end_mumero,
            'end_complemento' => $this->end_complemento,
            'des_telegram_chat_id' => $this->des_telegram_chat_id,
        ]);
        $this->dispatch('toast', message: 'Dados atualizados com sucesso.', variant: 'success');
    }

    public function submitContact(): void
    {
        $membro = auth()->user()->membro;
        $rules = [
            'contactName' => 'required|string|max:100',
            'contactEmail' => 'required|email|max:100',
            'contactMessage' => 'required|string|max:1000',
        ];
        if (!$membro) {
            $rules['contactAssociacaoId'] = 'required|exists:associacoes,idt_associacao';
        }
        $this->validate($rules);

        \App\Models\Contato::create([
            'nome' => $this->contactName,
            'email' => $this->contactEmail,
            'mensagem' => $this->contactMessage,
            'idt_associacao' => $membro ? $membro->idt_associacao : $this->contactAssociacaoId,
        ]);

        $botToken = config('services.telegram.bot_token', '');
        $chatId = config('services.telegram.contact_chat_id') ?: env('TELEGRAM_CONTACT_CHAT_ID', '');
        $enviouTelegram = false;
        if (filled($botToken) && filled($chatId)) {
            $texto = "📩 <b>Nova Mensagem de Contato</b>\n" .
                     "👤 <b>Nome:</b> {$this->contactName}\n" .
                     "✉️ <b>E-mail:</b> {$this->contactEmail}\n" .
                     "💬 <b>Mensagem:</b>\n{$this->contactMessage}";
            try {
                $response = \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $texto,
                    'parse_mode' => 'HTML'
                ]);
                $enviouTelegram = $response->successful();
            } catch (\Exception $e) {
                // ignore
            }
        }
        if ($enviouTelegram) {
            $this->dispatch('toast', message: 'Mensagem enviada com sucesso!', variant: 'success');
        } else {
            $this->dispatch('toast', message: 'Mensagem registrada. (Falha temporária ao enviar ao Telegram)', variant: 'warning');
        }
        $this->reset(['contactMessage']);
    }

    public function requestDischarge(int $resumoId): void
    {
        $membro = auth()->user()->membro;
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
        $user = auth()->user();

        if ($user->isMembro()) {
            $membro = $user->membro;
            $resumosPendentes = collect();
            $resumosRegularizados = collect();
            if ($membro) {
                $nomeMatching = $membro->nomeParaMatchingOfx();
                $todosResumos = Resumo::where('nom_pessoa', $nomeMatching)->orderByDesc('num_ano')->orderByDesc('num_mes')->get();
                $resumosPendentes = $todosResumos->where('ind_pago', false);
                $resumosRegularizados = $todosResumos->where('ind_pago', true);
            }
            return [
                'isMembroDashboard' => true,
                'membro' => $membro,
                'resumosPendentes' => $resumosPendentes,
                'resumosRegularizados' => $resumosRegularizados,
                'associacoes' => \App\Models\Associacao::orderBy('nom_associacao')->get(),
            ];
        }

        $isManager = $user->isAdmin() || $user->isDiretor();

        $importacoes = $isManager 
            ? Ofx::withoutGlobalScope('associacao')->latest()->get() 
            : Ofx::latest()->get();

        $importacaoSelecionada = null;
        if ($this->selectedImportId) {
            $importacaoSelecionada = $isManager
                ? Ofx::withoutGlobalScope('associacao')->with(['resumos' => fn($q) => $q->withoutGlobalScope('associacao')])->find($this->selectedImportId)
                : Ofx::with('resumos')->find($this->selectedImportId);
        } else {
            $importacaoSelecionada = $importacoes->first();
        }

        $dadosDashboard = [];
        $mesesDisponiveis = collect();
        $totalInadimplentes = 0;
        $totalAdimplentes = 0;
        $totalRecebido = 0;

        if ($importacaoSelecionada) {
            $resumos = $isManager
                ? $importacaoSelecionada->resumos()->withoutGlobalScope('associacao')
                    ->orderBy('num_ano')
                    ->orderBy('num_mes')
                    ->get()
                : $importacaoSelecionada->resumos()
                    ->orderBy('num_ano')
                    ->orderBy('num_mes')
                    ->get();

            $mesesDisponiveis = $resumos
                ->unique(fn ($r) => $r->num_ano.'-'.str_pad($r->num_mes, 2, '0', STR_PAD_LEFT))
                ->sortBy(['num_ano', 'num_mes'])
                ->values();

            $porPessoa = $resumos->groupBy('nom_pessoa');

            foreach ($porPessoa as $nomePessoa => $resumosPessoa) {
                $membro = $isManager
                    ? Membro::withoutGlobalScope('associacao')
                        ->where('nom_ofx', $nomePessoa)
                        ->orWhere('nom_membro', $nomePessoa)
                        ->first()
                    : Membro::where('nom_ofx', $nomePessoa)
                        ->orWhere('nom_membro', $nomePessoa)
                        ->first();

                $linha = [
                    'nome' => $nomePessoa,
                    'membro_id' => $membro?->idt_membro ?? null,
                    'is_member' => !is_null($membro),
                    'meses' => [],
                    'total' => 0,
                    'situacao' => 'Adimplente',
                ];

                foreach ($mesesDisponiveis as $mesRef) {
                    $resumoMes = $resumosPessoa->firstWhere(
                        fn ($r) => $r->num_ano == $mesRef->num_ano && $r->num_mes == $mesRef->num_mes
                    );

                    $valor = $resumoMes ? (float) $resumoMes->val_total : 0;
                    $linha['meses'][] = [
                        'value' => $valor,
                        'has_payment' => $valor > 0,
                    ];
                    $linha['total'] += $valor;

                    if ($valor <= 0) {
                        $linha['situacao'] = 'Inadimplente';
                    }
                }

                $totalRecebido += $linha['total'];
                if ($linha['situacao'] === 'Adimplente') {
                    $totalAdimplentes++;
                } else {
                    $totalInadimplentes++;
                }

                $dadosDashboard[] = $linha;
            }
        }

        $totalMembros = $isManager
            ? Membro::withoutGlobalScope('associacao')->count()
            : Membro::count();

        return [
            'isMembroDashboard' => false,
            'importacoes' => $importacoes,
            'importacaoSelecionada' => $importacaoSelecionada,
            'dadosDashboard' => $dadosDashboard,
            'mesesDisponiveis' => $mesesDisponiveis,
            'totalRecebido' => $totalRecebido,
            'totalAdimplentes' => $totalAdimplentes,
            'totalInadimplentes' => $totalInadimplentes,
            'totalMembros' => $totalMembros,
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

        @if($isMembroDashboard)
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
                                    <flux:select label="Associação" wire:model="contactAssociacaoId" placeholder="Selecione a associação..." required>
                                        @foreach($associacoes as $assoc)
                                            <flux:select.option value="{{ $assoc->idt_associacao }}">{{ $assoc->nom_associacao }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:textarea label="Mensagem para a administração" wire:model="contactMessage" rows="4" placeholder="Olá, gostaria de solicitar a vinculação do meu e-mail à minha conta de associado..." />
                                    <button type="submit" class="pc-btn pc-btn-primary" style="width:100%;justify-content:center">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                        Enviar solicitação
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                @elseif(!$membro->ind_aprovado)
                    {{-- ── Vinculação pendente de aprovação ── --}}
                    <div class="pc-page-header">
                        <div>
                            <div class="pc-label" style="margin-bottom:0.4rem">Associação</div>
                            <h1 class="pc-page-title">Minha Associação</h1>
                        </div>
                    </div>

                    <div style="max-width:560px;margin:0 auto">
                        <div class="pc-alert warning" style="margin-bottom:1.5rem">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <div>
                                <strong>Aprovação Pendente</strong><br>
                                <span style="font-size:0.8125rem">Sua vinculação à associação <strong>{{ $membro->associacao?->nom_associacao }}</strong> está aguardando aprovação de um diretor ou administrador. Por favor, aguarde a liberação do seu cadastro.</span>
                            </div>
                        </div>
                    </div>

                @else
                    {{-- ── Associado encontrado ── --}}
                    <div class="pc-page-header">
                        <div style="display:flex;align-items:center;gap:1rem">
                            <div>
                                <div class="pc-label" style="margin-bottom:0.2rem">Associação</div>
                                <h1 class="pc-page-title" style="font-size:1.4rem">{{ $membro->associacao?->nom_associacao }}</h1>
                                <p class="pc-page-subtitle">Membro desde {{ $membro->created_at->format('M/Y') }}</p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.5rem">
                            @if($resumosPendentes->count() > 0)
                                <span class="pc-badge red">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                                    {{ $resumosPendentes->count() }} pendência(s)
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- Tabs --}}
                    <div class="pc-tabs">
                        <button class="pc-tab {{ $activeTab === 'pagamentos' ? 'active' : '' }}" wire:click="$set('activeTab','pagamentos')">Pagamentos</button>
                        <button class="pc-tab {{ $activeTab === 'perfil' ? 'active' : '' }}" wire:click="$set('activeTab','perfil')">Meus Dados</button>
                        <button class="pc-tab {{ $activeTab === 'contato' ? 'active' : '' }}" wire:click="$set('activeTab','contato')">Fale com a Associação</button>
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
                                        Mensagem para a associação
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
                                                <div style="font-size:1rem;font-weight:700;color:var(--pc-red)">Pendente de pagamento</div>
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
        @else
            @if($importacaoSelecionada)
            <!-- Seletor de Importação -->
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                        <flux:icon name="building-library" class="size-5" />
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Extrato Selecionado</p>
                        <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">{{ $importacaoSelecionada->des_arquivo }}</p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">
                            {{ $importacaoSelecionada->dat_inicio?->format('d/m/Y') }} – {{ $importacaoSelecionada->dat_fim?->format('d/m/Y') }} • {{ $importacaoSelecionada->qtd_transacao }} transações
                        </p>
                    </div>
                </div>
                @if($importacoes->count() > 0)
                <div class="flex items-center gap-2">
                    <flux:select wire:model.live="selectedImportId" class="w-64">
                        @foreach($importacoes as $imp)
                            <flux:select.option value="{{ $imp->idt_ofx }}">
                                {{ $imp->des_arquivo }} ({{ $imp->dat_inicio?->format('m/Y') }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button href="{{ route('upload') }}" icon="plus" size="sm" variant="primary">Novo</flux:button>
                </div>
                @endif
            </div>

            <!-- Cards de Estatísticas -->
            <div class="grid auto-rows-min gap-4 md:grid-cols-4">
                <flux:card class="flex items-center justify-between p-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Total Recebido</p>
                        <p class="mt-1 text-2xl font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($totalRecebido,2,',','.') }}</p>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30">
                        <flux:icon name="banknotes" class="size-5" />
                    </div>
                </flux:card>
                <flux:card class="flex items-center justify-between p-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Adimplentes</p>
                        <p class="mt-1 text-2xl font-bold text-green-600">{{ $totalAdimplentes }}</p>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30">
                        <flux:icon name="check-circle" class="size-5" />
                    </div>
                </flux:card>
                <flux:card class="flex items-center justify-between p-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Inadimplentes</p>
                        <p class="mt-1 text-2xl font-bold text-red-600">{{ $totalInadimplentes }}</p>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-900/30">
                        <flux:icon name="exclamation-triangle" class="size-5" />
                    </div>
                </flux:card>
                <flux:card class="flex items-center justify-between p-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Membros</p>
                        <p class="mt-1 text-2xl font-bold text-blue-600">{{ $totalMembros }}</p>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                        <flux:icon name="users" class="size-5" />
                    </div>
                </flux:card>
            </div>

            <!-- Seções Principais -->
            <div class="space-y-6">
                {{-- Tabela de Acompanhamento Mensal --}}
                <flux:card class="p-0 overflow-hidden">
                    <div class="flex items-center justify-between border-b border-neutral-100 px-5 py-4 dark:border-neutral-800 bg-neutral-50/50 dark:bg-zinc-800/20">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300 flex items-center gap-2">
                            <flux:icon name="table-cells" class="size-4 text-blue-600" /> Acompanhamento Mensal por Pagador
                        </h3>
                        <span class="text-xs text-neutral-500">{{ count($dadosDashboard) }} pagadores</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-neutral-50 text-xs uppercase tracking-wider text-neutral-600 dark:bg-zinc-800/50 dark:text-neutral-400">
                                    <th class="px-4 py-3 text-left font-bold">Pagador (MEMO)</th>
                                    @foreach($mesesDisponiveis as $month)
                                        <th class="px-3 py-3 text-center font-bold">{{ $month->nom_mes }}<span class="block text-[10px] font-normal opacity-60">{{ $month->num_ano }}</span></th>
                                    @endforeach
                                    <th class="px-4 py-3 text-right font-bold">Total</th>
                                    <th class="px-4 py-3 text-center font-bold">Situação</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                @forelse($dadosDashboard as $row)
                                    <tr class="transition hover:bg-neutral-50/50 dark:hover:bg-zinc-800/30 {{ $row['situacao']==='Inadimplente' ? 'bg-red-50/10 dark:bg-red-950/5' : '' }}">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-2">
                                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-neutral-200 text-xs font-bold text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">
                                                    {{ strtoupper(substr($row['nome'],0,1)) }}
                                                </div>
                                                <div>
                                                    <p class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $row['nome'] }}</p>
                                                    @if($row['is_member'])
                                                        <span class="inline-block rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Membro</span>
                                                    @else
                                                        <span class="inline-block rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-zinc-800 dark:text-neutral-400">Não cadastrado</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        @foreach($row['meses'] as $m)
                                            <td class="px-3 py-3 text-center">
                                                @if($m['has_payment'])
                                                    <span class="inline-block rounded bg-green-50 px-2 py-1 text-xs font-semibold text-green-700 dark:bg-green-900/20 dark:text-green-400">R$ {{ number_format($m['value'],0,',','.') }}</span>
                                                @else
                                                    <span class="text-xs font-bold text-neutral-300 dark:text-neutral-600">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="px-4 py-3 text-right font-bold text-neutral-700 dark:text-neutral-300">R$ {{ number_format($row['total'],2,',','.') }}</td>
                                        <td class="px-4 py-3 text-center">
                                            @if($row['situacao'] === 'Adimplente')
                                                <flux:badge color="green" size="sm" icon="check">Adimplente</flux:badge>
                                            @else
                                                <flux:badge color="red" size="sm" icon="x-mark">Inadimplente</flux:badge>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($mesesDisponiveis)+3 }}" class="px-4 py-8 text-center text-neutral-400">
                                            <flux:icon name="inbox" class="mx-auto mb-2 size-8 opacity-40" />
                                            Nenhum dado de pagamento encontrado neste extrato.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </flux:card>                
            </div>

            @else
            <!-- Estado vazio -->
            <div class="flex flex-1 flex-col items-center justify-center rounded-xl border border-neutral-200 bg-white p-12 text-center dark:border-neutral-700 dark:bg-zinc-900">
                <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/20">
                    <flux:icon name="arrow-up-tray" class="size-8 text-blue-600" />
                </div>
                <h3 class="text-lg font-bold text-neutral-800 dark:text-neutral-100">Nenhum extrato OFX importado</h3>
                <p class="mx-auto mb-6 mt-2 max-w-md text-sm text-neutral-500 dark:text-neutral-400">
                    Importe seu primeiro extrato do Banco do Brasil para visualizar o acompanhamento mensal, identificar inadimplentes e disparar notificações.
                </p>
                <flux:button href="{{ route('upload') }}" variant="primary" icon="arrow-up-tray">Importar Extrato OFX</flux:button>
            </div>
            @endif
        @endif
    </div>
