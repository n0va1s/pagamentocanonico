-- =============================================================================
-- melhorias_producao.sql
-- =============================================================================
-- Aplicação MANUAL em produção (banco MySQL `pagamento`), executada pelo dono.
-- NÃO deve ser rodado por migration (`php artisan migrate` NÃO toca produção).
-- Em desenvolvimento o schema é sempre reconstruído com `php artisan migrate:fresh`.
--
-- Este arquivo é o equivalente DDL das migrations alteradas nesta entrega:
--   - database/migrations/2026_05_16_114700_create_associacoes_table.php
--       (novas tabelas regras_classificacao e regras_classificacao_criterios)
--   - database/migrations/2026_05_16_115100_create_transacoes_table.php
--       (novas colunas transacoes.ind_computa_receita / ind_computa_despesa)
--
-- Recomenda-se rodar dentro de uma transação e com backup prévio.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Regras de classificação de transações por associação.
-- FK idt_associacao -> associacoes(idt_associacao) ON DELETE CASCADE.
-- -----------------------------------------------------------------------------
CREATE TABLE `regras_classificacao` (
    `idt_regra` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `idt_associacao` BIGINT UNSIGNED NOT NULL,
    `nom_regra` VARCHAR(255) NOT NULL,
    `des_efeito` VARCHAR(255) NOT NULL,
    `ind_ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`idt_regra`),
    KEY `regras_classificacao_idt_associacao_foreign` (`idt_associacao`),
    CONSTRAINT `regras_classificacao_idt_associacao_foreign`
        FOREIGN KEY (`idt_associacao`) REFERENCES `associacoes` (`idt_associacao`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Critérios de cada regra (combinados por E: todos precisam casar).
-- FK idt_regra -> regras_classificacao(idt_regra) ON DELETE CASCADE.
-- -----------------------------------------------------------------------------
CREATE TABLE `regras_classificacao_criterios` (
    `idt_criterio` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `idt_regra` BIGINT UNSIGNED NOT NULL,
    `tip_match` VARCHAR(255) NOT NULL,
    `val_match` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`idt_criterio`),
    KEY `regras_classificacao_criterios_idt_regra_foreign` (`idt_regra`),
    CONSTRAINT `regras_classificacao_criterios_idt_regra_foreign`
        FOREIGN KEY (`idt_regra`) REFERENCES `regras_classificacao` (`idt_regra`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Flags por transação: por padrão toda transação compõe receita e despesa.
-- As regras de classificação zeram essas flags em transações FUTURAS.
-- -----------------------------------------------------------------------------
ALTER TABLE `transacoes`
    ADD COLUMN `ind_computa_receita` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN `ind_computa_despesa` TINYINT(1) NOT NULL DEFAULT 1;
