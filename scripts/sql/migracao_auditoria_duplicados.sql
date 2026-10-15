-- =============================================================================
-- Script de MIGRACAO de Schema — Auditoria de Pagamentos Duplicados (ADR-0003)
-- =============================================================================
--
-- OBJETIVO
--   Aplicar, diretamente no banco de PRODUCAO (sem acesso a `php artisan migrate`),
--   as alteracoes de schema das duas migrations desta versao:
--
--     1) 2026_05_17_000001_add_hash_arquivo_to_ofx_table
--          - ofx.hash_arquivo        VARCHAR(64) NULL  (apos des_arquivo)
--          - INDEX ofx_hash_arquivo_index (hash_arquivo)
--
--     2) 2026_05_17_000002_add_nom_pessoa_chv_pessoa_to_resumos_table
--          - resumos.nom_pessoa      VARCHAR(255) NULL (apos idt_membro)
--          - resumos.chv_pessoa      VARCHAR(191) NULL (apos nom_pessoa)
--          - INDEX resumos_idt_ofx_chv_pessoa_num_ano_num_mes_index
--                  (idt_ofx, chv_pessoa, num_ano, num_mes)
--
--   Ao final, registra as duas migrations na tabela `migrations` para que, se um
--   dia houver console, `php artisan migrate` NAO tente aplica-las de novo.
--
-- CARACTERISTICAS
--   - IDEMPOTENTE: pode ser rodado mais de uma vez sem erro. Cada ALTER/INDEX so
--     e aplicado se a coluna/indice ainda nao existir (checagem em
--     information_schema). O registro em `migrations` usa INSERT ... WHERE NOT EXISTS.
--   - NAO altera nem remove dados. E apenas DDL (ADD COLUMN / ADD INDEX) + 2 INSERTs
--     de controle. Nenhum UPDATE/DELETE em dados de negocio.
--   - Compativel com MySQL 8.x (usa prepared statements condicionais).
--
-- PRE-REQUISITOS / OBSERVACOES
--   - As tabelas `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches` ja foram
--     criadas no deploy inicial do Laravel (migrations 0001_01_01_*). A troca de
--     SESSION_DRIVER/CACHE_STORE/QUEUE_CONNECTION para "database" NAO exige novas
--     tabelas; apenas garanta que o .env de producao use esses drivers (ou outro
--     disponivel) e rode, se houver console, `php artisan config:clear`.
--   - Se o seu usuario de banco nao tiver privilegio para criar PROCEDURE, use a
--     SECAO ALTERNATIVA (no final) e simplesmente ignore o erro "Duplicate column"
--     / "Duplicate key" caso rode o script uma segunda vez.
--
-- COMO EXECUTAR
--   1. FACA BACKUP (mysqldump) antes.
--   2. Rode este arquivo inteiro no banco de producao (ex.: no painel do phpMyAdmin,
--      Adminer, ou importando o .sql). Ele e seguro para rodar de ponta a ponta.
--   3. Confira a SECAO DE VERIFICACAO (SELECTs no final) — todas as colunas e
--      indices devem aparecer, e as 2 migrations devem estar listadas.
--
-- =============================================================================

-- -----------------------------------------------------------------------------
-- SECAO 1 — Alteracoes idempotentes de schema (via procedure temporaria)
-- -----------------------------------------------------------------------------

DELIMITER $$

DROP PROCEDURE IF EXISTS pc_migracao_auditoria_duplicados $$

CREATE PROCEDURE pc_migracao_auditoria_duplicados()
BEGIN
    DECLARE v_schema VARCHAR(64);
    SET v_schema = DATABASE();

    -- 1) ofx.hash_arquivo
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = v_schema AND table_name = 'ofx' AND column_name = 'hash_arquivo'
    ) THEN
        ALTER TABLE `ofx` ADD COLUMN `hash_arquivo` VARCHAR(64) NULL AFTER `des_arquivo`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = v_schema AND table_name = 'ofx' AND index_name = 'ofx_hash_arquivo_index'
    ) THEN
        ALTER TABLE `ofx` ADD INDEX `ofx_hash_arquivo_index` (`hash_arquivo`);
    END IF;

    -- 2) resumos.nom_pessoa
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = v_schema AND table_name = 'resumos' AND column_name = 'nom_pessoa'
    ) THEN
        ALTER TABLE `resumos` ADD COLUMN `nom_pessoa` VARCHAR(255) NULL AFTER `idt_membro`;
    END IF;

    -- 3) resumos.chv_pessoa
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = v_schema AND table_name = 'resumos' AND column_name = 'chv_pessoa'
    ) THEN
        ALTER TABLE `resumos` ADD COLUMN `chv_pessoa` VARCHAR(191) NULL AFTER `nom_pessoa`;
    END IF;

    -- 4) indice composto em resumos
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = v_schema AND table_name = 'resumos'
          AND index_name = 'resumos_idt_ofx_chv_pessoa_num_ano_num_mes_index'
    ) THEN
        ALTER TABLE `resumos`
            ADD INDEX `resumos_idt_ofx_chv_pessoa_num_ano_num_mes_index`
            (`idt_ofx`, `chv_pessoa`, `num_ano`, `num_mes`);
    END IF;
END $$

DELIMITER ;

CALL pc_migracao_auditoria_duplicados();
DROP PROCEDURE IF EXISTS pc_migracao_auditoria_duplicados;

-- -----------------------------------------------------------------------------
-- SECAO 2 — Registro das migrations na tabela `migrations`
-- -----------------------------------------------------------------------------
-- Marca as duas migrations desta versao como ja executadas, para que um futuro
-- `php artisan migrate` (se houver console) NAO tente reaplica-las e falhe com
-- "Duplicate column"/"Duplicate key".
--
-- IDEMPOTENTE: cada INSERT so grava se a migration ainda nao estiver registrada
-- (INSERT ... SELECT ... WHERE NOT EXISTS). As duas entram no MESMO batch novo
-- (MAX(batch)+1), replicando o comportamento de uma unica rodada de `migrate`.
-- -----------------------------------------------------------------------------

SET @novo_batch := (SELECT COALESCE(MAX(batch), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_05_17_000001_add_hash_arquivo_to_ofx_table', @novo_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_05_17_000001_add_hash_arquivo_to_ofx_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_05_17_000002_add_nom_pessoa_chv_pessoa_to_resumos_table', @novo_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_05_17_000002_add_nom_pessoa_chv_pessoa_to_resumos_table'
);

-- -----------------------------------------------------------------------------
-- SECAO 3 — VERIFICACAO (somente leitura; rode e confira os resultados)
-- -----------------------------------------------------------------------------

-- 3.1 Colunas novas devem aparecer:
SELECT table_name, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
    (table_name = 'ofx'     AND column_name = 'hash_arquivo')
    OR (table_name = 'resumos' AND column_name IN ('nom_pessoa', 'chv_pessoa'))
  )
ORDER BY table_name, column_name;

-- 3.2 Indices novos devem aparecer:
SELECT table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS colunas
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND index_name IN (
    'ofx_hash_arquivo_index',
    'resumos_idt_ofx_chv_pessoa_num_ano_num_mes_index'
  )
GROUP BY table_name, index_name;

-- 3.3 As duas migrations devem estar registradas:
SELECT `migration`, `batch`
FROM `migrations`
WHERE `migration` IN (
    '2026_05_17_000001_add_hash_arquivo_to_ofx_table',
    '2026_05_17_000002_add_nom_pessoa_chv_pessoa_to_resumos_table'
)
ORDER BY `migration`;

-- =============================================================================
-- SECAO ALTERNATIVA (sem privilegio para criar PROCEDURE)
-- =============================================================================
-- Se a SECAO 1 falhar por falta de permissao de CREATE ROUTINE, use os comandos
-- abaixo. Eles NAO sao idempotentes: se a coluna/indice ja existir, o MySQL
-- retorna erro de "Duplicate column name" / "Duplicate key name" — nesse caso o
-- objeto ja existe e o erro pode ser ignorado com seguranca.
--
--   ALTER TABLE `ofx` ADD COLUMN `hash_arquivo` VARCHAR(64) NULL AFTER `des_arquivo`;
--   ALTER TABLE `ofx` ADD INDEX `ofx_hash_arquivo_index` (`hash_arquivo`);
--   ALTER TABLE `resumos` ADD COLUMN `nom_pessoa` VARCHAR(255) NULL AFTER `idt_membro`;
--   ALTER TABLE `resumos` ADD COLUMN `chv_pessoa` VARCHAR(191) NULL AFTER `nom_pessoa`;
--   ALTER TABLE `resumos` ADD INDEX `resumos_idt_ofx_chv_pessoa_num_ano_num_mes_index`
--       (`idt_ofx`, `chv_pessoa`, `num_ano`, `num_mes`);
--
-- E a SECAO 2 (registro das migrations) continua valida e idempotente.
-- =============================================================================