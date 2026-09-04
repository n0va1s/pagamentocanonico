<?php

use App\Models\Associacao;
use Livewire\Volt\Component;
use Illuminate\Validation\Rule;

new class extends Component {
    public ?Associacao $associacao = null;

    public string $novaAssociacaoNome = '';
    public string $novaAssociacaoTelefone = '';
    public string $novaAssociacaoPix = '';
    public ?string $novaAssociacaoTaxa = null;
    public ?string $novaAssociacaoAnual = null;
    public ?string $novaAssociacaoInicioCobranca = '2025-01-01';

    public function mount(?Associacao $associacao = null): void
    {
        $this->associacao = $associacao;

        if ($associacao?->exists) {
            $this->novaAssociacaoNome = $associacao->nom_associacao;
            $this->novaAssociacaoTelefone = $associacao->tel_contato ?? '';
            $this->novaAssociacaoPix = $associacao->chave_pix ?? '';
            $this->novaAssociacaoInicioCobranca = $associacao->dat_inicio_cobranca?->format('Y-m-d') ?? '2025-01-01';
            $taxaVigente = $associacao->getTaxaVigenteEm();
            $this->novaAssociacaoTaxa = $taxaVigente?->val_taxa;
            $this->novaAssociacaoAnual = $taxaVigente?->val_anual;
        }
    }

    public function salvar(): void
    {
        $id = $this->associacao?->idt_associacao;

        $this->validate([
            'novaAssociacaoNome' => [
                'required', 'string', 'min:3', 'max:100', 
                Rule::unique('associacoes', 'nom_associacao')->ignore($id, 'idt_associacao')
            ],
            'novaAssociacaoTelefone' => ['nullable', 'string', 'max:20'],
            'novaAssociacaoPix' => ['nullable', 'string', 'max:100'],
            'novaAssociacaoTaxa' => ['nullable', 'numeric', 'min:0'],
            'novaAssociacaoAnual' => ['nullable', 'numeric', 'min:0'],
            'novaAssociacaoInicioCobranca' => ['nullable', 'date'],
        ], [
            'novaAssociacaoNome.required' => 'O nome da associação é obrigatório.',
            'novaAssociacaoNome.unique' => 'Esta associação já está cadastrada.',
        ]);

        $dados = [
            'nom_associacao' => $this->novaAssociacaoNome,
            'tel_contato' => $this->novaAssociacaoTelefone,
            'chave_pix' => $this->novaAssociacaoPix,
            'dat_inicio_cobranca' => $this->novaAssociacaoInicioCobranca ?: null,
        ];

        $isNew = !$this->associacao?->exists;

        if ($this->associacao?->exists) {
            $this->associacao->update($dados);
            $associacao = $this->associacao;
        } else {
            $associacao = Associacao::create($dados);
        }

        $taxaVigente = $associacao->getTaxaVigenteEm();
        $novaTaxa = $this->novaAssociacaoTaxa ?: null;
        $novaAnual = $this->novaAssociacaoAnual ?: null;

        if (!$taxaVigente || $taxaVigente->val_taxa != $novaTaxa || $taxaVigente->val_anual != $novaAnual) {
            if ($taxaVigente) {
                $taxaVigente->update(['dat_fim' => now()->subDay()]);
            }
            $associacao->taxas()->create([
                'val_taxa' => $novaTaxa,
                'val_anual' => $novaAnual,
                'dat_inicio' => now(),
            ]);
        }

        \Flux::toast(variant: 'success', text: __('messages.alerts.success.saved'));

        if ($isNew) {
            $this->redirectRoute('associacoes.index', navigate: true);
        }
    }
}; ?>

<div>
    <form wire:submit="salvar" class="space-y-6">
        <flux:card class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:field class="md:col-span-2">
                    <flux:label for="novaAssociacaoNome" required>Nome da Associação</flux:label>
                    <flux:input id="novaAssociacaoNome" wire:model="novaAssociacaoNome" placeholder="Ex: Associação Canônica..." required />
                    <flux:error name="novaAssociacaoNome" />
                </flux:field>

                <flux:field>
                    <flux:label for="novaAssociacaoTelefone">Telefone de Contato</flux:label>
                    <flux:input id="novaAssociacaoTelefone" wire:model="novaAssociacaoTelefone" placeholder="Ex: (11) 99999-9999" />
                    <flux:error name="novaAssociacaoTelefone" />
                </flux:field>

                <flux:field>
                    <flux:label for="novaAssociacaoPix">Chave PIX</flux:label>
                    <flux:input id="novaAssociacaoPix" wire:model="novaAssociacaoPix" placeholder="Ex: email@dominio.com" />
                    <flux:error name="novaAssociacaoPix" />
                </flux:field>

                <flux:field>
                    <flux:label for="novaAssociacaoTaxa">Valor da Mensalidade (R$)</flux:label>
                    <flux:input id="novaAssociacaoTaxa" wire:model="novaAssociacaoTaxa" type="number" step="0.01" placeholder="Ex: 50.00" />
                    <flux:error name="novaAssociacaoTaxa" />
                </flux:field>

                <flux:field>
                    <flux:label for="novaAssociacaoAnual">Valor da Anuidade (R$)</flux:label>
                    <flux:input id="novaAssociacaoAnual" wire:model="novaAssociacaoAnual" type="number" step="0.01" placeholder="Ex: 500.00" />
                    <flux:error name="novaAssociacaoAnual" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label for="novaAssociacaoInicioCobranca">Início das Cobranças no Sistema (Marco Inicial)</flux:label>
                    <flux:input id="novaAssociacaoInicioCobranca" wire:model="novaAssociacaoInicioCobranca" type="date" />
                    <flux:description>Data a partir da qual o sistema começa a exigir mensalidades dos associados (membros antigos não terão cobranças retroativas anteriores a esta data).</flux:description>
                    <flux:error name="novaAssociacaoInicioCobranca" />
                </flux:field>
            </div>
        </flux:card>

        <div class="flex items-center justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:navigate href="{{ route('associacoes.index') }}">Cancelar</flux:button>
            <flux:button type="submit" variant="primary">Salvar</flux:button>
        </div>
    </form>
</div>
