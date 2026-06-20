<?php

use App\Models\Contato;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;

new #[Title('Contatos Recebidos')] class extends Component {
    use WithPagination;

    public function excluir(int $id): void
    {
        $contato = Contato::findOrFail($id);
        $contato->delete();
        $this->dispatch('toast', message: 'Contato marcado como resolvido e removido.', variant: 'success');
    }

    public function with(): array
    {
        return [
            'contatos' => Contato::latest()->paginate(15),
        ];
    }
}; ?>

<div class="pc-page">

    {{-- Cabeçalho --}}
    <div class="pc-page-header">
        <div>
            <div class="pc-label" style="margin-bottom:0.4rem">Administração</div>
            <h1 class="pc-page-title">Contatos Recebidos</h1>
            <p class="pc-page-subtitle">Solicitações enviadas por associados ou visitantes via formulário público.</p>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem">
            <span class="pc-badge accent">{{ $contatos->total() }} {{ Str::plural('pendente', $contatos->total()) }}</span>
        </div>
    </div>

    {{-- Tabela --}}
    <div class="pc-card">
        <div class="pc-card-header">
            <span class="pc-card-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                Caixa de entrada
            </span>
            <span style="font-size:0.78rem;color:var(--pc-subtle)">Página {{ $contatos->currentPage() }} de {{ $contatos->lastPage() }}</span>
        </div>

        @if($contatos->isEmpty())
            <div class="pc-empty">
                <div class="pc-empty-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <div class="pc-empty-title">Nenhum contato pendente</div>
                <div class="pc-empty-desc">Quando associados enviarem mensagens, elas aparecerão aqui.</div>
            </div>
        @else
            <div style="overflow-x:auto">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>Mensagem</th>
                            <th>Recebido em</th>
                            <th style="text-align:right">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contatos as $contato)
                        <tr wire:key="{{ $contato->id }}">
                            <td>
                                <div style="display:flex;align-items:center;gap:0.625rem">
                                    <div class="pc-avatar">{{ strtoupper(substr($contato->nome, 0, 1)) }}</div>
                                    <span class="cell-primary">{{ $contato->nome }}</span>
                                </div>
                            </td>
                            <td>
                                <a href="mailto:{{ $contato->email }}" style="color:var(--pc-blue);text-decoration:none;font-size:0.875rem">{{ $contato->email }}</a>
                            </td>
                            <td style="max-width:360px">
                                <p style="font-size:0.8125rem;color:var(--pc-muted);line-height:1.55;white-space:normal;word-break:break-word">{{ $contato->mensagem }}</p>
                            </td>
                            <td class="cell-mono" style="white-space:nowrap">
                                <span style="font-size:0.8125rem;color:var(--pc-text)">{{ $contato->created_at->format('d/m/Y') }}</span>
                                <span style="display:block;font-size:0.72rem;color:var(--pc-subtle)">{{ $contato->created_at->format('H:i') }} · {{ $contato->created_at->diffForHumans() }}</span>
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                <button
                                    class="pc-btn pc-btn-ghost pc-btn-sm"
                                    wire:click="excluir({{ $contato->id }})"
                                    wire:confirm="Marcar este contato como resolvido e removê-lo da lista?"
                                    title="Marcar como resolvido"
                                    style="color:var(--pc-red);border-color:#f5c6c2"
                                >
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                    Resolver
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($contatos->hasPages())
                <div style="padding:0.875rem 1.25rem;border-top:1px solid var(--pc-border)">
                    {{ $contatos->links() }}
                </div>
            @endif
        @endif
    </div>
</div>