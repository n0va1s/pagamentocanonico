-- =============================================================================
-- Script de CORRECAO de Inconsistencias — Pagamentos / Importacao OFX
-- =============================================================================
--
-- !!! ATENCAO — ESTE SCRIPT ALTERA E REMOVE DADOS !!!
--
--   Diferente de docs/sql/auditoria_inconsistencias.sql (que e somente-leitura),
--   este arquivo contem UPDATE e DELETE. Rode com extrema cautela em producao.
--
--   REGRAS DE OURO (leia antes de executar qualquer coisa):
--   1. FACA BACKUP COMPLETO ANTES (secao 0). Nao pule.
--   2. NUNCA rode o arquivo inteiro de uma vez. Execute UMA correcao por vez.
--   3. Para cada correcao, rode PRIMEIRO o bloco "PREVIEW" (SELECT) e confira
--      o que sera alterado/removido. So depois rode o bloco de correcao.
--   4. Cada correcao esta dentro de START TRANSACTION ... (COMMIT|ROLLBACK).
--      Deixei COMMIT comentado de proposito: confira o resultado e, se estiver
--      correto, rode COMMIT manualmente; se algo parecer errado, rode ROLLBACK.
--   5. Rode de novo o script de diagnostico apos cada correcao para confirmar.
--
--   Correlacao com o diagnostico (auditoria_inconsistencias.sql):
--     Verif. 1  (OFX reimportado, hash identico)   -> Correcao A
--     Verif. 2  (OFX de periodo sobreposto)        -> SEM correcao automatica (manual)
--     Verif. 3  (transacoes duplicadas por FITID)  -> Correcao B
--     Verif. 4  (resumos duplicados — R$80 vs R$40)-> Correcao C
--     Verif. 5  (divergencia de totais do OFX)     -> Correcao D (recalculo)
--     Verif. 6  (transacoes sem FITID)             -> SEM correcao automatica (manual)
--     Verif. 7  (resumo executivo)                 -> consequencia de B/C/D
--
--   PRINCIPIO GERAL DE "qual registro manter": sempre mantemos o de MENOR id
--   (idt_transacao / idt_resumo / idt_ofx) e removemos as copias excedentes —
--   mesma regra que a feature de auditoria aplica no codigo.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- Filtro opcional de associacao (mesma convencao do diagnostico).
-- Deixe NULL para abranger todas; informe o id para focar numa associacao.
-- -----------------------------------------------------------------------------
SET @idt_associacao = NULL;   -- ex.: SET @idt_associacao = 3;


-- =============================================================================
-- 0. BACKUP OBRIGATORIO — rode ISTO primeiro, fora deste cliente SQL
-- =============================================================================
-- Pela linha de comando (recomendado), gere um dump completo antes de tudo:
--
--   mysqldump -u <usuario> -p <banco> ofx transacoes resumos \
--     > backup_pagamentos_$(date +%Y%m%d_%H%M%S).sql
--
-- Alternativa dentro do banco: tabelas-copia de seguranca (snapshots).
-- Rode uma vez; se ja existirem, apague antes ou use outro sufixo.
--
--   CREATE TABLE bkp_ofx_YYYYMMDD        AS SELECT * FROM ofx;
--   CREATE TABLE bkp_transacoes_YYYYMMDD AS SELECT * FROM transacoes;
--   CREATE TABLE bkp_resumos_YYYYMMDD    AS SELECT * FROM resumos;
--
-- Para restaurar uma tabela a partir do snapshot (se precisar reverter apos COMMIT):
--   -- exemplo resumos:
--   -- DELETE FROM resumos; INSERT INTO resumos SELECT * FROM bkp_resumos_YYYYMMDD;
-- =============================================================================


-- #############################################################################
-- CORRECAO A — OFX REIMPORTADO (mesmo hash_arquivo na mesma associacao)
--   Corresponde a Verificacao 1. Requer a coluna ofx.hash_arquivo (feature nova).
--
--   O QUE FAZ: para cada grupo de OFX com o mesmo hash na mesma associacao,
--   mantem o OFX de MENOR idt_ofx e remove os demais. As transacoes e resumos
--   dos OFX removidos SAO APAGADOS JUNTO (FK onDelete cascade em transacoes;
--   resumos tem onDelete cascade pelo idt_ofx). Isso elimina a copia inteira
--   do arquivo reimportado — e o caminho mais limpo quando o mesmo arquivo
--   entrou duas vezes como OFX separados.
--
--   ATENCAO: so use se o OFX duplicado for REALMENTE o mesmo arquivo (hash
--   identico garante isso). Se houver duvida, prefira a Correcao B/C, que
--   agem no nivel de transacao/resumo sem apagar o OFX inteiro.
-- #############################################################################

-- --- A.1 PREVIEW: quais OFX seriam removidos (os NAO-minimos de cada grupo) ---
SELECT o.idt_ofx, o.idt_associacao, o.hash_arquivo, o.des_arquivo,
       o.qtd_transacao, o.val_total,
       g.idt_ofx_manter
FROM ofx o
JOIN (
    SELECT idt_associacao, hash_arquivo, MIN(idt_ofx) AS idt_ofx_manter
    FROM ofx
    WHERE hash_arquivo IS NOT NULL
    GROUP BY idt_associacao, hash_arquivo
    HAVING COUNT(*) > 1
) g ON g.idt_associacao <=> o.idt_associacao
   AND g.hash_arquivo = o.hash_arquivo
WHERE o.idt_ofx <> g.idt_ofx_manter
  AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao);

-- --- A.2 CORRECAO: remover os OFX duplicados (confira A.1 antes!) ---
-- START TRANSACTION;
--
-- DELETE o
-- FROM ofx o
-- JOIN (
--     SELECT idt_associacao, hash_arquivo, MIN(idt_ofx) AS idt_ofx_manter
--     FROM ofx
--     WHERE hash_arquivo IS NOT NULL
--     GROUP BY idt_associacao, hash_arquivo
--     HAVING COUNT(*) > 1
-- ) g ON g.idt_associacao <=> o.idt_associacao
--    AND g.hash_arquivo = o.hash_arquivo
-- WHERE o.idt_ofx <> g.idt_ofx_manter
--   AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao);
--
-- -- Confira o numero de linhas afetadas e os totais; se OK:
-- -- COMMIT;
-- -- senao:
-- -- ROLLBACK;


-- #############################################################################
-- CORRECAO B — TRANSACOES DUPLICADAS (mesmo FITID na associacao + Conta_Origem)
--   Corresponde a Verificacao 3.
--
--   O QUE FAZ: dentro de cada grupo (associacao + cod_banco + num_conta + FITID),
--   mantem a transacao de MENOR idt_transacao e remove as copias excedentes.
--   Em seguida (Correcao D) os totais dos OFX afetados sao recalculados.
--
--   Conta_Origem = COALESCE(cod_banco,'') + COALESCE(num_conta,''), igual ao
--   diagnostico e ao codigo da feature.
-- #############################################################################

-- --- B.1 PREVIEW: transacoes que seriam removidas (as nao-minimas do grupo) ---
SELECT t.idt_transacao, o.idt_associacao,
       COALESCE(o.cod_banco,'') AS cod_banco,
       COALESCE(o.num_conta,'') AS num_conta,
       t.num_transacao AS fitid, t.dat_transacao, t.val_transacao, t.tip_transacao,
       k.idt_transacao_manter
FROM transacoes t
JOIN ofx o ON o.idt_ofx = t.idt_ofx
JOIN (
    SELECT o2.idt_associacao,
           COALESCE(o2.cod_banco,'') AS cb,
           COALESCE(o2.num_conta,'') AS nc,
           t2.num_transacao,
           MIN(t2.idt_transacao) AS idt_transacao_manter
    FROM transacoes t2
    JOIN ofx o2 ON o2.idt_ofx = t2.idt_ofx
    WHERE t2.num_transacao IS NOT NULL AND t2.num_transacao <> ''
    GROUP BY o2.idt_associacao, COALESCE(o2.cod_banco,''), COALESCE(o2.num_conta,''), t2.num_transacao
    HAVING COUNT(*) > 1
) k ON k.idt_associacao <=> o.idt_associacao
   AND k.cb = COALESCE(o.cod_banco,'')
   AND k.nc = COALESCE(o.num_conta,'')
   AND k.num_transacao = t.num_transacao
WHERE t.idt_transacao <> k.idt_transacao_manter
  AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)
ORDER BY o.idt_associacao, fitid, t.idt_transacao;

-- --- B.2 CORRECAO: remover as transacoes duplicadas (confira B.1 antes!) ---
-- START TRANSACTION;
--
-- DELETE t
-- FROM transacoes t
-- JOIN ofx o ON o.idt_ofx = t.idt_ofx
-- JOIN (
--     SELECT o2.idt_associacao,
--            COALESCE(o2.cod_banco,'') AS cb,
--            COALESCE(o2.num_conta,'') AS nc,
--            t2.num_transacao,
--            MIN(t2.idt_transacao) AS idt_transacao_manter
--     FROM transacoes t2
--     JOIN ofx o2 ON o2.idt_ofx = t2.idt_ofx
--     WHERE t2.num_transacao IS NOT NULL AND t2.num_transacao <> ''
--     GROUP BY o2.idt_associacao, COALESCE(o2.cod_banco,''), COALESCE(o2.num_conta,''), t2.num_transacao
--     HAVING COUNT(*) > 1
-- ) k ON k.idt_associacao <=> o.idt_associacao
--    AND k.cb = COALESCE(o.cod_banco,'')
--    AND k.nc = COALESCE(o.num_conta,'')
--    AND k.num_transacao = t.num_transacao
-- WHERE t.idt_transacao <> k.idt_transacao_manter
--   AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao);
--
-- -- IMPORTANTE: apos remover transacoes, rode a CORRECAO D para recalcular os
-- -- totais dos OFX e os resumos afetados. Confira a contagem; se OK:
-- -- COMMIT;
-- -- senao:
-- -- ROLLBACK;


-- #############################################################################
-- CORRECAO C — RESUMOS DUPLICADOS (CAUSA DO R$ 80 vs R$ 40)
--   Corresponde a Verificacao 4.
--
--   O QUE FAZ: dentro de cada grupo (idt_ofx + num_ano + num_mes + idt_membro),
--   mantem o resumo de MENOR idt_resumo, reatribui as transacoes que apontavam
--   para os resumos excedentes (idt_resumo) para o resumo mantido, e remove os
--   excedentes. Depois recalcula val_total/qtd_transacao do resumo mantido a
--   partir das transacoes de credito reais (NUNCA somando os val_total antigos).
--
--   OBS sobre sem-membro: quando idt_membro IS NULL, o agrupamento por
--   (idt_ofx, ano, mes, idt_membro) junta todos os sem-membro do mesmo mes em
--   um unico grupo. Em bases SEM a coluna chv_pessoa (feature nao aplicada),
--   nao ha como distinguir pagadores anonimos distintos; por isso o passo C
--   para sem-membro recalcula o resumo mantido a partir das transacoes reais,
--   o que corrige a dupla contagem sem inventar separacao. Se voce ja aplicou a
--   feature (coluna chv_pessoa existe), prefira reimportar o mes, que reconstroi
--   os resumos de forma idempotente.
-- #############################################################################

-- --- C.1 PREVIEW: resumos que seriam removidos (os nao-minimos de cada grupo) ---
SELECT r.idt_resumo, o.idt_associacao, r.idt_ofx, r.num_ano, r.num_mes,
       r.idt_membro, r.val_total, r.qtd_transacao,
       k.idt_resumo_manter
FROM resumos r
JOIN ofx o ON o.idt_ofx = r.idt_ofx
JOIN (
    SELECT idt_ofx, num_ano, num_mes, idt_membro,
           MIN(idt_resumo) AS idt_resumo_manter
    FROM resumos
    GROUP BY idt_ofx, num_ano, num_mes, idt_membro
    HAVING COUNT(*) > 1
) k ON k.idt_ofx = r.idt_ofx
   AND k.num_ano = r.num_ano
   AND k.num_mes = r.num_mes
   AND k.idt_membro <=> r.idt_membro
WHERE r.idt_resumo <> k.idt_resumo_manter
  AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)
ORDER BY o.idt_associacao, r.idt_ofx, r.num_ano, r.num_mes;

-- --- C.2 CORRECAO (3 passos dentro de uma transacao; confira C.1 antes!) ---
-- START TRANSACTION;
--
-- -- Passo 1: reatribuir transacoes dos resumos excedentes para o resumo mantido.
-- UPDATE transacoes t
-- JOIN resumos r      ON r.idt_resumo = t.idt_resumo
-- JOIN ofx o          ON o.idt_ofx = r.idt_ofx
-- JOIN (
--     SELECT idt_ofx, num_ano, num_mes, idt_membro,
--            MIN(idt_resumo) AS idt_resumo_manter
--     FROM resumos
--     GROUP BY idt_ofx, num_ano, num_mes, idt_membro
--     HAVING COUNT(*) > 1
-- ) k ON k.idt_ofx = r.idt_ofx
--    AND k.num_ano = r.num_ano
--    AND k.num_mes = r.num_mes
--    AND k.idt_membro <=> r.idt_membro
-- SET t.idt_resumo = k.idt_resumo_manter
-- WHERE r.idt_resumo <> k.idt_resumo_manter
--   AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao);
--
-- -- Passo 2: remover os resumos excedentes (agora sem transacoes apontando).
-- DELETE r
-- FROM resumos r
-- JOIN ofx o ON o.idt_ofx = r.idt_ofx
-- JOIN (
--     SELECT idt_ofx, num_ano, num_mes, idt_membro,
--            MIN(idt_resumo) AS idt_resumo_manter
--     FROM resumos
--     GROUP BY idt_ofx, num_ano, num_mes, idt_membro
--     HAVING COUNT(*) > 1
-- ) k ON k.idt_ofx = r.idt_ofx
--    AND k.num_ano = r.num_ano
--    AND k.num_mes = r.num_mes
--    AND k.idt_membro <=> r.idt_membro
-- WHERE r.idt_resumo <> k.idt_resumo_manter
--   AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao);
--
-- -- Passo 3: recalcular val_total e qtd_transacao dos resumos mantidos a partir
-- --          das transacoes de credito reais (sem acumular). So atualiza resumos
-- --          que ainda tem transacoes vinculadas.
-- UPDATE resumos r
-- JOIN (
--     SELECT t.idt_resumo,
--            ROUND(SUM(CASE WHEN t.tip_transacao='CREDIT' THEN t.val_transacao ELSE 0 END), 2) AS novo_val,
--            COUNT(*) AS nova_qtd
--     FROM transacoes t
--     WHERE t.idt_resumo IS NOT NULL
--     GROUP BY t.idt_resumo
-- ) x ON x.idt_resumo = r.idt_resumo
-- JOIN ofx o ON o.idt_ofx = r.idt_ofx
-- SET r.val_total = x.novo_val,
--     r.qtd_transacao = x.nova_qtd
-- WHERE (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao);
--
-- -- Confira (rode a Verificacao 4 do diagnostico num outro cliente); se OK:
-- -- COMMIT;
-- -- senao:
-- -- ROLLBACK;


-- #############################################################################
-- CORRECAO D — RECALCULAR TOTAIS DO OFX (qtd_transacao e val_total)
--   Corresponde a Verificacao 5. Rode SEMPRE depois de B (e util apos A/C tb).
--
--   O QUE FAZ: regrava ofx.qtd_transacao (contagem de transacoes) e ofx.val_total
--   (soma dos creditos, tip_transacao='CREDIT') a partir das transacoes reais.
--   E uma operacao idempotente e segura: so ajusta o cabecalho do OFX ao que
--   realmente existe nas transacoes.
-- #############################################################################

-- --- D.1 PREVIEW: como ficariam os totais (compare com os atuais) ---
SELECT o.idt_ofx, o.idt_associacao, o.des_arquivo,
       o.qtd_transacao AS qtd_atual,
       COUNT(t.idt_transacao) AS qtd_nova,
       ROUND(o.val_total, 2) AS val_atual,
       ROUND(SUM(CASE WHEN t.tip_transacao='CREDIT' THEN t.val_transacao ELSE 0 END), 2) AS val_novo
FROM ofx o
LEFT JOIN transacoes t ON t.idt_ofx = o.idt_ofx
WHERE (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)
GROUP BY o.idt_ofx, o.idt_associacao, o.des_arquivo, o.qtd_transacao, o.val_total
HAVING o.qtd_transacao <> COUNT(t.idt_transacao)
    OR ABS(ROUND(o.val_total
          - SUM(CASE WHEN t.tip_transacao='CREDIT' THEN t.val_transacao ELSE 0 END), 2)) > 0.001;

-- --- D.2 CORRECAO: regravar os totais (seguro; confira D.1 antes) ---
-- START TRANSACTION;
--
-- UPDATE ofx o
-- LEFT JOIN (
--     SELECT t.idt_ofx,
--            COUNT(*) AS qtd,
--            ROUND(SUM(CASE WHEN t.tip_transacao='CREDIT' THEN t.val_transacao ELSE 0 END), 2) AS val
--     FROM transacoes t
--     GROUP BY t.idt_ofx
-- ) x ON x.idt_ofx = o.idt_ofx
-- SET o.qtd_transacao = COALESCE(x.qtd, 0),
--     o.val_total     = COALESCE(x.val, 0)
-- WHERE (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao);
--
-- -- COMMIT;   -- ou ROLLBACK;


-- #############################################################################
-- VERIF. 2 (OFX de periodo sobreposto) e VERIF. 6 (transacoes sem FITID)
--   NAO tem correcao automatica — exigem decisao humana:
--
--   - Periodo sobreposto (Verif. 2): dois extratos legitimos podem compartilhar
--     dias. NAO apague por regra automatica. Analise par a par; se um OFX for
--     claramente redundante, trate-o pela Correcao A (se mesmo hash) ou remova
--     manualmente o OFX redundante apos conferir as transacoes.
--
--   - Transacoes sem FITID (Verif. 6): sem identificador, nao da para decidir
--     por regra o que e duplicata. Inspecione manualmente (dat_transacao,
--     val_transacao, des_transacao) e remova caso a caso, se for o caso:
--       -- DELETE FROM transacoes WHERE idt_transacao IN (<ids conferidos>);
--     Depois rode a Correcao D para recalcular os totais do OFX.
-- #############################################################################

-- =============================================================================
-- ORDEM RECOMENDADA DE EXECUCAO (uma por vez, com PREVIEW antes de cada uma):
--   0) Backup.
--   1) Correcao C (resumos duplicados) — resolve o R$80 vs R$40.
--   2) Correcao B (transacoes duplicadas), se a Verif. 3 apontar.
--   3) Correcao D (recalcular totais do OFX) — sempre apos B/C.
--   4) Correcao A (OFX reimportado) — so se a Verif. 1 apontar hash identico.
--   5) Verif. 2 e 6 — tratar manualmente.
--   Reexecute docs/sql/auditoria_inconsistencias.sql ao final para confirmar
--   que nenhuma verificacao retorna mais linhas.
-- =============================================================================
