<?php

use App\Models\Membro;
use App\Models\Resumo;
use App\Models\Ofx;
use Livewire\Volt\Component;
use Livewire\Attributes\Title;
use App\Services\Notifications\Channels\TelegramChannel;

new #[Title('Minha Associação')] class extends Component {
    public string $activeTab = 'pagamentos';

    // Member profile form fields
    public string $nom_membro = '';
    public ?string $nom_ofx = null;
    public ?string $tel_membro = null;
    public ?string $end_logradouro = null;
    public ?string $end_mumero = null;
    public ?string $end_complemento = null;
    public ?string $des_telegram_chat_id = null;
    public bool $ind_notificar_whatsapp = true;
    public bool $ind_notificar_email = true;
    public bool $ind_notificar_telegram = false;

    // Contact form fields
    public string $contactName = '';
    public string $contactEmail = '';
    public string $contactMessage = '';

    public function mount(): void
    {
        $membro = Membro::where('eml_membro', auth()->user()->email)->first();
        if ($membro) {
            $this->nom_membro = $membro->nom_membro;
            $this->nom_ofx = $membro->nom_ofx;
            $this->tel_membro = $membro->tel_membro;
            $this->end_logradouro = $membro->end_logradouro;
            $this->end_mumero = $membro->end_mumero;
            $this->end_complemento = $membro->end_complemento;
            $this->des_telegram_chat_id = $membro->des_telegram_chat_id;
            $this->ind_notificar_whatsapp = (bool)$membro->ind_notificar_whatsapp;
            $this->ind_notificar_email = (bool)$membro->ind_notificar_email;
            $this->ind_notificar_telegram = (bool)$membro->ind_notificar_telegram;

            $this->contactName = $membro->nom_membro;
            $this->contactEmail = $membro->eml_membro;
        } else {
            $this->contactName = auth()->user()->name;
            $this->contactEmail = auth()->user()->email;
        }
    }

    /**
     * Update member profile
     */
    public function updateProfile(): void
    {
        $membro = Membro::where('eml_membro', auth()->user()->email)->first();
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
            'ind_notificar_whatsapp' => 'required|boolean',
            'ind_notificar_email' => 'required|boolean',
            'ind_notificar_telegram' => 'required|boolean',
        ]);

        $membro->update([
            'nom_membro' => $this->nom_membro,
            'nom_ofx' => $this->nom_ofx ?: null,
            'tel_membro' => $this->tel_membro,
            'end_logradouro' => $this->end_logradouro,
            'end_mumero' => $this->end_mumero,
            'end_complemento' => $this->end_complemento,
            'des_telegram_chat_id' => $this->des_telegram_chat_id,
            'ind_notificar_whatsapp' => $this->ind_notificar_whatsapp,
            'ind_notificar_email' => $this->ind_notificar_email,
            'ind_notificar_telegram' => $this->ind_notificar_telegram,
        ]);

        $this->dispatch('toast', message: 'Seus dados foram atualizados com sucesso!', variant: 'success');
    }

    /**
     * Submit Contact Form (sends alert via Telegram)
     */
    public function submitContact(): void
    {
        $this->validate([
            'contactName' => 'required|string|max:100',
            'contactEmail' => 'required|email|max:100',
            'contactMessage' => 'required|string|max:1000',
        ]);

        // 1. Salvar no banco de dados
        \App\Models\Contato::create([
            'nome' => $this->contactName,
            'email' => $this->contactEmail,
            'mensagem' => $this->contactMessage,
        ]);

        // 2. Enviar ao Telegram
        $telegram = app(TelegramChannel::class);
        $res = $telegram->sendContactRequest(
            $this->contactName,
            $this->contactEmail,
            $this->contactMessage
        );

        if ($res['success']) {
            $this->dispatch('toast', message: 'Contato enviado com sucesso via Telegram!', variant: 'success');
            $this->reset(['contactMessage']);
        } else {
            $this->dispatch('toast', message: 'Contato registrado no painel. (Falha temporária ao enviar ao Telegram)', variant: 'warning');
            $this->reset(['contactMessage']);
        }
    }

    /**
     * Request discharge for specific resumo debt
     */
    public function requestDischarge(int $resumoId): void
    {
        $membro = Membro::where('eml_membro', auth()->user()->email)->first();
        if (!$membro) {
            $this->dispatch('toast', message: 'Membro não cadastrado.', variant: 'danger');
            return;
        }

        $resumo = Resumo::findOrFail($resumoId);
        if ($resumo->nom_pessoa !== $membro->nomeParaMatchingOfx()) {
            $this->dispatch('toast', message: 'Acesso negado para este débito.', variant: 'danger');
            return;
        }

        $botToken = config('services.telegram.bot_token', '');
        $chatId = config('services.telegram.contact_chat_id') ?: env('TELEGRAM_CONTACT_CHAT_ID', '');

        if (empty($chatId)) {
            $this->dispatch('toast', message: 'Chat ID de contato administrativo não configurado.', variant: 'danger');
            return;
        }

        $texto = implode("\n", [
            "📄 <b>Solicitação de Quitação de Débito</b>",
            "👤 <b>Associado:</b> {$membro->nom_membro} ({$membro->eml_membro})",
            "📅 <b>Competência:</b> {$resumo->nom_mes}/{$resumo->num_ano}",
            "💰 <b>Valor:</b> R$ " . number_format($resumo->val_total, 2, ',', '.'),
            "✉️ Enviado via Minha Associação."
        ]);

        try {
            $response = \Illuminate\Support\Facades\Http::post(
                "https://api.telegram.org/bot{$botToken}/sendMessage",
                [
                    'chat_id' => $chatId,
                    'text' => $texto,
                    'parse_mode' => 'HTML',
                ]
            );

            if ($response->successful()) {
                $this->dispatch('toast', message: 'Solicitação de quitação enviada com sucesso!', variant: 'success');
            } else {
                $this->dispatch('toast', message: 'Erro ao enviar solicitação ao Telegram.', variant: 'danger');
            }
        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'Erro: ' . $e->getMessage(), variant: 'danger');
        }
    }

    public function with(): array
    {
        $membro = Membro::where('eml_membro', auth()->user()->email)->first();
        $resumosPendentes = collect();
        $resumosRegularizados = collect();

        if ($membro) {
            $nomeMatching = $membro->nomeParaMatchingOfx();
            $todosResumos = Resumo::where('nom_pessoa', $nomeMatching)
                ->orderByDesc('num_ano')
                ->orderByDesc('num_mes')
                ->get();

            $resumosPendentes = $todosResumos->where('ind_pago', false);
            $resumosRegularizados = $todosResumos->where('ind_pago', true);
        }

        return [
            'membro' => $membro,
            'resumosPendentes' => $resumosPendentes,
            'resumosRegularizados' => $resumosRegularizados,
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    {{-- Toast alerts --}}
    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Minha Associação</flux:heading>
    </div>

    @if(!$membro)
        {{-- Mensagem de membro não encontrado --}}
        <flux:card class="max-w-2xl mx-auto p-8 space-y-6 text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-yellow-50 dark:bg-yellow-950/20">
                <flux:icon name="exclamation-triangle" class="size-8 text-yellow-600" />
            </div>
            <div class="space-y-2">
                <h3 class="text-lg font-bold text-neutral-800 dark:text-neutral-100">Cadastro não localizado</h3>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                    O seu e-mail de login (<span class="font-semibold text-neutral-700 dark:text-neutral-300">{{ auth()->user()->email }}</span>) não está associado a nenhum membro cadastrado no sistema.
                </p>
            </div>

            <div class="border-t border-neutral-100 dark:border-neutral-800 pt-6 text-left space-y-4">
                <div>
                    <h4 class="text-sm font-bold text-neutral-800 dark:text-neutral-200">Solicitar Registro de Associado</h4>
                    <p class="text-xs text-neutral-500">Envie uma mensagem para que a administração possa cadastrar o seu perfil de membro.</p>
                </div>
                <form wire:submit="submitContact" class="space-y-4">
                    <flux:input label="Seu Nome" wire:model="contactName" />
                    <flux:input label="Seu E-mail" wire:model="contactEmail" disabled />
                    <flux:textarea label="Mensagem" wire:model="contactMessage" placeholder="Olá, gostaria de solicitar a vinculação do meu e-mail à minha conta de associado..." rows="4" />
                    <flux:button type="submit" variant="primary" class="w-full" icon="paper-airplane">Enviar Solicitação</flux:button>
                </form>
            </div>
        </flux:card>
    @else
        {{-- Painel de Associado Encontrado --}}
        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Tabs de Navegação --}}
            <div class="lg:col-span-3">
                <div class="flex gap-4 border-b border-zinc-200 dark:border-zinc-700 pb-2">
                    <button wire:click="$set('activeTab', 'pagamentos')" class="pb-2 border-b-2 text-sm transition {{ $activeTab === 'pagamentos' ? 'border-blue-600 font-semibold text-blue-600' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                        Meus Pagamentos
                    </button>
                    <button wire:click="$set('activeTab', 'perfil')" class="pb-2 border-b-2 text-sm transition {{ $activeTab === 'perfil' ? 'border-blue-600 font-semibold text-blue-600' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                        Meus Dados
                    </button>
                    <button wire:click="$set('activeTab', 'contato')" class="pb-2 border-b-2 text-sm transition {{ $activeTab === 'contato' ? 'border-blue-600 font-semibold text-blue-600' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                        Fale com a Administração
                    </button>
                </div>
            </div>

            {{-- Conteúdo Esquerda (Pagamentos ou Formulários) --}}
            <div class="lg:col-span-2 space-y-6">
                @if($activeTab === 'pagamentos')
                    {{-- Pagamentos Pendentes --}}
                    <flux:card class="p-0 overflow-hidden">
                        <div class="border-b border-neutral-100 px-5 py-4 dark:border-neutral-800 bg-red-50/20 dark:bg-red-950/5">
                            <h3 class="text-sm font-bold uppercase tracking-wider text-red-700 dark:text-red-400 flex items-center gap-2">
                                <flux:icon name="exclamation-circle" class="size-4" /> Pagamentos Pendentes
                            </h3>
                        </div>
                        <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @forelse($resumosPendentes as $pendente)
                                <div class="flex items-center justify-between p-4 hover:bg-neutral-50/50 dark:hover:bg-zinc-800/10">
                                    <div>
                                        <p class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $pendente->nom_mes }}/{{ $pendente->num_ano }}</p>
                                        <p class="text-xs text-neutral-500">Valor mensal esperado: R$ {{ number_format($pendente->val_total, 2, ',', '.') }}</p>
                                    </div>
                                    <flux:button wire:click="requestDischarge({{ $pendente->idt_resumo }})" size="xs" variant="primary" icon="document-text" wire:confirm="Deseja solicitar a quitação deste débito para a administração?">
                                        Solicitar Quitação
                                    </flux:button>
                                </div>
                            @empty
                                <div class="p-8 text-center text-zinc-400 text-sm">
                                    Parabéns! Você não possui nenhuma pendência registrada.
                                </div>
                            @endforelse
                        </div>
                    </flux:card>

                    {{-- Histórico de Pagamentos Regularizados --}}
                    <flux:card class="p-0 overflow-hidden">
                        <div class="border-b border-neutral-100 px-5 py-4 dark:border-neutral-800 bg-neutral-50/50 dark:bg-zinc-800/20">
                            <h3 class="text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300 flex items-center gap-2">
                                <flux:icon name="check-circle" class="size-4 text-green-600" /> Histórico de Pagamentos Regularizados
                            </h3>
                        </div>
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Competência</flux:table.column>
                                <flux:table.column>Valor Pago</flux:table.column>
                                <flux:table.column>Transações</flux:table.column>
                                <flux:table.column>Situação</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @forelse($resumosRegularizados as $pago)
                                    <flux:table.row wire:key="{{ $pago->idt_resumo }}">
                                        <flux:table.cell class="font-medium">
                                            {{ $pago->nom_mes }}/{{ $pago->num_ano }}
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            R$ {{ number_format($pago->val_total, 2, ',', '.') }}
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            {{ $pago->num_transacao }} depósito(s)
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <flux:badge color="green" size="sm" icon="check">Pago</flux:badge>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @empty
                                    <flux:table.row>
                                        <flux:table.cell colspan="4" class="py-12 text-center text-zinc-400">
                                            Nenhum histórico de pagamento localizado.
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforelse
                            </flux:table.rows>
                        </flux:table>
                    </flux:card>
                @endif

                @if($activeTab === 'perfil')
                    {{-- Formulário de Atualização de Dados --}}
                    <flux:card class="p-6">
                        <div class="mb-6">
                            <h3 class="text-md font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300">
                                Atualizar Meus Dados
                            </h3>
                            <p class="text-xs text-neutral-500">Mantenha suas informações cadastrais e preferências de notificação atualizadas.</p>
                        </div>

                        <form wire:submit="updateProfile" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <flux:field class="md:col-span-2">
                                    <flux:label required for="nom_membro">Nome Completo</flux:label>
                                    <flux:input id="nom_membro" wire:model="nom_membro" />
                                    <flux:error name="nom_membro" />
                                </flux:field>

                                <flux:field class="md:col-span-2">
                                    <flux:label for="nom_ofx">Nome no Extrato (Se diferente do acima)</flux:label>
                                    <flux:input id="nom_ofx" wire:model="nom_ofx" />
                                    <flux:error name="nom_ofx" />
                                </flux:field>

                                <flux:field>
                                    <flux:label for="tel_membro">Telefone / WhatsApp</flux:label>
                                    <flux:input id="tel_membro" wire:model="tel_membro" placeholder="(11) 99999-9999" />
                                    <flux:error name="tel_membro" />
                                </flux:field>

                                <flux:field>
                                    <flux:label for="des_telegram_chat_id">Telegram Chat ID</flux:label>
                                    <flux:input id="des_telegram_chat_id" wire:model="des_telegram_chat_id" placeholder="Ex: 12345678" />
                                    <flux:error name="des_telegram_chat_id" />
                                </flux:field>

                                <flux:field class="md:col-span-2">
                                    <flux:label for="end_logradouro">Logradouro (Rua, Avenida, etc.)</flux:label>
                                    <flux:input id="end_logradouro" wire:model="end_logradouro" />
                                    <flux:error name="end_logradouro" />
                                </flux:field>

                                <flux:field>
                                    <flux:label for="end_mumero">Número</flux:label>
                                    <flux:input id="end_mumero" wire:model="end_mumero" />
                                    <flux:error name="end_mumero" />
                                </flux:field>

                                <flux:field>
                                    <flux:label for="end_complemento">Bairro / Complemento</flux:label>
                                    <flux:input id="end_complemento" wire:model="end_complemento" />
                                    <flux:error name="end_complemento" />
                                </flux:field>

                                <div class="md:col-span-2 space-y-3 pt-2">
                                    <h4 class="text-xs font-semibold text-neutral-600 dark:text-neutral-400">Preferências de Notificações</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <label class="inline-flex items-center text-sm font-medium text-neutral-700 dark:text-neutral-300 cursor-pointer">
                                            <input type="checkbox" wire:model="ind_notificar_whatsapp" class="rounded border-neutral-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 mr-2">
                                            WhatsApp
                                        </label>
                                        <label class="inline-flex items-center text-sm font-medium text-neutral-700 dark:text-neutral-300 cursor-pointer">
                                            <input type="checkbox" wire:model="ind_notificar_email" class="rounded border-neutral-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 mr-2">
                                            E-mail
                                        </label>
                                        <label class="inline-flex items-center text-sm font-medium text-neutral-700 dark:text-neutral-300 cursor-pointer">
                                            <input type="checkbox" wire:model="ind_notificar_telegram" class="rounded border-neutral-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 mr-2">
                                            Telegram
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <flux:button type="submit" variant="primary" class="w-full" icon="check">Salvar Alterações</flux:button>
                        </form>
                    </flux:card>
                @endif

                @if($activeTab === 'contato')
                    {{-- Fale com a Administração --}}
                    <flux:card class="p-6">
                        <div class="mb-6">
                            <h3 class="text-md font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300 flex items-center gap-2">
                                <flux:icon name="envelope" class="size-5 text-blue-600" /> Fale com a Administração
                            </h3>
                            <p class="text-xs text-neutral-500">Mande uma solicitação direta aos administradores do sistema.</p>
                        </div>
                        <form wire:submit="submitContact" class="space-y-4">
                            <flux:input label="Seu Nome" wire:model="contactName" disabled />
                            <flux:input label="Seu E-mail" wire:model="contactEmail" disabled />
                            <flux:textarea label="Mensagem" wire:model="contactMessage" rows="5" placeholder="Escreva sua solicitação para a diretoria..." />
                            
                            <flux:button type="submit" variant="primary" class="w-full" icon="paper-airplane">Enviar Solicitação</flux:button>
                        </form>
                    </flux:card>
                @endif
            </div>

            {{-- Coluna Direita - Informações Rápidas --}}
            <div class="space-y-6">
                {{-- Card de Informações Gerais do Associado --}}
                <flux:card class="p-5 space-y-4">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300 border-b border-neutral-100 dark:border-neutral-800 pb-2">
                        Resumo do Perfil
                    </h3>
                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="text-xs text-neutral-400 block">Tipo de Associação</span>
                            <flux:badge class="uppercase" size="sm">{{ $membro->tip_associado->label() }}</flux:badge>
                        </div>
                        <div>
                            <span class="text-xs text-neutral-400 block">Nome Registrado</span>
                            <span class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $membro->nom_membro }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-neutral-400 block">E-mail Cadastrado</span>
                            <span class="text-neutral-700 dark:text-neutral-300">{{ $membro->eml_membro }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-neutral-400 block">Data de Cadastro</span>
                            <span class="text-neutral-700 dark:text-neutral-300">{{ $membro->created_at->format('d/m/Y') }}</span>
                        </div>
                    </div>
                </flux:card>

                {{-- Status Financeiro Rápido --}}
                <flux:card class="p-5 space-y-3">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300 border-b border-neutral-100 dark:border-neutral-800 pb-2">
                        Situação Financeira
                    </h3>
                    <div class="flex items-center gap-3">
                        @if($resumosPendentes->count() > 0)
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/20">
                                <flux:icon name="exclamation-triangle" class="size-5" />
                            </div>
                            <div>
                                <p class="text-xs text-neutral-400">Status OFX</p>
                                <p class="text-sm font-bold text-red-600">Inadimplente</p>
                                <p class="text-xs text-neutral-500">{{ $resumosPendentes->count() }} pendência(s)</p>
                            </div>
                        @else
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-950/20">
                                <flux:icon name="check" class="size-5" />
                            </div>
                            <div>
                                <p class="text-xs text-neutral-400">Status OFX</p>
                                <p class="text-sm font-bold text-green-600">Regularizado</p>
                                <p class="text-xs text-neutral-500">Sem débitos pendentes</p>
                            </div>
                        @endif
                    </div>
                </flux:card>
            </div>
        </div>
    @endif
</div>
