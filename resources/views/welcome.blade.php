<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
        <style>
            :root {
                --color-bg:       #F7F7F5;
                --color-surface:  #FFFFFF;
                --color-border:   #E0E2E7;
                --color-text:     #1C2332;
                --color-muted:    #5A637A;
                --color-subtle:   #8C93A3;
                --color-accent:   #B08520;
                --color-accent-lt:#FDF6E3;
            }

            * { box-sizing: border-box; margin: 0; padding: 0; }

            body {
                font-family: 'Inter', system-ui, sans-serif;
                background-color: var(--color-bg);
                color: var(--color-text);
                -webkit-font-smoothing: antialiased;
                min-height: 100vh;
            }

            /* NAV */
            .nav {
                position: sticky;
                top: 0;
                z-index: 50;
                background: rgba(247,247,245,0.92);
                backdrop-filter: blur(8px);
                border-bottom: 1px solid var(--color-border);
            }
            .nav-inner {
                max-width: 1100px;
                margin: 0 auto;
                padding: 0 1.5rem;
                height: 60px;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            .nav-brand {
                display: flex;
                align-items: center;
                gap: 0.625rem;
                text-decoration: none;
            }
            .nav-logo {
                width: 32px;
                height: 32px;
                background: var(--color-text);
                border-radius: 6px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .nav-logo svg { display: block; }
            .nav-wordmark {
                font-size: 0.9375rem;
                font-weight: 600;
                color: var(--color-text);
                letter-spacing: -0.01em;
            }
            .nav-links {
                display: flex;
                align-items: center;
                gap: 0.25rem;
            }
            .nav-link {
                font-size: 0.875rem;
                font-weight: 500;
                color: var(--color-muted);
                text-decoration: none;
                padding: 0.4rem 0.75rem;
                border-radius: 6px;
                transition: color 0.15s, background 0.15s;
            }
            .nav-link:hover { color: var(--color-text); background: var(--color-border); }
            .nav-cta {
                background: var(--color-text);
                color: #fff;
                font-size: 0.875rem;
                font-weight: 600;
                padding: 0.45rem 1.1rem;
                border-radius: 6px;
                text-decoration: none;
                transition: opacity 0.15s;
            }
            .nav-cta:hover { opacity: 0.85; }
            .nav-ghost {
                font-size: 0.875rem;
                font-weight: 500;
                color: var(--color-muted);
                text-decoration: none;
                padding: 0.45rem 0.9rem;
                border-radius: 6px;
                border: 1px solid var(--color-border);
                margin-right: 0.5rem;
                transition: border-color 0.15s, color 0.15s;
            }
            .nav-ghost:hover { border-color: var(--color-text); color: var(--color-text); }

            /* HERO */
            .hero {
                max-width: 1100px;
                margin: 0 auto;
                padding: 5rem 1.5rem 4.5rem;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 4rem;
                align-items: center;
            }
            .hero-eyebrow {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.75rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.1em;
                color: var(--color-accent);
                margin-bottom: 1.25rem;
            }
            .hero-eyebrow-dot {
                width: 6px; height: 6px;
                border-radius: 50%;
                background: var(--color-accent);
                flex-shrink: 0;
            }
            .hero-headline {
                font-family: 'DM Serif Display', Georgia, serif;
                font-size: clamp(2.25rem, 4vw, 3.25rem);
                line-height: 1.12;
                color: var(--color-text);
                letter-spacing: -0.02em;
                padding-left: 1.25rem;
                border-left: 3px solid var(--color-accent);
                margin-bottom: 1.5rem;
            }
            .hero-body {
                font-size: 1rem;
                line-height: 1.7;
                color: var(--color-muted);
                margin-bottom: 2rem;
                max-width: 42ch;
            }
            .hero-actions {
                display: flex;
                gap: 0.75rem;
                flex-wrap: wrap;
            }
            .btn-primary {
                background: var(--color-text);
                color: #fff;
                font-size: 0.9rem;
                font-weight: 600;
                padding: 0.65rem 1.4rem;
                border-radius: 7px;
                text-decoration: none;
                transition: opacity 0.15s;
                border: none;
                cursor: pointer;
            }
            .btn-primary:hover { opacity: 0.82; }
            .btn-secondary {
                background: transparent;
                color: var(--color-text);
                font-size: 0.9rem;
                font-weight: 500;
                padding: 0.65rem 1.4rem;
                border-radius: 7px;
                text-decoration: none;
                border: 1px solid var(--color-border);
                transition: border-color 0.15s;
            }
            .btn-secondary:hover { border-color: var(--color-text); }

            /* HERO VISUAL */
            .hero-visual {
                background: var(--color-surface);
                border: 1px solid var(--color-border);
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 24px rgba(28,35,50,0.07);
            }
            .hv-header {
                background: var(--color-text);
                padding: 0.875rem 1.25rem;
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }
            .hv-dots { display: flex; gap: 6px; }
            .hv-dot { width: 10px; height: 10px; border-radius: 50%; background: rgba(255,255,255,0.18); }
            .hv-title { font-size: 0.75rem; font-weight: 600; color: rgba(255,255,255,0.65); letter-spacing: 0.04em; text-transform: uppercase; margin-left: 0.25rem; }
            .hv-body { padding: 1.25rem; }
            .hv-stat-row { display: flex; gap: 0.75rem; margin-bottom: 0.75rem; }
            .hv-stat {
                flex: 1;
                background: var(--color-bg);
                border: 1px solid var(--color-border);
                border-radius: 8px;
                padding: 0.875rem 1rem;
            }
            .hv-stat-label { font-size: 0.7rem; font-weight: 600; color: var(--color-subtle); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.3rem; }
            .hv-stat-value { font-size: 1.4rem; font-weight: 700; color: var(--color-text); letter-spacing: -0.03em; }
            .hv-stat-value.accent { color: var(--color-accent); }
            .hv-stat-value.green { color: #2A7D4F; }
            .hv-table { width: 100%; border-collapse: collapse; margin-top: 0.25rem; }
            .hv-table th { font-size: 0.7rem; font-weight: 600; color: var(--color-subtle); text-transform: uppercase; letter-spacing: 0.06em; padding: 0.4rem 0.5rem; text-align: left; border-bottom: 1px solid var(--color-border); }
            .hv-table td { font-size: 0.8rem; color: var(--color-muted); padding: 0.55rem 0.5rem; border-bottom: 1px solid var(--color-border); }
            .hv-table tr:last-child td { border-bottom: none; }
            .hv-badge { display: inline-block; font-size: 0.65rem; font-weight: 600; padding: 0.15rem 0.45rem; border-radius: 4px; }
            .hv-badge.pago { background: #E6F4ED; color: #2A7D4F; }
            .hv-badge.pendente { background: #FDF2E3; color: #B08520; }
            .hv-badge.atraso { background: #FDECEA; color: #C0392B; }

            /* DIVIDER */
            .section-divider {
                max-width: 1100px;
                margin: 0 auto;
                border: none;
                border-top: 1px solid var(--color-border);
            }

            /* FEATURES */
            .features {
                max-width: 1100px;
                margin: 0 auto;
                padding: 4.5rem 1.5rem;
            }
            .section-label {
                font-size: 0.72rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.12em;
                color: var(--color-accent);
                margin-bottom: 0.75rem;
            }
            .section-title {
                font-family: 'DM Serif Display', Georgia, serif;
                font-size: clamp(1.6rem, 3vw, 2.1rem);
                color: var(--color-text);
                letter-spacing: -0.02em;
                line-height: 1.2;
                margin-bottom: 0.75rem;
            }
            .section-sub {
                font-size: 0.9375rem;
                color: var(--color-muted);
                max-width: 52ch;
                line-height: 1.65;
            }
            .features-header { margin-bottom: 3rem; }
            .features-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 1.5px;
                background: var(--color-border);
                border: 1px solid var(--color-border);
                border-radius: 10px;
                overflow: hidden;
            }
            .feature-card {
                background: var(--color-surface);
                padding: 2rem 1.75rem;
                transition: background 0.15s;
            }
            .feature-card:hover { background: var(--color-accent-lt); }
            .feature-icon {
                width: 40px;
                height: 40px;
                border: 1px solid var(--color-border);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 1.1rem;
                background: var(--color-bg);
            }
            .feature-icon svg { color: var(--color-accent); }
            .feature-title {
                font-size: 0.9375rem;
                font-weight: 700;
                color: var(--color-text);
                margin-bottom: 0.5rem;
                letter-spacing: -0.01em;
            }
            .feature-desc {
                font-size: 0.875rem;
                color: var(--color-muted);
                line-height: 1.65;
            }

            /* HOW */
            .how {
                border-top: 1px solid var(--color-border);
                border-bottom: 1px solid var(--color-border);
                background: var(--color-surface);
            }
            .how-inner {
                max-width: 1100px;
                margin: 0 auto;
                padding: 4.5rem 1.5rem;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 5rem;
                align-items: start;
            }
            .steps { margin-top: 2.5rem; display: flex; flex-direction: column; gap: 0; }
            .step {
                display: flex;
                gap: 1.25rem;
                position: relative;
                padding-bottom: 2rem;
            }
            .step:last-child { padding-bottom: 0; }
            .step-line-col { display: flex; flex-direction: column; align-items: center; }
            .step-num {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: var(--color-text);
                color: #fff;
                font-size: 0.75rem;
                font-weight: 700;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                z-index: 1;
            }
            .step-connector {
                flex: 1;
                width: 1px;
                background: var(--color-border);
                margin-top: 4px;
            }
            .step:last-child .step-connector { display: none; }
            .step-content { padding-top: 0.3rem; }
            .step-title { font-size: 0.9375rem; font-weight: 700; color: var(--color-text); margin-bottom: 0.3rem; }
            .step-desc { font-size: 0.875rem; color: var(--color-muted); line-height: 1.6; }

            .how-aside {
                background: var(--color-bg);
                border: 1px solid var(--color-border);
                border-radius: 10px;
                overflow: hidden;
                margin-top: 2.5rem;
            }
            .ha-header {
                background: var(--color-text);
                padding: 0.75rem 1rem;
                font-size: 0.72rem;
                font-weight: 600;
                color: rgba(255,255,255,0.55);
                text-transform: uppercase;
                letter-spacing: 0.08em;
            }
            .ha-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0.75rem 1rem;
                border-bottom: 1px solid var(--color-border);
                font-size: 0.85rem;
            }
            .ha-row:last-child { border-bottom: none; }
            .ha-name { color: var(--color-text); font-weight: 500; }
            .ha-amount { color: var(--color-muted); }

            /* CONTACT */
            .contact-wrap {
                max-width: 1100px;
                margin: 0 auto;
                padding: 4.5rem 1.5rem;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 5rem;
                align-items: start;
            }
            .contact-form-card {
                background: var(--color-surface);
                border: 1px solid var(--color-border);
                border-radius: 10px;
                padding: 2rem;
            }

            /* FOOTER */
            footer {
                border-top: 1px solid var(--color-border);
                background: var(--color-text);
            }
            .footer-inner {
                max-width: 1100px;
                margin: 0 auto;
                padding: 2.25rem 1.5rem;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
            }
            .footer-brand { display: flex; align-items: center; gap: 0.625rem; }
            .footer-logo { width: 28px; height: 28px; background: rgba(255,255,255,0.12); border-radius: 5px; display: flex; align-items: center; justify-content: center; }
            .footer-wordmark { font-size: 0.875rem; font-weight: 600; color: rgba(255,255,255,0.7); }
            .footer-copy { font-size: 0.8rem; color: rgba(255,255,255,0.35); }
            .footer-stack { font-size: 0.75rem; color: rgba(255,255,255,0.22); }

            /* RESPONSIVE */
            @media (max-width: 768px) {
                .hero { grid-template-columns: 1fr; gap: 2.5rem; padding: 3rem 1.25rem; }
                .hero-visual { display: none; }
                .features-grid { grid-template-columns: 1fr; }
                .how-inner { grid-template-columns: 1fr; gap: 2rem; }
                .contact-wrap { grid-template-columns: 1fr; gap: 2rem; }
                .footer-inner { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
                .nav-links .nav-ghost, .nav-links .nav-link { display: none; }
            }
        </style>
    </head>
    <body>

        {{-- NAV --}}
        <nav class="nav">
            <div class="nav-inner">
                <a href="/" class="nav-brand">
                    <div class="nav-logo">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="2" y="4" width="12" height="1.5" rx="0.75" fill="white" opacity="0.4"/>
                            <rect x="2" y="7.25" width="12" height="1.5" rx="0.75" fill="white" opacity="0.7"/>
                            <rect x="2" y="10.5" width="7" height="1.5" rx="0.75" fill="white"/>
                            <circle cx="12.5" cy="11.25" r="2.25" fill="#B08520"/>
                        </svg>
                    </div>
                    <span class="nav-wordmark">Pagamento Canônico</span>
                </a>

                <div class="nav-links">
                    <a href="#funcionalidades" class="nav-link">Funcionalidades</a>
                    <a href="#como-funciona" class="nav-link">Como funciona</a>
                    <a href="#contato" class="nav-link">Contato</a>

                    @auth
                        @can('diretor')
                            <a href="{{ route('dashboard') }}" class="nav-cta" wire:navigate>Painel de Controle</a>
                        @else
                            <a href="{{ route('dashboard') }}" class="nav-cta" wire:navigate>Minha Associação</a>
                        @endcan
                    @else
                        <a href="{{ route('login') }}" class="nav-ghost" wire:navigate>Entrar</a>
                        <a href="{{ route('register') }}" class="nav-cta" wire:navigate>Criar conta</a>
                    @endauth
                </div>
            </div>
        </nav>

        {{-- Toast --}}
        @persist('toast')
            <flux:toast.group><flux:toast /></flux:toast.group>
        @endpersist

        {{-- HERO --}}
        <section>
            <div class="hero">
                <div class="hero-left">
                    <div class="hero-eyebrow">
                        <span class="hero-eyebrow-dot"></span>
                        Gestão financeira para associações
                    </div>
                    <h1 class="hero-headline">
                        Controle de contribuições feito com clareza
                    </h1>
                    <p class="hero-body">
                        Importe extratos OFX, concilie mensalidades e notifique cada associado diretamente — tudo em um painel simples, sem planilhas soltas.
                    </p>
                    <div class="hero-actions">
                        @auth
                            @can('diretor')
                                <a href="{{ route('dashboard') }}" class="btn-primary" wire:navigate>Acessar o painel</a>
                            @else
                                <a href="{{ route('dashboard') }}" class="btn-primary" wire:navigate>Ver minha situação</a>
                            @endcan
                        @else
                            <a href="{{ route('register') }}" class="btn-primary" wire:navigate>Criar minha conta</a>
                            <a href="#contato" class="btn-secondary">Falar com a administração</a>
                        @endauth
                    </div>
                </div>

                {{-- Decorative dashboard preview --}}
                <div class="hero-visual">
                    <div class="hv-header">
                        <div class="hv-dots">
                            <div class="hv-dot"></div>
                            <div class="hv-dot"></div>
                            <div class="hv-dot"></div>
                        </div>
                        <span class="hv-title">Painel — Junho 2025</span>
                    </div>
                    <div class="hv-body">
                        <div class="hv-stat-row">
                            <div class="hv-stat">
                                <div class="hv-stat-label">Em dia</div>
                                <div class="hv-stat-value green">38</div>
                            </div>
                            <div class="hv-stat">
                                <div class="hv-stat-label">Pendentes</div>
                                <div class="hv-stat-value accent">7</div>
                            </div>
                            <div class="hv-stat">
                                <div class="hv-stat-label">Total membros</div>
                                <div class="hv-stat-value">45</div>
                            </div>
                        </div>
                        <table class="hv-table">
                            <thead>
                                <tr>
                                    <th>Associado</th>
                                    <th>Mês</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Ana Cristina M.</td>
                                    <td>Jun 25</td>
                                    <td><span class="hv-badge pago">Pago</span></td>
                                </tr>
                                <tr>
                                    <td>Roberto Farias</td>
                                    <td>Jun 25</td>
                                    <td><span class="hv-badge pendente">Pendente</span></td>
                                </tr>
                                <tr>
                                    <td>Carla Mendonça</td>
                                    <td>Jun 25</td>
                                    <td><span class="hv-badge pago">Pago</span></td>
                                </tr>
                                <tr>
                                    <td>José Augusto R.</td>
                                    <td>Mai 25</td>
                                    <td><span class="hv-badge atraso">Em atraso</span></td>
                                </tr>
                                <tr>
                                    <td>Márcia Lopes</td>
                                    <td>Jun 25</td>
                                    <td><span class="hv-badge pago">Pago</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <hr class="section-divider">

        {{-- FEATURES --}}
        <section id="funcionalidades">
            <div class="features">
                <div class="features-header">
                    <div class="section-label">Funcionalidades</div>
                    <h2 class="section-title">Uma plataforma,<br>três problemas resolvidos</h2>
                    <p class="section-sub">Sem configurações complexas. Do upload do extrato até a notificação do associado em poucos cliques.</p>
                </div>

                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                        </div>
                        <div class="feature-title">Importação de extrato OFX</div>
                        <p class="feature-desc">Envie o arquivo bancário e o sistema identifica automaticamente os depósitos de cada associado por correspondência de nome. Zero digitação manual.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                        </div>
                        <div class="feature-title">Portal do associado</div>
                        <p class="feature-desc">Cada membro acessa o próprio histórico de contribuições, vê pendências e atualiza seus dados cadastrais sem precisar ligar ou enviar mensagem.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.43 2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                            </svg>
                        </div>
                        <div class="feature-title">Notificações diretas</div>
                        <p class="feature-desc">Envie lembretes de pendência para WhatsApp, e-mail ou Telegram com um clique. O associado recebe o link direto para regularizar sua situação.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- HOW IT WORKS --}}
        <section id="como-funciona" class="how">
            <div class="how-inner">
                <div>
                    <div class="section-label">Como funciona</div>
                    <h2 class="section-title">Do banco ao associado<br>em quatro passos</h2>
                    <p class="section-sub">Um fluxo direto, pensado para quem administra uma associação sem equipe de TI.</p>

                    <div class="steps">
                        <div class="step">
                            <div class="step-line-col">
                                <div class="step-num">1</div>
                                <div class="step-connector"></div>
                            </div>
                            <div class="step-content">
                                <div class="step-title">Cadastre seus membros</div>
                                <p class="step-desc">Adicione os associados com nome, contato e dados para conciliação bancária.</p>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-line-col">
                                <div class="step-num">2</div>
                                <div class="step-connector"></div>
                            </div>
                            <div class="step-content">
                                <div class="step-title">Importe o extrato OFX</div>
                                <p class="step-desc">Baixe o arquivo OFX do seu banco e faça o upload. O sistema identifica os pagamentos automaticamente.</p>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-line-col">
                                <div class="step-num">3</div>
                                <div class="step-connector"></div>
                            </div>
                            <div class="step-content">
                                <div class="step-title">Revise e confirme</div>
                                <p class="step-desc">Veja as correspondências encontradas, ajuste as que precisam de atenção e confirme a conciliação do período.</p>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-line-col">
                                <div class="step-num">4</div>
                                <div class="step-connector"></div>
                            </div>
                            <div class="step-content">
                                <div class="step-title">Notifique quem está pendente</div>
                                <p class="step-desc">Com um clique, dispare avisos personalizados para os membros em débito pelos canais que eles preferem.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="section-label" style="margin-top: 0.25rem;">Conciliação do mês</div>
                    <div class="how-aside" style="margin-top: 0.75rem;">
                        <div class="ha-header">Importação — Jun / 2025</div>
                        <div class="ha-row">
                            <span class="ha-name">Ana Cristina M.</span>
                            <span class="hv-badge pago">Identificado</span>
                        </div>
                        <div class="ha-row">
                            <span class="ha-name">Carla Mendonça</span>
                            <span class="hv-badge pago">Identificado</span>
                        </div>
                        <div class="ha-row">
                            <span class="ha-name">Roberto Farias</span>
                            <span class="hv-badge pendente">Não encontrado</span>
                        </div>
                        <div class="ha-row">
                            <span class="ha-name">José Augusto R.</span>
                            <span class="hv-badge atraso">2 meses em aberto</span>
                        </div>
                        <div class="ha-row">
                            <span class="ha-name">Márcia Lopes</span>
                            <span class="hv-badge pago">Identificado</span>
                        </div>
                        <div class="ha-row" style="border-top: 1px solid var(--color-border); margin-top: -1px;">
                            <span style="font-size:0.78rem; color: var(--color-subtle);">38 de 45 identificados</span>
                            <span style="font-size:0.78rem; font-weight:600; color: var(--color-text);">84%</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- CONTACT --}}
        <section id="contato">
            <div class="contact-wrap">
                <div>
                    <div class="section-label">Contato</div>
                    <h2 class="section-title">Fale com a administração</h2>
                    <p class="section-sub" style="margin-top: 0.75rem;">Não encontrou seu cadastro ou tem dúvidas sobre sua situação? Envie uma mensagem e a diretoria responderá em breve.</p>
                </div>
                <div class="contact-form-card">
                    <livewire:welcome-contact />
                </div>
            </div>
        </section>

        {{-- FOOTER --}}
        <footer>
            <div class="footer-inner">
                <div class="footer-brand">
                    <div class="footer-logo">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                            <rect x="2" y="4" width="12" height="1.5" rx="0.75" fill="white" opacity="0.4"/>
                            <rect x="2" y="7.25" width="12" height="1.5" rx="0.75" fill="white" opacity="0.7"/>
                            <rect x="2" y="10.5" width="7" height="1.5" rx="0.75" fill="white"/>
                            <circle cx="12.5" cy="11.25" r="2.25" fill="#B08520"/>
                        </svg>
                    </div>
                    <span class="footer-wordmark">Pagamento Canônico</span>
                </div>
                <span class="footer-copy">&copy; {{ date('Y') }} Todos os direitos reservados.</span>
                <span class="footer-stack">Laravel · Livewire · Flux UI</span>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>