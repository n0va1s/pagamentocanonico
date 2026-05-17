# Procedimentos manuais pós-deploy (Hostinger FTP)

Como o plano atual da Hostinger não permite SSH, algumas operações que normalmente
rodariam via `php artisan` precisam ser feitas manualmente após cada deploy.

---

## 1. Configurar o `.env` de produção (apenas no primeiro deploy)

O arquivo `.env` **nunca é enviado pelo FTP** (está no `.exclude` do workflow).
Crie-o diretamente no servidor:

1. Acesse **Hostinger → Gerenciador de Arquivos**
2. Navegue até `/public_html/pagamento/`
3. Crie o arquivo `.env` com o conteúdo baseado no `.env.example`
4. Preencha as variáveis de produção (DB, MAIL, EVOLUTION, etc.)

---

## 2. Rodar migrations após deploy

### Opção A — Via Gerenciador de Arquivos + PHP Runner (recomendado)

Crie temporariamente o arquivo `/public_html/pagamento/deploy-run.php`:

```php
<?php
// ATENÇÃO: delete este arquivo imediatamente após usar!
// Proteja com uma senha ou token antes de expor.

$token = $_GET['token'] ?? '';
if ($token !== 'SEU_TOKEN_SECRETO_AQUI') {
    http_response_code(403);
    die('Forbidden');
}

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$commands = [
    ['migrate', ['--force' => true]],
    ['config:cache', []],
    ['route:cache', []],
    ['view:cache', []],
    ['event:cache', []],
];

echo '<pre>';
foreach ($commands as [$command, $args]) {
    echo "Running: php artisan {$command}\n";
    $status = $kernel->call($command, $args);
    echo "Exit: {$status}\n\n";
}
echo '</pre>';
```

Acesse via browser:
```
https://seudominio.com/pagamento/deploy-run.php?token=SEU_TOKEN_SECRETO_AQUI
```

**Delete o arquivo imediatamente após usar.**

### Opção B — Hostinger Terminal (planos Business+)

Se o seu plano tiver acesso ao terminal no painel:

```bash
cd /public_html/pagamento
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

## 3. Configurar o `storage` e permissões

Na primeira instalação, crie o link simbólico do storage:

```bash
# Via terminal Hostinger (se disponível)
cd /public_html/pagamento
php artisan storage:link
```

Ou manualmente: crie um link simbólico de `/public_html/pagamento/public/storage`
apontando para `/public_html/pagamento/storage/app/public`.

---

## 4. Secrets necessários no GitHub

Configure em `Settings → Secrets and variables → Actions`:

| Secret | Onde obter |
|---|---|
| `FTP_HOST` | Hostinger → Hospedagem → FTP Accounts → Host |
| `FTP_USER` | Hostinger → Hospedagem → FTP Accounts → Username |
| `FTP_PASSWORD` | Hostinger → Hospedagem → FTP Accounts → Password |
| `FTP_PORT` | Geralmente `21` (FTP) ou `990` (FTPS) |

---

## 5. Checklist de primeiro deploy

- [ ] `.env` criado no servidor com variáveis de produção
- [ ] `APP_KEY` gerada (`php artisan key:generate` ou copie do `.env` local)
- [ ] Banco de dados criado no painel da Hostinger
- [ ] Migrations rodadas via `deploy-run.php` ou terminal
- [ ] `storage:link` executado
- [ ] `deploy-run.php` deletado do servidor
- [ ] Secrets FTP configurados no GitHub
