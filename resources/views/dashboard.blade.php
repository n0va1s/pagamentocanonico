<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-6 p-2">

        @if(session('success'))
            <flux:callout variant="success" icon="check-circle" heading="{{ session('success') }}" class="mb-2" />
        @endif
        @if(session('error'))
            <flux:callout variant="danger" icon="exclamation-triangle" heading="{{ session('error') }}" class="mb-2" />
        @endif

        @php
            $importId = request('import');
            $imports = App\Models\OfxImport::latest()->get();
            $selectedImport = $importId ? App\Models\OfxImport::with(['transactions','monthlySummaries'])->find($importId) : $imports->first();

            $dashboardData = [];
            $availableMonths = collect();
            $chartLabels = [];
            $chartValues = [];
            $totalInadimplentes = 0;
            $totalAdimplentes = 0;
            $totalRecebido = 0;

            if($selectedImport) {
                $summaries = $selectedImport->monthlySummaries()->orderBy('year')->orderBy('month')->get();
                $availableMonths = $summaries->unique(fn($i) => $i->year.'-'.str_pad($i->month,2,'0',STR_PAD_LEFT))->sortBy(['year','month'])->values();

                $monthlyTotals = $summaries->groupBy(fn($i) => $i->month_name.'/'.$i->year)->map->sum('total_amount')->sortKeys();
                $chartLabels = $monthlyTotals->keys()->values()->toArray();
                $chartValues = $monthlyTotals->values()->toArray();

                $byPerson = $summaries->groupBy('person_name');
                foreach($byPerson as $personName => $personData) {
                    $row = ['name'=>$personName,'months'=>[],'total'=>0,'situation'=>'Adimplente','is_member'=>App\Models\Member::where('name',$personName)->exists()];
                    foreach($availableMonths as $monthRef) {
                        $md = $personData->firstWhere(fn($i) => $i->year==$monthRef->year && $i->month==$monthRef->month);
                        $val = $md ? (float)$md->total_amount : 0;
                        $row['months'][] = ['value'=>$val,'has_payment'=>$val>0];
                        $row['total'] += $val;
                        if($val<=0) $row['situation'] = 'Inadimplente';
                    }
                    $totalRecebido += $row['total'];
                    $row['situation']==='Adimplente' ? $totalAdimplentes++ : $totalInadimplentes++;
                    $dashboardData[] = $row;
                }
            }

            $members = App\Models\Member::withCount('notificationLogs')->orderBy('name')->get()->map(function($m) use ($selectedImport) {
                $m->overdue = $selectedImport && App\Models\MonthlySummary::where('ofx_import_id',$selectedImport->id)->where('person_name',$m->name)->where('has_payment',false)->exists();
                $m->last_notification = $m->notificationLogs()->latest()->first();
                return $m;
            });

            $recentLogs = App\Models\NotificationLog::with('member')->latest()->limit(8)->get();
        @endphp

        @if($selectedImport)
        <!-- Seletor de Importação -->
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                    <flux:icon name="building-library" class="size-5" />
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Extrato Selecionado</p>
                    <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">{{ $selectedImport->filename }}</p>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">
                        {{ $selectedImport->date_start?->format('d/m/Y') }} – {{ $selectedImport->date_end?->format('d/m/Y') }} • {{ $selectedImport->transaction_count }} transações
                    </p>
                </div>
            </div>
            @if($imports->count() > 0)
            <form method="GET" action="{{ route('dashboard') }}" class="flex items-center gap-2">
                <flux:select name="import" variant="listbox" onchange="this.form.submit()" class="w-64">
                    @foreach($imports as $imp)
                        <flux:select.option value="{{ $imp->id }}" :selected="$selectedImport && $selectedImport->id == $imp->id">
                            {{ $imp->filename }} ({{ $imp->date_start?->format('m/Y') }})
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button href="{{ route('upload') }}" icon="plus" size="sm" variant="primary">Novo</flux:button>
            </form>
            @endif
        </div>

        <!-- Cards -->
        <div class="grid auto-rows-min gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Total Recebido</p>
                        <p class="mt-1 text-2xl font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($totalRecebido,2,',','.') }}</p>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30">
                        <flux:icon name="banknotes" class="size-5" />
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Adimplentes</p>
                        <p class="mt-1 text-2xl font-bold text-green-600">{{ $totalAdimplentes }}</p>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30">
                        <flux:icon name="check-circle" class="size-5" />
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Inadimplentes</p>
                        <p class="mt-1 text-2xl font-bold text-red-600">{{ $totalInadimplentes }}</p>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-900/30">
                        <flux:icon name="exclamation-triangle" class="size-5" />
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Membros</p>
                        <p class="mt-1 text-2xl font-bold text-blue-600">{{ $members->count() }}</p>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30">
                        <flux:icon name="users" class="size-5" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráfico + Logs -->
        <div class="grid auto-rows-min gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
                <h3 class="mb-4 text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300">
                    <flux:icon name="chart-bar" class="mr-1 inline size-4 text-blue-600" /> Evolução de Recebimentos
                </h3>
                <canvas id="revenueChart" height="100"></canvas>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
                <h3 class="mb-4 text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300">
                    <flux:icon name="paper-airplane" class="mr-1 inline size-4 text-blue-600" /> Notificações Recentes
                </h3>
                <div class="max-h-64 space-y-3 overflow-y-auto pr-1">
                    @forelse($recentLogs as $log)
                        <div class="flex items-start gap-3 border-b border-neutral-100 pb-2 text-sm last:border-0 dark:border-neutral-800">
                            <div class="mt-0.5">
                                @if($log->channel === 'whatsapp')
                                    <span class="text-green-500"><i class="fa-brands fa-whatsapp"></i></span>
                                @elseif($log->channel === 'email')
                                    <span class="text-blue-500"><i class="fa-solid fa-envelope"></i></span>
                                @else
                                    <span class="text-sky-500"><i class="fa-brands fa-telegram"></i></span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-neutral-800 dark:text-neutral-200">{{ $log->member?->name ?? 'Desconhecido' }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ $log->created_at->format('d/m H:i') }} •
                                    <span class="font-semibold {{ $log->status==='sent' ? 'text-green-600' : 'text-red-500' }}">{{ $log->status==='sent' ? 'Enviado' : 'Falha' }}</span>
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="py-4 text-center text-sm italic text-neutral-400">Nenhuma notificação enviada ainda.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Tabela Principal -->
        <div class="rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-neutral-100 px-5 py-4 dark:border-neutral-800">
                <h3 class="text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300">
                    <flux:icon name="table-cells" class="mr-1 inline size-4 text-blue-600" /> Acompanhamento Mensal por Pagador
                </h3>
                <span class="text-xs text-neutral-500">{{ count($dashboardData) }} pagadores</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-neutral-50 text-xs uppercase tracking-wider text-neutral-600 dark:bg-zinc-800/50 dark:text-neutral-400">
                            <th class="px-4 py-3 text-left font-bold">Pagador (MEMO)</th>
                            @foreach($availableMonths as $month)
                                <th class="px-3 py-3 text-center font-bold">{{ $month->month_name }}<span class="block text-[10px] font-normal opacity-60">{{ $month->year }}</span></th>
                            @endforeach
                            <th class="px-4 py-3 text-right font-bold">Total</th>
                            <th class="px-4 py-3 text-center font-bold">Situação</th>
                            <th class="px-4 py-3 text-center font-bold">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse($dashboardData as $row)
                            <tr class="transition hover:bg-neutral-50/50 dark:hover:bg-zinc-800/30 {{ $row['situation']==='Inadimplente' ? 'bg-red-50/30 dark:bg-red-900/10' : '' }}">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-neutral-200 text-xs font-bold text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">
                                            {{ strtoupper(substr($row['name'],0,1)) }}
                                        </div>
                                        <div>
                                            <p class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $row['name'] }}</p>
                                            @if($row['is_member'])
                                                <span class="inline-block rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Membro</span>
                                            @else
                                                <span class="inline-block rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-zinc-800 dark:text-neutral-400">Não cadastrado</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                @foreach($row['months'] as $m)
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
                                    @if($row['situation'] === 'Adimplente')
                                        <flux:badge color="green" size="sm" icon="check">Adimplente</flux:badge>
                                    @else
                                        <flux:badge color="red" size="sm" icon="x-mark">Inadimplente</flux:badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @php $memberRecord = App\Models\Member::where('name', $row['name'])->first(); @endphp
                                    @if($memberRecord && $row['situation'] === 'Inadimplente')
                                        <form action="{{ route('dashboard.notify') }}" method="POST" class="inline">
                                            @csrf
                                            <input type="hidden" name="member_id" value="{{ $memberRecord->id }}">
                                            <input type="hidden" name="channel" value="all">
                                            <flux:button type="submit" size="xs" variant="primary" icon="bell">Alertar</flux:button>
                                        </form>
                                    @else
                                        <span class="text-xs text-neutral-300 dark:text-neutral-600">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($availableMonths)+4 }}" class="px-4 py-8 text-center text-neutral-400">
                                    <flux:icon name="inbox" class="mx-auto mb-2 size-8 opacity-40" />
                                    Nenhum dado de pagamento encontrado neste extrato.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Membros Cadastrados -->
        <div class="rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-neutral-100 px-5 py-4 dark:border-neutral-800">
                <h3 class="text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300">
                    <flux:icon name="users" class="mr-1 inline size-4 text-blue-600" /> Base de Membros Cadastrados
                </h3>
                <flux:button href="{{ route('members.create') }}" size="sm" variant="primary" icon="plus">Cadastrar</flux:button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-neutral-50 text-xs uppercase tracking-wider text-neutral-600 dark:bg-zinc-800/50 dark:text-neutral-400">
                            <th class="px-4 py-3 text-left font-bold">Membro</th>
                            <th class="px-4 py-3 text-left font-bold">Contato</th>
                            <th class="px-4 py-3 text-center font-bold">Tipo</th>
                            <th class="px-4 py-3 text-center font-bold">Status OFX</th>
                            <th class="px-4 py-3 text-center font-bold">Último Alerta</th>
                            <th class="px-4 py-3 text-center font-bold">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse($members as $member)
                            <tr class="transition hover:bg-neutral-50/50 dark:hover:bg-zinc-800/30">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $member->name }}</p>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ Str::limit($member->full_address, 35) }}</p>
                                </td>
                                <td class="px-4 py-3 text-xs text-neutral-600 dark:text-neutral-400">
                                    <div class="space-y-0.5">
                                        @if($member->email)<div><i class="fa-solid fa-envelope mr-1 w-4 text-neutral-400"></i>{{ $member->email }}</div>@endif
                                        @if($member->whatsapp)<div><i class="fa-brands fa-whatsapp mr-1 w-4 text-green-500"></i>{{ $member->whatsapp }}</div>@endif
                                        @if($member->telegram_chat_id)<div><i class="fa-brands fa-telegram mr-1 w-4 text-sky-500"></i>{{ $member->telegram_chat_id }}</div>@endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <flux:badge size="sm" 
                                        color="{{ $member->membership_type==='premium' ? 'purple' : ($member->membership_type==='regular' ? 'blue' : ($member->membership_type==='suspended' ? 'red' : 'zinc')) }}"
                                        class="uppercase">
                                        {{ $member->membership_type_label }}
                                    </flux:badge>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @if($member->overdue)
                                        <span class="text-xs font-bold text-red-600"><i class="fa-solid fa-circle mr-1 text-[8px]"></i>Inadimplente</span>
                                    @else
                                        <span class="text-xs font-bold text-green-600"><i class="fa-solid fa-circle mr-1 text-[8px]"></i>Regular</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center text-xs text-neutral-500 dark:text-neutral-400">
                                    @if($member->last_notification)
                                        {{ $member->last_notification->created_at->diffForHumans() }}
                                        <span class="block {{ $member->last_notification->status==='sent' ? 'text-green-600' : 'text-red-500' }}">
                                            {{ $member->last_notification->channel }} • {{ $member->last_notification->status }}
                                        </span>
                                    @else
                                        <span class="text-neutral-400">Nunca alertado</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex justify-center gap-1">
                                        @if($member->notify_whatsapp && $member->whatsapp)
                                            <form action="{{ route('dashboard.notify') }}" method="POST" class="inline">
                                                @csrf
                                                <input type="hidden" name="member_id" value="{{ $member->id }}">
                                                <input type="hidden" name="channel" value="whatsapp">
                                                <flux:button type="submit" size="xs" class="!bg-green-100 !text-green-700 hover:!bg-green-200 dark:!bg-green-900/30 dark:!text-green-400" title="WhatsApp">
                                                    <i class="fa-brands fa-whatsapp"></i>
                                                </flux:button>
                                            </form>
                                        @endif
                                        @if($member->notify_email && $member->email)
                                            <form action="{{ route('dashboard.notify') }}" method="POST" class="inline">
                                                @csrf
                                                <input type="hidden" name="member_id" value="{{ $member->id }}">
                                                <input type="hidden" name="channel" value="email">
                                                <flux:button type="submit" size="xs" class="!bg-blue-100 !text-blue-700 hover:!bg-blue-200 dark:!bg-blue-900/30 dark:!text-blue-400" title="E-mail">
                                                    <i class="fa-solid fa-envelope"></i>
                                                </flux:button>
                                            </form>
                                        @endif
                                        @if($member->notify_telegram && $member->telegram_chat_id)
                                            <form action="{{ route('dashboard.notify') }}" method="POST" class="inline">
                                                @csrf
                                                <input type="hidden" name="member_id" value="{{ $member->id }}">
                                                <input type="hidden" name="channel" value="telegram">
                                                <flux:button type="submit" size="xs" class="!bg-sky-100 !text-sky-700 hover:!bg-sky-200 dark:!bg-sky-900/30 dark:!text-sky-400" title="Telegram">
                                                    <i class="fa-brands fa-telegram"></i>
                                                </flux:button>
                                            </form>
                                        @endif
                                        <form action="{{ route('dashboard.notify') }}" method="POST" class="inline">
                                            @csrf
                                            <input type="hidden" name="member_id" value="{{ $member->id }}">
                                            <input type="hidden" name="channel" value="all">
                                            <flux:button type="submit" size="xs" class="!bg-neutral-100 !text-neutral-700 hover:!bg-neutral-200 dark:!bg-zinc-800 dark:!text-neutral-300" title="Todos os canais">
                                                <i class="fa-solid fa-bullhorn"></i>
                                            </flux:button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-neutral-400">
                                    Nenhum membro cadastrado. <a href="{{ route('members.create') }}" class="text-blue-600 underline">Cadastrar agora</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
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

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        @if($selectedImport && count($chartLabels) > 0)
        const ctx = document.getElementById('revenueChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: @json($chartLabels),
                datasets: [{
                    label: 'Recebimentos (R$)',
                    data: @json($chartValues),
                    backgroundColor: 'rgba(59, 130, 246, 0.7)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'R$ ' + context.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'R$ ' + value.toLocaleString('pt-BR');
                            }
                        }
                    }
                }
            }
        });
        @endif
    </script>
</x-layouts::app>