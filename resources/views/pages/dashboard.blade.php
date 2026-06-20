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



    /**
     * Trigger alert for single member
     */
    public function alertMember(int $membroId, string $channel): void
    {
        $membro = Membro::findOrFail($membroId);
        $dispatcher = app(NotificationDispatcher::class);

        $selectedImport = Ofx::find($this->selectedImportId);
        if (!$selectedImport) {
            $this->dispatch('toast', message: 'Nenhuma importação selecionada.', variant: 'danger');
            return;
        }

        $atrasado = Resumo::where('idt_ofx', $this->selectedImportId)
            ->where('nom_pessoa', $membro->nomeParaMatchingOfx())
            ->where('ind_pago', false)
            ->orderByDesc('num_ano')
            ->orderByDesc('num_mes')
            ->first();

        $mesRef = $atrasado ? "{$atrasado->nom_mes}/{$atrasado->num_ano}" : 'mês atual';
        $valorRef = $atrasado ? $atrasado->val_total : 0;

        $mensagem = app(\App\Services\Notifications\Messages\MensagemBuilder::class)->construir(
            TipoNotificacao::INADIMPLENTE,
            $membro,
            ['mes' => $mesRef, 'valor' => $valorRef]
        );

        if ($channel === 'whatsapp' || $channel === 'all') {
            $wa = app(WhatsAppChannel::class);
            $res = $wa->send($membro, $mensagem, TipoNotificacao::INADIMPLENTE);
            if ($res['success'] && isset($res['redirect_url'])) {
                $this->dispatch('open-wa-link', url: $res['redirect_url']);
            }
        }

        if ($channel === 'email' || $channel === 'all') {
            $email = app(EmailChannel::class);
            $email->send($membro, $mensagem, TipoNotificacao::INADIMPLENTE);
        }

        if ($channel === 'telegram' || $channel === 'all') {
            $telegram = app(TelegramChannel::class);
            $telegram->send($membro, $mensagem, TipoNotificacao::INADIMPLENTE);
        }

        $this->dispatch('toast', message: 'Alerta disparado!', variant: 'success');
    }

    public function with(): array
    {
        $importacoes = Ofx::latest()->get();
        $importacaoSelecionada = $this->selectedImportId 
            ? Ofx::with('resumos')->find($this->selectedImportId)
            : $importacoes->first();

        $dadosDashboard = [];
        $mesesDisponiveis = collect();
        $totalInadimplentes = 0;
        $totalAdimplentes = 0;
        $totalRecebido = 0;

        if ($importacaoSelecionada) {
            $resumos = $importacaoSelecionada->resumos()
                ->orderBy('num_ano')
                ->orderBy('num_mes')
                ->get();

            $mesesDisponiveis = $resumos
                ->unique(fn ($r) => $r->num_ano.'-'.str_pad($r->num_mes, 2, '0', STR_PAD_LEFT))
                ->sortBy(['num_ano', 'num_mes'])
                ->values();

            $porPessoa = $resumos->groupBy('nom_pessoa');

            foreach ($porPessoa as $nomePessoa => $resumosPessoa) {
                $membro = Membro::where('nom_ofx', $nomePessoa)
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

        return [
            'importacoes' => $importacoes,
            'importacaoSelecionada' => $importacaoSelecionada,
            'dadosDashboard' => $dadosDashboard,
            'mesesDisponiveis' => $mesesDisponiveis,
            'totalRecebido' => $totalRecebido,
            'totalAdimplentes' => $totalAdimplentes,
            'totalInadimplentes' => $totalInadimplentes,
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
                    <p class="mt-1 text-2xl font-bold text-blue-600">{{ App\Models\Membro::count() }}</p>
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
                                <th class="px-4 py-3 text-center font-bold">Ação</th>
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
                                    <td class="px-4 py-3 text-center">
                                        @if($row['is_member'] && $row['situacao'] === 'Inadimplente')
                                            <div class="flex justify-center gap-1">
                                                <flux:button wire:click="alertMember({{ $row['membro_id'] }}, 'whatsapp')" size="xs" variant="primary" icon="phone" title="WhatsApp Web + E-mail espelho"></flux:button>
                                                <flux:button wire:click="alertMember({{ $row['membro_id'] }}, 'email')" size="xs" variant="ghost" icon="envelope" title="Apenas E-mail"></flux:button>
                                            </div>
                                        @else
                                            <span class="text-xs text-neutral-300 dark:text-neutral-600">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($mesesDisponiveis)+4 }}" class="px-4 py-8 text-center text-neutral-400">
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
    </div>
