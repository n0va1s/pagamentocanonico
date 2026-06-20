<?php

use App\Models\Membro;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;
use App\Models\Notificacao;
use App\Enums\TipoNotificacao;
use App\Enums\Canal;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use App\Services\Notifications\Channels\EmailChannel;
use Livewire\Volt\Component;
use Livewire\Attributes\Title;

new #[Title('Dashboard')] class extends Component {
    public ?int $selectedImportId = null;

    public function mount(): void
    {
        $importId = request()->query('import') ?? request()->query('ofx');
        if ($importId) {
            $this->selectedImportId = (int) $importId;
        } else {
            $latest = Ofx::latest()->first();
            $this->selectedImportId = $latest ? $latest->idt_ofx : null;
        }
    }

    public function updatedSelectedImportId(): void
    {
        $this->redirect(route('dashboard', ['import' => $this->selectedImportId]), navigate: true);
    }

    public function alertMember(int $membroId, string $channel): void
    {
        $membro = Membro::findOrFail($membroId);
        $selectedImport = Ofx::find($this->selectedImportId);
        if (!$selectedImport) { $this->dispatch('toast', message: 'Nenhuma importação selecionada.', variant: 'danger'); return; }
        $atrasado = Resumo::where('idt_ofx', $this->selectedImportId)->where('nom_pessoa', $membro->nomeParaMatchingOfx())->where('ind_pago', false)->orderByDesc('num_ano')->orderByDesc('num_mes')->first();
        $mesRef = $atrasado ? "{$atrasado->nom_mes}/{$atrasado->num_ano}" : 'mês atual';
        $valorRef = $atrasado ? $atrasado->val_total : 0;
        $mensagem = app(\App\Services\Notifications\Messages\MensagemBuilder::class)->construir(TipoNotificacao::INADIMPLENTE, $membro, ['mes' => $mesRef, 'valor' => $valorRef]);
        if ($channel === 'whatsapp' || $channel === 'all') {
            $wa = app(WhatsAppChannel::class);
            $res = $wa->send($membro, $mensagem, TipoNotificacao::INADIMPLENTE);
            if ($res['success'] && isset($res['redirect_url'])) { $this->dispatch('open-wa-link', url: $res['redirect_url']); }
        }
        if ($channel === 'email' || $channel === 'all') { app(EmailChannel::class)->send($membro, $mensagem, TipoNotificacao::INADIMPLENTE); }
        if ($channel === 'telegram' || $channel === 'all') { app(TelegramChannel::class)->send($membro, $mensagem, TipoNotificacao::INADIMPLENTE); }
        $this->dispatch('toast', message: 'Alerta disparado com sucesso.', variant: 'success');
    }

    public function with(): array
    {
        $importacoes = Ofx::latest()->get();
        $importacaoSelecionada = $this->selectedImportId ? Ofx::with('resumos')->find($this->selectedImportId) : $importacoes->first();
        $dadosDashboard = [];
        $mesesDisponiveis = collect();
        $totalInadimplentes = 0;
        $totalAdimplentes = 0;
        $totalRecebido = 0;
        if ($importacaoSelecionada) {
            $resumos = $importacaoSelecionada->resumos()->orderBy('num_ano')->orderBy('num_mes')->get();
            $mesesDisponiveis = $resumos->unique(fn ($r) => $r->num_ano.'-'.str_pad($r->num_mes, 2, '0', STR_PAD_LEFT))->sortBy(['num_ano', 'num_mes'])->values();
            $porPessoa = $resumos->groupBy('nom_pessoa');
            foreach ($porPessoa as $nomePessoa => $resumosPessoa) {
                $membro = Membro::where('nom_ofx', $nomePessoa)->orWhere('nom_membro', $nomePessoa)->first();
                $linha = ['nome' => $nomePessoa, 'membro_id' => $membro?->idt_membro ?? null, 'is_member' => !is_null($membro), 'meses' => [], 'total' => 0, 'situacao' => 'Adimplente'];
                foreach ($mesesDisponiveis as $mesRef) {
                    $resumoMes = $resumosPessoa->firstWhere(fn ($r) => $r->num_ano == $mesRef->num_ano && $r->num_mes == $mesRef->num_mes);
                    $valor = $resumoMes ? (float) $resumoMes->val_total : 0;
                    $linha['meses'][] = ['value' => $valor, 'has_payment' => $valor > 0];
                    $linha['total'] += $valor;
                    if ($valor <= 0) $linha['situacao'] = 'Inadimplente';
                }
                $totalRecebido += $linha['total'];
                $linha['situacao'] === 'Adimplente' ? $totalAdimplentes++ : $totalInadimplentes++;
                $dadosDashboard[] = $linha;
            }
        }
        return compact('importacoes','importacaoSelecionada','dadosDashboard','mesesDisponiveis','totalRecebido','totalAdimplentes','totalInadimplentes');
    }
}; ?>

<div class="pc-page" x-data="{}" x-on:open-wa-link.window="window.open($event.detail.url, '_blank')">

    {{-- Cabeçalho --}}
    <div class="pc-page-header">
        <div>
            <div class="pc-label" style="margin-bottom:0.4rem">Administração</div>
            <h1 class="pc-page-title">Dashboard</h1>
            <p class="pc-page-subtitle">Acompanhamento de contribuições por extrato importado.</p>
        </div>
        <a href="{{ route('upload') }}" class="pc-btn pc-btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Importar OFX
        </a>
    </div>

    @if($importacaoSelecionada)

        {{-- Seletor de importação --}}
        <div class="pc-import-bar">
            <div class="pc-import-bar-info">
                <div class="pc-import-bar-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </div>
                <div>
                    <div class="pc-import-bar-name">{{ $importacaoSelecionada->des_arquivo }}</div>
                    <div class="pc-import-bar-meta">
                        {{ $importacaoSelecionada->dat_inicio?->format('d/m/Y') }} – {{ $importacaoSelecionada->dat_fim?->format('d/m/Y') }}
                        · {{ $importacaoSelecionada->qtd_transacao }} transações
                    </div>
                </div>
            </div>
            <div class="pc-import-bar-actions">
                @if($importacoes->count() > 0)
                    <flux:select wire:model.live="selectedImportId" style="min-width:220px">
                        @foreach($importacoes as $imp)
                            <flux:select.option value="{{ $imp->idt_ofx }}">
                                {{ $imp->des_arquivo }} ({{ $imp->dat_inicio?->format('m/Y') }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
        </div>

        {{-- Stats --}}
        <div class="pc-stats">
            <div class="pc-stat">
                <div>
                    <div class="pc-stat-label">Total recebido</div>
                    <div class="pc-stat-value" style="font-size:1.3rem">R$ {{ number_format($totalRecebido,2,',','.') }}</div>
                </div>
                <div class="pc-stat-icon green">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                </div>
            </div>
            <div class="pc-stat">
                <div>
                    <div class="pc-stat-label">Adimplentes</div>
                    <div class="pc-stat-value green">{{ $totalAdimplentes }}</div>
                </div>
                <div class="pc-stat-icon green">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
            </div>
            <div class="pc-stat">
                <div>
                    <div class="pc-stat-label">Inadimplentes</div>
                    <div class="pc-stat-value red">{{ $totalInadimplentes }}</div>
                </div>
                <div class="pc-stat-icon red">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                </div>
            </div>
            <div class="pc-stat">
                <div>
                    <div class="pc-stat-label">Total de membros</div>
                    <div class="pc-stat-value blue">{{ App\Models\Membro::count() }}</div>
                </div>
                <div class="pc-stat-icon blue">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
            </div>
        </div>

        {{-- Tabela mensal --}}
        <div class="pc-card">
            <div class="pc-card-header">
                <span class="pc-card-title">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="9" x2="9" y2="21"/></svg>
                    Acompanhamento mensal por pagador
                </span>
                <span style="font-size:0.78rem;color:var(--pc-subtle)">{{ count($dadosDashboard) }} pagadores</span>
            </div>
            <div style="overflow-x:auto">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th>Pagador</th>
                            @foreach($mesesDisponiveis as $month)
                                <th style="text-align:center">{{ $month->nom_mes }}<span style="display:block;font-size:0.65rem;font-weight:400;opacity:0.65;letter-spacing:0">{{ $month->num_ano }}</span></th>
                            @endforeach
                            <th style="text-align:right">Total</th>
                            <th style="text-align:center">Situação</th>
                            <th style="text-align:center">Alertar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($dadosDashboard as $row)
                        <tr wire:key="row-{{ $loop->index }}" style="{{ $row['situacao']==='Inadimplente' ? 'background:#fffaf8' : '' }}">
                            <td>
                                <div style="display:flex;align-items:center;gap:0.625rem">
                                    <div class="pc-avatar">{{ strtoupper(substr($row['nome'],0,1)) }}</div>
                                    <div>
                                        <div style="font-weight:600;color:var(--pc-text);font-size:0.875rem">{{ $row['nome'] }}</div>
                                        @if($row['is_member'])
                                            <span class="pc-badge blue" style="font-size:0.6rem;padding:0.1rem 0.4rem">Membro</span>
                                        @else
                                            <span class="pc-badge muted" style="font-size:0.6rem;padding:0.1rem 0.4rem">Não cadastrado</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            @foreach($row['meses'] as $m)
                            <td style="text-align:center">
                                @if($m['has_payment'])
                                    <span style="display:inline-block;background:var(--pc-green-lt);color:var(--pc-green);font-size:0.75rem;font-weight:600;padding:0.2rem 0.5rem;border-radius:4px;white-space:nowrap">
                                        R$&nbsp;{{ number_format($m['value'],0,',','.') }}
                                    </span>
                                @else
                                    <span style="color:var(--pc-border);font-weight:700;font-size:0.9rem">—</span>
                                @endif
                            </td>
                            @endforeach
                            <td style="text-align:right;font-weight:700;color:var(--pc-text);font-variant-numeric:tabular-nums;white-space:nowrap">
                                R$ {{ number_format($row['total'],2,',','.') }}
                            </td>
                            <td style="text-align:center">
                                @if($row['situacao'] === 'Adimplente')
                                    <span class="pc-badge green">Em dia</span>
                                @else
                                    <span class="pc-badge red">Pendente</span>
                                @endif
                            </td>
                            <td style="text-align:center">
                                @if($row['is_member'] && $row['situacao'] === 'Inadimplente')
                                <div style="display:flex;justify-content:center;gap:0.375rem">
                                    <button class="pc-btn pc-btn-ghost pc-btn-sm" style="padding:0.3rem 0.5rem" wire:click="alertMember({{ $row['membro_id'] }}, 'whatsapp')" title="Enviar via WhatsApp">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.43 2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                    </button>
                                    <button class="pc-btn pc-btn-ghost pc-btn-sm" style="padding:0.3rem 0.5rem" wire:click="alertMember({{ $row['membro_id'] }}, 'email')" title="Enviar por e-mail">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    </button>
                                </div>
                                @else
                                    <span style="color:var(--pc-border);font-size:0.875rem">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="{{ count($mesesDisponiveis) + 4 }}" style="padding:4rem 1rem">
                                <div class="pc-empty" style="padding:0">
                                    <div class="pc-empty-icon" style="margin-bottom:0.75rem">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>
                                    </div>
                                    <div class="pc-empty-title">Nenhum dado encontrado</div>
                                    <div class="pc-empty-desc">Nenhum pagamento registrado neste extrato.</div>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    @else
        {{-- Estado vazio --}}
        <div class="pc-card" style="padding:5rem 2rem">
            <div class="pc-empty">
                <div class="pc-empty-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </div>
                <div class="pc-empty-title">Nenhum extrato importado</div>
                <div class="pc-empty-desc" style="max-width:38ch;margin:0 auto 1.5rem">Importe o primeiro arquivo OFX para visualizar o acompanhamento mensal e identificar inadimplentes.</div>
                <a href="{{ route('upload') }}" class="pc-btn pc-btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Importar extrato OFX
                </a>
            </div>
        </div>
    @endif
</div>