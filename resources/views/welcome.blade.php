<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </head>
    <body class="min-h-screen bg-zinc-900 text-zinc-100 antialiased selection:bg-blue-600 selection:text-white">
        
        {{-- Toast alerts --}}
        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        {{-- Header / Navbar --}}
        <header class="sticky top-0 z-50 border-b border-zinc-800 bg-zinc-900/80 backdrop-blur-md">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <span class="font-bold text-lg text-white tracking-wide">OFX Tracker</span>
                </div>

                <nav class="hidden md:flex items-center gap-6">
                    <a href="#funcionalidades" class="text-sm text-zinc-400 hover:text-white transition">Funcionalidades</a>
                    <a href="#contato" class="text-sm text-zinc-400 hover:text-white transition">Contato</a>
                </nav>

                <div class="flex items-center gap-3">
                    @auth
                        @can('diretor')
                            <flux:button href="{{ route('dashboard') }}" variant="primary" icon="layout-grid" wire:navigate>
                                Dashboard
                            </flux:button>
                        @else
                            <flux:button href="{{ route('minha-associacao') }}" variant="primary" icon="user" wire:navigate>
                                Minha Associação
                            </flux:button>
                        @endcan
                        
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <flux:button type="submit" variant="ghost" icon="arrow-right-start-on-rectangle">
                                {{ __('Sair') }}
                            </flux:button>
                        </form>
                    @else
                        <flux:button href="{{ route('login') }}" variant="ghost" wire:navigate>
                            Entrar
                        </flux:button>
                        <flux:button href="{{ route('register') }}" variant="primary" wire:navigate>
                            Registrar-se
                        </flux:button>
                    @endauth
                </div>
            </div>
        </header>

        {{-- Hero Section --}}
        <section class="relative overflow-hidden pt-20 pb-16 lg:pt-32 lg:pb-24 border-b border-zinc-800 bg-gradient-to-b from-zinc-950 via-zinc-900 to-zinc-900">
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:24px_24px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_0%,#000_70%,transparent_100%)]"></div>
            
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative text-center">
                <span class="inline-flex items-center gap-1.5 py-1 px-3 rounded-full text-xs font-medium bg-blue-500/10 text-blue-400 border border-blue-500/20 mb-6">
                    <i class="fa-solid fa-sparkles"></i> Simplifique o Controle Financeiro da sua Associação
                </span>
                
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white tracking-tight max-w-4xl mx-auto leading-tight">
                    Centralize os Extratos OFX e <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 via-indigo-400 to-violet-400">Pagamentos de Membros</span>
                </h1>
                
                <p class="mt-6 text-lg sm:text-xl text-zinc-400 max-w-3xl mx-auto leading-relaxed">
                    Importação inteligente de movimentações bancárias, conciliação facilitada de mensalidades e portal exclusivo para o associado visualizar suas pendências de forma clara e amigável.
                </p>

                <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                    @auth
                        @can('diretor')
                            <flux:button href="{{ route('dashboard') }}" variant="primary" icon="layout-grid" wire:navigate>
                                Acessar Painel Admin
                            </flux:button>
                        @else
                            <flux:button href="{{ route('minha-associacao') }}" variant="primary" icon="user" wire:navigate>
                                Ir para Minha Associação
                            </flux:button>
                        @endcan
                    @else
                        <flux:button href="{{ route('register') }}" variant="primary" icon="user-plus" wire:navigate>
                            Criar Minha Conta
                        </flux:button>
                        <flux:button href="#contato" variant="ghost" icon="envelope">
                            Falar com Suporte
                        </flux:button>
                    @endauth
                </div>
            </div>
        </section>

        {{-- Features Section --}}
        <section id="funcionalidades" class="py-20 lg:py-24 border-b border-zinc-800 bg-zinc-900">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <h2 class="text-3xl font-bold text-white tracking-tight">Tudo que você precisa em uma única plataforma</h2>
                    <p class="mt-4 text-zinc-400 text-base">Uma interface moderna projetada para aproximar a administração dos seus membros de forma ágil e segura.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    {{-- Feature 1 --}}
                    <flux:card class="p-6 bg-zinc-950/40 border-zinc-800 hover:border-zinc-700 transition space-y-4">
                        <div class="w-10 h-10 rounded-lg bg-blue-500/10 text-blue-400 flex items-center justify-center text-lg">
                            <i class="fa-solid fa-file-import"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white">Importação OFX Inteligente</h3>
                        <p class="text-sm text-zinc-400 leading-relaxed">
                            Envie o extrato bancário em formato OFX e o sistema identificará automaticamente os depósitos de cada associado por correspondência de nomes.
                        </p>
                    </flux:card>

                    {{-- Feature 2 --}}
                    <flux:card class="p-6 bg-zinc-950/40 border-zinc-800 hover:border-zinc-700 transition space-y-4">
                        <div class="w-10 h-10 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center text-lg">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white">Área Exclusiva do Associado</h3>
                        <p class="text-sm text-zinc-400 leading-relaxed">
                            Cada membro possui um portal privativo para atualizar seus dados cadastrais, revisar histórico de mensalidades quitadas e ver pendências.
                        </p>
                    </flux:card>

                    {{-- Feature 3 --}}
                    <flux:card class="p-6 bg-zinc-950/40 border-zinc-800 hover:border-zinc-700 transition space-y-4">
                        <div class="w-10 h-10 rounded-lg bg-violet-500/10 text-violet-400 flex items-center justify-center text-lg">
                            <i class="fa-solid fa-paper-airplane"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white">Notificações Diretas</h3>
                        <p class="text-sm text-zinc-400 leading-relaxed">
                            Dispare alertas amigáveis de pendências para os canais preferidos dos membros: WhatsApp, E-mail ou Telegram, com link direto de suporte.
                        </p>
                    </flux:card>
                </div>
            </div>
        </section>

        {{-- Contact Section --}}
        <section id="contato" class="py-20 lg:py-24 bg-gradient-to-b from-zinc-900 to-zinc-950">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-12">
                    <h2 class="text-3xl font-bold text-white tracking-tight">Fale com a Administração</h2>
                    <p class="mt-4 text-zinc-400 text-base">Não encontrou seu cadastro ou tem dúvidas sobre sua associação? Envie uma mensagem rápida abaixo.</p>
                </div>

                <flux:card class="max-w-2xl mx-auto p-6 md:p-8 bg-zinc-950/40 border-zinc-800">
                    <livewire:welcome-contact />
                </flux:card>
            </div>
        </section>

        {{-- Footer --}}
        <footer class="border-t border-zinc-800 bg-zinc-950 py-8 text-center text-zinc-500 text-xs">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-2">
                <p>&copy; {{ date('Y') }} OFX Tracker. Todos os direitos reservados.</p>
                <p class="text-zinc-600">Desenvolvido com Laravel, Livewire e Flux UI.</p>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
