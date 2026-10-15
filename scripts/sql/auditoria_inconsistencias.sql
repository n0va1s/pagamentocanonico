-- =============================================================================
-- Script de Auditoria de Inconsistencias — Pagamentos / Importacao OFX
-- =============================================================================
--
-- OBJETIVO
--   Diagnosticar, de forma SOMENTE-LEITURA, possiveis duplicidades e
--   inconsistencias geradas por importacoes repetidas/indevidas de OFX,
--   incluindo a causa do bug observado em producao (dashboard somando
--   R$ 80,00 em vez de R$ 40,00 apos reimportar o extrato do mes).
--
-- SEGURANCA
--   Este script contem APENAS comandos SELECT. Nao altera, nao remove e
--   nao insere dados. Pode ser executado com seguranca em producao.
--
-- COMO USAR
--   1. Rode no banco de producao (MySQL/MariaDB), por exemplo:
--        mysql -u <usuario> -p <nome_do_banco> < docs/sql/auditoria_inconsistencias.sql
--      ou cole as consultas de interesse em um cliente SQL.
--   2. Para focar em UMA associacao, defina o filtro abaixo (deixe NULL para
--      analisar TODAS as associacoes).
--
-- DEFINICOES USADAS (iguais as do codigo/feature de auditoria)
--   - Conta_Origem = COALESCE(cod_banco,'') + COALESCE(num_conta,'')
--   - Credito       = tip_transacao = 'CREDIT'
--   - FITID         = transacoes.num_transacao (id unico da transacao no OFX)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Filtro opcional de associacao.
-- Deixe em NULL para analisar TODAS; informe o idt_associacao para focar numa.
-- -----------------------------------------------------------------------------
SET @idt_associacao = NULL;   -- ex.: SET @idt_associacao = 3;


-- =============================================================================
-- 0. VISAO GERAL — volume por associacao
--    Panorama rapido de quantos OFX / transacoes / resumos existem.
-- =============================================================================
SELECT
    a.idt_associacao,
    a.nom_associacao,
    COUNT(DISTINCT o.idt_ofx)                                  AS qtd_ofx,
    COUNT(DISTINCT t.idt_transacao)                            AS qtd_transacoes,
    COUNT(DISTINCT r.idt_resumo)                               AS qtd_resumos,
    ROUND(SUM(CASE WHEN t.tip_transacao = 'CREDIT'
                   THEN t.val_transacao ELSE 0 END), 2)        AS total_creditos
FROM associacoes a
LEFT JOIN ofx        o ON o.idt_associacao = a.idt_associacao
LEFT JOIN transacoes t ON t.idt_ofx = o.idt_ofx
LEFT JOIN resumos    r ON r.idt_ofx = o.idt_ofx
WHERE (@idt_associacao IS NULL OR a.idt_associacao = @idt_associacao)
GROUP BY a.idt_associacao, a.nom_associacao
ORDER BY a.idt_associacao;


-- =============================================================================
-- 1. OFX REIMPORTADO — mesmo arquivo (hash identico) na mesma associacao
--    Dois OFX com o mesmo hash = o mesmo arquivo importado mais de uma vez.
--
--    COMPATIVEL COM BASE ANTIGA: a coluna ofx.hash_arquivo so existe apos a
--    migracao da feature nova. O bloco abaixo detecta se a coluna existe e,
--    se NAO existir, retorna apenas um aviso (nao gera erro #1054). Assim o
--    mesmo script roda em producao antes e depois de aplicar a feature.
--    Requer que a consulta seja executada como UM statement (o cliente deve
--    permitir PREPARE/EXECUTE; a maioria dos clientes MySQL permite).
-- =============================================================================
SET @tem_hash := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'ofx'
      AND column_name  = 'hash_arquivo'
);

SET @sql_hash := IF(@tem_hash > 0,
    'SELECT
        o.idt_associacao,
        o.hash_arquivo,
        COUNT(*)                                                      AS qtd_importacoes,
        GROUP_CONCAT(o.idt_ofx ORDER BY o.idt_ofx)                    AS ids_ofx,
        GROUP_CONCAT(o.des_arquivo ORDER BY o.idt_ofx SEPARATOR " | ") AS arquivos
     FROM ofx o
     WHERE o.hash_arquivo IS NOT NULL
       AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)
     GROUP BY o.idt_associacao, o.hash_arquivo
     HAVING COUNT(*) > 1
     ORDER BY qtd_importacoes DESC',
    'SELECT "Coluna ofx.hash_arquivo ainda nao existe (feature de auditoria nao aplicada). Verificacao 1 ignorada." AS aviso'
);

PREPARE stmt_hash FROM @sql_hash;
EXECUTE stmt_hash;
DEALLOCATE PREPARE stmt_hash;


-- =============================================================================
-- 2. OFX DE PERIODO SOBREPOSTO — mesma Conta_Origem, periodos que se cruzam
--    Dois extratos da mesma conta cujo intervalo [dat_inicio, dat_fim] se
--    sobrepoe podem conter as MESMAS transacoes em arquivos diferentes.
--    (Apenas informativo; so considera pares com as 4 datas preenchidas.)
-- =============================================================================
SELECT
    a.idt_ofx                               AS ofx_a,
    b.idt_ofx                               AS ofx_b,
    a.idt_associacao,
    COALESCE(a.cod_banco,'')                AS cod_banco,
    COALESCE(a.num_conta,'')                AS num_conta,
    a.dat_inicio AS a_inicio, a.dat_fim AS a_fim,
    b.dat_inicio AS b_inicio, b.dat_fim AS b_fim
FROM ofx a
JOIN ofx b
     ON  b.idt_associacao <=> a.idt_associacao
     AND COALESCE(b.cod_banco,'') = COALESCE(a.cod_banco,'')
     AND COALESCE(b.num_conta,'') = COALESCE(a.num_conta,'')
     AND b.idt_ofx > a.idt_ofx
WHERE a.dat_inicio IS NOT NULL AND a.dat_fim IS NOT NULL
  AND b.dat_inicio IS NOT NULL AND b.dat_fim IS NOT NULL
  AND a.dat_inicio <= b.dat_fim
  AND b.dat_inicio <= a.dat_fim
  AND (@idt_associacao IS NULL OR a.idt_associacao = @idt_associacao)
ORDER BY a.idt_associacao, cod_banco, num_conta, a.idt_ofx;


-- =============================================================================
-- 3. TRANSACOES DUPLICADAS — mesmo FITID na mesma associacao + Conta_Origem
--    Esta e a duplicidade de transacao real: o mesmo lancamento (FITID)
--    aparece 2x ou mais dentro do escopo associacao+banco+conta.
--    excedentes = quantas copias a mais existem alem da original.
-- =============================================================================
SELECT
    o.idt_associacao,
    COALESCE(o.cod_banco,'')        AS cod_banco,
    COALESCE(o.num_conta,'')        AS num_conta,
    t.num_transacao                 AS fitid,
    COUNT(*)                        AS qtd,
    COUNT(*) - 1                    AS excedentes,
    ROUND(SUM(CASE WHEN t.tip_transacao = 'CREDIT'
                   THEN t.val_transacao ELSE 0 END)
          - MAX(CASE WHEN t.tip_transacao = 'CREDIT'
                     THEN t.val_transacao ELSE 0 END), 2) AS valor_excedente_credito,
    GROUP_CONCAT(t.idt_transacao ORDER BY t.idt_transacao) AS ids_transacao
FROM transacoes t
JOIN ofx o ON o.idt_ofx = t.idt_ofx
WHERE t.num_transacao IS NOT NULL
  AND t.num_transacao <> ''
  AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)
GROUP BY o.idt_associacao, COALESCE(o.cod_banco,''), COALESCE(o.num_conta,''), t.num_transacao
HAVING COUNT(*) > 1
ORDER BY COUNT(*) DESC, o.idt_associacao;


-- =============================================================================
-- 4. RESUMOS DUPLICADOS — CAUSA PROVAVEL DO R$ 80 vs R$ 40
--    Mais de um resumo para a mesma pessoa/OFX/ano/mes.
--    "pessoa" = idt_membro quando houver; senao a descricao (des/nom).
--    O caso critico sao os SEM membro (idt_membro NULL), que o codigo
--    antigo criava sem idempotencia, dobrando o valor ao reimportar.
-- =============================================================================
SELECT
    o.idt_associacao,
    r.idt_ofx,
    r.num_ano,
    r.num_mes,
    CASE WHEN r.idt_membro IS NOT NULL
         THEN CONCAT('membro:', r.idt_membro)
         ELSE CONCAT('sem_membro')
    END                                           AS tipo_pessoa,
    r.idt_membro,
    COUNT(*)                                       AS qtd_resumos,
    COUNT(*) - 1                                   AS excedentes,
    ROUND(SUM(r.val_total) - MAX(r.val_total), 2)  AS valor_excedente,
    GROUP_CONCAT(r.idt_resumo ORDER BY r.idt_resumo) AS ids_resumo,
    GROUP_CONCAT(ROUND(r.val_total,2) ORDER BY r.idt_resumo) AS valores
FROM resumos r
JOIN ofx o ON o.idt_ofx = r.idt_ofx
WHERE (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)
GROUP BY o.idt_associacao, r.idt_ofx, r.num_ano, r.num_mes, r.idt_membro
HAVING COUNT(*) > 1
ORDER BY ROUND(SUM(r.val_total) - MAX(r.val_total), 2) DESC, o.idt_associacao;


-- =============================================================================
-- 5. DIVERGENCIA DE TOTAIS DO OFX — val_total/qtd_transacao vs. realidade
--    Compara o que esta gravado no cabecalho do OFX com a soma/contagem
--    recalculada a partir das transacoes. Divergencia indica total "preso"
--    de uma reconciliacao antiga ou de duplicidade.
-- =============================================================================
SELECT
    o.idt_associacao,
    o.idt_ofx,
    o.des_arquivo,
    o.qtd_transacao                                   AS qtd_gravada,
    COUNT(t.idt_transacao)                            AS qtd_real,
    ROUND(o.val_total, 2)                             AS val_total_gravado,
    ROUND(SUM(CASE WHEN t.tip_transacao = 'CREDIT'
                   THEN t.val_transacao ELSE 0 END), 2) AS val_credito_real,
    ROUND(o.val_total
          - SUM(CASE WHEN t.tip_transacao = 'CREDIT'
                     THEN t.val_transacao ELSE 0 END), 2) AS diferenca
FROM ofx o
LEFT JOIN transacoes t ON t.idt_ofx = o.idt_ofx
WHERE (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)
GROUP BY o.idt_associacao, o.idt_ofx, o.des_arquivo, o.qtd_transacao, o.val_total
HAVING o.qtd_transacao <> COUNT(t.idt_transacao)
    OR ABS(ROUND(o.val_total
          - SUM(CASE WHEN t.tip_transacao = 'CREDIT'
                     THEN t.val_transacao ELSE 0 END), 2)) > 0.001
ORDER BY ABS(ROUND(o.val_total
          - SUM(CASE WHEN t.tip_transacao = 'CREDIT'
                     THEN t.val_transacao ELSE 0 END), 2)) DESC;


-- =============================================================================
-- 6. TRANSACOES SEM FITID — nao sao deduplicaveis por identificador
--    Lancamentos sem num_transacao escapam da deduplicacao por FITID e
--    sao candidatos a entrar em duplicidade silenciosa ao reimportar.
-- =============================================================================
SELECT
    o.idt_associacao,
    o.idt_ofx,
    o.des_arquivo,
    COUNT(*) AS qtd_sem_fitid,
    ROUND(SUM(CASE WHEN t.tip_transacao = 'CREDIT'
                   THEN t.val_transacao ELSE 0 END), 2) AS total_credito_sem_fitid
FROM transacoes t
JOIN ofx o ON o.idt_ofx = t.idt_ofx
WHERE (t.num_transacao IS NULL OR t.num_transacao = '')
  AND (@idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)
GROUP BY o.idt_associacao, o.idt_ofx, o.des_arquivo
ORDER BY COUNT(*) DESC;


-- =============================================================================
-- 7. RESUMO EXECUTIVO — impacto financeiro estimado por associacao
--    Soma o valor excedente de transacoes duplicadas e de resumos duplicados.
--    E a estimativa de quanto o dashboard pode estar inflado por duplicidade.
-- =============================================================================
SELECT
    assoc.idt_associacao,
    assoc.nom_associacao,
    ROUND(COALESCE(tx.valor_excedente, 0), 2)                       AS excedente_transacoes,
    ROUND(COALESCE(rs.valor_excedente, 0), 2)                       AS excedente_resumos,
    ROUND(COALESCE(tx.valor_excedente,0) + COALESCE(rs.valor_excedente,0), 2) AS excedente_total_estimado
FROM associacoes assoc
LEFT JOIN (
    SELECT o.idt_associacao,
           SUM(sub.valor_exc) AS valor_excedente
    FROM (
        SELECT o2.idt_associacao AS idt_associacao,
               (SUM(CASE WHEN t.tip_transacao='CREDIT' THEN t.val_transacao ELSE 0 END)
                - MAX(CASE WHEN t.tip_transacao='CREDIT' THEN t.val_transacao ELSE 0 END)) AS valor_exc
        FROM transacoes t
        JOIN ofx o2 ON o2.idt_ofx = t.idt_ofx
        WHERE t.num_transacao IS NOT NULL AND t.num_transacao <> ''
        GROUP BY o2.idt_associacao, COALESCE(o2.cod_banco,''), COALESCE(o2.num_conta,''), t.num_transacao
        HAVING COUNT(*) > 1
    ) sub
    JOIN ofx o ON o.idt_associacao = sub.idt_associacao
    GROUP BY o.idt_associacao
) tx ON tx.idt_associacao = assoc.idt_associacao
LEFT JOIN (
    SELECT sub.idt_associacao,
           SUM(sub.valor_exc) AS valor_excedente
    FROM (
        SELECT o.idt_associacao AS idt_associacao,
               (SUM(r.val_total) - MAX(r.val_total)) AS valor_exc
        FROM resumos r
        JOIN ofx o ON o.idt_ofx = r.idt_ofx
        GROUP BY o.idt_associacao, r.idt_ofx, r.num_ano, r.num_mes, r.idt_membro
        HAVING COUNT(*) > 1
    ) sub
    GROUP BY sub.idt_associacao
) rs ON rs.idt_associacao = assoc.idt_associacao
WHERE (@idt_associacao IS NULL OR assoc.idt_associacao = @idt_associacao)
  AND (COALESCE(tx.valor_excedente,0) <> 0 OR COALESCE(rs.valor_excedente,0) <> 0)
ORDER BY excedente_total_estimado DESC;

-- =============================================================================
-- FIM — nenhuma linha retornada em uma verificacao = sem a inconsistencia.
-- =============================================================================
