<?php

use App\Exceptions\AutorizacaoAuditoriaException;
use App\Models\Associacao;
use App\Services\AuditoriaService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Auditoria de Duplicidades')] class extends Component
{
    public ?int $associacaoId = null;

    public bool $semVinculo = false;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            // Admin pode receber a associacao pela URL (ex.: atalho na lista de
            // associacoes). Se vier um id valido, abre ja com o resultado dela;
            // caso contrario inicia sem selecao (seletor).
            $idUrl = request()->query('associacao');
            if ($idUrl !== null && Associacao::whereKey((int) $idUrl)->exists()) {
                $this->associacaoId = (int) $idUrl;
            } else {
                $this->associacaoId = null;
            }
        } else {
            $this->associacaoId = $user->getMembroAssociacaoId();
            if ($this->associacaoId === null) {
                // Diretor sem membro vinculado: estado vazio com aviso, sem chamar o servico.
                $this->semVinculo = true;
            }
        }
    }

    public function updatedAssociacaoId(): void
    {
        // Admin trocou de associacao no seletor; nada alem de re-render.
    }

    public function corrigirTransacoes(string $chaveGrupo): void
    {
        $this->corrigir('transacao', $chaveGrupo);
    }

    public function corrigirResumos(string $chaveGrupo): void
    {
        $this->corrigir('resumo', $chaveGrupo);
    }

    private function corrigir(string $tipo, string $chaveGrupo): void
    {
        $associacaoId = $this->resolverAssociacaoId();
        if ($associacaoId === null) {
            Flux::toast(variant: 'danger', text: 'Operação não permitida para a associação informada.');

            return;
        }

        $servico = app(AuditoriaService::class);

        try {
            if ($tipo === 'transacao') {
                $servico->corrigirTransacoesDuplicadas($associacaoId, $chaveGrupo, (int) auth()->id());
            } else {
                $servico->corrigirResumosDuplicados($associacaoId, $chaveGrupo, (int) auth()->id());
            }

            Flux::toast(variant: 'success', text: 'Duplicidade corrigida com sucesso.');
            $this->dispatch('auditoria-atualizada');
        } catch (AutorizacaoAuditoriaException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Falha ao corrigir duplicidade: '.$e->getMessage());
            Flux::toast(variant: 'danger', text: 'A correção não foi aplicada. Os dados foram mantidos no estado original.');
        }
    }

    /**
     * Resolve a associacao efetiva, com enforcement multi-tenant: nao-admin
     * SEMPRE deriva do usuario (ignora qualquer parametro de requisicao).
     */
    private function resolverAssociacaoId(): ?int
    {
        $user = auth()->user();
        if (! $user->isAdmin()) {
            return $user->getMembroAssociacaoId();
        }

        return $this->associacaoId ? (int) $this->associacaoId : null;
    }

    public function with(): array
    {
        $associacaoId = $this->resolverAssociacaoId();

        $vazio = [
            'relatorio' => [
                'ofx_reimportados' => collect(),
                'transacoes_duplicadas' => collect(),
                'resumos_duplicados' => collect(),
                'total_excedente' => 0.0,
            ],
            'temAssociacao' => $associacaoId !== null,
            'associacoes' => auth()->user()->isAdmin()
                ? Associacao::orderBy('nom_associacao')->get()
                : collect(),
        ];

        if ($associacaoId === null) {
            return $vazio;
        }

        $relatorio = app(AuditoriaService::class)->detectar($associacaoId);

        return array_merge($vazio, [
            'relatorio' => $relatorio,
            'temAssociacao' => true,
        ]);
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto" wire:poll.visible.60s>
    <flux:toast.group>
        <flux:toast />
    </flux:toast.group>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                <flux:icon name="shield-exclamation" class="size-6 text-amber-600" /> Auditoria de Duplicidades
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                Detecte e corrija transações, resumos e importações duplicadas que afetam os totais do dashboard.
            </p>
        </div>
    </div>

    @if($semVinculo)
        <flux:callout variant="warning" icon="exclamation-triangle"
            heading="Seu usuário não está vinculado a uma associação. Procure um administrador." />
    @else
        @if(auth()->user()->isAdmin())
            <flux:card class="p-6">
                <x-select-associacao id="associacaoId" label="Associação" wire:model.live="associacaoId" :show-all-option="false" />
            </flux:card>
        @endif

        {{-- Indicador de progresso (Req 7.7): visivel durante toda a deteccao. --}}
        <div wire:loading wire:target="associacaoId, corrigirTransacoes, corrigirResumos" class="flex items-center gap-2 text-sm text-neutral-500">
            <flux:icon name="arrow-path" class="size-4 animate-spin" /> Carregando auditoria...
        </div>

        <div wire:loading.remove wire:target="associacaoId, corrigirTransacoes, corrigirResumos">
            @if(!$temAssociacao)
                <flux:callout variant="secondary" icon="information-circle"
                    heading="Selecione uma associação para visualizar a auditoria." />
            @else
                {{-- Total excedente --}}
                <flux:card class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm text-neutral-500">Valor total excedente estimado</div>
                            <div class="text-2xl font-bold text-amber-600">
                                R$ {{ number_format($relatorio['total_excedente'], 2, ',', '.') }}
                            </div>
                        </div>
                    </div>
                </flux:card>

                @php
                    $temDuplicidade = $relatorio['transacoes_duplicadas']->isNotEmpty()
                        || $relatorio['resumos_duplicados']->isNotEmpty()
                        || $relatorio['ofx_reimportados']->isNotEmpty();
                @endphp

                @if(!$temDuplicidade)
                    <flux:callout variant="success" icon="check-circle"
                        heading="Nenhuma duplicidade encontrada."
                        text="O valor total excedente estimado é R$ 0,00." />
                @else
                    {{-- OFX reimportados / periodo redundante --}}
                    @if($relatorio['ofx_reimportados']->isNotEmpty())
                        <flux:card class="p-0 overflow-x-auto">
                            <div class="p-6 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:heading size="md">Importações sinalizadas</flux:heading>
                                <p class="text-sm text-neutral-500 mt-1">Alertas informativos (não entram no total excedente).</p>
                            </div>
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>Motivo</flux:table.column>
                                    <flux:table.column>Registros OFX</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach($relatorio['ofx_reimportados'] as $i => $grupo)
                                        <flux:table.row wire:key="ofx-{{ $i }}">
                                            <flux:table.cell>{{ $grupo->motivo }}</flux:table.cell>
                                            <flux:table.cell>
                                                @foreach($grupo->registros as $reg)
                                                    <span class="inline-block mr-2">#{{ $reg->idt_ofx }} ({{ $reg->des_arquivo }})</span>
                                                @endforeach
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </flux:card>
                    @endif

                    {{-- Transacoes duplicadas --}}
                    @if($relatorio['transacoes_duplicadas']->isNotEmpty())
                        <flux:card class="p-0 overflow-x-auto">
                            <div class="p-6 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:heading size="md">Transações duplicadas</flux:heading>
                            </div>
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>FITID</flux:table.column>
                                    <flux:table.column>Conta</flux:table.column>
                                    <flux:table.column>Repetições</flux:table.column>
                                    <flux:table.column>Valor excedente</flux:table.column>
                                    <flux:table.column>Ação</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach($relatorio['transacoes_duplicadas'] as $i => $grupo)
                                        <flux:table.row wire:key="tx-{{ $i }}">
                                            <flux:table.cell class="font-mono text-xs">{{ $grupo->num_transacao }}</flux:table.cell>
                                            <flux:table.cell>{{ $grupo->cod_banco }}/{{ $grupo->num_conta }}</flux:table.cell>
                                            <flux:table.cell>{{ $grupo->excedentes }}</flux:table.cell>
                                            <flux:table.cell>R$ {{ number_format($grupo->valor_excedente, 2, ',', '.') }}</flux:table.cell>
                                            <flux:table.cell>
                                                <flux:modal.trigger name="confirmar-tx-{{ $i }}">
                                                    <flux:button size="sm" variant="danger">Corrigir</flux:button>
                                                </flux:modal.trigger>
                                                <flux:modal name="confirmar-tx-{{ $i }}" class="min-w-96">
                                                    <div class="space-y-4">
                                                        <flux:heading size="lg">Confirmar correção</flux:heading>
                                                        <flux:text>Serão removidas {{ $grupo->excedentes }} transação(ões) duplicada(s), mantendo a original. Esta ação não pode ser desfeita.</flux:text>
                                                        <div class="flex justify-end gap-2">
                                                            <flux:modal.close>
                                                                <flux:button variant="ghost">Cancelar</flux:button>
                                                            </flux:modal.close>
                                                            <flux:button variant="danger" wire:click="corrigirTransacoes('{{ $grupo->chave_grupo }}')">
                                                                Confirmar
                                                            </flux:button>
                                                        </div>
                                                    </div>
                                                </flux:modal>
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </flux:card>
                    @endif

                    {{-- Resumos duplicados --}}
                    @if($relatorio['resumos_duplicados']->isNotEmpty())
                        <flux:card class="p-0 overflow-x-auto">
                            <div class="p-6 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:heading size="md">Resumos duplicados</flux:heading>
                            </div>
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>OFX</flux:table.column>
                                    <flux:table.column>Período</flux:table.column>
                                    <flux:table.column>Repetições</flux:table.column>
                                    <flux:table.column>Valor excedente</flux:table.column>
                                    <flux:table.column>Ação</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach($relatorio['resumos_duplicados'] as $i => $grupo)
                                        <flux:table.row wire:key="rs-{{ $i }}">
                                            <flux:table.cell>#{{ $grupo->idt_ofx }}</flux:table.cell>
                                            <flux:table.cell>{{ str_pad($grupo->num_mes, 2, '0', STR_PAD_LEFT) }}/{{ $grupo->num_ano }}</flux:table.cell>
                                            <flux:table.cell>{{ $grupo->excedentes }}</flux:table.cell>
                                            <flux:table.cell>R$ {{ number_format($grupo->valor_excedente, 2, ',', '.') }}</flux:table.cell>
                                            <flux:table.cell>
                                                <flux:modal.trigger name="confirmar-rs-{{ $i }}">
                                                    <flux:button size="sm" variant="danger">Corrigir</flux:button>
                                                </flux:modal.trigger>
                                                <flux:modal name="confirmar-rs-{{ $i }}" class="min-w-96">
                                                    <div class="space-y-4">
                                                        <flux:heading size="lg">Confirmar correção</flux:heading>
                                                        <flux:text>Serão removidos {{ $grupo->excedentes }} resumo(s) duplicado(s), mantendo o original. Esta ação não pode ser desfeita.</flux:text>
                                                        <div class="flex justify-end gap-2">
                                                            <flux:modal.close>
                                                                <flux:button variant="ghost">Cancelar</flux:button>
                                                            </flux:modal.close>
                                                            <flux:button variant="danger" wire:click="corrigirResumos('{{ $grupo->chave_grupo }}')">
                                                                Confirmar
                                                            </flux:button>
                                                        </div>
                                                    </div>
                                                </flux:modal>
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </flux:card>
                    @endif
                @endif
            @endif
        </div>
    @endif
</div>