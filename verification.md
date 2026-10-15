# Verificação integrada — Melhorias OFX (dedup por CHECKNUM + regras de classificação + Pix obrigatório)

Primeira iteração do passo de VERIFICAÇÃO INTEGRADA (não existia `.agents/tasks/review.json`).
Executada no worktree `/.worktrees/melhorias-ofx-classificacao` (branch `melhorias-ofx-classificacao`).
Produção (MySQL `pagamento`) **não foi tocada**. `migrate:fresh` **não foi usado** (bloqueado); schema validado com `php artisan migrate` em SQLite temporário.

Ambiente: PHP 8.4.26, Pest 4, Laravel.

## 1. Schema — `php artisan migrate` em SQLite temporário

Comando (SQLite em arquivo temporário via `mktemp`, env inline, FKs ligadas; nada aponta para produção):

```
TMPDB="$(mktemp -t ofx_schema_XXXX.sqlite)"
APP_ENV=testing APP_KEY=... DB_CONNECTION=sqlite DB_DATABASE="$TMPDB" DB_FOREIGN_KEYS=true \
  php artisan migrate --force --no-ansi
rm -f "$TMPDB"
```

Resultado: **exit 0**. Todas as migrations rodaram (`DONE`), incluindo as alteradas:

```
2026_05_16_114700_create_associacoes_table ..................... DONE
  (cria associacoes + regras_classificacao + regras_classificacao_criterios)
2026_05_16_115100_create_transacoes_table ...................... DONE
  (transacoes + colunas ind_computa_receita / ind_computa_despesa)
```

O schema novo sobe sem erro de ordem de FK (as tabelas de regras são criadas logo após `associacoes`, antes de `transacoes`/`resumos`). Arquivo temporário removido ao final.

## 2. Suíte completa — `php artisan test`

Config de teste: SQLite `:memory:` (`phpunit.xml` + `.env.testing`).

Resultado final:

```
Tests:    14 failed, 28 passed (117 assertions)
Duration: ~8.5s
```

### Teste central — PASSA

```
PASS  Tests\Feature\OfxClassificacaoDedupTest
  ✓ reimportar o mesmo arquivo OFX é idempotente
  ✓ recorte sobreposto reconcilia no mesmo Ofx sem duplicar transações
  ✓ transações Rende Fácil ficam fora da computação mas continuam gravadas
  ✓ resumos de receita não incluem Rende Fácil
```

Cobre os critérios de aceite: reimport idempotente (0 novas transações/resumos/Ofx), recorte sobreposto (202608 vs 202609) reconcilia no MESMO registro Ofx, João Paulo (CPF 85236250110) em set/2026 soma 40,00 (não 80,00), Rende Fácil (CHECKNUM 9903) com `ind_computa_receita=false` e `ind_computa_despesa=false` mas ainda gravado, e resumos de receita sem Rende Fácil.

### OFX parser — PASSA (as 2 que antes falhavam agora passam ao apontar para `scripts/`)

```
PASS  Tests\Feature\OfxParserServiceTest
  ✓ ofx parser service processes BB OFX file correctly
  ✓ ofx parser service does not duplicate ofx records when importing the same file
```

### 14 falhas = baseline conhecido (Vite manifest), SEM regressão

Todas as 14 falhas são `Illuminate\View\ViewException: Vite manifest not found at .../public/build/manifest.json` em testes de renderização de página (login, registro, verificação de e-mail, confirmação de senha, reset de senha, dashboard, profile, security, example). São páginas que carregam `resources/views/partials/head.blade.php` (`@vite`), e o worktree não tem `public/build/manifest.json` (nenhum `npm run build` rodado). Confirmado: 70 ocorrências de "Vite manifest not found" no log; nenhuma falha de lógica.

Suítes afetadas (todas por Vite manifest):
`Auth\AuthenticationTest`, `Auth\EmailVerificationTest`, `Auth\PasswordConfirmationTest`, `Auth\PasswordResetTest`, `Auth\RegistrationTest`, `DashboardTest`, `ExampleTest` (Feature), `Settings\ProfileUpdateTest`, `Settings\SecurityTest`.

Baseline esperado = 14 Vite + 2 OFX. Após esta entrega, as 2 de OFX **passam**; restam apenas as 14 de Vite. Nenhuma regressão nova.

## 3. Lint — `./vendor/bin/pint --test`

Rodado nos arquivos PHP tocados pela entrega:

```
app/Enums/EfeitoRegra.php
app/Models/Associacao.php
app/Models/RegraClassificacao.php
app/Models/RegraClassificacaoCriterio.php
app/Models/Transacao.php
app/Services/OfxParserService.php
database/migrations/2026_05_16_114700_create_associacoes_table.php
database/migrations/2026_05_16_115100_create_transacoes_table.php
routes/web.php
tests/Feature/OfxClassificacaoDedupTest.php
tests/Feature/OfxParserServiceTest.php
```

Resultado: **PASS — 11 files, exit 0**. Nada a corrigir. (Views Blade não são formatadas pelo Pint.)

## 4. Consistência DDL de produção x migrations

`scripts/sql/melhorias_producao.sql` confere coluna-a-coluna com as migrations alteradas:

- `regras_classificacao`: `idt_regra` PK auto-increment, `idt_associacao` FK → `associacoes(idt_associacao)` ON DELETE CASCADE, `nom_regra`, `efeito`, `ind_ativo` TINYINT(1) DEFAULT 1, timestamps.
- `regras_classificacao_criterios`: `idt_criterio` PK, `idt_regra` FK → `regras_classificacao(idt_regra)` ON DELETE CASCADE, `tip_match`, `val_match`, timestamps.
- `transacoes`: `ADD COLUMN ind_computa_receita TINYINT(1) NOT NULL DEFAULT 1` e `ind_computa_despesa TINYINT(1) NOT NULL DEFAULT 1` — equivalem a `boolean(...)->default(true)` no MySQL.

Aplicação é manual em produção pelo dono; nada aqui roda contra o MySQL.

## Conclusão

Verificação integrada OK. Schema sobe limpo em SQLite, teste central passa, suíte sem regressão além do baseline de Vite (14), as 2 de OFX agora passam, e Pint limpo nos arquivos tocados. Nenhuma correção de integração entre as FEATs foi necessária.

---

# Iteração de review — correção dos 4 achados (`.agents/tasks/review.json`)

Verdict do review: `CHANGES_REQUESTED`. Os 4 achados foram corrigidos nesta iteração. Produção **não foi tocada**; schema revalidado em SQLite temporário.

## Achado 1 — Normalização persistida incompleta (confirmado)

`existeNoBanco` comparava o alvo (só dígitos, via `normalizarDigitos`) contra `num_transacao`/`num_check` usando `REPLACE` SQL que removia apenas `.`, `-` e `/`. Separadores como espaço ou parênteses escapavam da dedup.

**Correção** (`app/Services/OfxParserService.php`, `existeNoBanco`): a comparação do CHECKNUM discriminante passou a ser feita em PHP — os candidatos do escopo (associação+banco+conta) são percorridos via `cursor()` e cada `num_transacao`/`num_check` é normalizado com o MESMO `normalizarDigitos` do alvo. Qualquer separador vira igual. Teste novo `dedup por CHECKNUM casa separadores variados em num_transacao e num_check` pré-grava `"12 34"` e `"(56) 78."` e confirma 0 transações novas ao importar `"12-34"` e `"5.6.7.8"`.

## Achado 2 — Critério CHECKNUM vazio após normalização (confirmado)

Um critério CHECKNUM sem dígitos (ex.: `"ABC"`) normalizava para vazio e casava toda transação sem CHECKNUM.

**Correção**:
- Matcher (`criterioCasa` → novo `checknumCriterioCasa`): alvo normalizado vazio **recusa** o match (retorna `false`).
- CRUD (`resources/views/pages/associacoes/regras.blade.php`, `salvar`): validação adicional exige ao menos um dígito em critérios `CHECKNUM`, com mensagem "O CHECKNUM deve conter ao menos um dígito."; interrompe o save se houver erro.

Teste novo `critério CHECKNUM sem dígitos não exclui transações sem CHECKNUM` confirma flags `true` para transação sem CHECKNUM mesmo com regra `CHECKNUM = "ABC"` EXCLUI_TUDO.

## Achado 3 — Idempotência concorrente sem garantia (provável)

A busca/criação do OFX e os pares `exists()`/`create()` de transações rodavam sem lock, podendo intercalar entre importações concorrentes.

**Correção** (`processar`): todo o fluxo (busca/criação do OFX, dedup, resumos) corre dentro de UMA `DB::transaction`. A serialização por contexto é feita com `lockForUpdate`: lock na linha da `associacoes` (sempre existe quando há `idt_associacao`) e, quando já existe, na linha do `ofx`. Importações concorrentes do mesmo contexto são serializadas, evitando OFX e transações duplicados. Sem mudança de schema (nenhuma coluna/índice novo) — comportamento visível ao usuário inalterado. Reimport sequencial continua idempotente (testes centrais seguem passando).

## Achado 4 — Matriz de regras e consumidores sem prova (confirmado)

A evidência anterior cobria só `EXCLUI_TUDO` com um critério.

**Correção** (`tests/Feature/OfxClassificacaoDedupTest.php`): adicionados testes com OFX sintético (helper `escreverOfxTemporario`) cobrindo:
- `EXCLUI_RECEITA` isolado (só a flag de receita zera);
- `EXCLUI_DESPESA` isolado (só a flag de despesa zera);
- lógica E com dois critérios (casa só quando ambos batem);
- regra inativa (não afeta flags);
- sem regra casando (defaults `true`);
- smoke das consultas consumidoras: query da tela de despesas (`DEBIT` + `ind_computa_despesa=true` oculta não computáveis) e da receita do dashboard (`val>0` + `ind_computa_receita=true` exclui não computáveis). As telas em si dependem do Vite manifest (baseline), então o smoke é no nível de consulta, independente do manifest.

## Reexecução das verificações

- Schema (`php artisan migrate` em SQLite temporário): **exit 0**, todas as migrations `DONE`.
- Teste central `OfxClassificacaoDedupTest`: **13 passed** (4 originais + 9 novos).
- `OfxParserServiceTest`: **2 passed**.
- Suíte completa `php artisan test`: **14 failed, 37 passed**. As 14 falhas são exatamente o baseline conhecido de `Vite manifest not found` (login, registro, verificação de e-mail, confirmação/reset de senha, dashboard, profile, security, example) — nenhuma regressão nova.
- `./vendor/bin/pint --test` em `OfxParserService.php` e no teste: **PASS, 2 files**.

---

# Segunda iteração de review — `CHANGES_REQUESTED` (2 achados confirmados)

Verdict: `CHANGES_REQUESTED`. Os 2 achados confirmados foram corrigidos nesta iteração, ambos
sem alterar o comportamento visível ao usuário nem o schema. Produção (MySQL `pagamento`)
**não foi tocada**; schema revalidado em SQLite temporário.

## Achado 1 — Importação sem associação não é serializada (confirmado)

`processar(..., null)` corria sem ponto de sincronização: duas importações concorrentes do mesmo
`NULL|banco|conta` podiam ambas observar a ausência do OFX e ambas criá-lo (o `lockForUpdate` não
trava linha inexistente e não há restrição única para a chave lógica).

**Correção** (`app/Services/OfxParserService.php`): a transação de `processar` passou a rodar dentro
de `comLockDeContexto()`. Com associação, o `lockForUpdate` na linha de `associacoes` continua sendo
o ponto estável de serialização (inalterado). Sem associação, antes de abrir a transação adquire-se
um **lock advisory de sessão** na chave `NULL|banco|conta` (`GET_LOCK`/`RELEASE_LOCK`, MySQL/produção),
serializando o par observar-ausência/criar entre processos concorrentes; o nome do lock é um hash
estável da chave (cabe no limite de 64 chars do MySQL). Em SQLite (testes) a escrita já é serializada
pelo próprio banco, então o lock advisory é dispensado por verificação de driver — nenhum efeito
observável muda. Assinatura pública, schema e caminho com associação permanecem iguais; a decisão de
tornar a associação obrigatória (mudança de comportamento) foi evitada e o caminho `null` preservado,
pois os testes existentes o exercitam.

## Achado 2 — Deduplicação normalizada revarre o histórico (confirmado)

`existeNoBanco()` fazia, para CADA CHECKNUM discriminante, um `cursor()` sobre todas as transações do
contexto (custo ~`N × H`).

**Correção** (`app/Services/OfxParserService.php`): removido `existeNoBanco()`. O importador agora
monta UMA vez, por importação, um **índice canônico em memória** (`carregarIndiceIdentidades()`) com
as identidades das transações já gravadas no contexto — lido com um único `cursor()`. A dedup contra
o banco virou um lookup O(1) nesse set, e cada criação atualiza o índice via `identidadesDeTransacao()`.
Cada transação persistida contribui com: `chk:<dígitos>` de `num_transacao` (FITID) e `num_check`
(CHECKNUM) quando discriminantes, e **sempre** a chave de negócio `biz:data|valor|tipo|cpf` — o `biz:`
é incondicional porque um bloco recebido sem CHECKNUM discriminante casa pela chave de negócio
independentemente de a linha gravada ter FITID numérico. O lado de consulta (`identidadeCanonica()`)
é inalterado: discriminante consulta só `chk:`, não-discriminante só `biz:`. Isso reproduz exatamente
as duas vias do antigo `existeNoBanco()`, reduzindo o trabalho a uma leitura do histórico mais uma
passagem pelo arquivo. Custo passa de ~`N × H` para ~`H + N`.

## Reexecução das verificações (segunda iteração)

- Schema (`php artisan migrate` em SQLite temporário via env inline; produção intocada): **exit 0**,
  todas as migrations `DONE`.
- Teste central `OfxClassificacaoDedupTest`: **13 passed** (inclui idempotência de reimport, recorte
  sobreposto, separadores variados na dedup e matriz de regras).
- `OfxParserServiceTest`: **2 passed** (parse correto + reimport sem duplicar).
- Suíte completa `php artisan test`: **14 failed, 37 passed** — as 14 falhas são exatamente o baseline
  conhecido `Vite manifest not found` (login, registro, verificação/confirmação/reset de senha,
  dashboard, profile, security, example); nenhuma falha fora de `manifest`. Sem regressão nova.
- `./vendor/bin/pint --test app/Services/OfxParserService.php`: **PASS, 1 file**.
