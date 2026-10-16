# Resumo — Melhorias OFX (dedup por CHECKNUM + regras de classificação + Pix obrigatório)

Branch: `melhorias-ofx-classificacao`. Verificação integrada concluída (schema sobe em SQLite, teste central passa, suíte sem regressão além do baseline de Vite, Pint limpo). Detalhes da evidência em `verification.md`.

Produção (MySQL `pagamento`) é atualizada **manualmente** — nenhuma migration roda contra ela.

## O que mudou por FEAT

### FEAT-001 — Fundação (enum, tabelas, models, flags)
- Novo enum `app/Enums/EfeitoRegra.php`: `EXCLUI_RECEITA`, `EXCLUI_DESPESA`, `EXCLUI_TUDO` (com `label()`).
- Migrations alteradas (sem migration nova):
  - `2026_05_16_114700_create_associacoes_table.php`: cria `regras_classificacao` e `regras_classificacao_criterios` (FKs cascade), logo após `associacoes`.
  - `2026_05_16_115100_create_transacoes_table.php`: colunas `ind_computa_receita` e `ind_computa_despesa` (boolean, default `true`).
- Models: `RegraClassificacao`, `RegraClassificacaoCriterio`; `Associacao` ganha `regrasClassificacao` (hasMany); `Transacao` ganha os dois flags em `$fillable` + cast boolean.

### FEAT-002 — Dedup canônica + regras na importação (parser)
- `OfxParserService`:
  - Chave lógica do registro OFX passa a ser `associação + banco + conta` (sem período) → reimport de recorte sobreposto reconcilia no MESMO Ofx, não cria outro.
  - Identidade canônica por transação: CHECKNUM normalizado (só dígitos) quando discriminante; senão chave de negócio (data+valor+tipo+CPF). CHECKNUMs não-discriminantes (ex.: `9903`) tratados à parte.
  - Dedup contra o banco casa o CHECKNUM normalizado da nova transação contra `num_transacao` **e** `num_check` já gravados (ambos só-dígitos), no escopo associação+banco+conta. Dedup intra-arquivo pela mesma identidade.
  - Regras ativas da associação aplicadas na importação (todos os critérios casam = lógica E): `NOM_PESSOA`/`DES_TRANSACAO` (contém, case-insensitive), `CHECKNUM` (igualdade normalizada), `TIPO` (igualdade). Efeito grava os flags; default ambos `true`. Nada é descartado na importação.
  - `gerarResumosMensais` soma receita só de transações com `ind_computa_receita = true` e é idempotente no reimport (não dobra avulsos).
- Teste central `tests/Feature/OfxClassificacaoDedupTest.php` (critério de aceite).

### FEAT-003 — Consumidores dos flags no front
- `dashboard.blade.php`: `totalRecebido` e `totalNaoIdentificado` só com `ind_computa_receita = true`; `totalPagoDespesas` só com `ind_computa_despesa = true`.
- `despesas/index.blade.php`: lista de DEBIT oculta `ind_computa_despesa = false`.
- MUDANÇA B: DEBIT `Pix - Enviado` sem anexo exibe badge vermelho "Documento obrigatório" (anexo obrigatório); demais DEBIT sem anexo seguem "Pendente".

### FEAT-004 — CRUD de regras por associação (UI)
- Rota Volt `role:admin` para regras da associação (`routes/web.php`).
- Ícone em cada card de `associacoes/index.blade.php` abre a tela de regras.
- `associacoes/regras.blade.php`: CRUD Flux (criar/editar/ativar-desativar/excluir regra + critérios; `efeito` via `EfeitoRegra::label()`; mínimo 1 critério por regra).
- Confirmada ausência do command `DiagnosticarPagamentos` (nada a remover).

## SQL de produção

Arquivo: `scripts/sql/melhorias_producao.sql` (DDL MySQL, aplicação manual pelo dono).
Confere coluna-a-coluna com as migrations:
- `CREATE TABLE regras_classificacao` (FK → `associacoes` ON DELETE CASCADE).
- `CREATE TABLE regras_classificacao_criterios` (FK → `regras_classificacao` ON DELETE CASCADE).
- `ALTER TABLE transacoes ADD COLUMN ind_computa_receita TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN ind_computa_despesa TINYINT(1) NOT NULL DEFAULT 1`.
Rodar com backup prévio e, de preferência, dentro de uma transação.

## Passos de deploy

1. **Aplicar `scripts/sql/melhorias_producao.sql`** no MySQL de produção (backup antes; de preferência dentro de uma transação). Cria as duas tabelas de regras e adiciona os dois flags em `transacoes`.
2. **Cadastrar a regra `9903` → `EXCLUI_TUDO` por associação**: na tela de Associações, abrir Regras da associação, criar regra com efeito "Excluir de Tudo" e um critério `CHECKNUM` = `9903` (Rende Fácil), ativa. Repetir por associação que use o BB Rende Fácil.
3. **Apagar a base e reimportar os arquivos**: limpar os dados de OFX/transações/resumos e reimportar os extratos OFX. Com a chave lógica sem período e a dedup canônica por CHECKNUM, os recortes sobrepostos reconciliam sem duplicar; com a regra do passo 2 já cadastrada, Rende Fácil entra marcado como não computável (receita e despesa) desde a importação, sem inflar dashboard, resumos nem /despesas.
