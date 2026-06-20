<?php

use App\Enums\Perfil;
use App\Enums\TipoNotificacao;
use App\Models\Membro;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Notificacao;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use App\Services\Notifications\Channels\EmailChannel;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $busca        = '';
    public string $tip_associado = '';

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function updatedTipAssociado(): void
    {
        $this->resetPage();
    }

    public function excluir(int $id): void
    {
        $membro = Membro::findOrFail($id);
        $membro->delete();

        $this->dispatch('toast', message: 'Membro removido com sucesso!', variant: 'success');
    }

    public function alertMember(int $membroId, string $channel): void
    {
        $membro = Membro::findOrFail($membroId);
        $dispatcher = app(NotificationDispatcher::class);

        $selectedImport = Ofx::latest()->first();
        if (!$selectedImport) {
            $this->dispatch('toast', message: 'Nenhuma importação encontrada.', variant: 'danger');
            return;
        }

        $atrasado = Resumo::where('idt_ofx', $selectedImport->idt_ofx)
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
        $latestOfx = Ofx::latest()->first();

        $membros = Membro::query()
            ->when($this->busca, fn ($q) =>
                $q->where('nom_membro', 'like', "%{$this->busca}%")
                  ->orWhere('eml_membro', 'like', "%{$this->busca}%")
            )
            ->when($this->tip_associado, fn ($q) =>
                $q->where('tip_associado', $this->tip_associado)
            )
            ->orderBy('nom_membro')
            ->paginate(15);

        $membros->getCollection()->transform(function ($m) use ($latestOfx) {
            $m->overdue = false;
            if ($latestOfx) {
                $m->overdue = Resumo::where('idt_ofx', $latestOfx->idt_ofx)
                    ->where('nom_pessoa', $m->nomeParaMatchingOfx())
                    ->where('ind_pago', false)
                    ->exists();
            }
            $m->last_notification = Notificacao::where('idt_membro', $m->idt_membro)->latest()->first();
            return $m;
        });

        return [
            'membros'        => $membros,
            'tiposAssociado' => Perfil::cases(),
        ];
    }
}; ?>

<div class="space-y-4" x-data="{}" x-on:open-wa-link.window="window.open($event.detail.url, '_blank')">

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Membros</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('membros.create')" wire:navigate>
            Novo membro
        </flux:button>
    </div>

    {{-- Filtros --}}
    <flux:card class="flex flex-col sm:flex-row gap-3">
        <div class="flex-1">
            <flux:input
                 wire:model.live.debounce.300ms="busca"
                 placeholder="Buscar por nome ou e-mail..."
                 icon="magnifying-glass"
                 clearable
            />
        </div>
        <div class="sm:w-52">
            <flux:select wire:model.live="tip_associado">
                <flux:select.option value="">Todos os tipos</flux:select.option>
                @foreach ($tiposAssociado as $tipo)
                    <flux:select.option value="{{ $tipo->value }}">
                        {{ $tipo->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </flux:card>

    {{-- Tabela --}}
    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Membro</flux:table.column>
                <flux:table.column>Contato</flux:table.column>
                <flux:table.column>Tipo</flux:table.column>
                <flux:table.column>Status OFX</flux:table.column>
                <flux:table.column>Último Alerta</flux:table.column>
                <flux:table.column class="text-right">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($membros as $membro)
                    <flux:table.row wire:key="{{ $membro->idt_membro }}">

                        <flux:table.cell class="font-medium">
                            <p class="font-semibold text-neutral-800 dark:text-neutral-200">{{ $membro->nom_membro }}</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ Str::limit($membro->end_logradouro, 35) }}</p>
                        </flux:table.cell>

                        <flux:table.cell class="text-xs text-neutral-600 dark:text-neutral-400">
                            <div class="space-y-0.5">
                                @if($membro->eml_membro)<div><i class="fa-solid fa-envelope mr-1 w-4 text-neutral-400"></i>{{ $membro->eml_membro }}</div>@endif
                                @if($membro->tel_membro)<div><i class="fa-brands fa-whatsapp mr-1 w-4 text-green-500"></i>{{ $membro->tel_membro }}</div>@endif
                                @if($membro->des_telegram_chat_id)<div><i class="fa-brands fa-telegram mr-1 w-4 text-sky-500"></i>{{ $membro->des_telegram_chat_id }}</div>@endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" class="uppercase">
                                {{ $membro->tip_associado->label() }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($membro->overdue)
                                <span class="text-xs font-bold text-red-600"><i class="fa-solid fa-circle mr-1 text-[8px]"></i>Inadimplente</span>
                            @else
                                <span class="text-xs font-bold text-green-600"><i class="fa-solid fa-circle mr-1 text-[8px]"></i>Regular</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-xs text-neutral-500 dark:text-neutral-400">
                            @if($membro->last_notification)
                                {{ $membro->last_notification->created_at->diffForHumans() }}
                                <span class="block {{ $membro->last_notification->ind_enviada ? 'text-green-600' : 'text-red-500' }}">
                                    {{ $membro->last_notification->tip_canal }} • {{ $membro->last_notification->ind_enviada ? 'Enviado' : 'Falha' }}
                                </span>
                            @else
                                <span class="text-neutral-400">Nunca alertado</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <div class="flex justify-end gap-1">
                                @if($membro->tel_membro)
                                    <flux:button wire:click="alertMember({{ $membro->idt_membro }}, 'whatsapp')" size="xs" class="!bg-green-100 !text-green-700 hover:!bg-green-200 dark:!bg-green-900/30 dark:!text-green-400" title="Alerta WhatsApp Web + Email Espelho">
                                        <i class="fa-brands fa-whatsapp"></i>
                                    </flux:button>
                                @endif
                                @if($membro->eml_membro)
                                    <flux:button wire:click="alertMember({{ $membro->idt_membro }}, 'email')" size="xs" class="!bg-blue-100 !text-blue-700 hover:!bg-blue-200 dark:!bg-blue-900/30 dark:!text-blue-400" title="Alerta E-mail">
                                        <i class="fa-solid fa-envelope"></i>
                                    </flux:button>
                                @endif
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil"
                                    :href="route('membros.edit', $membro)"
                                    wire:navigate
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="excluir({{ $membro->idt_membro }})"
                                    wire:confirm="Tem certeza que deseja remover {{ $membro->nom_membro }}?"
                                />
                            </div>
                        </flux:table.cell>

                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="py-12 text-center text-zinc-400">
                            Nenhum membro encontrado.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if ($membros->hasPages())
            <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-700">
                {{ $membros->links() }}
            </div>
        @endif
    </flux:card>

</div>
