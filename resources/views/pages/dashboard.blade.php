<?php

use App\Models\Membro;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;

use Livewire\Volt\Component;
use Livewire\Attributes\Title;

new #[Title('Dashboard')] class extends Component {
    public ?int $selectedImportId = null;
    public $selectedAssociacaoId = null;
    public ?int $selectedYear = null;
    public ?int $selectedMonth = null;

    // Member properties
    public string $activeTab = 'pagamentos';
    public string $nom_membro = '';
    public ?string $nom_apelido = null;
    public ?string $tel_membro = null;
    public ?string $end_logradouro = null;
    public ?string $end_numero = null;
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
                $this->nom_apelido = $membro->nom_apelido;
                $this->tel_membro = $membro->tel_membro;
                $this->end_logradouro = $membro->end_logradouro;
                $this->end_numero = $membro->end_numero;
                $this->end_complemento = $membro->end_complemento;
                $this->des_telegram_chat_id = $membro->des_telegram_chat_id;

                $this->contactName = $membro->nom_membro;
                $this->contactEmail = $membro->eml_membro;
                $this->contactAssociacaoId = $membro->idt_associacao;
            } else {
                $this->contactName = $user->name;
                $this->contactEmail = $user->email;
                $this->contactAssociacaoId = $user->membro?->idt_associacao;
            }
        } else {
            $importId = request()->query('import') ?? request()->query('ofx');
            if ($importId) {
                $this->selectedImportId = (int) $importId;
            } else {
                $latest = Ofx::withoutGlobalScope('associacao')->latest()->first();
                $this->selectedImportId = $latest ? $latest->idt_ofx : null;
            }
            
            if (!$user->isAdmin()) {
                $this->selectedAssociacaoId = $user->membro?->idt_associacao;
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
            \Flux::toast(variant: 'danger', text: 'Membro não cadastrado.');
            return;
        }
        $this->validate([
            'nom_membro' => 'required|string|max:255',
            'nom_apelido' => 'nullable|string|max:100',
            'tel_membro' => 'nullable|string|max:20',
            'end_logradouro' => 'nullable|string|max:150',
            'end_numero' => 'nullable|string|max:20',
            'end_complemento' => 'nullable|string|max:150',
            'des_telegram_chat_id' => 'nullable|string|max:50',
        ]);
        $membro->update([
            'nom_membro' => $this->nom_membro,
            'nom_apelido' => $this->nom_apelido ?: null,
            'tel_membro' => $this->tel_membro,
            'end_logradouro' => $this->end_logradouro,
            'end_numero' => $this->end_numero,
            'end_complemento' => $this->end_complemento,
            'des_telegram_chat_id' => $this->des_telegram_chat_id,
        ]);
        \Flux::toast(variant: 'success', text: __('messages.alerts.success.saved'));
    }

    public function submitContact(): void
    {
        $membro = auth()->user()->membro;
        $this->contactAssociacaoId = auth()->user()->membro?->idt_associacao;
        $rules = [
            'contactName' => 'required|string|max:100',
            'contactEmail' => 'required|email|max:100',
            'contactMessage' => 'required|string|max:1000',
        ];
        if (!$membro) {
            $rules['contactAssociacaoId'] = 'nullable';
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
            \Flux::toast(variant: 'success', text: 'Mensagem enviada com sucesso!');
        } else {
            \Flux::toast(variant: 'warning', text: 'Mensagem registrada. (Falha temporária ao enviar ao Telegram)');
        }
        $this->reset(['contactMessage']);
    }

    public function atualizarPagamentos(): void
    {
        $user = auth()->user();
        if (!$user->isAdmin() && !$user->isDiretor()) {
            \Flux::toast(variant: 'danger', text: 'Sem permissão.');
            return;
        }

        $associacaoId = $this->selectedAssociacaoId;
        $membrosQuery = \App\Models\Membro::query();
        if ($associacaoId) {
            $membrosQuery->where('idt_associacao', $associacaoId);
        }
        $membros = $membrosQuery->get();

        $totalResumosAfetados = 0;
        $totalTransacoesAfetadas = 0;

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            foreach ($membros as $membro) {
                $membroCpf = $membro->num_cpf_membro ? preg_replace('/\D/', '', $membro->num_cpf_membro) : null;
                
                if (!$membroCpf) {
                    continue; // Pula membros sem CPF, já que a busca é estrita
                }

                $transacoesQuery = \App\Models\Transacao::withoutGlobalScope('associacao')
                    ->whereNull('idt_membro')
                    ->when($membro->idt_associacao, function ($q) use ($membro) {
                        $q->whereHas('ofx', function ($ofxQ) use ($membro) {
                            $ofxQ->withoutGlobalScope('associacao')->where('idt_associacao', $membro->idt_associacao);
                        });
                    })
                    ->where(function ($query) use ($membroCpf) {
                        $query->whereRaw("REPLACE(REPLACE(num_cpf_pagador, '.', ''), '-', '') = ?", [$membroCpf]);
                        $query->orWhereRaw("REPLACE(REPLACE(num_cpf_pagador, '.', ''), '-', '') LIKE ?", ['%'.$membroCpf]);
                    });

                $transacoes = $transacoesQuery->get();
                $afetadosTransacao = $transacoesQuery->update([
                    'idt_membro' => $membro->idt_membro,
                ]);

                $totalTransacoesAfetadas += $afetadosTransacao;

                $idtResumos = $transacoes->pluck('idt_resumo')->filter()->unique();
                if ($idtResumos->isNotEmpty()) {
                    $afetadosResumo = \App\Models\Resumo::withoutGlobalScope('associacao')
                        ->whereIn('idt_resumo', $idtResumos)
                        ->update(['idt_membro' => $membro->idt_membro]);
                    
                    $totalResumosAfetados += $afetadosResumo;
                }
            }

            \Illuminate\Support\Facades\DB::commit();
            \Flux::toast(variant: 'success', text: "Atualização concluída: {$totalTransacoesAfetadas} transações vinculadas.");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Flux::toast(variant: 'danger', text: "Erro ao atualizar pagamentos: " . $e->getMessage());
        }
    }

    public function with(): array
    {
        $user = auth()->user();

        if ($user->isMembro()) {
            $membro = $user->membro;
            $resumosPendentes = collect();
            $resumosRegularizados = collect();
            if ($membro) {
                $todosResumos = Resumo::where('idt_membro', $membro->idt_membro)
                ->orderByDesc('num_ano')
                ->orderByDesc('num_mes')
                ->get();

                $resumosPendentes = $todosResumos->where('ind_pago', false);
                $resumosRegularizados = $todosResumos->where('ind_pago', true);
            }
            return [
                'isMembroDashboard' => true,
                'membro' => $membro,
                'todosResumos' => $todosResumos ?? collect(),
                'resumosPendentes' => $resumosPendentes,
                'resumosRegularizados' => $resumosRegularizados,
                'associacoes' => \App\Models\Associacao::orderBy('nom_associacao')->get(),
            ];
        }

        $isAdmin = $user->isAdmin();
        $isManager = $isAdmin || $user->isDiretor();
        $isAdminDashboard = $isAdmin && !$user->isDiretor();

        if (!$isAdmin) {
            $this->selectedAssociacaoId = $user->membro?->idt_associacao;
        }

        // Anos disponíveis baseados em transações
        $yearsQuery = Transacao::query();
        if ($this->selectedAssociacaoId) {
            $yearsQuery->whereHas('ofx', function($q) {
                $q->withoutGlobalScope('associacao')->where('idt_associacao', $this->selectedAssociacaoId);
            });
        } else {
            $yearsQuery->whereHas('ofx', function($q) {
                $q->withoutGlobalScope('associacao');
            });
        }

        $years = $yearsQuery->selectRaw('YEAR(dat_transacao) as year')
            ->distinct()
            ->whereNotNull('dat_transacao')
            ->pluck('year')
            ->map(fn($y) => (int) $y)
            ->sortDesc()
            ->values();

        if ($years->isEmpty()) {
            $years = collect([(int) date('Y')]);
        }

        if (!$this->selectedYear || !$years->contains($this->selectedYear)) {
            $this->selectedYear = $years->first();
        }
        $selectedYear = $this->selectedYear;

        // Meses disponíveis com transações no ano selecionado
        $monthsQuery = Transacao::query();
        if ($this->selectedAssociacaoId) {
            $monthsQuery->whereHas('ofx', function($q) {
                $q->withoutGlobalScope('associacao')->where('idt_associacao', $this->selectedAssociacaoId);
            });
        } else {
            $monthsQuery->whereHas('ofx', function($q) {
                $q->withoutGlobalScope('associacao');
            });
        }
        $availableMonths = $monthsQuery->whereYear('dat_transacao', $selectedYear)
            ->selectRaw('MONTH(dat_transacao) as month')
            ->distinct()
            ->whereNotNull('dat_transacao')
            ->pluck('month')
            ->map(fn($m) => (int) $m)
            ->sort()
            ->values();

        if ($this->selectedMonth && !$availableMonths->contains($this->selectedMonth)) {
            $this->selectedMonth = null;
        }
        $selectedMonth = $this->selectedMonth;

        // 1. Transações para Total Recebido, Total Pago (Despesas) e Não Identificado
        $transacoesQuery = Transacao::query();
        if ($this->selectedAssociacaoId) {
            $transacoesQuery->whereHas('ofx', function($q) {
                $q->withoutGlobalScope('associacao')->where('idt_associacao', $this->selectedAssociacaoId);
            });
        } else {
            $transacoesQuery->whereHas('ofx', function($q) {
                $q->withoutGlobalScope('associacao');
            });
        }
        $transacoesQuery->whereYear('dat_transacao', $selectedYear);
        if ($selectedMonth) {
            $transacoesQuery->whereMonth('dat_transacao', $selectedMonth);
        }

        $cloneCreditos = clone $transacoesQuery;
        $cloneDebitos = clone $transacoesQuery;
        $cloneNaoIdentificados = clone $transacoesQuery;

        $totalRecebido = (float) $cloneCreditos->where('tip_transacao', 'CREDIT')->sum('val_transacao');
        $totalPagoDespesas = abs((float) $cloneDebitos->where('tip_transacao', 'DEBIT')->sum('val_transacao'));
        $totalNaoIdentificado = (float) $cloneNaoIdentificados->where('tip_transacao', 'CREDIT')->whereNull('idt_membro')->sum('val_transacao');

        // 2. Membros e Adimplência / Inadimplência no Ano
        $membrosQuery = Membro::withoutGlobalScope('associacao')->with('associacao')->where('ind_aprovado', true);
        if ($this->selectedAssociacaoId) {
            $membrosQuery->where('idt_associacao', $this->selectedAssociacaoId);
        }
        $membrosAtivos = $membrosQuery->get();
        $totalMembrosAtivos = $membrosAtivos->count();

        $resumosAno = Resumo::withoutGlobalScope('associacao')->where('num_ano', $selectedYear);
        if ($this->selectedAssociacaoId) {
            $resumosAno->whereHas('ofx', function($q) {
                $q->withoutGlobalScope('associacao')->where('idt_associacao', $this->selectedAssociacaoId);
            });
        }
        $resumosAnoCollection = $resumosAno->get();
        $pagosPorMembro = $resumosAnoCollection->where('ind_pago', true)->groupBy('idt_membro');

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');
        $isCurrentYear = $selectedYear === $currentYear;
        $monthsToConsider = $isCurrentYear ? $currentMonth : 12;

        $totalAdimplentes = 0;
        $totalInadimplentes = 0;
        $totalIsentos = 0;

        foreach ($membrosAtivos as $membro) {
            $isHonorario = $membro->tip_associado === \App\Enums\Perfil::HONORARIO || $membro->tip_associado?->value === 'honorario';
            if ($isHonorario) {
                $totalIsentos++;
                continue;
            }

            $taxaVigente = $membro->associacao?->getTaxaVigenteEm();
            $valTaxa = (float) ($taxaVigente?->val_taxa ?? 0);
            $valAnual = (float) ($taxaVigente?->val_anual ?? 0);
            
            $pagos = $pagosPorMembro->get($membro->idt_membro, collect());
            $totalPagoAno = (float) $pagos->sum('val_total');
            
            if ($valAnual > 0 && $totalPagoAno >= $valAnual) {
                $totalAdimplentes++;
                continue;
            }

            $dataAdesao = $membro->dat_adesao ? \Carbon\Carbon::parse($membro->dat_adesao) : $membro->created_at;
            $dataInicioAssoc = $membro->associacao?->dat_inicio_cobranca 
                ? \Carbon\Carbon::parse($membro->associacao->dat_inicio_cobranca) 
                : \Carbon\Carbon::create(2025, 1, 1);

            $dataInicioObrigacao = ($dataAdesao && $dataAdesao->greaterThan($dataInicioAssoc)) 
                ? $dataAdesao 
                : $dataInicioAssoc;

            $startYear = (int) $dataInicioObrigacao->format('Y');
            $startMonth = (int) $dataInicioObrigacao->format('n');

            $mesesDevidos = 0;
            if ($selectedYear >= $startYear) {
                for ($m = 1; $m <= $monthsToConsider; $m++) {
                    if ($startYear < $selectedYear || ($startYear === $selectedYear && $startMonth <= $m)) {
                        $mesesDevidos++;
                    }
                }
            }

            $valorEsperado = $mesesDevidos * $valTaxa;

            if ($valTaxa > 0) {
                if ($totalPagoAno >= $valorEsperado) {
                    $totalAdimplentes++;
                } else {
                    $totalInadimplentes++;
                }
            } else {
                $totalPagoAno > 0 ? $totalAdimplentes++ : $totalInadimplentes++;
            }
        }

        // 3. Tabela de Acompanhamento Mensal
        $dadosDashboard = [];
        $mesesDisponiveis = collect();
        $totaisPorMes = [];

        $nomesMeses = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
        ];

        for ($m = 1; $m <= 12; $m++) {
            $mesesDisponiveis->push((object)[
                'num_ano' => $selectedYear,
                'num_mes' => $m,
                'nom_mes' => substr($nomesMeses[$m], 0, 3)
            ]);
            $totaisPorMes[$selectedYear . '-' . $m] = ['total' => 0, 'identificado' => 0];
        }

        // Transações Avulsas / Não Identificadas
        $resumosAvulsos = $resumosAnoCollection->whereNull('idt_membro');
        if ($resumosAvulsos->isNotEmpty()) {
            $linha = [
                'nome' => 'Transações Avulsas / Não Identificadas',
                'membro_id' => null,
                'is_member' => false,
                'meses' => [],
                'total' => 0,
                'situacao' => 'Adimplente',
            ];
            foreach ($mesesDisponiveis as $mesRef) {
                $valor = $resumosAvulsos->where('num_mes', $mesRef->num_mes)->sum('val_total');
                $linha['meses'][] = [
                    'key' => $mesRef->num_ano . '-' . $mesRef->num_mes,
                    'value' => $valor,
                    'has_payment' => $valor > 0,
                ];
                $linha['total'] += $valor;
                $totaisPorMes[$mesRef->num_ano . '-' . $mesRef->num_mes]['total'] += $valor;
            }
            $dadosDashboard[] = $linha;
        }

        foreach ($membrosAtivos as $membro) {
            $membroId = $membro->idt_membro;
            $resumosMembro = $resumosAnoCollection->where('idt_membro', $membroId);
            
            $linha = [
                'nome' => $membro->nom_membro,
                'membro_id' => $membroId,
                'is_member' => true,
                'meses' => [],
                'total' => 0,
                'situacao' => 'Adimplente',
            ];
            
            $isHonorario = $membro->tip_associado === \App\Enums\Perfil::HONORARIO || $membro->tip_associado?->value === 'honorario';
            $taxaVigente = $membro->associacao?->getTaxaVigenteEm();
            $valTaxa = (float) ($taxaVigente?->val_taxa ?? 0);
            $valAnual = (float) ($taxaVigente?->val_anual ?? 0);

            $dataAdesao = $membro->dat_adesao ? \Carbon\Carbon::parse($membro->dat_adesao) : $membro->created_at;
            $dataInicioAssoc = $membro->associacao?->dat_inicio_cobranca 
                ? \Carbon\Carbon::parse($membro->associacao->dat_inicio_cobranca) 
                : \Carbon\Carbon::create(2025, 1, 1);

            $dataInicioObrigacao = ($dataAdesao && $dataAdesao->greaterThan($dataInicioAssoc)) 
                ? $dataAdesao 
                : $dataInicioAssoc;

            $startYear = (int) $dataInicioObrigacao->format('Y');
            $startMonth = (int) $dataInicioObrigacao->format('n');

            $mesesDevidos = 0;
            if ($selectedYear >= $startYear) {
                for ($m = 1; $m <= $monthsToConsider; $m++) {
                    if ($startYear < $selectedYear || ($startYear === $selectedYear && $startMonth <= $m)) {
                        $mesesDevidos++;
                    }
                }
            }

            foreach ($mesesDisponiveis as $mesRef) {
                $m = $mesRef->num_mes;
                $valor = (float) $resumosMembro->where('num_mes', $m)->sum('val_total');

                $linha['meses'][] = [
                    'key' => $mesRef->num_ano . '-' . $mesRef->num_mes,
                    'value' => $valor,
                    'has_payment' => $valor > 0,
                ];
                $linha['total'] += $valor;
                
                $totaisPorMes[$mesRef->num_ano . '-' . $mesRef->num_mes]['total'] += $valor;
                $totaisPorMes[$mesRef->num_ano . '-' . $mesRef->num_mes]['identificado'] += $valor;
            }
            
            $valorEsperado = $mesesDevidos * $valTaxa;

            if ($isHonorario) {
                $linha['situacao'] = 'Isento';
            } elseif ($valAnual > 0 && $linha['total'] >= $valAnual) {
                $linha['situacao'] = 'Adimplente';
            } elseif ($valTaxa > 0) {
                $linha['situacao'] = ($linha['total'] >= $valorEsperado) ? 'Adimplente' : 'Inadimplente';
            } else {
                $linha['situacao'] = ($linha['total'] > 0) ? 'Adimplente' : 'Inadimplente';
            }
            
            $dadosDashboard[] = $linha;
        }

        $membrosIdsAtivos = $membrosAtivos->pluck('idt_membro')->toArray();
        $resumosInativos = $resumosAnoCollection->whereNotNull('idt_membro')->whereNotIn('idt_membro', $membrosIdsAtivos)->groupBy('idt_membro');
        foreach ($resumosInativos as $membroId => $resumosMembro) {
            $membroInativo = Membro::withoutGlobalScope('associacao')->find($membroId);
            $linha = [
                'nome' => ($membroInativo ? $membroInativo->nom_membro : 'Membro Excluído') . ' (Inativo)',
                'membro_id' => $membroId,
                'is_member' => true,
                'meses' => [],
                'total' => 0,
                'situacao' => 'Inativo',
            ];
            foreach ($mesesDisponiveis as $mesRef) {
                $m = $mesRef->num_mes;
                $valor = $resumosMembro->where('num_mes', $m)->sum('val_total');
                $linha['meses'][] = [
                    'key' => $mesRef->num_ano . '-' . $mesRef->num_mes,
                    'value' => $valor,
                    'has_payment' => $valor > 0,
                ];
                $linha['total'] += $valor;
                $totaisPorMes[$mesRef->num_ano . '-' . $mesRef->num_mes]['total'] += $valor;
                $totaisPorMes[$mesRef->num_ano . '-' . $mesRef->num_mes]['identificado'] += $valor;
            }
            $dadosDashboard[] = $linha;
        }

        $selectedAssociacao = $this->selectedAssociacaoId ? \App\Models\Associacao::find($this->selectedAssociacaoId) : null;

        return [
            'isMembroDashboard' => false,
            'isAdminDashboard' => $isAdminDashboard,
            'isDiretor' => $user->isDiretor(),
            'isAdmin' => $isAdmin,
            'selectedAssociacao' => $selectedAssociacao,
            'dadosDashboard' => $dadosDashboard,
            'mesesDisponiveis' => $mesesDisponiveis,
            'totaisPorMes' => $totaisPorMes,
            'totalRecebido' => $totalRecebido,
            'totalPagoDespesas' => $totalPagoDespesas,
            'totalNaoIdentificado' => $totalNaoIdentificado,
            'availableYears' => $years,
            'availableMonths' => $availableMonths,
            'nomesMeses' => $nomesMeses,
            'totalAdimplentes' => $totalAdimplentes,
            'totalInadimplentes' => $totalInadimplentes,
            'totalIsentos' => $totalIsentos,
            'totalMembrosAtivos' => $totalMembrosAtivos,
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
                                    <x-select-associacao label="Associação" wire:model="contactAssociacaoId" placeholder="Selecione a associação..." disabled />
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
                        </div>
                    </div>

                    <div class="assoc-grid" style="display:grid;grid-template-columns:1fr 280px;gap:1.5rem;align-items:start">
                        {{-- ── Conteúdo principal ── --}}
                        <div style="display:flex;flex-direction:column;gap:1.25rem">
                            @if($todosResumos->isEmpty())
                                <div class="pc-card">
                                    <div class="pc-empty" style="padding:2.5rem 1.5rem">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <p>Nenhum registro de mensalidade encontrado para você até o momento.</p>
                                    </div>
                                </div>
                            @else
                                <div class="pc-card">
                                    <div class="pc-card-header">
                                        <span class="pc-card-title">Minhas Mensalidades</span>
                                    </div>
                                    <div class="pc-card-body" style="padding:0">
                                        <div style="overflow-x:auto">
                                            <table class="pc-table">
                                                <thead>
                                                    <tr>
                                                        <th>Competência</th>
                                                        <th>Ano</th>
                                                        <th style="text-align:right">Valor</th>
                                                        <th style="text-align:center">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($todosResumos as $r)
                                                        <tr>
                                                            <td style="font-weight:600;color:var(--pc-text)">{{ $r->nom_mes_ano }}</td>
                                                            <td>{{ $r->num_ano }}</td>
                                                            <td style="text-align:right;font-weight:600;color:var(--pc-text)">R$ {{ number_format($r->val_total, 2, ',', '.') }}</td>
                                                            <td style="text-align:center">
                                                                @if($r->ind_pago)
                                                                    <span class="pc-badge green">Pago</span>
                                                                @else
                                                                    <span class="pc-badge red">Pendente</span>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
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
                                <div class="pc-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                                    <span class="pc-card-title">Perfil</span>
                                    <a href="/settings/profile" style="font-size: 0.75rem; color: var(--pc-primary); text-decoration: none; font-weight: 600;">Editar Perfil</a>
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
            <div x-data="{ showFilters: false, showCards: false }">
                <div class="flex gap-4 mb-4">
                    <button @click="showFilters = !showFilters" class="flex items-center gap-2 text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100 transition-colors">
                        <flux:icon name="funnel" class="size-4" />
                        <span x-text="showFilters ? 'Ocultar Filtros' : 'Mostrar Filtros'">Mostrar Filtros</span>
                    </button>
                    <button @click="showCards = !showCards" class="flex items-center gap-2 text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100 transition-colors">
                        <flux:icon name="chart-pie" class="size-4" />
                        <span x-text="showCards ? 'Ocultar Resumo' : 'Mostrar Resumo'">Mostrar Resumo</span>
                    </button>
                </div>

                <!-- Filtros Unificados (Admin e Diretor) -->
                <div x-show="showFilters" style="display: none;" class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-zinc-900 mb-6">
                    <div class="flex flex-wrap items-center gap-4 w-full sm:w-auto">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                                <flux:icon name="building-office-2" class="size-5" />
                            </div>
                            <div class="flex flex-col gap-1 flex-1 sm:flex-initial">
                                <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Associação Selecionada</p>
                                <x-select-associacao wire:model.live="selectedAssociacaoId" class="w-full sm:w-64" :show-all-option="$isAdmin" :disabled="!$isAdmin" />
                                @if($selectedAssociacao)
                                    <div class="flex items-center gap-3 text-xs text-neutral-600 dark:text-neutral-400 mt-1">
                                        @php $taxaVigenteSelected = $selectedAssociacao?->getTaxaVigenteEm(); @endphp
                                        <span>Taxa mensal: <strong class="text-neutral-800 dark:text-neutral-200">R$ {{ number_format($taxaVigenteSelected?->val_taxa ?? 0, 2, ',', '.') }}</strong></span>
                                        <span>•</span>
                                        <span>Anuidade: <strong class="text-neutral-800 dark:text-neutral-200">R$ {{ number_format($taxaVigenteSelected?->val_anual ?? 0, 2, ',', '.') }}</strong></span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-3 border-t sm:border-t-0 sm:border-l border-neutral-200 dark:border-neutral-700 pt-4 sm:pt-0 sm:pl-4">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                                <flux:icon name="calendar" class="size-5" />
                            </div>
                            <div class="flex flex-col gap-1 w-full sm:w-36">
                                <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Ano</p>
                                <flux:select wire:model.live="selectedYear" class="w-full">
                                    @foreach($availableYears as $y)
                                        <flux:select.option value="{{ $y }}">{{ $y }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 border-t sm:border-t-0 sm:border-l border-neutral-200 dark:border-neutral-700 pt-4 sm:pt-0 sm:pl-4">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                                <flux:icon name="calendar-days" class="size-5" />
                            </div>
                            <div class="flex flex-col gap-1 w-full sm:w-40">
                                <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Mês</p>
                                <flux:select wire:model.live="selectedMonth" class="w-full">
                                    <flux:select.option value="">Todos os Meses</flux:select.option>
                                    @foreach($availableMonths as $m)
                                        <flux:select.option value="{{ $m }}">{{ $nomesMeses[$m] ?? $m }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center w-full sm:w-auto mt-4 sm:mt-0">
                        <button wire:click="atualizarPagamentos" wire:loading.attr="disabled" class="flex items-center justify-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition w-full sm:w-auto disabled:opacity-50">
                            <flux:icon name="arrow-path" class="size-4" wire:loading.class="animate-spin" />
                            <span wire:loading.remove>Atualizar Pagamentos</span>
                            <span wire:loading>Atualizando...</span>
                        </button>
                    </div>
                </div>

                <!-- Cards de Estatísticas Unificados -->
                <div x-show="showCards" style="display: none;" class="grid auto-rows-min gap-3 grid-cols-2 md:grid-cols-3 lg:grid-cols-6 mb-6">
                    <flux:card class="flex flex-col justify-center p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 truncate">Total Recebido</p>
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-green-100 text-green-600 dark:bg-green-900/30">
                                <flux:icon name="banknotes" class="size-3" />
                            </div>
                        </div>
                        <p class="mt-2 text-lg font-bold text-neutral-800 dark:text-neutral-100 truncate">{{ number_format($totalRecebido,2,',','.') }}</p>
                    </flux:card>

                    <flux:card class="flex flex-col justify-center p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 truncate">Total Pago</p>
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-red-100 text-red-600 dark:bg-red-900/30">
                                <flux:icon name="arrow-trending-down" class="size-3" />
                            </div>
                        </div>
                        <p class="mt-2 text-lg font-bold text-red-600 truncate">{{ number_format($totalPagoDespesas,2,',','.') }}</p>
                    </flux:card>

                    <flux:card class="flex flex-col justify-center p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 truncate">Adimplentes</p>
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-green-100 text-green-600 dark:bg-green-900/30">
                                <flux:icon name="check-circle" class="size-3" />
                            </div>
                        </div>
                        <p class="mt-2 text-lg font-bold text-green-600 truncate">{{ $totalAdimplentes }}</p>
                    </flux:card>

                    <flux:card class="flex flex-col justify-center p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 truncate">Inadimplentes</p>
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-red-100 text-red-600 dark:bg-red-900/30">
                                <flux:icon name="exclamation-triangle" class="size-3" />
                            </div>
                        </div>
                        <p class="mt-2 text-lg font-bold text-red-600 truncate">{{ $totalInadimplentes }}</p>
                    </flux:card>

                    <flux:card class="flex flex-col justify-center p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 truncate">Isentos</p>
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                                <flux:icon name="star" class="size-3" />
                            </div>
                        </div>
                        <p class="mt-2 text-lg font-bold text-blue-600 truncate">{{ $totalIsentos }}</p>
                    </flux:card>

                    <flux:card class="flex flex-col justify-center p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 truncate">Membros Ativos</p>
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                                <flux:icon name="users" class="size-3" />
                            </div>
                        </div>
                        <p class="mt-2 text-lg font-bold text-blue-600 truncate">{{ $totalMembrosAtivos }}</p>
                    </flux:card>
                </div>

                <!-- Seções Principais -->
                <div class="space-y-6">
                    {{-- Tabela de Acompanhamento Mensal --}}
                    <flux:card class="p-0 overflow-hidden mt-6">
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
                                        <th class="px-4 py-3 text-right font-bold">Total</th>
                                        @foreach($mesesDisponiveis as $month)
                                            <th class="px-3 py-3 text-center font-bold">{{ $month->nom_mes }}<span class="block text-[10px] font-normal opacity-60">{{ $month->num_ano }}</span></th>
                                        @endforeach
                                        <th class="px-4 py-3 text-center font-bold">Situação</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                    @forelse($dadosDashboard as $row)
                                        <tr class="transition hover:bg-neutral-50/50 dark:hover:bg-zinc-800/30 {{ $row['situacao']==='Inadimplente' ? 'bg-red-50/10 dark:bg-red-950/5' : '' }}">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2">
                                                    <div class="hidden sm:flex h-8 w-8 items-center justify-center rounded-full bg-neutral-200 text-xs font-bold text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">
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
                                            <td class="px-4 py-3 text-right font-bold text-neutral-700 dark:text-neutral-300">
                                                R$ {{ number_format($row['total'],0,',','.') }}
                                            </td>
                                            @foreach($row['meses'] as $m)
                                                <td class="px-3 py-3 text-center">
                                                    @if($m['has_payment'])
                                                        <span class="inline-block rounded bg-green-50 px-2 py-1 text-xs font-semibold text-green-700 dark:bg-green-900/20 dark:text-green-400">R$ {{ number_format($m['value'],0,',','.') }}</span>
                                                    @else
                                                        <span class="text-xs font-bold text-neutral-300 dark:text-neutral-600">R$ 0</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="px-4 py-3 text-center">
                                                @if($row['situacao'] === 'Adimplente')
                                                    <span class="inline-flex items-center gap-1 rounded bg-green-100 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                        <flux:icon name="check-circle" class="size-3" /> Adimplente
                                                    </span>
                                                @elseif($row['situacao'] === 'Isento')
                                                    <span class="inline-flex items-center gap-1 rounded bg-blue-100 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                                        <flux:icon name="star" class="size-3" /> Isento
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center gap-1 rounded bg-red-100 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                                        <flux:icon name="exclamation-triangle" class="size-3" /> Inadimplente
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="100%" class="px-4 py-8 text-center text-neutral-500">
                                                Nenhuma pessoa identificada neste ano.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                @if(count($dadosDashboard) > 0)
                                <tfoot class="bg-neutral-50 border-t-2 border-neutral-200 dark:bg-zinc-800/50 dark:border-neutral-700">
                                    <tr>
                                        <td class="px-4 py-4 text-left">
                                            <div class="text-xs font-bold uppercase tracking-wider text-neutral-600 dark:text-neutral-400">Total Pago Pelos Membros</div>
                                            <div class="text-[10px] mt-1 text-neutral-500 uppercase tracking-wider">Total Recebido (Geral)</div>
                                        </td>
                                        <td class="px-4 py-4 text-right">
                                            @php
                                                $totalMembrosAno = array_sum(array_column($totaisPorMes, 'identificado'));
                                                $totalGeralAno = array_sum(array_column($totaisPorMes, 'total'));
                                            @endphp
                                            <div class="text-sm font-bold text-green-700 dark:text-green-400">R$ {{ number_format($totalMembrosAno, 0, ',', '.') }}</div>
                                            <div class="text-xs mt-1 font-semibold text-neutral-600 dark:text-neutral-400">R$ {{ number_format($totalGeralAno, 0, ',', '.') }}</div>
                                        </td>
                                        @foreach($mesesDisponiveis as $month)
                                            @php
                                                $key = $month->num_ano . '-' . $month->num_mes;
                                                $tot = $totaisPorMes[$key] ?? ['identificado' => 0, 'total' => 0];
                                            @endphp
                                            <td class="px-3 py-4 text-center">
                                                <div class="text-sm font-bold text-green-700 dark:text-green-400">R$ {{ number_format($tot['identificado'], 0, ',', '.') }}</div>
                                                <div class="text-xs mt-1 font-semibold text-neutral-600 dark:text-neutral-400">R$ {{ number_format($tot['total'], 0, ',', '.') }}</div>
                                            </td>
                                        @endforeach
                                        <td></td>
                                    </tr>
                                </tfoot>
                                @endif
                            </table>
                        </div>
                    </flux:card>
                </div>
            </div>
        @endif
    </div>
