# OFX Tracker

Sistema de acompanhamento de pagamentos de membros via importação de extratos bancários OFX, com notificações automáticas por WhatsApp, Telegram e e-mail.

---

## Funcionalidades

- **Importação OFX** — processa extratos do Banco do Brasil (Money 2000+ / OFX 102), extrai transações e gera resumos mensais por pagador
- **Dashboard** — tabela de adimplência por membro e mês, com indicadores visuais de situação
- **Gestão de membros** — CRUD completo com tipos de associação (Associado, Diretor, Honorário)
- **Notificações automáticas** — WhatsApp via Evolution API, Telegram Bot e e-mail
  - Inadimplentes: disparo manual ou agendado por importação OFX
  - Aniversariantes: disparo diário automático
  - Boas-vindas: disparo automático ao cadastrar novo membro
- **PWA** — instalável como aplicativo no celular e desktop

---

## Stack

| Camada | Tecnologia |
|---|---|
| Backend | Laravel 13, PHP 8.3+ |
| Frontend | Livewire 4, Volt, Flux UI, Tailwind CSS 4 |
| Banco | MySQL 8.4 (produção) / SQLite (testes) |
| Cache & Queue | Redis |
| WhatsApp | Evolution API (self-hosted, Docker) |
| Build | Vite 8 + Rolldown |
| CI/CD | GitHub Actions |
| Deploy | FTP (Hostinger) |

---

## Requisitos

- PHP 8.3+
- Node.js 22+
- Composer 2
- Docker (para ambiente local com Sail)

---

## Instalação local (Laravel Sail)

```bash
# 1. Clone o repositório
git clone https://github.com/n0va1s/pagamentocanonico.git pagamento
cd pagamento

# 2. Instale as dependências PHP
composer install

# 3. Configure o ambiente
cp .env.example .env
php artisan key:generate

# 4. Suba os containers
./vendor/bin/sail up -d

# 5. Instale o Volt
./vendor/bin/sail artisan volt:install

# 6. Rode as migrations e seeders
./vendor/bin/sail artisan migrate --seed

# 7. Instale as dependências Node e compile os assets
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

Acesse em: **http://localhost**

### Contas para Teste (Seeder)

Após rodar os seeders, você pode utilizar as seguintes credenciais para acessar o painel (todas com a senha: `localhost@1`):

- **Administrador**: `admin@email.com`
- **Diretor**: `diretor@email.com`
- **Membro**: `membro@email.com`
- **Membro com Pendências**: `devedor@email.com`
- **Pendente (Aguardando aprovação)**: `pendente@email.com`

### Serviços disponíveis no Sail

| Serviço | URL |
|---|---|
| Aplicação | http://localhost |
| Mailpit (e-mails) | http://localhost:8025 |
| Evolution API (WhatsApp) | http://localhost:8080 |

---

## Variáveis de ambiente relevantes

```env
# Evolution API (WhatsApp)
EVOLUTION_URL=http://evolution-api:8080
EVOLUTION_API_KEY=sua_chave
EVOLUTION_INSTANCE=default

# Telegram Bot
TELEGRAM_BOT_TOKEN=seu_token

# E-mail (Mailpit em dev)
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

---

## Comandos úteis

```bash
# Notificar inadimplentes (usa a importação OFX mais recente)
php artisan membros:notificar inadimplentes

# Notificar inadimplentes de uma importação específica
php artisan membros:notificar inadimplentes --ofx=3

# Simular sem enviar (dry-run)
php artisan membros:notificar inadimplentes --dry-run

# Notificar aniversariantes do dia
php artisan membros:notificar aniversariantes

# Rodar worker de fila
php artisan queue:work --tries=3
```

---

## Estrutura de rotas

| Método | Rota | Descrição |
|---|---|---|
| GET | `/dashboard` | Painel de adimplência |
| GET | `/upload` | Formulário de importação OFX |
| POST | `/upload` | Processar arquivo OFX |
| GET | `/membros` | Listagem de membros |
| GET | `/membros/novo` | Formulário de cadastro |
| GET | `/membros/{id}/editar` | Formulário de edição |
| POST | `/notificacoes/testar` | Enviar notificação de teste |

---

## Arquitetura de notificações

```
Evento (created / comando / schedule)
    └── Job (queue)
            └── NotificationDispatcher
                    ├── MensagemBuilder   ← monta o texto por tipo
                    └── canaisAtivos()    ← filtra por preferências do membro
                            ├── WhatsAppChannel  (Evolution API)
                            ├── TelegramChannel  (Telegram Bot API)
                            └── EmailChannel     (Laravel Mail)
```

Decisões arquiteturais documentadas em [`docs/adr/`](docs/adr/).

---

## CI/CD

| Workflow | Gatilho | O que faz |
|---|---|---|
| `lint.yml` | Push / PR em `main` | Verifica estilo com Laravel Pint |
| `tests.yml` | Push / PR em `main` | Roda testes com Pest (PHP 8.3, 8.4, 8.5) |
| `deploy.yml` | Push em `main` | Build + deploy via FTP para Hostinger |

### Secrets necessários no GitHub

| Secret | Descrição |
|---|---|
| `FTP_HOST` | Host FTP da Hostinger |
| `FTP_USER` | Usuário FTP |
| `FTP_PASSWORD` | Senha FTP |
| `FTP_PORT` | Porta FTP (geralmente `21`) |

Para instruções de pós-deploy (migrations sem SSH), veja [`docs/deploy-manual.md`](docs/deploy-manual.md).

---

## Testes

```bash
# Rodar todos os testes
./vendor/bin/pest

# Com cobertura
./vendor/bin/pest --coverage

# Apenas um grupo
./vendor/bin/pest --group=notifications
```

---

## Licença

Uso privado. Todos os direitos reservados.

