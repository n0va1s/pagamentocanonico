# ADR-0003 — Auditoria de Pagamentos Duplicados e Reconciliação Incremental de OFX

| Campo       | Valor                     |
|-------------|---------------------------|
| **Status**  | Aceito                    |
| **Data**    | 2026-05-17                |
| **Autores** | Equipe de desenvolvimento |

---

## Contexto

Em produção, ao reimportar o arquivo OFX do mês, o dashboard passou a somar **R$ 80,00 em vez de R$ 40,00**. A investigação do código confirmou três causas-raiz no fluxo de importação (`OfxParserService`):

1. **Resumos de pessoas sem membro criados sem idempotência.** Para pagadores sem `idt_membro`, `gerarResumosMensais()` chamava `Resumo::create(...)` direto, agrupando pela descrição limpa do MEMO (`des_transacao`). A cada reimportação, um novo `Resumo` era criado — e o dashboard soma os resumos sem membro na linha "Transações Avulsas / Não Identificadas", dobrando o valor.
2. **Deduplicação de FITID global em vez de escopada.** A verificação era `Transacao::where('num_transacao', $fitid)->exists()` — global, sem considerar associação nem conta. Isso tanto suprimia transações legítimas de outra conta/associação com FITID coincidente quanto não impedia duplicação correta no escopo certo.
3. **Ausência de assinatura do arquivo.** Não havia como reconhecer um arquivo byte a byte idêntico já processado para evitar reprocessamento.

Além disso, a operação real importa **vários arquivos OFX do mesmo período ao longo do mês** (pessoas pagam em dias diferentes). Logo, a reimportação **não pode ser bloqueada** — precisa ser uma **reconciliação idempotente**: adicionar só o que é novo, recalcular a partir das transações reais, sem acumular.

Por fim, era preciso dar visibilidade e correção às duplicidades **já existentes** no banco (dados legados), respeitando o isolamento multi-tenant por associação.

A chave de pessoa sem membro definida no glossário (`idt_ofx + nom_pessoa + ano + mês`) é frágil: `limparDescricao()` colapsa pagadores distintos no literal `'Não identificado'`. Isso exigiu uma decisão específica sobre a chave de idempotência (ver abaixo), complementar ao matching OFX↔Membro decidido no [ADR-0002](./0002-matching-ofx-membro.md).

---

## Decisão

Dividir a solução em duas frentes complementares:

1. **Corrigir o fluxo de importação** para que a reimportação seja uma reconciliação idempotente (resolve a causa-raiz do R$ 80 vs R$ 40 e impede novas duplicidades).
2. **Adicionar uma camada de auditoria** (`AuditoriaService` + tela Volt `pages/auditoria`) para detectar, relatar e corrigir duplicidades pré-existentes, com indicador no dashboard.

### Critério canônico de crédito

Fixou-se **`tip_transacao = 'CREDIT'`** como o único critério de "crédito" em toda a feature (importação, resumos e auditoria), alinhado ao que o dashboard já exibia. Antes, `gerarResumosMensais()` usava `val_transacao > 0`, divergente do `val_total` do OFX.

### Conta de origem canônica

A identidade da conta é sempre `COALESCE(cod_banco,'') + COALESCE(num_conta,'')`, usada de forma **idêntica** nas três camadas: reúso do `Registro_OFX`, deduplicação na inserção e detecção na auditoria. O uso de `COALESCE` trata colunas nulas (extratos legados sem conta) como string vazia, evitando o comportamento `coluna = NULL` (UNKNOWN) do SQL.

### Chave de idempotência da pessoa sem membro

Separou-se **exibição** de **idempotência**:

| Coluna       | Papel                                                                 |
|--------------|-----------------------------------------------------------------------|
| `nom_pessoa` | Rótulo humano exibido no relatório (MEMO limpo). Apenas exibição.     |
| `chv_pessoa` | Chave de idempotência determinística com precedência de discriminantes. |

Precedência de `chv_pessoa` (método `chavePessoaSemMembro()`):

```
1. cpf:{dígitos}        → CPF do pagador (discriminante mais forte)
2. desc:{descrição}     → MEMO limpo normalizado, quando != 'Não identificado'
3. name:{NAME}          → tag NAME crua normalizada
4. nao_identificado     → literal (pagadores realmente anônimos colapsam, explicitamente)
```

A unicidade por pessoa sem membro é responsabilidade da **camada de serviço** (`updateOrCreate` por `idt_ofx + chv_pessoa + ano + mês`), não do banco — um índice `UNIQUE` sobre colunas nulas repetiria o problema de múltiplos `NULL` do MySQL.

### Reconciliação incremental (passos de `processar()`)

```
1. Hash SHA-256 do arquivo ANTES de qualquer escrita (RuntimeException se ilegível).
2. Curto-circuito: hash idêntico na associação → atualiza des_arquivo e retorna
   (status 'ja_atualizado'), sem inserir transações nem alterar resumos.
3. Reúso/criação do Registro_OFX pela chave (associação, Conta_Origem, dat_inicio, dat_fim).
4. Transação de banco por arquivo: insere só FITIDs novos (dedup escopada),
   recalcula qtd_transacao e val_total A PARTIR DO BANCO (sem acúmulo), gera resumos.
5. Anexa $ofx->resultadoImportacao (status + contadores) para a UI.
```

A deduplicação de FITID passou a ser escopada por associação + `Conta_Origem` via `whereHas('ofx')`, com dedup intra-arquivo em memória e contagem de FITIDs inválidos (ausentes/vazios).

### Consolidação de resumos (idempotência e salvaguardas)

- **Com membro:** `updateOrCreate` por `idt_ofx + idt_membro + ano + mês`.
- **Mesclagem em colisão** da `UNIQUE (idt_ofx, idt_membro, num_ano, num_mes)` (quando `atualizarPagamentos()` converte um resumo sem-membro para um membro que já tinha resumo no período): mantém o de menor `idt_resumo`, reatribui as transações, recalcula sem acúmulo e remove os excedentes.
- **Consolidação de órfãos sem-membro** com duas salvaguardas: (1) nunca remover chave não-discriminante (`nao_identificado` / `'Não identificado'`); (2) só remover órfão discriminante quando **todas** as transações migraram para **um único** membro cujo resumo cobre a quantidade.

### Camada de auditoria

`AuditoriaService` (stateless, sempre com `withoutGlobalScopes()` + filtro explícito por associação):

- `detectar(int): array` — grupos de Transação_Duplicada, Resumo_Duplicado e OFX reimportado/período redundante, mais `total_excedente` (critério CREDIT).
- `contarPendentes(int): int` — agregação leve para o indicador do dashboard.
- `corrigirTransacoesDuplicadas(...)` / `corrigirResumosDuplicados(...)` — correção atômica (`DB::transaction`), mantém o de menor id, recalcula os OFX afetados, registra log e revalida a associação (recusa cross-tenant com `AutorizacaoAuditoriaException`).

A `chaveGrupo` é uma string serializada com prefixo de tipo (`tx|...` e `rs|...`) e campos textuais com `rawurlencode`, decomposta e revalidada dentro da transação.

### Enforcement multi-tenant

Dupla camada: a rota (`role:admin,diretor`) e o serviço (revalidação explícita do dono do grupo). Não-admin **sempre** deriva a associação de `getMembroAssociacaoId()`, ignorando qualquer parâmetro da requisição. **Diretor sem membro vinculado** (`getMembroAssociacaoId() === null`) é barrado na camada de apresentação — a tela e o dashboard **não chamam o serviço** (cujo contrato é `int`), exibindo estado vazio/badge oculto.

---

## Alternativas consideradas

### Bloquear reimportação por hash

Rejeitada. O fluxo real precisa de reimportações sucessivas do mesmo período para incorporar novos pagamentos. O hash é usado como **otimização e sinalização** (reconhecer arquivo idêntico, alertar reimportação), nunca como bloqueio.

### Índice `UNIQUE` no banco para resumos sem membro

Rejeitada. Em MySQL/MariaDB, múltiplos `NULL` são permitidos em índice único, então um `UNIQUE` com `idt_membro`/`chv_pessoa` nulos não impediria duplicatas. A idempotência depende de normalização textual que o banco não garante — logo, é responsabilidade do serviço.

### Chave de sem-membro pelo MEMO limpo

Rejeitada como chave de idempotência. `limparDescricao()` retorna `'Não identificado'` para múltiplos pagadores distintos, colapsando-os. Mantido apenas como rótulo de exibição (`nom_pessoa`).

### Estender a detecção de Resumo_Duplicado para ignorar `idt_ofx`

Fora de escopo. O Requisito 6.1 fixa a chave em `idt_ofx`. Para não deixar o caso invisível, a detecção de OFX reimportado foi ampliada para **sinalizar** (apenas alerta, não entra no total) OFX de mesma `Conta_Origem` com períodos sobrepostos.

---

## Consequências

### Positivas

- A reimportação do mesmo período é idempotente: o dashboard volta a somar R$ 40,00.
- A dedup de inserção e a detecção da auditoria usam exatamente a mesma `Conta_Origem`, eliminando transações legítimas suprimidas silenciosamente.
- Duplicidades legadas tornam-se visíveis e corrigíveis, com correção atômica e auditável (log estruturado).
- Isolamento multi-tenant preservado e explicitamente testado (inclusive diretor sem associação).

### Negativas / Riscos

- A assinatura pública `processar(string, string, ?int): Ofx` foi mantida travada; os contadores trafegam por atributo dinâmico `$ofx->resultadoImportacao`, não persistido.
- OFX de períodos ligeiramente divergentes (datas diferentes) geram `Registro_OFX` distintos; a duplicidade de resumos entre eles fica como **sinalização informativa**, não correção automática.
- A tela de auditoria lista grupos agregados; para volumes muito grandes, a listagem depende de paginação/limite (o cálculo de totais usa agregação no banco).

---

## Alterações de esquema

**Tabela `ofx`:**

| Campo          | Tipo           | Descrição                                                        |
|----------------|----------------|------------------------------------------------------------------|
| `hash_arquivo` | `varchar(64)`  | Assinatura SHA-256 (hexdigest). Nullable (legados), index simples. |

**Tabela `resumos`:**

| Campo        | Tipo           | Descrição                                                     |
|--------------|----------------|---------------------------------------------------------------|
| `nom_pessoa` | `varchar(255)` | Rótulo humano do pagador sem membro (exibição). Nullable.     |
| `chv_pessoa` | `varchar(191)` | Chave de idempotência normalizada. Nullable. Index composto `(idt_ofx, chv_pessoa, num_ano, num_mes)`. |

A `UNIQUE (idt_ofx, idt_membro, num_ano, num_mes)` existente foi preservada.

---

## Verificação

- Migrações aplicadas via `./vendor/bin/sail artisan migrate --force`.
- Suíte de testes nova (Pest, `tests/Feature`) **verde** cobrindo: reprodução do R$ 40 vs R$ 80, reconciliação incremental, dedup escopada por associação/conta, curto-circuito por hash, detecção e correção de duplicidades, multi-tenant, diretor sem associação, indicador do dashboard e consolidação de órfãos.
- `./vendor/bin/pint --test` sem violações nos arquivos da feature.
- `tests/Feature/DashboardTest.php` preservado (sem alterações).

> **Nota:** os dois testes de `tests/Feature/OfxParserServiceTest.php` dependem da fixture `docs/ofx/Extrato conta corrente - 032026.ofx`, que não está versionada. Sua inexequibilidade por `file_exists()===false` não é regressão desta feature. Os testes novos são autossuficientes (factories/inserts; fixture OFX mínima gerada em diretório temporário quando o parser precisa rodar ponta a ponta).

---

## Arquivos

**Criados:**
- `app/Services/AuditoriaService.php`
- `app/Exceptions/AutorizacaoAuditoriaException.php`
- `resources/views/pages/auditoria.blade.php`
- `database/migrations/2026_05_17_000001_add_hash_arquivo_to_ofx_table.php`
- `database/migrations/2026_05_17_000002_add_nom_pessoa_chv_pessoa_to_resumos_table.php`

**Modificados:**
- `app/Services/OfxParserService.php`
- `resources/views/pages/ofx/upload.blade.php`
- `resources/views/pages/dashboard.blade.php`
- `resources/views/layouts/app.blade.php`, `layouts/app/header.blade.php`, `layouts/app/sidebar.blade.php`
- `routes/web.php`
- `app/Models/Ofx.php`, `app/Models/Resumo.php`

---

## Pendências

- [ ] Avaliar paginação/limite por tipo de grupo na tela de auditoria para associações de volume muito alto
- [ ] Monitorar, via auditoria, casos de OFX de período sobreposto para decidir se vale correção assistida (hoje apenas sinalização)
- [ ] Orientar operadores sobre o fluxo de correção de duplicidades legadas