<?php

use App\Models\Associacao;
use App\Models\Mensagem;
use App\Models\MensagemEnvio;
use App\Models\Membro;
use App\Models\Ofx;
use App\Models\Resumo;
use Livewire\Volt\Component;
use Livewire\Attributes\Computed;

new class extends Component {
    public ?int $associacaoId = null;
    public string $nom_campanha = '';
    public string $txt_mensagem = '';
    public string $tip_destinatario = 'A'; // A - Todos, D - Adimplentes, I - Inadimplentes

    public int $previewSpinCounter = 0;

    public function mount(): void
    {
        $primeiraAssociacao = Associacao::orderBy('nom_associacao', 'asc')->first();
        if ($primeiraAssociacao) {
            $this->associacaoId = $primeiraAssociacao->idt_associacao;
        }
        $this->txt_mensagem = "{Olá|Oi|Tudo bem?}, {nome}! Confirmamos que seu cadastro na associação {associacao} está atualizado.";
        $this->nom_campanha = "Informativo Geral - " . ($primeiraAssociacao ? $primeiraAssociacao->nom_associacao : '');
    }

    public function updatedAssociacaoId($value): void
    {
        $associacao = Associacao::find($value);
        if ($associacao) {
            $this->nom_campanha = "Informativo Geral - " . $associacao->nom_associacao;
        }
    }

    public function girarPrevia(): void
    {
        $this->previewSpinCounter++;
    }

    #[Computed]
    public function associacoes(): \Illuminate\Database\Eloquent\Collection
    {
        return Associacao::orderBy('nom_associacao', 'asc')->get();
    }

    #[Computed]
    public function destinatariosEstimados(): array
    {
        return $this->obterDestinatarios();
    }

    #[Computed]
    public function previewText(): string
    {
        $spin = $this->previewSpinCounter;

        if (!$this->txt_mensagem) {
            return 'Digite uma mensagem para ver a prévia...';
        }

        $data = [
            'nome' => 'Maria Silva',
            'apelido' => 'Mari',
            'associacao' => 'Associação Exemplo',
            'participante' => 'Maria Silva',
            'responsavel_nome' => '',
        ];

        if ($this->associacaoId) {
            $associacao = Associacao::find($this->associacaoId);
            $data['associacao'] = $associacao->nom_associacao;

            $dest = $this->obterDestinatarios();
            if (!empty($dest)) {
                $primeiro = $dest[0];
                $data['nome'] = $primeiro['nom_destinatario'];
            }
        }

        return Mensagem::formatar($this->txt_mensagem, $data);
    }

    public function criarCampanha()
    {
        $this->validate([
            'associacaoId' => 'required|exists:associacoes,idt_associacao',
            'nom_campanha' => 'required|string|max:150',
            'txt_mensagem' => 'required|string',
            'tip_destinatario' => 'required|in:A,D,I',
        ]);

        $destinatarios = $this->obterDestinatarios();

        if (count($destinatarios) === 0) {
            $this->dispatch('toast', message: 'Nenhum destinatário com telefone válido foi encontrado.', variant: 'danger');
            return;
        }

        $mensagem = Mensagem::create([
            'idt_associacao' => $this->associacaoId,
            'usu_inclusao' => auth()->id(),
            'nom_campanha' => $this->nom_campanha,
            'txt_mensagem' => $this->txt_mensagem,
            'tip_destinatario' => $this->tip_destinatario,
            'qtd_impactados' => count($destinatarios),
        ]);

        foreach ($destinatarios as $dest) {
            MensagemEnvio::create([
                'idt_mensagem' => $mensagem->idt_mensagem,
                'nom_destinatario' => $dest['nom_destinatario'],
                'tel_destinatario' => $dest['tel_destinatario'],
                'nom_responsavel' => null,
                'ind_enviado' => false,
                'dat_envio' => null,
            ]);
        }

        $this->dispatch('toast', message: 'Campanha de WhatsApp configurada com sucesso!', variant: 'success');

        return redirect()->route('mensagens.show', ['mensagem' => $mensagem->idt_mensagem]);
    }

    private function obterDestinatarios(): array
    {
        if (!$this->associacaoId) {
            return [];
        }

        $destinatarios = [];
        $latestOfx = Ofx::latest()->first();
        $membros = Membro::where('idt_associacao', $this->associacaoId)->get();

        foreach ($membros as $membro) {
            $telefone = $membro->tel_membro;
            if (!$telefone) {
                continue;
            }

            $isOverdue = false;
            if ($latestOfx) {
                $isOverdue = Resumo::where('idt_ofx', $latestOfx->idt_ofx)
                    ->where('nom_pessoa', $membro->nomeParaMatchingOfx())
                    ->where('ind_pago', false)
                    ->exists();
            }

            if ($this->tip_destinatario === 'D' && $isOverdue) {
                continue;
            }
            if ($this->tip_destinatario === 'I' && !$isOverdue) {
                continue;
            }

            $destinatarios[] = [
                'nom_destinatario' => $membro->nom_membro,
                'tel_destinatario' => \App\Services\PhoneService::clean($telefone),
                'nom_responsavel' => null,
            ];
        }

        return $destinatarios;
    }
}; ?>

<div class="space-y-6 w-full max-w-4xl mx-auto p-4 md:p-8">
    <div class="flex items-center gap-2 text-sm text-zinc-500">
        <a href="{{ route('mensagens.index') }}" class="hover:underline" wire:navigate>Mensagens</a>
        <span>/</span>
        <span>Nova Mensagem</span>
    </div>

    <div>
        <flux:heading size="xl">Configurar Nova Mensagem</flux:heading>
        <flux:subheading>Escolha a associação, defina o público e elabore um modelo com mensagens rotativas (Spintax) anti-bloqueio.</flux:subheading>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-8 items-start">
        <flux:card class="xl:col-span-2 space-y-6 border border-zinc-200 dark:border-zinc-700 shadow-sm rounded-xl">
            <form wire:submit="criarCampanha" class="space-y-6">
                <flux:select wire:model.live="associacaoId" label="Associação Associada">
                    <option value="">Selecione uma associação...</option>
                    @foreach ($this->associacoes as $assoc)
                        <option value="{{ $assoc->idt_associacao }}">
                            {{ $assoc->nom_associacao }}
                        </option>
                    @endforeach
                </flux:select>

                <flux:radio.group wire:model.live="tip_destinatario" label="Público-Alvo" variant="cards" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <flux:radio value="A" label="Todos" description="Envia diretamente aos contatos de todos os associados." />
                    <flux:radio value="D" label="Adimplentes" description="Envia para os associados com contribuições em dia." />
                    <flux:radio value="I" label="Inadimplentes" description="Envia para os associados com contribuições pendentes." />
                </flux:radio.group>

                <flux:input wire:model="nom_campanha" label="Título da Campanha" placeholder="Ex: Aviso Importante - Mensalidades" />

                <flux:textarea wire:model.live="txt_mensagem" label="Mensagem (Template)" rows="6" placeholder="Digite a mensagem..." />
                
                <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-xs space-y-2 text-zinc-600 dark:text-zinc-400">
                    <p class="font-bold flex items-center gap-1 text-zinc-800 dark:text-zinc-200">
                        <flux:icon.information-circle class="size-4" /> Dicas de Customização:
                    </p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li><strong>{nome}</strong>: Nome do associado.</li>
                        <li><strong>{associacao}</strong>: Nome da associação.</li>
                        <li><strong>{Saudação1|Saudação2}</strong>: Blocos dinâmicos rotativos (ex: <code>{Olá|Oi|Bom dia}</code>) para evitar bloqueio por spam.</li>
                    </ul>
                </div>

                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 border-t border-zinc-200 dark:border-zinc-700 pt-6">
                    <div class="text-sm">
                        <span class="text-zinc-500">Destinatários estimados:</span>
                        <span class="font-bold text-zinc-800 dark:text-zinc-200">
                            {{ count($this->destinatariosEstimados) }} contatos válidos
                        </span>
                    </div>

                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto">
                        Criar e Iniciar Envios
                    </flux:button>
                </div>
            </form>
        </flux:card>

        {{-- Preview Panel --}}
        <div class="space-y-4 lg:sticky lg:top-8">
            <flux:heading size="md" class="flex justify-between items-center">
                <span>Prévia do Envio</span>
                @if (str_contains($txt_mensagem, '|'))
                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="girarPrevia">
                        Girar Spintax
                    </flux:button>
                @endif
            </flux:heading>
            
            <div class="relative bg-[#efeae2] dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden shadow-sm h-72">
                <div class="bg-[#075e54] dark:bg-zinc-800 text-white px-4 py-3 flex items-center gap-3">
                    <div class="size-8 rounded-full bg-zinc-300 dark:bg-zinc-600 flex items-center justify-center font-bold text-xs text-[#075e54]">W</div>
                    <div>
                        <div class="font-bold text-xs">WhatsApp Preview</div>
                        <div class="text-3xs text-emerald-100">online</div>
                    </div>
                </div>
                <div class="p-4 h-[calc(100%-60px)] overflow-y-auto bg-[url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png')] bg-repeat bg-contain dark:bg-none">
                    <div class="max-w-[85%] bg-white dark:bg-zinc-800 dark:text-zinc-100 p-3 rounded-lg shadow-sm text-xs rounded-tl-none border border-zinc-100 dark:border-zinc-700 leading-relaxed whitespace-pre-wrap">
                        {{ $this->previewText }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
