<?php

use Livewire\Volt\Component;

new class extends Component {
    // Componente de navegação inferior mobile (Bottom Navigation) - WCAG 2.2 AAA
}; ?>

<nav aria-label="{{ __('Navegação Principal Mobile') }}" class="fixed bottom-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-md border-t border-slate-300 shadow-lg dark:bg-zinc-900/95 dark:border-zinc-700 sm:hidden">
    <div class="flex items-center justify-around h-16 max-w-md mx-auto px-2">
        @can('diretor')
            @php
                $isDashboard = request()->routeIs('dashboard');
            @endphp
            <a href="{{ route('dashboard') }}" 
               wire:navigate 
               aria-label="{{ __('Dashboard') }}"
               @if($isDashboard) aria-current="page" @endif
               class="flex flex-col items-center justify-center min-w-[48px] min-h-[48px] w-full py-1 text-xs transition-colors rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 {{ $isDashboard ? 'text-indigo-700 dark:text-indigo-300 font-bold' : 'text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-300 font-medium' }}">
                <flux:icon name="layout-grid" class="size-6 mb-0.5" />
                <span class="truncate max-w-[64px] text-[10px] sm:text-xs">{{ __('Dashboard') }}</span>
                @if($isDashboard)
                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-700 dark:bg-indigo-300 mt-0.5" aria-hidden="true"></span>
                @endif
            </a>

            @php
                $isAprovacoes = request()->routeIs('aprovacoes');
            @endphp
            <a href="{{ route('aprovacoes') }}" 
               wire:navigate 
               aria-label="{{ __('Aprovações') }}"
               @if($isAprovacoes) aria-current="page" @endif
               class="flex flex-col items-center justify-center min-w-[48px] min-h-[48px] w-full py-1 text-xs transition-colors rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 {{ $isAprovacoes ? 'text-indigo-700 dark:text-indigo-300 font-bold' : 'text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-300 font-medium' }}">
                <flux:icon name="check-badge" class="size-6 mb-0.5" />
                <span class="truncate max-w-[64px] text-[10px] sm:text-xs">{{ __('Aprovações') }}</span>
                @if($isAprovacoes)
                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-700 dark:bg-indigo-300 mt-0.5" aria-hidden="true"></span>
                @endif
            </a>

            @php
                $isUpload = request()->routeIs('upload');
            @endphp
            <a href="{{ route('upload') }}" 
               wire:navigate 
               aria-label="{{ __('Importar OFX') }}"
               @if($isUpload) aria-current="page" @endif
               class="flex flex-col items-center justify-center min-w-[48px] min-h-[48px] w-full py-1 text-xs transition-colors rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 {{ $isUpload ? 'text-indigo-700 dark:text-indigo-300 font-bold' : 'text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-300 font-medium' }}">
                <flux:icon name="arrow-up-tray" class="size-6 mb-0.5" />
                <span class="truncate max-w-[64px] text-[10px] sm:text-xs">{{ __('OFX') }}</span>
                @if($isUpload)
                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-700 dark:bg-indigo-300 mt-0.5" aria-hidden="true"></span>
                @endif
            </a>

            @php
                $isDespesas = request()->routeIs('despesas');
            @endphp
            <a href="{{ route('despesas') }}" 
               wire:navigate 
               aria-label="{{ __('Despesas') }}"
               @if($isDespesas) aria-current="page" @endif
               class="flex flex-col items-center justify-center min-w-[48px] min-h-[48px] w-full py-1 text-xs transition-colors rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 {{ $isDespesas ? 'text-indigo-700 dark:text-indigo-300 font-bold' : 'text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-300 font-medium' }}">
                <flux:icon name="document-duplicate" class="size-6 mb-0.5" />
                <span class="truncate max-w-[64px] text-[10px] sm:text-xs">{{ __('Despesas') }}</span>
                @if($isDespesas)
                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-700 dark:bg-indigo-300 mt-0.5" aria-hidden="true"></span>
                @endif
            </a>

            @php
                $isMembros = request()->routeIs('membros.*');
            @endphp
            <a href="{{ route('membros.index') }}" 
               wire:navigate 
               aria-label="{{ __('Membros') }}"
               @if($isMembros) aria-current="page" @endif
               class="flex flex-col items-center justify-center min-w-[48px] min-h-[48px] w-full py-1 text-xs transition-colors rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 {{ $isMembros ? 'text-indigo-700 dark:text-indigo-300 font-bold' : 'text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-300 font-medium' }}">
                <flux:icon name="users" class="size-6 mb-0.5" />
                <span class="truncate max-w-[64px] text-[10px] sm:text-xs">{{ __('Membros') }}</span>
                @if($isMembros)
                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-700 dark:bg-indigo-300 mt-0.5" aria-hidden="true"></span>
                @endif
            </a>

            @can('admin')
                @php
                    $isAssociacoes = request()->routeIs('associacoes.*');
                @endphp
                <a href="{{ route('associacoes.index') }}" 
                   wire:navigate 
                   aria-label="{{ __('Associações') }}"
                   @if($isAssociacoes) aria-current="page" @endif
                   class="flex flex-col items-center justify-center min-w-[48px] min-h-[48px] w-full py-1 text-xs transition-colors rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 {{ $isAssociacoes ? 'text-indigo-700 dark:text-indigo-300 font-bold' : 'text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-300 font-medium' }}">
                    <flux:icon name="building-office-2" class="size-6 mb-0.5" />
                    <span class="truncate max-w-[64px] text-[10px] sm:text-xs">{{ __('Associações') }}</span>
                    @if($isAssociacoes)
                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-700 dark:bg-indigo-300 mt-0.5" aria-hidden="true"></span>
                    @endif
                </a>
            @endcan

            @php
                $isMensagens = request()->routeIs('mensagens.*');
            @endphp
            <a href="{{ route('mensagens.index') }}" 
               wire:navigate 
               aria-label="{{ __('Mensagens') }}"
               @if($isMensagens) aria-current="page" @endif
               class="flex flex-col items-center justify-center min-w-[48px] min-h-[48px] w-full py-1 text-xs transition-colors rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 {{ $isMensagens ? 'text-indigo-700 dark:text-indigo-300 font-bold' : 'text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-300 font-medium' }}">
                <flux:icon name="bell" class="size-6 mb-0.5" />
                <span class="truncate max-w-[64px] text-[10px] sm:text-xs">{{ __('Mensagens') }}</span>
                @if($isMensagens)
                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-700 dark:bg-indigo-300 mt-0.5" aria-hidden="true"></span>
                @endif
            </a>
        @endcan
    </div>
</nav>
