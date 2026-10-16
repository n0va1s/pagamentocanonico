<?php

use App\Enums\EfeitoRegra;
use App\Models\Associacao;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Regras de Classificação')] class extends Component
{
    public Associacao $associacao;

    public const TIPOS_MATCH = ['NOM_PESSOA', 'DES_TRANSACAO', 'CHECKNUM', 'TIPO'];

    public ?int $regraId = null;

    public string $nom_regra = '';

    public string $efeito = '';

    public bool $ind_ativo = true;

    public array $criterios = [];

    public function mount(Associacao $associacao): void
    {
        if (! auth()->user()->isAdmin()) {
            abort(403, 'Acesso não autorizado.');
        }

        $this->associacao = $associacao;
    }

    public function novaRegra(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'regra-form');
    }

    public function editarRegra(int $id): void
    {
        $regra = $this->associacao->regrasClassificacao()
            ->with('criterios')
            ->findOrFail($id);

        $this->regraId = $regra->idt_regra;
        $this->nom_regra = $regra->nom_regra;
        $this->efeito = $regra->des_efeito->value;
        $this->ind_ativo = (bool) $regra->ind_ativo;
        $this->criterios = $regra->criterios
            ->map(fn ($c) => ['tip_match' => $c->tip_match, 'val_match' => $c->val_match])
            ->values()
            ->all();

        if (empty($this->criterios)) {
            $this->adicionarCriterio();
        }

        $this->dispatch('open-modal', name: 'regra-form');
    }

    public function adicionarCriterio(): void
    {
        $this->criterios[] = ['tip_match' => 'NOM_PESSOA', 'val_match' => ''];
    }

    public function removerCriterio(int $index): void
    {
        unset($this->criterios[$index]);
        $this->criterios = array_values($this->criterios);
    }

    public function salvar(): void
    {
        $dados = $this->validate([
            'nom_regra' => ['required', 'string', 'max:100'],
            'efeito' => ['required', Rule::enum(EfeitoRegra::class)],
            'ind_ativo' => ['boolean'],
            'criterios' => ['required', 'array', 'min:1'],
            'criterios.*.tip_match' => ['required', Rule::in(self::TIPOS_MATCH)],
            'criterios.*.val_match' => ['required', 'string', 'max:255'],
        ], [
            'nom_regra.required' => 'O nome da regra é obrigatório.',
            'efeito.required' => 'O efeito é obrigatório.',
            'criterios.required' => 'Adicione ao menos um critério.',
            'criterios.min' => 'Adicione ao menos um critério.',
            'criterios.*.tip_match.required' => 'Selecione o tipo do critério.',
            'criterios.*.val_match.required' => 'Informe o valor do critério.',
        ]);

        // Um critério CHECKNUM precisa conter ao menos um dígito: um valor sem
        // dígitos normaliza para vazio e casaria toda transação sem CHECKNUM.
        foreach ($this->criterios as $index => $criterio) {
            if (($criterio['tip_match'] ?? null) === 'CHECKNUM'
                && preg_replace('/\D/', '', (string) ($criterio['val_match'] ?? '')) === '') {
                $this->addError(
                    "criterios.{$index}.val_match",
                    'O CHECKNUM deve conter ao menos um dígito.'
                );
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $regra = $this->associacao->regrasClassificacao()->updateOrCreate(
            ['idt_regra' => $this->regraId],
            [
                'nom_regra' => $dados['nom_regra'],
                'des_efeito' => $dados['efeito'],
                'ind_ativo' => $dados['ind_ativo'],
            ],
        );

        $regra->criterios()->delete();
        $regra->criterios()->createMany($dados['criterios']);

        $this->dispatch('close-modal', name: 'regra-form');
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('messages.alerts.success.saved'));
    }

    public function alternarAtivo(int $id): void
    {
        $regra = $this->associacao->regrasClassificacao()->findOrFail($id);
        $regra->update(['ind_ativo' => ! $regra->ind_ativo]);

        Flux::toast(variant: 'success', text: __('messages.alerts.success.saved'));
    }

    public function excluirRegra(int $id): void
    {
        $regra = $this->associacao->regrasClassificacao()->findOrFail($id);
        $regra->criterios()->delete();
        $regra->delete();

        Flux::toast(variant: 'success', text: __('messages.alerts.success.deleted'));
    }

    public function fecharModal(): void
    {
        $this->dispatch('close-modal', name: 'regra-form');
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->regraId = null;
        $this->nom_regra = '';
        $this->efeito = '';
        $this->ind_ativo = true;
        $this->criterios = [['tip_match' => 'NOM_PESSOA', 'val_match' => '']];
    }

    public function with(): array
    {
        return [
            'regras' => $this->associacao->regrasClassificacao()
                ->withCount('criterios')
                ->orderBy('nom_regra')
                ->get(),
            'efeitos' => EfeitoRegra::cases(),
            'tiposMatch' => self::TIPOS_MATCH,
        ];
    }
}; ?>

<div class="space-y-6 p-6 max-w-7xl mx-auto">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                <flux:icon name="adjustments-horizontal" class="size-6 text-blue-600" /> Regras de Classificação
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                {{ $associacao->nom_associacao }}
            </p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <flux:button variant="ghost" icon="arrow-left" wire:navigate href="{{ route('associacoes.index') }}">
                Voltar
            </flux:button>
            <flux:button variant="primary" icon="plus" wire:click="novaRegra">
                Nova Regra
            </flux:button>
        </div>
    </div>

    {{-- Lista --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($regras as $regra)
            <flux:card class="flex flex-col justify-between p-5 space-y-4" wire:key="regra-{{ $regra->idt_regra }}">
                <div class="space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-bold text-lg text-neutral-800 dark:text-neutral-200">
                                {{ $regra->nom_regra }}
                            </h3>
                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                <flux:badge size="sm" color="blue" class="font-semibold">
                                    {{ $regra->des_efeito->label() }}
                                </flux:badge>
                                <flux:badge size="sm" :color="$regra->ind_ativo ? 'green' : 'zinc'" class="font-semibold">
                                    {{ $regra->ind_ativo ? 'Ativa' : 'Inativa' }}
                                </flux:badge>
                            </div>
                        </div>
                        <div class="flex gap-1 flex-shrink-0">
                            <flux:button wire:click="editarRegra({{ $regra->idt_regra }})" size="sm" variant="ghost" icon="pencil" aria-label="Editar regra" />
                            <flux:button wire:click="excluirRegra({{ $regra->idt_regra }})" wire:confirm="Deseja remover esta regra?" size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-700" aria-label="Remover regra" />
                        </div>
                    </div>

                    <div class="pt-2 border-t border-neutral-100 dark:border-neutral-800/60 space-y-2 text-xs text-neutral-600 dark:text-neutral-400">
                        <div class="flex justify-between">
                            <span class="text-neutral-400 dark:text-neutral-500">Critérios:</span>
                            <span class="font-medium text-neutral-800 dark:text-neutral-200">
                                {{ $regra->criterios_count }} {{ Str::plural('critério', $regra->criterios_count) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex pt-2 border-t border-neutral-100 dark:border-neutral-800/60">
                    <flux:switch
                        wire:click="alternarAtivo({{ $regra->idt_regra }})"
                        :checked="$regra->ind_ativo"
                        label="Ativa"
                    />
                </div>
            </flux:card>
        @empty
            <div class="col-span-full py-12 text-center text-zinc-400 bg-white border border-neutral-200 dark:border-neutral-700 dark:bg-zinc-900 rounded-xl shadow-xs">
                Nenhuma regra cadastrada.
            </div>
        @endforelse
    </div>

    {{-- Modal de formulário --}}
    <flux:modal name="regra-form" class="min-w-[400px] md:min-w-[560px]">
        <form wire:submit="salvar" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $regraId ? 'Editar Regra' : 'Nova Regra' }}</flux:heading>
                <flux:subheading>Defina o nome, o efeito e os critérios de correspondência da regra.</flux:subheading>
            </div>

            <flux:field>
                <flux:label for="nom_regra" required>Nome da Regra</flux:label>
                <flux:input id="nom_regra" wire:model="nom_regra" placeholder="Ex: Rende Fácil" required />
                <flux:error name="nom_regra" />
            </flux:field>

            <flux:field>
                <flux:label for="efeito" required>Efeito</flux:label>
                <flux:select id="efeito" wire:model="efeito" placeholder="Selecione o efeito...">
                    @foreach($efeitos as $ef)
                        <flux:select.option value="{{ $ef->value }}">{{ $ef->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="efeito" />
            </flux:field>

            <flux:field variant="inline">
                <flux:switch wire:model="ind_ativo" />
                <flux:label>Regra ativa</flux:label>
                <flux:error name="ind_ativo" />
            </flux:field>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <flux:label>Critérios</flux:label>
                    <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="adicionarCriterio">
                        Adicionar critério
                    </flux:button>
                </div>

                @error('criterios')
                    <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
                @enderror

                @foreach($criterios as $index => $criterio)
                    <div class="flex items-start gap-2" wire:key="criterio-{{ $index }}">
                        <div class="w-48 flex-shrink-0">
                            <flux:select wire:model="criterios.{{ $index }}.tip_match" aria-label="Tipo de correspondência">
                                @foreach($tiposMatch as $tipo)
                                    <flux:select.option value="{{ $tipo }}">{{ $tipo }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="criterios.{{ $index }}.tip_match" />
                        </div>
                        <div class="flex-1">
                            <flux:input wire:model="criterios.{{ $index }}.val_match" placeholder="Valor a comparar" aria-label="Valor do critério" />
                            <flux:error name="criterios.{{ $index }}.val_match" />
                        </div>
                        <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removerCriterio({{ $index }})" class="text-red-500 hover:text-red-700" aria-label="Remover critério" />
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="fecharModal">Cancelar</flux:button>
                <flux:button type="submit" variant="primary">Salvar</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
