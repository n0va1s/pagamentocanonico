<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold text-sm">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <span class="font-bold text-zinc-800 dark:text-white text-sm">OFX Tracker</span>
                </div>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @can('diretor')
                    <flux:sidebar.group :heading="__('Painel')" class="grid">
                        <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group :heading="__('Financeiro')" class="grid">
                        <flux:sidebar.item icon="arrow-up-tray" :href="route('upload')" :current="request()->routeIs('upload')" wire:navigate>
                            {{ __('Importar OFX') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="table-cells" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Acompanhamento') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                <flux:sidebar.group :heading="__('Cadastros')" class="grid">
                    @can('diretor')
                        <flux:sidebar.item icon="users" :href="route('members.index')" :current="request()->routeIs('members.*')" wire:navigate>
                            {{ __('Membros') }}
                        </flux:sidebar.item>
                    @endcan
                    <flux:sidebar.item icon="envelope" :href="route('contato')" :current="request()->routeIs('contato')" wire:navigate>
                        {{ __('Contato') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="user" :href="route('minha-associacao')" :current="request()->routeIs('minha-associacao')" wire:navigate>
                        {{ __('Minha Associação') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            @can('diretor')
                <flux:sidebar.nav>
                    <flux:sidebar.item icon="bell" :href="route('mensagens')" :current="request()->routeIs('mensagens')" wire:navigate>
                        {{ __('Mensagens') }}
                        <flux:badge color="red" size="sm" class="ml-auto">{{ App\Models\Notificacao::where('ind_enviada', false)->count() }}</flux:badge>
                    </flux:sidebar.item>
                </flux:sidebar.nav>
            @endcan

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile Header -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <flux:spacer />
            <flux:dropdown position="top" align="end">
                <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />
                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />
                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>
                    <flux:menu.separator />
                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Configurações') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>
                    <flux:menu.separator />
                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">
                            {{ __('Sair') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>