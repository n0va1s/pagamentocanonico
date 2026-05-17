# ADR-0001 — Módulo de Notificações

| Campo       | Valor                          |
|-------------|--------------------------------|
| **Status**  | Aceito                         |
| **Data**    | 2026-05-17                     |
| **Autores** | Equipe de desenvolvimento      |

---

## Contexto

O sistema precisa comunicar membros em três situações distintas:

1. **Inadimplência** — membro com pagamento em aberto identificado via extrato OFX importado
2. **Aniversário** — membro faz aniversário no dia corrente
3. **Boas-vindas** — membro recém-cadastrado no sistema

Os membros podem ter preferências diferentes de canal de comunicação. O canal principal é WhatsApp, com Telegram e e-mail como alternativas. A solução precisa ser extensível para novos canais sem alterar o código existente.

---

## Decisão

Adotar uma **arquitetura de canais intercambiáveis** baseada em interface, com um dispatcher central e jobs assíncronos por tipo de notificação.

### Estrutura adotada

```
App\Services\Notifications\
├── Contracts\
│   └── NotificationChannelInterface     ← contrato de canal
├── Channels\
│   ├── WhatsAppChannel                  ← Evolution API (self-hosted)
│   ├── TelegramChannel                  ← Telegram Bot API
│   └── EmailChannel                     ← Laravel Mail
├── Messages\
│   └── MensagemBuilder                  ← templates por tipo
└── NotificationDispatcher               ← orquestrador

App\Jobs\
├── NotificarInadimplentes               ← batch por importação OFX
├── NotificarAniversariantes             ← batch diário por data de nascimento
└── NotificarBoasVindas                  ← individual, disparado no created()

App\Enums\
├── TipoNotificacao                      ← inadimplente | aniversariante | boas_vindas
└── Canal                                ← W | T | E
```

### Fluxo de execução

```
Evento (created / comando / schedule)
    └── Job (ShouldQueue)
            └── NotificationDispatcher::notificar()
                    ├── MensagemBuilder::construir()   ← monta o texto
                    └── canaisAtivos()                 ← filtra por preferências do membro
                            ├── WhatsAppChannel::send()
                            ├── TelegramChannel::send()
                            └── EmailChannel::send()
                                    └── Notificacao::create()  ← registra log
```

### Canal WhatsApp — Evolution API

Optou-se pela **Evolution API** (open-source, self-hosted via Docker) em vez de provedores pagos como Twilio ou Z-API. A Evolution API usa a biblioteca Baileys (WhatsApp Web multi-device) e expõe endpoints REST compatíveis com a interface adotada. Não há custo por mensagem.

Configuração via variáveis de ambiente:

```env
EVOLUTION_URL=http://localhost:8080
EVOLUTION_API_KEY=
EVOLUTION_INSTANCE=default
```

### Disparo de boas-vindas

O job `NotificarBoasVindas` é disparado diretamente no hook `booted()` do model `Membro`, usando `afterCommit()` para garantir que o job só entre na fila após o `INSERT` ser confirmado no banco. Optou-se por não usar Observer para evitar uma classe extra sem lógica adicional.

```php
// App\Models\Membro
protected static function booted(): void
{
    static::created(function (self $membro) {
        NotificarBoasVindas::dispatch($membro)->afterCommit();
    });
}
```

### Prioridade de canais

O dispatcher respeita a seguinte ordem de prioridade ao iterar os canais ativos do membro:

1. WhatsApp (`ind_notificar_whatsapp = true` + `tel_membro` preenchido)
2. Telegram (`ind_notificar_telegram = true` + `des_telegram_chat_id` preenchido)
3. E-mail (`ind_notificar_email = true` + `eml_membro` preenchido)

Todos os canais ativos são notificados — não é exclusivo (o primeiro que funcionar não cancela os demais).

### Registro de notificações

Cada envio (bem-sucedido ou não) é registrado na tabela `notificacoes` via model `Notificacao`, com os campos:

| Campo          | Descrição                              |
|----------------|----------------------------------------|
| `idt_membro`   | FK para o membro                       |
| `tip_canal`    | Canal utilizado (`whatsapp`, `email`, `telegram`) |
| `txt_conteudo` | Texto da mensagem enviada              |
| `ind_enviada`  | `true` se enviado com sucesso          |
| `num_externo`  | ID retornado pela API externa          |
| `msg_erro`     | Mensagem de erro em caso de falha      |

### Comando Artisan

```bash
# Notificar inadimplentes (usa OFX mais recente automaticamente)
php artisan membros:notificar inadimplentes

# Com OFX específico e modo simulação
php artisan membros:notificar inadimplentes --ofx=3 --dry-run

# Aniversariantes do dia
php artisan membros:notificar aniversariantes --dry-run
```

---

## Alternativas consideradas

### Laravel Notifications nativo

O Laravel possui um sistema de notificações embutido (`Notifiable` trait + classes `Notification`). Foi descartado pelos seguintes motivos:

- A integração com WhatsApp via Evolution API exigiria um driver customizado de qualquer forma
- O sistema nativo não tem suporte a `TipoNotificacao` como enum tipado
- A abordagem com channels explícitos oferece mais controle sobre logging, retry e ordem de prioridade

### Serviços pagos (Twilio, Z-API, MessageBird)

Descartados por custo por mensagem e dependência de terceiros. A Evolution API self-hosted elimina esses custos e mantém os dados na infraestrutura própria.

### Notificação síncrona (sem queue)

Descartada. Envios para APIs externas (WhatsApp, Telegram) introduzem latência imprevisível e risco de timeout durante o request HTTP. O uso de jobs assíncronos com `ShouldQueue` isola falhas de rede do fluxo principal da aplicação.

---

## Consequências

### Positivas

- Adicionar um novo canal (ex: SMS) requer apenas implementar `NotificationChannelInterface` e registrá-lo no dispatcher — sem alterar código existente
- Falhas de envio são isoladas por canal e registradas individualmente
- O modo `--dry-run` nos jobs permite validar a lógica sem enviar mensagens reais
- `afterCommit()` previne race condition entre o job e a transação do banco

### Negativas / Riscos

- `NotificarAniversariantes` depende do campo `dat_nascimento` na tabela `membros`, que ainda não existe na migration atual — requer migration adicional
- A correspondência entre `Membro.nom_membro` e `Resumo.nom_pessoa` para identificar inadimplentes é frágil: variações de grafia no MEMO do OFX podem causar falsos negativos
- A Evolution API requer infraestrutura Docker própria e manutenção do QR Code de conexão do WhatsApp

---

## Pendências

- [ ] Criar migration para adicionar `dat_nascimento` à tabela `membros`
- [ ] Implementar agendamento via `Schedule` no `routes/console.php` para disparo automático diário
- [ ] ~~Avaliar estratégia de matching entre `nom_membro` e `nom_pessoa` do OFX~~ → **Decidido em [ADR-0002](./0002-matching-ofx-membro.md)**: campo `nom_ofx` no cadastro do membro (Opção C), com caminho de evolução para fuzzy match (Opção B)
- [ ] Adicionar testes unitários para `MensagemBuilder` e `NotificationDispatcher`
