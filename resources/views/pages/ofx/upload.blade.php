<?php

use App\Models\Associacao;
use App\Models\Membro;
use App\Services\OfxParserService;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

new class extends Component {
    use WithFileUploads;

    public $ofx_files = [];
    public $idt_associacao = null;
    public $transacoes = [];
    public $vinculos = []; // key: index, value: idt_membro
    public $processado = false;
    public $tempPaths = [];
    public $originalNames = [];

    public function mount()
    {
        if (!auth()->user()->isAdmin()) {
            $this->idt_associacao = auth()->user()->membro?->idt_associacao;
        }

        // If the association is still null, and there is exactly one association in the database, auto-select it.
        if (empty($this->idt_associacao)) {
            $firstAssoc = Associacao::first();
            if ($firstAssoc && Associacao::count() === 1) {
                $this->idt_associacao = $firstAssoc->idt_associacao;
            }
        }
    }

    public function updatedIdtAssociacao()
    {
        // Reset state when association changes
        $this->processado = false;
        $this->transacoes = [];
        $this->vinculos = [];
    }

    public function updatedOfxFiles()
    {
        $this->processado = false;
    }

    public function processar()
    {
        $this->validate([
            'idt_associacao' => 'required|exists:associacoes,idt_associacao',
            'ofx_files' => 'required|array|min:1',
            'ofx_files.*' => 'file|max:5120', // 5MB
        ], [
            'idt_associacao.required' => 'A associação é obrigatória.',
            'idt_associacao.exists' => 'A associação selecionada é inválida.',
            'ofx_files.required' => 'Selecione pelo menos um arquivo OFX.',
            'ofx_files.array' => 'O formato de arquivos é inválido.',
            'ofx_files.min' => 'Selecione pelo menos um arquivo OFX.',
            'ofx_files.*.file' => 'O arquivo enviado é inválido.',
            'ofx_files.*.max' => 'Cada arquivo OFX deve ter no máximo 5MB.',
        ]);

        $this->transacoes = [];
        $this->vinculos = [];
        $this->tempPaths = [];
        $this->originalNames = [];

        $parser = app(OfxParserService::class);
        $globalIndex = 0;

        foreach ($this->ofx_files as $file) {
            $path = $file->store('temp/ofx', 'local');
            $tempPath = Storage::disk('local')->path($path);
            $originalName = $file->getClientOriginalName();

            $this->tempPaths[] = $tempPath;
            $this->originalNames[] = $originalName;

            $conteudo = file_get_contents($tempPath);

            // Normalizar encoding
            if (!str_contains($conteudo, 'ENCODING:UTF-8') && !mb_check_encoding($conteudo, 'UTF-8')) {
                $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
            }
            $conteudo = str_replace(["\r\n", "\r"], "\n", $conteudo);

            $blocos = $this->extrairBlocosTransacao($conteudo);

            foreach ($blocos as $bloco) {
                $valor = (float) str_replace(',', '.', $bloco['TRNAMT'] ?? '0');
                // Processar apenas créditos/recebimentos positivos
                if (($bloco['TRNTYPE'] ?? '') !== 'CREDIT' || $valor <= 0) {
                    continue;
                }

                $fitid = trim($bloco['FITID'] ?? '');
                $name = trim($bloco['NAME'] ?? '');

                // Ignorar linhas informativas de saldo
                if ($fitid === '' || 
                    str_contains(strtolower($name), 'saldo anterior') || 
                    str_contains(strtolower($name), 'saldo do dia') || 
                    str_contains(strtolower($name), 'saldo anterior/atual')) {
                    continue;
                }

                $datTransacao = $this->converterDataOfx($bloco['DTPOSTED'] ?? null);
                $memo = $bloco['MEMO'] ?? '';

                // Extrair CPF e nome limpo do MEMO
                $cpf = $parser->extrairCpfDoMemo($memo);
                $nomePagador = $this->limparDescricao($memo);

                // Buscar membro da associação selecionada
                $membro = null;
                if ($cpf) {
                    $membro = Membro::withoutGlobalScopes()
                        ->where('idt_associacao', $this->idt_associacao)
                        ->where(function($query) use ($cpf) {
                            $query->whereRaw("REPLACE(REPLACE(num_cpf_membro, '.', ''), '-', '') = ?", [$cpf]);
                            if (strlen($cpf) === 14 && str_starts_with($cpf, '000')) {
                                $query->orWhereRaw("REPLACE(REPLACE(num_cpf_membro, '.', ''), '-', '') = ?", [substr($cpf, 3)]);
                            }
                        })->first();
                }

                $this->transacoes[] = [
                    'index' => $globalIndex,
                    'date' => $datTransacao,
                    'valor' => $valor,
                    'cpf' => $cpf,
                    'nome_pagador' => $nomePagador,
                    'membro_id' => $membro?->idt_membro ?? null,
                    'membro_nome' => $membro?->nom_membro ?? null,
                    'fitid' => $fitid,
                    'trntype' => $bloco['TRNTYPE'] ?? null,
                    'checknum' => $bloco['CHECKNUM'] ?? null,
                    'name_tag' => $bloco['NAME'] ?? null,
                    'memo' => $memo,
                ];

                $this->vinculos[$globalIndex] = $membro?->idt_membro ?? '';
                $globalIndex++;
            }
        }

        $this->processado = true;
    }

    public function importar()
    {
        $this->validate([
            'idt_associacao' => 'required|exists:associacoes,idt_associacao',
        ], [
            'idt_associacao.required' => 'A associação é obrigatória.',
            'idt_associacao.exists' => 'A associação selecionada é inválida.',
        ]);

        if (!$this->processado || empty($this->tempPaths)) {
            \Flux::toast(variant: 'danger', text: 'Nenhum arquivo processado ou arquivo expirado.');
            return;
        }

        DB::beginTransaction();
        try {
            // Atualiza os membros que foram manualmente vinculados
            foreach ($this->transacoes as $t) {
                $idx = $t['index'];
                $selectedMembroId = $this->vinculos[$idx] ?? null;
                $extractedCpf = $t['cpf'];

                if ($selectedMembroId && $extractedCpf) {
                    $membro = Membro::withoutGlobalScopes()->find($selectedMembroId);
                    if ($membro) {
                        // Atualiza com o CPF extraído do extrato
                        $membro->update(['num_cpf_membro' => $extractedCpf]);
                    }
                }
            }

            // Invoca o parser para persistir cada arquivo no banco
            $parser = app(OfxParserService::class);
            $totalImported = 0;
            $lastOfxId = null;

            foreach ($this->tempPaths as $idx => $tempPath) {
                if (File::exists($tempPath)) {
                    $ofx = $parser->processar($tempPath, $this->originalNames[$idx], $this->idt_associacao);
                    $totalImported += $ofx->qtd_transacao;
                    $lastOfxId = $ofx->idt_ofx;
                    File::delete($tempPath);
                }
            }

            DB::commit();

            session()->flash('success', "Arquivos processados com sucesso! {$totalImported} transações importadas.");
            return $this->redirectRoute('dashboard', ['ofx' => $lastOfxId], navigate: true);

        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Erro ao importar dados: ' . $e->getMessage());
        }
    }

    private function extrairBlocosTransacao(string $conteudo): array
    {
        $blocos = [];
        $campos = ['TRNTYPE', 'DTPOSTED', 'TRNAMT', 'FITID', 'CHECKNUM', 'NAME', 'MEMO'];

        if (preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/s', $conteudo, $matches)) {
            foreach ($matches[1] as $bloco) {
                $transacao = [];
                foreach ($campos as $campo) {
                    if (preg_match("/<{$campo}>([^<\n]+)/i", $bloco, $tagMatches)) {
                        $transacao[$campo] = trim($tagMatches[1]);
                    } else {
                        $transacao[$campo] = null;
                    }
                }
                $blocos[] = $transacao;
            }
        }

        return $blocos;
    }

    private function converterDataOfx(?string $data): ?string
    {
        if (empty($data)) {
            return null;
        }
        $data = preg_replace('/\[.*?\]/', '', $data);
        $dataLimpa = substr($data, 0, 8);
        if (strlen($dataLimpa) === 8 && ctype_digit($dataLimpa)) {
            $ano = substr($dataLimpa, 0, 4);
            $mes = substr($dataLimpa, 4, 2);
            $dia = substr($dataLimpa, 6, 2);
            if (checkdate((int) $mes, (int) $dia, (int) $ano)) {
                return "{$ano}-{$mes}-{$dia}";
            }
        }
        return null;
    }

    private function limparDescricao(?string $descricao): ?string
    {
        if (empty($descricao)) {
            return null;
        }
        $descricao = preg_replace('/\s+/', ' ', trim($descricao));
        $descricao = preg_replace('/^\d{2}\/\d{2}(?:\s+\d{2}:\d{2})?\s+/', '', $descricao);
        $descricao = preg_replace('/^(?:\d|[\.\-\/]){11,18}\s+/', '', $descricao);
        $descricao = preg_replace('/\bAG\s*\d+\s*CC\s*[\d\-]+\b/i', '', $descricao);
        $descricao = preg_replace('/\bPIX\s*-\s*ENVIADO\b/i', '', $descricao);
        $descricao = preg_replace('/\bPIX\s*RECEBIDO\b/i', '', $descricao);
        $descricao = preg_replace('/\bTED\s*-\s*\d+\b/i', '', $descricao);
        $descricao = preg_replace('/\bDOC\s*-\s*\d+\b/i', '', $descricao);
        return trim($descricao) ?: 'Não identificado';
    }

    public function with(): array
    {
        return [
            'associacoes' => Associacao::orderBy('nom_associacao')->get(),
            'membros' => $this->idt_associacao 
                ? Membro::withoutGlobalScopes()->where('idt_associacao', $this->idt_associacao)->orderBy('nom_membro')->get()
                : collect(),
        ];
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                <flux:icon name="arrow-up-tray" class="size-6 text-blue-600" /> Importar Extrato OFX
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                Selecione a associação, o arquivo e processe para validar as transações e vínculos com membros.
            </p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <flux:button href="{{ route('dashboard') }}" variant="ghost" icon="arrow-left" size="sm" wire:navigate>
                Voltar ao Dashboard
            </flux:button>
        </div>
    </div>

    {{-- Alerts --}}
    @if(session('error'))
        <flux:callout variant="danger" icon="exclamation-triangle" heading="{{ session('error') }}" />
    @endif

    @if(session('success'))
        <flux:callout variant="success" icon="check-circle" heading="{{ session('success') }}" />
    @endif

    <div class="grid grid-cols-1 gap-6">
        {{-- Form Card --}}
        <flux:card class="p-6">
            <form wire:submit.prevent="processar" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                    <flux:field>
                        <flux:label for="idt_associacao" class="font-semibold text-zinc-700 dark:text-zinc-300">Associação</flux:label>
                        <div class="mt-2">
                            @if(auth()->user()->isAdmin())
                                <flux:select id="idt_associacao" wire:model.live="idt_associacao" placeholder="Selecione a associação..." required>
                                    @foreach($associacoes as $assoc)
                                        <flux:select.option value="{{ $assoc->idt_associacao }}">
                                            {{ $assoc->nom_associacao }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            @else
                                <flux:select id="idt_associacao_disabled" placeholder="Selecione a associação..." disabled>
                                    @foreach($associacoes as $assoc)
                                        <flux:select.option value="{{ $assoc->idt_associacao }}" :selected="$idt_associacao === $assoc->idt_associacao">
                                            {{ $assoc->nom_associacao }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                        </div>
                        <flux:error name="idt_associacao" />
                    </flux:field>

                    <flux:field>
                        <flux:label for="ofx_files" class="font-semibold text-zinc-700 dark:text-zinc-300">Selecione os arquivos OFX</flux:label>
                        <div class="flex items-center justify-center w-full mt-2">
                            <label for="ofx_files" class="flex flex-col items-center justify-center w-full h-32 border-2 border-zinc-300 border-dashed rounded-xl cursor-pointer bg-zinc-50 dark:hover:bg-zinc-800/40 dark:bg-zinc-900/10 hover:bg-zinc-100/50 dark:border-zinc-700 transition focus-within:ring-2 focus-within:ring-blue-500 focus-within:outline-none">
                                <div class="flex flex-col items-center justify-center pt-5 pb-6 px-4 text-center">
                                    <flux:icon name="arrow-up-tray" class="w-8 h-8 mb-2 text-blue-600 dark:text-blue-500" />
                                    @if (!empty($ofx_files))
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400 font-semibold truncate max-w-xs">
                                            @if (count($ofx_files) === 1)
                                                {{ $ofx_files[0]->getClientOriginalName() }}
                                            @else
                                                {{ count($ofx_files) }} arquivos selecionados
                                            @endif
                                        </p>
                                    @else
                                        <p class="mb-1 text-sm text-zinc-600 dark:text-zinc-400 font-medium">
                                            Clique para escolher ou arraste os arquivos aqui
                                        </p>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-500">
                                            Apenas arquivos .ofx (Máx: 5MB cada)
                                        </p>
                                    @endif
                                </div>
                                <input id="ofx_files" wire:model="ofx_files" type="file" class="sr-only" accept=".ofx" multiple required />
                            </label>
                        </div>
                        <flux:error name="ofx_files" />
                    </flux:field>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <flux:button type="submit" variant="primary" class="px-5">
                        Processar Extrato
                    </flux:button>
                </div>
            </form>
        </flux:card>

        {{-- Preview Table --}}
        @if($processado)
            <flux:card class="overflow-x-auto p-0">
                <div class="p-6 border-b border-zinc-200 dark:border-zinc-700">
                    <flux:heading size="md">Transações Identificadas</flux:heading>
                    <p class="text-sm text-neutral-500 mt-1">
                        Verifique os dados abaixo. Caso um pagador não seja identificado automaticamente pelo CPF, selecione o respectivo membro correspondente na lista.
                    </p>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Data</flux:table.column>
                        <flux:table.column>Valor</flux:table.column>
                        <flux:table.column>CPF da Transação</flux:table.column>
                        <flux:table.column>Membro Identificado / Associar a</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse($transacoes as $t)
                            <flux:table.row wire:key="transacao-{{ $t['index'] }}">
                                <flux:table.cell class="font-medium">
                                    {{ $t['date'] ? \Carbon\Carbon::parse($t['date'])->format('d/m/Y') : '-' }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    R$ {{ number_format($t['valor'], 2, ',', '.') }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $t['cpf'] ?: 'Não extraído' }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if($t['membro_id'])
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                                {{ $t['membro_nome'] }}
                                            </span>
                                        </div>
                                    @else
                                        <div class="w-72">
                                            <flux:select wire:model="vinculos.{{ $t['index'] }}" placeholder="Selecione o membro da associação...">
                                                <flux:select.option value="">-- Não associado --</flux:select.option>
                                                @foreach($membros as $m)
                                                    <flux:select.option value="{{ $m->idt_membro }}">
                                                        {{ $m->num_cpf_membro ?: 'Sem CPF' }} - {{ $m->nom_membro }}
                                                    </flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </div>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="py-12 text-center text-zinc-400">
                                    Nenhum recebimento ou transação de crédito encontrada no extrato.
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>

                @if(count($transacoes) > 0)
                    <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/50 flex items-center justify-end gap-3">
                        <flux:button wire:click="importar" variant="primary" class="px-5">
                            Confirmar Importação
                        </flux:button>
                        <flux:button href="{{ route('dashboard') }}" variant="ghost">
                            Cancelar
                        </flux:button>
                    </div>
                @endif
            </flux:card>
        @endif
    </div>
</div>
