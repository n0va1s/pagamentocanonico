<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <div class="flex items-center gap-2 max-lg:hidden">
                <div class="w-7 h-7 rounded-md bg-blue-600 flex items-center justify-center text-white text-xs">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <span class="font-bold text-zinc-800 dark:text-white text-sm">Pagamento - Canônico</span>
            </div>

            <flux:navbar class="-mb-px max-lg:hidden">
                @can('diretor')
                    <flux:navbar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:navbar.item>
                    <flux:navbar.item icon="check-badge" :href="route('aprovacoes')" :current="request()->routeIs('aprovacoes')" wire:navigate>
                        {{ __('Aprovações') }}
                    </flux:navbar.item>
                    <flux:navbar.item icon="arrow-up-tray" :href="route('upload')" :current="request()->routeIs('upload')" wire:navigate>
                        {{ __('Importar OFX') }}
                    </flux:navbar.item>
                    <flux:navbar.item icon="users" :href="route('membros.index')" :current="request()->routeIs('membros.*')" wire:navigate>
                        {{ __('Membros') }}
                    </flux:navbar.item>
                    @can('admin')
                        <flux:navbar.item icon="building-office-2" :href="route('associacoes.index')" :current="request()->routeIs('associacoes.*')" wire:navigate>
                            {{ __('Associações') }}
                        </flux:navbar.item>
                    @endcan
                    <flux:navbar.item icon="bell" :href="route('mensagens.index')" :current="request()->routeIs('mensagens.*')" wire:navigate>
                        {{ __('Mensagens') }}
                    </flux:navbar.item>
                @endcan
            </flux:navbar>

            <flux:spacer />

            <flux:navbar class="me-1.5 space-x-0.5 rtl:space-x-reverse py-0!">

            </flux:navbar>

            <x-desktop-user-menu />
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold text-sm">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <span class="font-bold text-zinc-800 dark:text-white text-sm">Pagamento - Canônico<span>
                </div>
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @can('diretor')
                    <flux:sidebar.group :heading="__('Painel')">
                        <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="check-badge" :href="route('aprovacoes')" :current="request()->routeIs('aprovacoes')" wire:navigate>
                            {{ __('Aprovações') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                    <flux:sidebar.group :heading="__('Financeiro')">
                        <flux:sidebar.item icon="arrow-up-tray" :href="route('upload')" :current="request()->routeIs('upload')" wire:navigate>
                            {{ __('Importar OFX') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan
                @can('admin')
                    <flux:sidebar.group :heading="__('Associações')">
                        <flux:sidebar.item icon="building-office-2" :href="route('associacoes.index')" :current="request()->routeIs('associacoes.*')" wire:navigate>
                            {{ __('Associações') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan
                <flux:sidebar.group :heading="__('Cadastros')">
                    @can('diretor')
                        <flux:sidebar.item icon="users" :href="route('membros.index')" :current="request()->routeIs('membros.*')" wire:navigate>
                            {{ __('Membros') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="bell" :href="route('mensagens.index')" :current="request()->routeIs('mensagens.*')" wire:navigate>
                            {{ __('Mensagens') }}
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>