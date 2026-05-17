# ADR-0002 — Estratégia de Matching OFX ↔ Membro

| Campo       | Valor                     |
|-------------|---------------------------|
| **Status**  | Aceito                    |
| **Data**    | 2026-05-17                |
| **Autores** | Equipe de desenvolvimento |

---

## Contexto

Ao importar um extrato OFX, cada transação de crédito gera um registro em `resumos.nom_pessoa` extraído do campo MEMO do arquivo. Esse campo é texto livre gerado pelo banco — não segue padrão fixo e frequentemente contém abreviações, códigos de operação e variações de grafia.

Exemplos reais de como um mesmo pagador pode aparecer no MEMO:

```
JOAO PAULO SILVA
JOAO P SILVA
J PAULO SILVA
PIX JOAO PAULO SILVA AG 1234
TRANSF JOAO PAULO
```

O job `NotificarInadimplentes` precisa cruzar `Resumo.nom_pessoa` com `Membro.nom_membro` para identificar quem está inadimplente. Com igualdade exata, qualquer variação de grafia resulta em falso negativo — o membro inadimplente não é notificado.

---

## Alternativas consideradas

### Opção A — Vínculo manual pelo operador

Uma tela de "pendências de matching" lista os `nom_pessoa` do OFX que não foram associados a nenhum membro. O operador faz a associação manualmente via dropdown. O vínculo é persistido em uma tabela `ofx_vinculos` e reutilizado nas importações seguintes.

**Prós:**
- Precisão total — o operador decide
- Reutilizável entre importações
- Sem risco de associação errada automática

**Contras:**
- Requer tela dedicada de gestão de vínculos
- Operador precisa agir após cada importação com nomes novos
- Maior complexidade de implementação (nova tabela, nova tela, novo fluxo)

---

### Opção B — Fuzzy match automático com confirmação

O sistema calcula a similaridade entre `nom_pessoa` e todos os `nom_membro` usando algoritmos como `similar_text()`, distância de Levenshtein ou `soundex`. Sugestões com alta confiança (ex: > 85%) são aplicadas automaticamente; casos ambíguos são apresentados para confirmação.

**Prós:**
- Experiência mais fluida — menos intervenção manual
- Lida bem com abreviações e pequenas variações de grafia
- Escalável para grandes volumes

**Contras:**
- Risco de falsos positivos — associações erradas com alta confiança
- Algoritmos de similaridade de string têm comportamento imprevisível com nomes brasileiros abreviados
- Requer calibração do threshold de confiança
- Maior complexidade de implementação e testes
- Difícil de auditar — o operador pode não perceber uma associação errada automática

---

### Opção C — Campo `nom_ofx` no cadastro do membro ✅ **(Decisão)**

Adiciona o campo `nom_ofx` ao cadastro do membro, onde o operador informa exatamente como o nome aparece no extrato bancário. O matching passa a comparar `Resumo.nom_pessoa` com `Membro.nom_ofx` (quando preenchido) antes de tentar `Membro.nom_membro`.

**Prós:**
- Simples de implementar — uma migration, um campo no form, uma linha no matching
- Compreensível para o operador — "como seu nome aparece no extrato do banco"
- Determinístico e auditável — sem lógica probabilística
- Reutilizável automaticamente em todas as importações futuras

**Contras:**
- Requer ação do operador no cadastro de cada membro
- Se o banco mudar o formato do MEMO, o campo precisa ser atualizado manualmente
- Não resolve casos onde o mesmo membro aparece com grafias diferentes em importações distintas

---

## Decisão

Adotar a **Opção C** como implementação inicial.

É a abordagem mais simples, mais compreensível para o operador e suficiente para o volume atual. A Opção B permanece como evolução futura desejável — especialmente útil quando o volume de membros crescer e a manutenção manual do `nom_ofx` se tornar custosa.

### Lógica de matching resultante

```
Resumo.nom_pessoa
    └── tenta casar com Membro.nom_ofx  (quando preenchido)
    └── fallback: tenta casar com Membro.nom_membro  (igualdade exata)
    └── sem match → membro não notificado (registra log de aviso)
```

### Campos adicionados

**Tabela `membros`:**

| Campo     | Tipo          | Descrição                                              |
|-----------|---------------|--------------------------------------------------------|
| `nom_ofx` | `varchar(255)` | Nome exato como aparece no MEMO do extrato OFX. Nullable. |

**Formulário de cadastro/edição do membro:**
- Campo opcional com label: *"Nome no extrato bancário (como aparece no OFX)"*
- Hint: *"Preencha se o nome no extrato for diferente do nome cadastrado"*

---

## Caminho de evolução para Opção B

Quando a Opção C se mostrar insuficiente, a migração para fuzzy match pode ser feita de forma incremental:

1. Manter `nom_ofx` como âncora de alta confiança
2. Usar fuzzy match apenas para membros sem `nom_ofx` cadastrado
3. Apresentar sugestões para confirmação — nunca aplicar automaticamente sem revisão
4. Persistir os vínculos confirmados em `nom_ofx` para eliminar a necessidade de re-confirmar

Bibliotecas PHP candidatas para a Opção B:
- `php-levenshtein` — distância de edição, bom para abreviações
- `soundex()` / `metaphone()` — nativo PHP, fonético, útil para nomes brasileiros
- `similar_text()` — nativo PHP, percentual de similaridade direto

---

## Consequências

- Migration simples: `$table->string('nom_ofx')->nullable()`
- Atualização do form Volt de membros com o novo campo
- Atualização do `NotificarInadimplentes` para usar `nom_ofx ?? nom_membro` no matching
- Membros sem `nom_ofx` continuam funcionando via `nom_membro` (sem breaking change)
- Operadores precisam ser orientados a preencher `nom_ofx` no cadastro

---

## Pendências

- [ ] Migration: adicionar `nom_ofx` à tabela `membros`
- [ ] Atualizar model `Membro` com o novo campo em `$fillable`
- [ ] Atualizar form Volt (`⚡form.blade.php`) com o campo opcional
- [ ] Atualizar `NotificarInadimplentes` com a lógica de fallback
- [ ] Atualizar ADR-0001 referenciando esta decisão
