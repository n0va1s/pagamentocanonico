-- =============================================================================
-- reset_para_reimportar_ofx.sql
-- =============================================================================
-- OBJETIVO
--   Limpar os dados importados de OFX (ofx, transacoes, resumos e as despesas
--   vinculadas) de UMA associação, para permitir a REIMPORTAÇÃO dos arquivos
--   pela tela /importar-ofx com o parser já corrigido (dedup por CHECKNUM +
--   regras de classificação).
--
-- IMPORTANTE — O QUE ESTE SCRIPT **NÃO** FAZ
--   Este script NÃO reimporta os arquivos OFX. A reimportação lê os arquivos
--   .ofx, faz o parsing, deduplica e aplica as regras — isso é feito pela
--   aplicação (tela de upload), NÃO por SQL. Aqui é só o RESET dos dados.
--
-- ORDEM DE EXECUÇÃO RECOMENDADA EM PRODUÇÃO
--   1) Fazer BACKUP das tabelas (ver bloco de backup opcional abaixo).
--   2) Cadastrar a regra de classificação por associação ANTES de reimportar:
--      efeito = 'EXCLUI_TUDO', critério CHECKNUM = '9903' (tela de Regras).
--   3) Rodar este script (reset).
--   4) Reimportar os arquivos OFX pela tela /importar-ofx.
--
-- SEGURANÇA
--   - Rode dentro de uma transação (START TRANSACTION ... COMMIT/ROLLBACK).
--   - Confira as contagens (SELECTs abaixo) ANTES do COMMIT.
--   - NÃO há como desfazer após o COMMIT a não ser pelo backup.
--
-- ESCOPO
--   Ajuste @idt_associacao para a associação alvo. Deixe NULL para TODAS as
--   associações (use com extrema cautela — apaga os dados de importação de
--   TODAS de uma vez).
-- =============================================================================

-- Associação alvo. Ex.: SET @idt_associacao = 1;  (deixe NULL para TODAS)
SET @idt_associacao = 1;


-- -----------------------------------------------------------------------------
-- (Opcional, MAS recomendado) BACKUP das tabelas antes do reset.
-- Descomente para gerar cópias com sufixo _bkp. Remova/renomeie depois.
-- -----------------------------------------------------------------------------
-- CREATE TABLE ofx_bkp        AS SELECT * FROM ofx;
-- CREATE TABLE transacoes_bkp AS SELECT * FROM transacoes;
-- CREATE TABLE resumos_bkp    AS SELECT * FROM resumos;
-- CREATE TABLE despesas_bkp   AS SELECT * FROM despesas;


-- -----------------------------------------------------------------------------
-- 0. CONFERÊNCIA PRÉVIA — quanto será apagado (rode antes de deletar).
-- -----------------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM ofx o
        WHERE @idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)                       AS ofx_a_apagar,
    (SELECT COUNT(*) FROM transacoes t
        JOIN ofx o ON o.idt_ofx = t.idt_ofx
        WHERE @idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)                       AS transacoes_a_apagar,
    (SELECT COUNT(*) FROM resumos r
        JOIN ofx o ON o.idt_ofx = r.idt_ofx
        WHERE @idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)                       AS resumos_a_apagar,
    (SELECT COUNT(*) FROM despesas d
        JOIN transacoes t ON t.idt_transacao = d.idt_transacao
        JOIN ofx o ON o.idt_ofx = t.idt_ofx
        WHERE @idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)                       AS despesas_a_apagar;


-- =============================================================================
-- RESET — executar dentro de transação.
-- =============================================================================
START TRANSACTION;

-- 1. Despesas vinculadas às transações da associação (FK idt_transacao).
DELETE d
FROM despesas d
JOIN transacoes t ON t.idt_transacao = d.idt_transacao
JOIN ofx o        ON o.idt_ofx = t.idt_ofx
WHERE idt_associacao IS NULL OR o.idt_associacao = @idt_associacao;

-- 2. Zera o vínculo transacoes.idt_resumo antes de apagar os resumos
--    (idt_resumo é nullOnDelete, mas zeramos explicitamente para previsibilidade).
UPDATE transacoes t
JOIN ofx o ON o.idt_ofx = t.idt_ofx
SET t.idt_resumo = NULL
WHERE idt_associacao IS NULL OR o.idt_associacao = @idt_associacao;

-- 3. Resumos da associação (FK idt_ofx).
DELETE r
FROM resumos r
JOIN ofx o ON o.idt_ofx = r.idt_ofx
WHERE idt_associacao IS NULL OR o.idt_associacao = @idt_associacao;

-- 4. Transações da associação (FK idt_ofx).
DELETE t
FROM transacoes t
JOIN ofx o ON o.idt_ofx = t.idt_ofx
WHERE idt_associacao IS NULL OR o.idt_associacao = @idt_associacao;

-- 5. Os próprios registros de OFX da associação.
DELETE o
FROM ofx o
WHERE @idt_associacao IS NULL OR o.idt_associacao = @idt_associacao;


-- -----------------------------------------------------------------------------
-- CONFERÊNCIA PÓS-DELETE (deve retornar tudo zero para a associação alvo).
-- -----------------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM ofx o
        WHERE @idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)  AS ofx_restantes,
    (SELECT COUNT(*) FROM transacoes t
        JOIN ofx o ON o.idt_ofx = t.idt_ofx
        WHERE @idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)  AS transacoes_restantes,
    (SELECT COUNT(*) FROM resumos r
        JOIN ofx o ON o.idt_ofx = r.idt_ofx
        WHERE @idt_associacao IS NULL OR o.idt_associacao = @idt_associacao)  AS resumos_restantes;

-- Se as contagens estiverem corretas (zeradas), confirme:
--   COMMIT;
-- Se algo parecer errado:
--   ROLLBACK;

-- =============================================================================
-- APÓS O COMMIT: reimporte os arquivos OFX pela tela /importar-ofx.
-- A dedup por CHECKNUM impede duplicação mesmo com extratos de período
-- sobreposto; a regra CHECKNUM=9903 -> EXCLUI_TUDO mantém o Rende Fácil fora
-- do cálculo de receita/despesa.
-- =============================================================================
