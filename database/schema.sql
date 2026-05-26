-- =============================================================================
-- schema.sql — Estado canônico do banco Yuris (sistema_vendas)
-- =============================================================================
-- Última geração: 2026-05-26 — pós migrations 001-070 aplicadas em local.
-- Anterior gerada em 2026-05-11 parou na migration 027, deixando 43
-- migrations órfãs no schema monolítico (SaaS billing 038, LGPD 049-057,
-- security_incidents 054, data_processors 055, intimações 060-066, AASP
-- 064-065, advogado_vinculos 067, webhooks v2 068-070).
--
-- Como regenerar (a partir de um local com todas as migrations aplicadas):
--   mysqldump --no-data --routines --triggers --skip-add-drop-table \
--     --skip-comments --skip-set-charset --no-tablespaces \
--     sistema_vendas > database/schema.sql
--
-- Como aplicar em servidor virgem:
--   1. CREATE DATABASE yuris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   2. mysql -u <user> -p yuris < database/schema.sql
--   3. php scripts/seed_admin.php
--
-- Total atual: 92 tabelas + procedures + triggers de imutabilidade LGPD.
-- NÃO edite manualmente. Para mudanças, crie nova migration em
-- database/migrations/NNN_descricao.sql e regenere este arquivo.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS sistema_vendas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_vendas;
-- ^^ Para outro nome de banco em produção (ex: yuris), troque os 2 comandos
-- acima ou rode com:  mysql -u user -p MEU_BANCO < schema.sql

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `aasp_credential_audit` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `integration_id` int(10) unsigned DEFAULT NULL,
  `action` enum('created','rotated','tested','revoked','error','sync_ok','sync_fail') NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `detalhe` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_account_created` (`account_id`,`created_at`),
  KEY `idx_integration` (`integration_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `aasp_integrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `advogado_id` int(10) unsigned DEFAULT NULL,
  `nome` varchar(120) NOT NULL,
  `tipo` enum('associado','empresa') NOT NULL DEFAULT 'associado',
  `chave_encrypted` text NOT NULL,
  `chave_masked` varchar(24) NOT NULL,
  `codigos_associados` text DEFAULT NULL,
  `status` enum('active','inactive','error','pending') NOT NULL DEFAULT 'pending',
  `intervalo_minutos` int(10) unsigned NOT NULL DEFAULT 120,
  `ultima_sync_em` datetime DEFAULT NULL,
  `ultima_sync_status` varchar(50) DEFAULT NULL,
  `ultima_sync_erro` varchar(500) DEFAULT NULL,
  `proxima_sync_em` datetime DEFAULT NULL,
  `total_intimacoes_hoje` int(10) unsigned NOT NULL DEFAULT 0,
  `total_intimacoes_lifetime` int(10) unsigned NOT NULL DEFAULT 0,
  `ultimo_diferencial_em` datetime DEFAULT NULL,
  `erros_consecutivos` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_nome` (`account_id`,`nome`),
  KEY `idx_account_status` (`account_id`,`status`),
  KEY `idx_proxima_sync` (`status`,`proxima_sync_em`),
  KEY `idx_advogado` (`advogado_id`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `account_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `acao` varchar(100) NOT NULL,
  `entidade` varchar(100) DEFAULT NULL,
  `entidade_id` int(11) DEFAULT NULL,
  `dados_antes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dados_antes`)),
  `dados_depois` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dados_depois`)),
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_agent` varchar(255) DEFAULT NULL,
  `request_id` char(12) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_request_id` (`request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_aal_no_update BEFORE UPDATE ON account_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'account_audit_log e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_aal_no_delete BEFORE DELETE ON account_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'account_audit_log e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `account_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL = notificação para toda a conta | preenchido = só para este usuário',
  `tipo` varchar(50) NOT NULL COMMENT 'vinculo.pendente | convite.recebido | share.criado | convite.aceito | convite.rejeitado',
  `titulo` varchar(255) NOT NULL,
  `mensagem` text DEFAULT NULL,
  `lida` tinyint(1) DEFAULT 0,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Dados extras: { resource_type, resource_id, from_account_id, ... }' CHECK (json_valid(`payload`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `lida_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_account` (`account_id`),
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_lida` (`lida`),
  KEY `idx_notif_tipo` (`tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `account_vinculos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `matriz_account_id` int(11) NOT NULL,
  `filial_account_id` int(11) NOT NULL,
  `status` enum('pending','active','suspended','rejected') DEFAULT 'pending',
  `solicitado_por` int(11) DEFAULT NULL COMMENT 'user_id que iniciou a solicitação',
  `aprovado_por` int(11) DEFAULT NULL COMMENT 'user_id owner/admin da Matriz que aprovou',
  `solicitado_em` datetime DEFAULT current_timestamp(),
  `aprovado_em` datetime DEFAULT NULL,
  `suspenso_em` datetime DEFAULT NULL,
  `motivo_suspensao` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sync_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Toggle mestre: matriz puxa dados desta filial?',
  `sync_processos` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sincronizar processos da filial?',
  `sync_cards` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sincronizar cards/leads (prospec├º├úo)?',
  `sync_tarefas` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sincronizar tarefas (boards)?',
  `sync_updated_at` datetime DEFAULT NULL COMMENT '├Ültima altera├º├úo dos flags de sincroniza├º├úo',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vinculo` (`matriz_account_id`,`filial_account_id`),
  KEY `idx_vmc_filial` (`filial_account_id`),
  KEY `idx_vmc_status` (`status`),
  CONSTRAINT `fk_vmc_filial` FOREIGN KEY (`filial_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_vmc_matriz` FOREIGN KEY (`matriz_account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(191) NOT NULL,
  `razao_social` varchar(191) DEFAULT NULL,
  `cnpj` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `telefone` varchar(30) DEFAULT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `estado` char(2) DEFAULT NULL,
  `tipo` enum('matriz','filial','advogado') NOT NULL DEFAULT 'matriz',
  `matriz_id` int(11) DEFAULT NULL COMMENT 'NULL se for matriz; FK para accounts.id se for filial',
  `codigo_vinculo` varchar(64) NOT NULL COMMENT 'UUID público para filial solicitar vínculo — nunca expor internamente',
  `plano` varchar(50) NOT NULL DEFAULT 'basico' COMMENT 'basico | profissional | enterprise',
  `status` enum('active','trial','overdue','suspended','cancelled','inactive') NOT NULL DEFAULT 'active',
  `configuracoes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON de configurações personalizadas da conta' CHECK (json_valid(`configuracoes`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_vinculo` (`codigo_vinculo`),
  KEY `idx_accounts_tipo` (`tipo`),
  KEY `idx_accounts_matriz_id` (`matriz_id`),
  KEY `idx_accounts_status` (`status`),
  KEY `idx_accounts_cnpj` (`cnpj`),
  CONSTRAINT `fk_account_matriz` FOREIGN KEY (`matriz_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `advogado_convites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_type` enum('card','processo','contato') NOT NULL,
  `resource_id` int(11) NOT NULL,
  `from_account_id` int(11) NOT NULL,
  `convidado_user_id` int(11) DEFAULT NULL COMMENT 'Preenchido após aceite, se o email já tem cadastro no sistema',
  `convidado_email` varchar(191) NOT NULL,
  `convidado_nome` varchar(191) DEFAULT NULL,
  `token_convite` varchar(64) NOT NULL COMMENT 'bin2hex(random_bytes(32)) — 256 bits de entropia',
  `permission_level` enum('view','edit') NOT NULL DEFAULT 'view',
  `mensagem` text DEFAULT NULL COMMENT 'Mensagem personalizada ao convidado (opcional)',
  `status` enum('pending','accepted','rejected','revoked','expired') DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL COMMENT 'user_id que revogou',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_convite` (`token_convite`),
  KEY `idx_conv_token` (`token_convite`),
  KEY `idx_conv_email` (`convidado_email`),
  KEY `idx_conv_resource` (`resource_type`,`resource_id`),
  KEY `idx_conv_status` (`status`),
  KEY `idx_conv_expires` (`expires_at`),
  KEY `fk_conv_from` (`from_account_id`),
  KEY `idx_convidado_user` (`convidado_user_id`),
  KEY `idx_convidado_email` (`convidado_email`),
  KEY `idx_status` (`status`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_conv_from` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `advogado_vinculos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host_account_id` int(11) NOT NULL COMMENT 'Matriz ou filial que vinculou o advogado',
  `advogado_account_id` int(11) NOT NULL COMMENT 'Conta tipo=advogado que foi vinculada',
  `status` enum('pending','active','suspended','rejected') DEFAULT 'pending',
  `solicitado_por` int(11) DEFAULT NULL COMMENT 'user_id que iniciou a solicitacao',
  `aprovado_por` int(11) DEFAULT NULL COMMENT 'user_id que aprovou (advogado ou host)',
  `solicitado_em` datetime DEFAULT current_timestamp(),
  `aprovado_em` datetime DEFAULT NULL,
  `suspenso_em` datetime DEFAULT NULL,
  `motivo_suspensao` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sync_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Toggle mestre: host puxa dados do advogado?',
  `sync_processos` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sincronizar processos do advogado?',
  `sync_cards` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sincronizar cards/leads?',
  `sync_tarefas` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sincronizar tarefas (boards)?',
  `sync_updated_at` datetime DEFAULT NULL COMMENT 'Ultima alteracao dos flags',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_adv_vinculo` (`host_account_id`,`advogado_account_id`),
  KEY `idx_adv_advogado` (`advogado_account_id`),
  KEY `idx_adv_status` (`status`),
  CONSTRAINT `fk_adv_advogado` FOREIGN KEY (`advogado_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_adv_host` FOREIGN KEY (`host_account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_configs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `whatsapp_number` varchar(30) DEFAULT NULL,
  `provider` varchar(50) DEFAULT NULL COMMENT 'openai | anthropic | gemini | etc',
  `api_key_enc` varbinary(2048) DEFAULT NULL COMMENT 'API key cifrada com AES-256-CBC (MFA_ENCRYPTION_KEY)',
  `prompt` mediumtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_account_user` (`account_id`,`user_id`),
  KEY `idx_agent_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `anonymization_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `entidade` varchar(100) NOT NULL COMMENT 'user | contato | card | processo | etc',
  `entidade_id` int(11) NOT NULL,
  `motivo` varchar(255) DEFAULT NULL COMMENT 'Texto livre ÔÇö ex: "Solicita├º├úo LGPD #42"',
  `executado_por_user_id` int(11) DEFAULT NULL,
  `lgpd_request_id` bigint(20) DEFAULT NULL COMMENT 'FK lgpd_requests.id se origem foi solicita├º├úo',
  `campos_afetados` text DEFAULT NULL COMMENT 'JSON array dos campos zerados/mascarados',
  `ip` varchar(45) DEFAULT NULL,
  `executado_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_entidade` (`entidade`,`entidade_id`),
  KEY `idx_request` (`lgpd_request_id`),
  KEY `idx_data` (`executado_em`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_anon_no_update BEFORE UPDATE ON anonymization_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'anonymization_log e imutavel (LGPD Art. 12)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_anon_no_delete BEFORE DELETE ON anonymization_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'anonymization_log e imutavel (LGPD Art. 12)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `card_checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `concluido` tinyint(1) DEFAULT 0,
  `ordem` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cdck_card` (`card_id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `card_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `acao` varchar(100) DEFAULT NULL,
  `campo_alterado` varchar(100) DEFAULT NULL,
  `valor_anterior` text DEFAULT NULL,
  `valor_novo` text DEFAULT NULL,
  `de_coluna_id` int(11) DEFAULT NULL,
  `para_coluna_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `request_id` char(12) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cdh_card` (`card_id`,`created_at`),
  KEY `idx_request_id` (`request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ch_no_update BEFORE UPDATE ON card_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'card_history e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ch_no_delete BEFORE DELETE ON card_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'card_history e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `cliente_nome` varchar(191) NOT NULL,
  `empresa_nome` varchar(191) DEFAULT NULL,
  `telefone_whatsapp` varchar(50) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `responsavel_user_id` int(11) DEFAULT NULL,
  `coluna_id` int(11) DEFAULT NULL,
  `ordem_na_coluna` int(11) DEFAULT 0,
  `valor_estimado` decimal(15,2) DEFAULT 0.00,
  `valor_proposta` decimal(15,2) DEFAULT 0.00,
  `valor_fechado_final` decimal(15,2) DEFAULT 0.00,
  `data_prevista_fechamento` date DEFAULT NULL,
  `data_fechamento` date DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'aberto',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `checklist_percentual` decimal(5,2) DEFAULT 0.00,
  `contato_id` int(11) DEFAULT NULL,
  `titulo` varchar(255) DEFAULT NULL COMMENT 'Título do card para exibição em vínculos de tarefas (espelha cliente_nome)',
  `anonymized_at` datetime DEFAULT NULL,
  `deletion_reason` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_card_contato` (`contato_id`),
  KEY `idx_cards_account` (`account_id`),
  KEY `idx_anon` (`anonymized_at`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_conversas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned DEFAULT NULL COMMENT 'FK accounts.id — tenant dono da conversa',
  `nome` varchar(200) DEFAULT NULL COMMENT 'NULL para conversas diretas; definido para grupos/geral',
  `tipo` enum('direto','grupo','geral','setor','filial','matriz','advogado') NOT NULL DEFAULT 'direto' COMMENT 'direto=2 usuários | grupo=N | geral=canal conta | setor=team | filial/matriz=cross-account | advogado=externo',
  `team_id` int(10) unsigned DEFAULT NULL COMMENT 'FK teams.id — preenchido quando tipo = setor',
  `ref_account_id` int(10) unsigned DEFAULT NULL COMMENT 'Conta de destino — preenchido nos tipos filial/matriz (cross-account)',
  `criado_por` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived_at` datetime DEFAULT NULL COMMENT 'Preenchido quando a conversa é arquivada; NULL = ativa',
  `deleted_at` datetime DEFAULT NULL COMMENT 'Soft-delete — conversa excluída mas dados preservados',
  `canal_geral_key` varchar(64) DEFAULT NULL COMMENT 'NULL para não-geral; account_id.toString() para tipo=geral (garante unicidade)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_canal_geral` (`canal_geral_key`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_updated_at` (`updated_at`),
  KEY `idx_criado_por` (`criado_por`),
  KEY `idx_cc_account` (`account_id`),
  KEY `idx_cc_team` (`team_id`),
  KEY `idx_cc_ref_acc` (`ref_account_id`),
  KEY `idx_cc_deleted` (`deleted_at`),
  KEY `idx_cc_archived` (`archived_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_mencoes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mensagem_id` int(10) unsigned NOT NULL,
  `tipo` enum('usuario','processo','card') NOT NULL,
  `referencia_id` int(10) unsigned NOT NULL,
  `texto_exibido` varchar(200) NOT NULL,
  `url_destino` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mensagem` (`mensagem_id`),
  KEY `idx_tipo_ref` (`tipo`,`referencia_id`),
  KEY `idx_chatmen_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_mensagens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversa_id` int(10) unsigned NOT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `mensagem` text NOT NULL COMMENT 'Texto com tokens de menção: @[tipo|id|display]',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `account_id` int(11) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conversa` (`conversa_id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_chatm_account` (`account_id`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_participantes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversa_id` int(10) unsigned NOT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `ultima_leitura_id` int(10) unsigned DEFAULT NULL COMMENT 'ID da última mensagem lida',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conv_user` (`conversa_id`,`usuario_id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_chatp_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contato_vinculos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contato_id` int(11) NOT NULL,
  `tipo_vinculo` enum('card','processo','chat') NOT NULL,
  `referencia_id` int(11) NOT NULL COMMENT 'PK da tabela referenciada pelo tipo_vinculo',
  `origem` varchar(50) NOT NULL DEFAULT 'manual' COMMENT 'Origem do vínculo: manual, sync, webhook, migracao',
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vinculo` (`contato_id`,`tipo_vinculo`,`referencia_id`),
  KEY `idx_contato` (`contato_id`),
  KEY `idx_referencia` (`tipo_vinculo`,`referencia_id`),
  KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contatos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `nome` varchar(191) NOT NULL,
  `telefone` varchar(30) DEFAULT NULL COMMENT 'Somente dígitos com DDI: 5511999998888',
  `remote_jid` varchar(120) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `anonymized_at` datetime DEFAULT NULL,
  `deletion_reason` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_telefone` (`telefone`),
  UNIQUE KEY `uniq_remote_jid` (`remote_jid`),
  KEY `idx_contatos_account` (`account_id`),
  KEY `idx_contatos_deleted` (`deleted_at`),
  KEY `idx_anon` (`anonymized_at`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_processor_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `processor_id` int(11) NOT NULL,
  `acao` varchar(60) NOT NULL COMMENT 'criado | atualizado | dpa_assinado | dpa_renovado | dpa_vencido | desativado | reativado',
  `descricao` text DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot dos campos alterados (antes/depois)' CHECK (json_valid(`payload`)),
  `by_user_id` int(11) DEFAULT NULL COMMENT 'Operador que executou a acao (NULL = sistema/cron)',
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `request_id` varchar(32) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_proc_history` (`processor_id`,`created_at`),
  KEY `idx_acao` (`acao`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_dph_no_update BEFORE UPDATE ON data_processor_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'data_processor_history e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_dph_no_delete BEFORE DELETE ON data_processor_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'data_processor_history e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_processors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `categoria` enum('api_externa','hospedagem','cdn','gateway_pagamento','smtp','llm_ia','monitoramento','suporte','analytics','backup','outro') NOT NULL,
  `papel` enum('operador','suboperador','controlador_conjunto') NOT NULL DEFAULT 'operador' COMMENT 'LGPD Art. 5: operador trata em nome do controlador; suboperador ├® terceiro contratado pelo operador',
  `cnpj_ou_id` varchar(100) DEFAULT NULL COMMENT 'CNPJ Brasil ou identificador estrangeiro (EIN, VAT, etc.)',
  `pais` varchar(2) DEFAULT NULL COMMENT 'Codigo ISO 3166-1 alpha-2 (ex: BR, US, DE)',
  `dados_tratados` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Estrutura: {categorias:[pii_basica|documentos|...],sensiveis:bool,volume_estimado:N}' CHECK (json_valid(`dados_tratados`)),
  `finalidade` text DEFAULT NULL COMMENT 'Para qual finalidade os dados sao compartilhados',
  `retencao_terceiro` varchar(255) DEFAULT NULL COMMENT 'Politica de retencao do terceiro (ex: "90 dias", "ate revogacao")',
  `transferencia_internacional` tinyint(1) NOT NULL DEFAULT 0,
  `base_legal_transferencia` enum('clausulas_contratuais_padrao','regras_corporativas_globais','decisao_anpd_adequacao','autorizacao_anpd_especifica','cooperacao_juridica_internacional','protecao_vida','cumprimento_obrigacao_legal','execucao_contrato_titular','consentimento_especifico','garantias_outras','nao_aplicavel') DEFAULT NULL,
  `dpa_status` enum('pendente','em_negociacao','assinado','vencido','dispensado','rejeitado') NOT NULL DEFAULT 'pendente',
  `dpa_assinado_em` date DEFAULT NULL,
  `dpa_validade` date DEFAULT NULL COMMENT 'NULL = sem prazo (renovacao automatica ou indefinido)',
  `dpa_url` varchar(500) DEFAULT NULL COMMENT 'Path/URL do PDF do contrato (storage interno)',
  `certificacoes` text DEFAULT NULL COMMENT 'Ex: ISO 27001, SOC 2 Type II, ISO 27701',
  `contato_dpo_terceiro` varchar(150) DEFAULT NULL COMMENT 'E-mail do encarregado do terceiro',
  `url_politica_privacidade` varchar(500) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `desativado_em` datetime DEFAULT NULL,
  `motivo_desativacao` text DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nome` (`nome`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_dpa_status` (`dpa_status`),
  KEY `idx_dpa_validade` (`dpa_validade`),
  KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dre_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `codigo` varchar(64) DEFAULT NULL,
  `nome` varchar(191) NOT NULL,
  `tipo` enum('receita','despesa') NOT NULL DEFAULT 'despesa',
  `valor_fixo` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `recorrencia` varchar(30) DEFAULT 'fixa' COMMENT 'Tipo de recorrência do lançamento: fixa | variavel | unica',
  `data_referencia` date DEFAULT NULL COMMENT 'Data de referência do lançamento (mês/ano para fixos mensais)',
  `descricao` text DEFAULT NULL COMMENT 'Descrição longa do lançamento (usada por TaskLink para resolver nome)',
  PRIMARY KEY (`id`),
  KEY `idx_dre_accounts_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dre_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `code` varchar(64) NOT NULL,
  `descricao` varchar(191) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dre_codes_account_code` (`account_id`,`code`),
  KEY `idx_dre_codes_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `emails_outbox` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `to_email` varchar(150) NOT NULL,
  `to_name` varchar(100) DEFAULT NULL,
  `subject` varchar(200) NOT NULL,
  `body_html` longtext DEFAULT NULL,
  `body_text` text DEFAULT NULL,
  `template_key` varchar(80) DEFAULT NULL,
  `status` enum('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `retries` int(11) NOT NULL DEFAULT 0,
  `error` text DEFAULT NULL,
  `scheduled_at` datetime DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emo_status` (`status`,`scheduled_at`),
  KEY `idx_emo_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gateway_events_received` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gateway` varchar(50) NOT NULL,
  `event_id` varchar(255) NOT NULL,
  `event_type` varchar(100) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `processed` tinyint(1) NOT NULL DEFAULT 0,
  `error` text DEFAULT NULL,
  `received_at` datetime DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gateway_event` (`gateway`,`event_id`),
  KEY `idx_gev_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `goals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `referencia_mes` varchar(7) DEFAULT NULL,
  `valor_meta` decimal(15,2) DEFAULT 0.00,
  `tipo_meta` varchar(50) DEFAULT 'global',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_goals_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subscription_id` int(11) DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `amount_cents` int(11) NOT NULL DEFAULT 0,
  `moeda` varchar(3) NOT NULL DEFAULT 'BRL',
  `status` enum('draft','open','paid','void','uncollectible','refunded') NOT NULL DEFAULT 'draft',
  `due_date` date DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `numero` varchar(40) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `gateway` varchar(50) DEFAULT NULL,
  `gateway_invoice_id` varchar(120) DEFAULT NULL,
  `pdf_url` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `observacoes_cobranca` text DEFAULT NULL,
  `marcado_manualmente_por` int(11) DEFAULT NULL COMMENT 'FK super_admins.id quando alterado manualmente',
  `marcado_manualmente_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero` (`numero`),
  KEY `idx_inv_account` (`account_id`,`status`,`due_date`),
  KEY `idx_inv_subscription` (`subscription_id`),
  KEY `idx_inv_gateway` (`gateway`,`gateway_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `legal_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('privacy_policy','terms_of_use','cookies_policy','dpa','security_policy','privacy_notice') NOT NULL COMMENT 'Tipo do documento legal',
  `versao` varchar(20) NOT NULL COMMENT 'Ex: 2026-05-23 ou 1.0.0',
  `titulo` varchar(200) NOT NULL,
  `conteudo_md` longtext NOT NULL COMMENT 'Conteudo em Markdown',
  `hash_conteudo` char(64) NOT NULL COMMENT 'SHA-256 do conteudo (detecta mudancas)',
  `publicado_em` datetime DEFAULT NULL,
  `vigente_ate` datetime DEFAULT NULL COMMENT 'NULL = vigente; data = arquivado',
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_por_user_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tipo_versao` (`tipo`,`versao`),
  KEY `idx_tipo_vigente` (`tipo`,`ativo`,`vigente_ate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lgpd_consents` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `titular_email` varchar(150) DEFAULT NULL COMMENT 'Para titulares nao-cadastrados',
  `titular_telefone` varchar(30) DEFAULT NULL,
  `finalidade` varchar(100) NOT NULL COMMENT 'cookies_analiticos | marketing_email | ia_processar_dados | envio_whatsapp etc',
  `base_legal` enum('consentimento','contrato','obrigacao_legal','exercicio_direito','interesse_legitimo','tutela_saude','protecao_credito','interesse_publico') NOT NULL DEFAULT 'consentimento',
  `status` enum('ativo','revogado','expirado') NOT NULL DEFAULT 'ativo',
  `versao_termo_id` int(11) DEFAULT NULL COMMENT 'FK opcional legal_documents.id (qual versao do termo)',
  `concedido_em` datetime DEFAULT current_timestamp(),
  `revogado_em` datetime DEFAULT NULL,
  `fonte` varchar(80) DEFAULT NULL COMMENT 'banner_cookie | signup | form | painel_config',
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `evidencia` longtext DEFAULT NULL COMMENT 'JSON: checkbox marcado, texto exibido, etc',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_fin` (`user_id`,`finalidade`,`status`),
  KEY `idx_account_fin` (`account_id`,`finalidade`),
  KEY `idx_email_fin` (`titular_email`,`finalidade`),
  KEY `idx_concedido` (`concedido_em`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lgpd_request_attachments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) NOT NULL,
  `nome_arquivo` varchar(200) NOT NULL,
  `caminho_arquivo` varchar(500) NOT NULL COMMENT 'Path em storage/lgpd_requests/<request_id>/<sha256>.<ext>',
  `tipo_arquivo` varchar(100) NOT NULL COMMENT 'MIME validado via finfo_file',
  `tamanho` int(11) NOT NULL DEFAULT 0,
  `sha256` char(64) NOT NULL COMMENT 'Hash para detectar adultera├º├úo',
  `categoria` enum('documento_titular','procuracao','export_dados','comprovante_envio','evidencia_analise','justificativa_juridica','outro') NOT NULL,
  `visibilidade` enum('interno','entregue_titular') NOT NULL DEFAULT 'interno' COMMENT 'interno = s├│ DPO/painel. entregue_titular = enviado/disponibilizado ao titular',
  `descricao` text DEFAULT NULL,
  `uploaded_by_user_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_visibilidade` (`visibilidade`),
  KEY `idx_sha256` (`sha256`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lra_partial_update BEFORE UPDATE ON lgpd_request_attachments
FOR EACH ROW BEGIN
  IF (NEW.request_id     <> OLD.request_id)
  OR (NEW.nome_arquivo   <> OLD.nome_arquivo)
  OR (NEW.caminho_arquivo<> OLD.caminho_arquivo)
  OR (NEW.tipo_arquivo   <> OLD.tipo_arquivo)
  OR (NEW.sha256         <> OLD.sha256)
  OR (NEW.categoria      <> OLD.categoria)
  OR (NEW.uploaded_by_user_id <> OLD.uploaded_by_user_id)
  OR (NEW.created_at     <> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_attachments: apenas visibilidade e descricao podem mudar';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lra_no_delete BEFORE DELETE ON lgpd_request_attachments
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_attachments e imutavel (DELETE bloqueado)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lgpd_request_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) NOT NULL,
  `evento` varchar(80) NOT NULL COMMENT 'criado | identidade_confirmada | aceito | em_analise | dados_buscados | exportado | respondido | rejeitado | expirado',
  `tipo_acao` varchar(50) DEFAULT NULL COMMENT 'criacao | atualizacao | consulta | busca_modulo | export | anonimizacao | rejeicao | aprovacao | comentario | acesso_drawer | prorrogacao',
  `observacao` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'Operador (NULL se a├º├úo automatizada do sistema)',
  `status_anterior` varchar(40) DEFAULT NULL,
  `status_novo` varchar(40) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_request_event` (`request_id`,`created_at`),
  KEY `idx_evento` (`evento`),
  KEY `idx_tipo_acao` (`tipo_acao`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lre_no_update BEFORE UPDATE ON lgpd_request_events
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_events e imutavel (LGPD Art. 18)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lre_no_delete BEFORE DELETE ON lgpd_request_events
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_events e imutavel (LGPD Art. 18)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lgpd_request_findings` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) NOT NULL,
  `modulo` varchar(50) NOT NULL COMMENT 'Mesma taxonomia de lgpd_request_modules.modulo',
  `entidade` varchar(60) NOT NULL COMMENT 'users | contatos | cards | processos | whatsapp_messages | chat_mensagens | invoices | etc',
  `entidade_id` bigint(20) NOT NULL,
  `account_id` int(11) DEFAULT NULL COMMENT 'Tenant onde o dado foi encontrado (NULL se global)',
  `tipo_dado` enum('pii_basica','documento','juridico_processual','financeiro','autenticacao','comunicacao','sensivel_lgpd','metadado','outro') NOT NULL,
  `campo` varchar(80) DEFAULT NULL COMMENT 'Nome da coluna onde achou (ex: nome, email, telefone)',
  `valor_mascarado` varchar(255) DEFAULT NULL COMMENT 'Ex: "ana***@gmail.com", "(11) ****-5678", "***.456.***-**"',
  `hash_valor` char(64) DEFAULT NULL COMMENT 'SHA-256 do valor cru + pepper ÔÇö para compara├º├úo sem expor PII',
  `base_legal` enum('consentimento','contrato','obrigacao_legal','exercicio_direito','interesse_legitimo','tutela_saude','protecao_credito','interesse_publico') DEFAULT NULL,
  `finalidade` varchar(200) DEFAULT NULL,
  `pode_excluir` tinyint(1) DEFAULT NULL COMMENT 'NULL=ainda nao analisado | 1=pode excluir/anonimizar | 0=retem',
  `motivo_retencao` varchar(255) DEFAULT NULL COMMENT 'Texto resumido. Justificativa completa fica em lgpd_request_retention_justifications',
  `incluido_no_export` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'DPO marca true ap├│s revisar e decidir incluir no ZIP',
  `revisado_em` datetime DEFAULT NULL,
  `revisado_por_user_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  KEY `idx_entidade` (`entidade`,`entidade_id`),
  KEY `idx_tipo_dado` (`tipo_dado`),
  KEY `idx_pendente_revisao` (`request_id`,`revisado_em`),
  KEY `idx_hash` (`hash_valor`)
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lrf_partial_update BEFORE UPDATE ON lgpd_request_findings
FOR EACH ROW BEGIN
  IF (NEW.request_id  <> OLD.request_id)
  OR (NEW.modulo      <> OLD.modulo)
  OR (NEW.entidade    <> OLD.entidade)
  OR (NEW.entidade_id <> OLD.entidade_id)
  OR (NEW.tipo_dado   <> OLD.tipo_dado)
  OR (IFNULL(NEW.campo,'')           <> IFNULL(OLD.campo,''))
  OR (IFNULL(NEW.valor_mascarado,'') <> IFNULL(OLD.valor_mascarado,''))
  OR (IFNULL(NEW.hash_valor,'')      <> IFNULL(OLD.hash_valor,''))
  OR (NEW.created_at  <> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_findings: apenas campos de revisao podem ser alterados';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lrf_no_delete BEFORE DELETE ON lgpd_request_findings
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_findings e imutavel (DELETE bloqueado)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lgpd_request_modules` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) NOT NULL,
  `modulo` varchar(50) NOT NULL COMMENT 'usuarios | contatos | leads_cards | processos | whatsapp | chat_interno | financeiro | task_attachments | logs_auditoria | consentimentos | aceites_termos | outros',
  `pesquisado_em` datetime DEFAULT current_timestamp(),
  `pesquisado_por_user_id` int(11) NOT NULL COMMENT 'DPO que disparou a busca',
  `resultado_resumo` varchar(255) DEFAULT NULL COMMENT 'Ex: "2 registros encontrados", "Nenhum dado", "Acesso negado por sigilo profissional"',
  `total_registros` int(11) NOT NULL DEFAULT 0 COMMENT 'Quantos registros bateram a busca',
  `observacao` text DEFAULT NULL,
  `request_id_correlacao` varchar(32) DEFAULT NULL COMMENT 'RequestId helper ÔÇö correlaciona com master_audit_log',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`,`modulo`),
  KEY `idx_modulo` (`modulo`),
  KEY `idx_data` (`pesquisado_em`)
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lrm_no_update BEFORE UPDATE ON lgpd_request_modules
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_modules e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lrm_no_delete BEFORE DELETE ON lgpd_request_modules
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_modules e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lgpd_request_retention_justifications` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) NOT NULL,
  `entidade` varchar(60) NOT NULL,
  `entidade_id` bigint(20) NOT NULL,
  `finding_id` bigint(20) DEFAULT NULL COMMENT 'FK opcional ÔåÆ lgpd_request_findings.id',
  `base_legal_retencao` enum('obrigacao_legal','exercicio_direitos_processo','cumprimento_contrato','dados_terceiros','sigilo_profissional','seguranca_sistema','auditoria_obrigatoria','anonimizacao_inviavel','outro') NOT NULL,
  `prazo_retencao_ate` date DEFAULT NULL COMMENT 'NULL = indeterminado (manter enquanto houver fundamento)',
  `justificativa` text NOT NULL COMMENT 'Texto livre obrigat├│rio com raz├úo',
  `fundamentacao_juridica` text DEFAULT NULL COMMENT 'Cita├º├úo de artigo, jurisprud├¬ncia, parecer',
  `responsavel_user_id` int(11) NOT NULL,
  `aprovado_por_user_id` int(11) DEFAULT NULL,
  `aprovado_em` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  KEY `idx_entidade` (`entidade`,`entidade_id`),
  KEY `idx_finding` (`finding_id`),
  KEY `idx_base_legal` (`base_legal_retencao`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lrj_partial_update BEFORE UPDATE ON lgpd_request_retention_justifications
FOR EACH ROW BEGIN
  IF (NEW.request_id <> OLD.request_id)
  OR (NEW.entidade   <> OLD.entidade)
  OR (NEW.entidade_id<> OLD.entidade_id)
  OR (IFNULL(NEW.finding_id,0) <> IFNULL(OLD.finding_id,0))
  OR (NEW.base_legal_retencao <> OLD.base_legal_retencao)
  OR (NEW.justificativa       <> OLD.justificativa)
  OR (NEW.responsavel_user_id <> OLD.responsavel_user_id)
  OR (NEW.created_at <> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_retention_justifications: justificativa imutavel apos criada (apenas aprovacao pode mudar)';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_lrj_no_delete BEFORE DELETE ON lgpd_request_retention_justifications
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_retention_justifications e imutavel (DELETE bloqueado)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lgpd_requests` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `titular_nome` varchar(150) NOT NULL,
  `titular_email` varchar(150) NOT NULL,
  `titular_cpf` varchar(14) DEFAULT NULL,
  `titular_telefone` varchar(30) DEFAULT NULL,
  `titular_tipo` enum('lead','cliente','advogado_associado','usuario_interno','parte_processual','contato_whatsapp','responsavel_financeiro','visitante','outro') DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL COMMENT 'Tenant que armazena os dados (NULL se n├úo identificado)',
  `user_id` int(11) DEFAULT NULL COMMENT 'Se o titular j├í ├® usu├írio cadastrado',
  `tipo` enum('confirmacao_existencia','acesso','correcao','anonimizacao','bloqueio','eliminacao','portabilidade','info_compartilhamento','revogacao_consentimento','revisao_decisao_automatizada') NOT NULL,
  `descricao` text DEFAULT NULL COMMENT 'Detalhes da solicita├º├úo informados pelo titular',
  `status` enum('aberto','em_analise','aguardando_titular','concluido','rejeitado','expirado') NOT NULL DEFAULT 'aberto',
  `motivo_rejeicao` text DEFAULT NULL,
  `prazo_resposta` datetime NOT NULL COMMENT 'LGPD Art. 19 ÔÇö padr├úo 15 dias',
  `recebido_em` datetime DEFAULT current_timestamp(),
  `respondido_em` datetime DEFAULT NULL,
  `respondido_por_user_id` int(11) DEFAULT NULL,
  `resposta` text DEFAULT NULL,
  `arquivo_resposta_path` varchar(500) DEFAULT NULL COMMENT 'Path do ZIP de export (portabilidade)',
  `ip_origem` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `token_acesso` char(64) NOT NULL COMMENT 'Token ├║nico pra titular acompanhar sem login',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `titular_referencia_entidade` varchar(40) DEFAULT NULL COMMENT 'Tabela onde titular ja esta cadastrado (users, contatos, cards...)',
  `titular_referencia_id` bigint(20) DEFAULT NULL COMMENT 'ID na tabela acima (polimorfico)',
  `documento_tipo` enum('cpf','cnpj','rg','cnh','oab','passaporte','outro') DEFAULT NULL,
  `documento_numero_mascarado` varchar(40) DEFAULT NULL COMMENT 'Ex: 123.***.***-45',
  `documento_hash` char(64) DEFAULT NULL COMMENT 'SHA-256(numero_completo + LGPD_DOC_HASH_PEPPER) para busca sem PII',
  `matriz_account_id` int(11) DEFAULT NULL COMMENT 'Derivado de accounts.matriz_id quando account_id e filial',
  `responsavel_dpo_user_id` int(11) DEFAULT NULL COMMENT 'DPO atribuido (tenant-specific ou plataforma)',
  `canal_origem` enum('formulario_publico','email','telefone','presencial','ouvidoria_anpd','painel_interno','outro') NOT NULL DEFAULT 'formulario_publico',
  `prioridade` enum('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
  `prazo_prorrogado_em` date DEFAULT NULL COMMENT 'Art. 19 par. 3 - quando o prazo original foi prorrogado',
  `motivo_prorrogacao` text DEFAULT NULL,
  `atendimento_parcial` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Marca quando parte foi concedida e parte negada',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token_acesso`),
  KEY `idx_email` (`titular_email`),
  KEY `idx_status_prazo` (`status`,`prazo_resposta`),
  KEY `idx_account` (`account_id`),
  KEY `idx_tipo` (`tipo`,`status`),
  KEY `idx_titular_tipo` (`titular_tipo`),
  KEY `idx_documento_hash` (`documento_hash`),
  KEY `idx_responsavel` (`responsavel_dpo_user_id`),
  KEY `idx_matriz` (`matriz_account_id`),
  KEY `idx_prioridade` (`prioridade`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `login` varchar(100) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_ip_login` (`ip`,`login`,`created_at`),
  KEY `idx_login_attempts_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `super_admin_id` int(11) NOT NULL,
  `acao` varchar(80) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `metadata` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `user_agent` varchar(255) DEFAULT NULL,
  `request_id` char(12) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mal_super` (`super_admin_id`,`created_at`),
  KEY `idx_mal_target` (`target_type`,`target_id`),
  KEY `idx_request_id` (`request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_mal_no_update BEFORE UPDATE ON master_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'master_audit_log e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_mal_no_delete BEFORE DELETE ON master_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'master_audit_log e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria` enum('servidor','pessoas','apis','marketing','infraestrutura','impostos','software','juridico','outros') NOT NULL DEFAULT 'outros',
  `descricao` varchar(255) NOT NULL,
  `fornecedor` varchar(150) DEFAULT NULL,
  `valor_cents` int(11) NOT NULL DEFAULT 0 COMMENT 'Valor em centavos pra evitar floating point',
  `moeda` varchar(3) NOT NULL DEFAULT 'BRL',
  `data_competencia` date NOT NULL COMMENT 'M├¬s/ano em que a despesa pertence (pra agrega├º├úo no DRE)',
  `vencimento` date DEFAULT NULL COMMENT 'Data limite pra pagar',
  `data_pagamento` date DEFAULT NULL COMMENT 'Quando foi efetivamente pago',
  `status` enum('pendente','pago','atrasado','cancelado') NOT NULL DEFAULT 'pendente',
  `recorrencia` enum('nenhuma','mensal','anual') NOT NULL DEFAULT 'nenhuma' COMMENT 'Se mensal/anual, futuras inst├óncias replicam',
  `metodo_pagamento` varchar(50) DEFAULT NULL COMMENT 'cartao | pix | boleto | debito | transferencia | outro',
  `observacoes` text DEFAULT NULL,
  `created_by_super_admin_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_competencia` (`data_competencia`),
  KEY `idx_status_venc` (`status`,`vencimento`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `gateway` varchar(50) NOT NULL,
  `gateway_token` varchar(255) NOT NULL,
  `tipo` enum('card','pix','boleto','wallet') NOT NULL DEFAULT 'card',
  `brand` varchar(20) DEFAULT NULL,
  `last4` varchar(4) DEFAULT NULL,
  `exp_month` tinyint(4) DEFAULT NULL,
  `exp_year` smallint(6) DEFAULT NULL,
  `holder_name` varchar(100) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pm_account` (`account_id`,`is_default`),
  KEY `idx_pm_gateway` (`gateway`,`gateway_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pending_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria` enum('documento_legal','operador_dpa','configuracao_env','seguranca_tecnica','treinamento','auditoria_externa','designacao_dpo','outro') NOT NULL,
  `item_key` varchar(100) NOT NULL COMMENT 'Slug identificador (ex: doc_privacidade, contrato_smtp)',
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `responsavel` varchar(80) DEFAULT NULL COMMENT 'DPO | juridico_externo | diretoria | equipe_tecnica | RH',
  `prioridade` enum('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
  `prazo` date DEFAULT NULL COMMENT 'Quando idealmente deve estar concluido (NULL = sem prazo definido)',
  `status` enum('pendente','em_revisao','concluido','dispensado','bloqueado') NOT NULL DEFAULT 'pendente',
  `bloqueia_producao` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Se 1, bloqueia go-live ate concluir',
  `concluido_em` datetime DEFAULT NULL,
  `concluido_por_user_id` int(11) DEFAULT NULL,
  `notas_internas` text DEFAULT NULL COMMENT 'Notas livres do DPO/Diretoria (so visiveis no painel master)',
  `link_referencia` varchar(500) DEFAULT NULL COMMENT 'URL/path para documento relacionado (ex: /privacidade.php)',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_key` (`item_key`),
  KEY `idx_status` (`status`),
  KEY `idx_categoria` (`categoria`),
  KEY `idx_prazo` (`prazo`),
  KEY `idx_bloqueia` (`bloqueia_producao`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pipeline_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `nome` varchar(191) NOT NULL,
  `slug` varchar(191) NOT NULL,
  `cor` varchar(20) DEFAULT '#EEEEEE',
  `ordem` int(11) DEFAULT 0,
  `conta_funil` tinyint(1) DEFAULT 1,
  `conta_oportunidade` tinyint(1) DEFAULT 1,
  `conta_fechado` tinyint(1) DEFAULT 0,
  `conta_perdido` tinyint(1) DEFAULT 0,
  `peso_projecao` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pipeline_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plan_features` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `feature_key` varchar(80) NOT NULL,
  `limit_value` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plan_feature` (`plan_id`,`feature_key`),
  KEY `idx_pf_plan` (`plan_id`),
  CONSTRAINT `fk_pf_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `preco_mensal_cents` int(11) NOT NULL DEFAULT 0,
  `preco_anual_cents` int(11) NOT NULL DEFAULT 0,
  `moeda` varchar(3) NOT NULL DEFAULT 'BRL',
  `trial_dias` int(11) NOT NULL DEFAULT 0,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `destaque` tinyint(1) NOT NULL DEFAULT 0,
  `ordem` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_plans_ativo` (`ativo`,`ordem`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processo_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `processo_id` int(11) NOT NULL,
  `user_email` varchar(150) DEFAULT NULL,
  `acao` varchar(100) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `author_account_id` int(11) DEFAULT NULL COMMENT 'Conta do usu├írio que executou a a├º├úo (snapshot, n├úo FK)',
  `author_account_tipo` varchar(20) DEFAULT NULL COMMENT 'matriz | filial ÔÇö snapshot no momento da a├º├úo',
  `author_account_nome` varchar(150) DEFAULT NULL COMMENT 'Nome da conta do autor ÔÇö snapshot pra exibi├º├úo',
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `request_id` char(12) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `processo_id` (`processo_id`),
  KEY `idx_proch_processo` (`processo_id`,`created_at`),
  KEY `idx_ph_author_account` (`author_account_id`),
  KEY `idx_request_id` (`request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=127 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ph_no_update BEFORE UPDATE ON processo_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'processo_history e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ph_no_delete BEFORE DELETE ON processo_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'processo_history e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processo_prazos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `processo_id` int(11) NOT NULL,
  `descricao` varchar(255) NOT NULL,
  `data_limite` date DEFAULT NULL,
  `responsavel` varchar(150) DEFAULT NULL,
  `status` enum('pendente','concluido','vencido') DEFAULT 'pendente',
  `prioridade` enum('baixa','media','alta') DEFAULT 'media',
  `observacao` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `processo_id` (`processo_id`),
  KEY `idx_prpz_processo` (`processo_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processo_tarefas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `processo_id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `concluido` tinyint(4) DEFAULT 0,
  `prioridade` enum('baixa','media','alta') DEFAULT 'media',
  `responsavel` varchar(150) DEFAULT NULL,
  `data_tarefa` date DEFAULT NULL,
  `ordem` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `processo_id` (`processo_id`),
  KEY `idx_prtf_processo` (`processo_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `account_seq` int(11) DEFAULT NULL,
  `numero` varchar(255) DEFAULT NULL,
  `cliente_nome` varchar(255) DEFAULT NULL,
  `parte_contraria` varchar(255) DEFAULT NULL,
  `tipo_acao` varchar(255) DEFAULT NULL,
  `setor_id` int(11) DEFAULT NULL,
  `vara_comarca` varchar(255) DEFAULT NULL,
  `responsavel_user_id` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'ativo',
  `data_inicio` date DEFAULT NULL,
  `proximo_prazo` date DEFAULT NULL,
  `ultima_movimentacao` datetime DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `anexos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`anexos`)),
  `alerts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`alerts`)),
  `card_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `contato_id` int(11) DEFAULT NULL,
  `cpf_cnpj_parte_contraria` varchar(20) DEFAULT NULL COMMENT 'CPF ou CNPJ da parte contrária',
  `numero_cnj` varchar(30) DEFAULT NULL COMMENT 'Número CNJ do processo (formato NNNNNNN-DD.AAAA.J.TT.OOOO)',
  `anonymized_at` datetime DEFAULT NULL,
  `deletion_reason` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_processos_account_seq` (`account_id`,`account_seq`),
  KEY `idx_processo_contato` (`contato_id`),
  KEY `idx_processos_account` (`account_id`),
  KEY `idx_anon` (`anonymized_at`)
) ENGINE=InnoDB AUTO_INCREMENT=133 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_event_user_status` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned NOT NULL,
  `lida` tinyint(1) NOT NULL DEFAULT 0,
  `lida_em` datetime DEFAULT NULL,
  `favorita` tinyint(1) NOT NULL DEFAULT 0,
  `com_prazo` tinyint(1) NOT NULL DEFAULT 0,
  `prazo_data` date DEFAULT NULL,
  `comentario` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_user` (`event_id`,`user_id`),
  KEY `idx_user_account` (`user_id`,`account_id`),
  KEY `idx_account_lida` (`account_id`,`lida`),
  KEY `idx_account_fav` (`account_id`,`favorita`),
  KEY `idx_account_prazo` (`account_id`,`com_prazo`,`prazo_data`),
  CONSTRAINT `fk_pues_event` FOREIGN KEY (`event_id`) REFERENCES `push_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `source_id` varchar(40) NOT NULL DEFAULT 'djen',
  `advogado_id` int(10) unsigned DEFAULT NULL,
  `processo_id` int(10) unsigned DEFAULT NULL,
  `monitor_id` int(10) unsigned DEFAULT NULL,
  `tipo_evento` varchar(50) NOT NULL DEFAULT 'publicacao',
  `titulo` varchar(255) DEFAULT NULL,
  `resumo` varchar(500) DEFAULT NULL,
  `conteudo` longtext DEFAULT NULL,
  `data_evento` datetime NOT NULL,
  `data_disponibilizacao` date NOT NULL,
  `data_publicacao` date DEFAULT NULL,
  `tribunal` varchar(20) NOT NULL,
  `orgao` varchar(255) DEFAULT NULL,
  `id_orgao` int(10) unsigned DEFAULT NULL,
  `numero_processo` varchar(40) DEFAULT NULL,
  `numero_processo_mascara` varchar(40) DEFAULT NULL,
  `tipo_comunicacao` varchar(80) DEFAULT NULL,
  `meio` varchar(8) DEFAULT NULL,
  `meio_completo` varchar(150) DEFAULT NULL,
  `classe_nome` varchar(200) DEFAULT NULL,
  `classe_codigo` varchar(20) DEFAULT NULL,
  `advogado_nome` varchar(200) DEFAULT NULL,
  `advogado_oab` varchar(20) DEFAULT NULL,
  `url_origem` varchar(500) DEFAULT NULL,
  `numero_comunicacao` bigint(20) unsigned DEFAULT NULL,
  `hash_externo` varchar(80) DEFAULT NULL,
  `hash_conteudo` char(64) NOT NULL,
  `payload_original` longtext DEFAULT NULL,
  `origem_busca` enum('automatica','manual','cache_hoje') NOT NULL DEFAULT 'manual',
  `status` varchar(20) NOT NULL DEFAULT 'ativo',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_hash` (`account_id`,`hash_conteudo`),
  KEY `idx_account_data` (`account_id`,`data_disponibilizacao`),
  KEY `idx_tribunal` (`tribunal`),
  KEY `idx_processo` (`numero_processo`),
  KEY `idx_monitor` (`monitor_id`),
  KEY `idx_advogado` (`advogado_id`),
  KEY `idx_processo_id` (`processo_id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_monitors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `advogado_id` int(10) unsigned DEFAULT NULL,
  `processo_id` int(10) unsigned DEFAULT NULL,
  `source_id` varchar(40) NOT NULL DEFAULT 'djen',
  `tipo_monitoramento` enum('oab','processo','nome','customizado') NOT NULL DEFAULT 'oab',
  `valor_monitorado` varchar(120) NOT NULL,
  `nome_complementar` varchar(200) DEFAULT NULL,
  `uf` char(2) DEFAULT NULL,
  `tribunal` varchar(20) DEFAULT NULL,
  `status` enum('ativo','pausado','erro','arquivado') NOT NULL DEFAULT 'ativo',
  `prioridade` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `intervalo_minutos` int(10) unsigned NOT NULL DEFAULT 120,
  `ultima_consulta_em` datetime DEFAULT NULL,
  `proxima_consulta_em` datetime DEFAULT NULL,
  `ultimo_hash_resultado` varchar(64) DEFAULT NULL,
  `ultimo_erro` varchar(255) DEFAULT NULL,
  `erros_consecutivos` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_account_status` (`account_id`,`status`),
  KEY `idx_proxima` (`status`,`proxima_consulta_em`),
  KEY `idx_advogado` (`advogado_id`),
  KEY `idx_processo` (`processo_id`),
  KEY `idx_valor` (`valor_monitorado`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_processo_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `numero_processo` varchar(40) NOT NULL,
  `processo_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_processo` (`account_id`,`numero_processo`),
  KEY `idx_processo` (`processo_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_query_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned DEFAULT NULL,
  `monitor_id` int(10) unsigned DEFAULT NULL,
  `source_id` varchar(40) NOT NULL DEFAULT 'djen',
  `origem_busca` enum('automatica','manual','cache_hoje','retry') NOT NULL DEFAULT 'manual',
  `data_inicio_filtro` date DEFAULT NULL,
  `data_fim_filtro` date DEFAULT NULL,
  `request_url` varchar(500) DEFAULT NULL,
  `filtros_hash` char(64) DEFAULT NULL,
  `response_status` smallint(5) unsigned DEFAULT NULL,
  `response_hash` varchar(64) DEFAULT NULL,
  `total_resultados` int(10) unsigned DEFAULT 0,
  `status` enum('ok','erro','timeout','rate_limit') NOT NULL DEFAULT 'ok',
  `erro` varchar(500) DEFAULT NULL,
  `duracao_ms` int(10) unsigned DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_started` (`account_id`,`started_at`),
  KEY `idx_monitor` (`monitor_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=132 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_today_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `source_id` varchar(40) NOT NULL DEFAULT 'djen',
  `tribunal` varchar(20) NOT NULL,
  `data_disponibilizacao` date NOT NULL,
  `data_publicacao` date DEFAULT NULL,
  `numero_processo` varchar(40) DEFAULT NULL,
  `numero_processo_mascara` varchar(40) DEFAULT NULL,
  `orgao` varchar(255) DEFAULT NULL,
  `id_orgao` int(10) unsigned DEFAULT NULL,
  `tipo_comunicacao` varchar(80) DEFAULT NULL,
  `meio` varchar(8) DEFAULT NULL,
  `meio_completo` varchar(150) DEFAULT NULL,
  `classe_nome` varchar(200) DEFAULT NULL,
  `classe_codigo` varchar(20) DEFAULT NULL,
  `titulo` varchar(255) DEFAULT NULL,
  `resumo` varchar(500) DEFAULT NULL,
  `conteudo` longtext DEFAULT NULL,
  `url_origem` varchar(500) DEFAULT NULL,
  `numero_comunicacao` bigint(20) unsigned DEFAULT NULL,
  `hash_externo` varchar(80) DEFAULT NULL,
  `hash_conteudo` char(64) NOT NULL,
  `payload_original` longtext DEFAULT NULL,
  `encontrado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_hash` (`account_id`,`hash_conteudo`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_account_data` (`account_id`,`data_disponibilizacao`),
  KEY `idx_tribunal` (`tribunal`),
  KEY `idx_numero_processo` (`numero_processo`)
) ENGINE=InnoDB AUTO_INCREMENT=168 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resource_shares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_type` enum('card','processo','contato','task_board','module') NOT NULL,
  `resource_id` int(11) NOT NULL,
  `module_key` varchar(40) DEFAULT NULL COMMENT 'Chave do m├│dulo quando resource_type=module (ex: processos, juridico, dashboard)',
  `from_account_id` int(11) NOT NULL COMMENT 'Conta dona do recurso',
  `to_account_id` int(11) DEFAULT NULL COMMENT 'NULL = compartilhado com TODAS as contas vinculadas (Matriz→todas Filiais ou vice-versa)',
  `to_user_id` int(11) DEFAULT NULL COMMENT 'NULL = para toda a conta | preenchido = para usuário específico dentro da conta',
  `permission_level` enum('view','edit','full') NOT NULL DEFAULT 'view' COMMENT 'view=leitura | edit=edição | full=edição+exclusão+recompartilhamento',
  `criado_por` int(11) NOT NULL COMMENT 'user_id que criou o share',
  `status` enum('active','revoked') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rs_resource` (`resource_type`,`resource_id`),
  KEY `idx_rs_from` (`from_account_id`),
  KEY `idx_rs_to` (`to_account_id`),
  KEY `idx_rs_to_user` (`to_user_id`),
  KEY `idx_rs_status` (`status`),
  KEY `idx_to_user` (`to_user_id`),
  KEY `idx_resource_shares_module` (`module_key`,`status`),
  KEY `idx_rs_module` (`module_key`,`status`),
  CONSTRAINT `fk_rs_from` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_rs_to` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=125 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `retention_policies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entidade` varchar(100) NOT NULL COMMENT 'Ex: webhook_logs | login_attempts | whatsapp_messages_payload | emails_outbox_body',
  `campo_referencia` varchar(80) NOT NULL DEFAULT 'created_at' COMMENT 'Coluna usada pra calcular idade (created_at, sent_at, last_activity_at)',
  `retencao_dias` int(11) NOT NULL DEFAULT 90 COMMENT 'Tempo de reten├º├úo antes da a├º├úo',
  `acao_apos` enum('purge_fisico','anonimizar','arquivar') NOT NULL DEFAULT 'purge_fisico',
  `base_legal` text DEFAULT NULL COMMENT 'Justificativa LGPD para a pol├¡tica (Art. 7, II ÔÇö obriga├º├úo legal; leg├¡timo interesse; etc)',
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_run` datetime DEFAULT NULL,
  `ultimo_purge_count` int(11) NOT NULL DEFAULT 0,
  `ultimo_status` varchar(50) DEFAULT NULL COMMENT 'success | error | running',
  `ultimo_erro` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entidade` (`entidade`),
  KEY `idx_ativo_run` (`ativo`,`ultimo_run`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_incident_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) NOT NULL,
  `tipo_evento` varchar(80) NOT NULL COMMENT 'criado | status_alterado | medida_aplicada | notificacao_anpd | notificacao_titulares | comentario | anexo | reabertura',
  `descricao` text DEFAULT NULL,
  `status_anterior` varchar(40) DEFAULT NULL,
  `status_novo` varchar(40) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `user_id` int(11) DEFAULT NULL COMMENT 'Operador (NULL se a├º├úo do sistema)',
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `request_id` varchar(32) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_incident_event` (`incident_id`,`created_at`),
  KEY `idx_tipo_evento` (`tipo_evento`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_sie_no_update BEFORE UPDATE ON security_incident_events
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_incident_events e imutavel (LGPD Art. 48)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_sie_no_delete BEFORE DELETE ON security_incident_events
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_incident_events e imutavel (LGPD Art. 48)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_incidents` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL COMMENT 'Tenant afetado (NULL = plataforma / m├║ltiplos)',
  `tipo` enum('vazamento_dados','acesso_indevido','ransomware','phishing','dos_ddos','exposicao_credenciais','perda_dispositivo','engenharia_social','config_indevida','outro') NOT NULL,
  `severidade` enum('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
  `status` enum('detectado','em_analise','contido','mitigado','notificado_anpd','notificado_titulares','encerrado','falso_positivo') NOT NULL DEFAULT 'detectado',
  `titulo` varchar(200) NOT NULL,
  `descricao_interna` text DEFAULT NULL COMMENT 'Detalhes t├®cnicos (apenas DPO/admin)',
  `descricao_publica` text DEFAULT NULL COMMENT 'Vers├úo sanitizada para titulares/ANPD',
  `dados_afetados` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Estrutura: {categorias:[pii_basica|financeiro|juridico|autenticacao],titulares_estimados:N,registros:N,dados_sensiveis:bool}' CHECK (json_valid(`dados_afetados`)),
  `impacto` text DEFAULT NULL COMMENT 'Avalia├º├úo do impacto aos titulares',
  `causa_raiz` text DEFAULT NULL,
  `medidas_imediatas` text DEFAULT NULL COMMENT 'Conten├º├úo (primeiras horas)',
  `medidas_corretivas` text DEFAULT NULL COMMENT 'Mitiga├º├úo + li├º├Áes aprendidas',
  `detectado_em` datetime NOT NULL COMMENT 'Quando a equipe descobriu',
  `ocorrido_em` datetime DEFAULT NULL COMMENT 'In├¡cio estimado do incidente (pode ser antes da deteccao)',
  `contido_em` datetime DEFAULT NULL,
  `mitigado_em` datetime DEFAULT NULL,
  `notificacao_anpd_em` datetime DEFAULT NULL,
  `notificacao_anpd_protocolo` varchar(100) DEFAULT NULL,
  `notificacao_titulares_em` datetime DEFAULT NULL,
  `notificacao_titulares_canal` varchar(50) DEFAULT NULL COMMENT 'email | telefone | aviso_publico | nenhum',
  `encerrado_em` datetime DEFAULT NULL,
  `reportado_por_user_id` int(11) DEFAULT NULL COMMENT 'Quem abriu o registro',
  `responsavel_user_id` int(11) DEFAULT NULL COMMENT 'DPO ou respons├ível pela resposta',
  `request_id` varchar(32) DEFAULT NULL COMMENT 'Correla├º├úo com master_audit_log',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status_severidade` (`status`,`severidade`),
  KEY `idx_account` (`account_id`),
  KEY `idx_detectado` (`detectado_em`),
  KEY `idx_tipo` (`tipo`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `status` enum('trialing','active','past_due','canceled','unpaid','incomplete') NOT NULL DEFAULT 'trialing',
  `billing_cycle` enum('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `trial_ends_at` datetime DEFAULT NULL,
  `current_period_start` datetime DEFAULT NULL,
  `current_period_end` datetime DEFAULT NULL,
  `cancel_at_period_end` tinyint(1) NOT NULL DEFAULT 0,
  `canceled_at` datetime DEFAULT NULL,
  `payment_method_id` int(11) DEFAULT NULL,
  `gateway` varchar(50) DEFAULT NULL,
  `gateway_subscription_id` varchar(120) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sub_account` (`account_id`,`status`),
  KEY `idx_sub_plan` (`plan_id`),
  KEY `idx_sub_period_end` (`current_period_end`),
  KEY `idx_sub_gateway` (`gateway`,`gateway_subscription_id`),
  CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `super_admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `nivel` enum('viewer','operator','super') NOT NULL DEFAULT 'operator',
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `mfa_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login_at` datetime DEFAULT NULL,
  `mfa_secret` varbinary(255) DEFAULT NULL COMMENT 'Base32 secret cifrado (AES-256-CBC com MFA_ENCRYPTION_KEY)',
  `mfa_backup_codes_hash` text DEFAULT NULL COMMENT 'JSON array de 10 backup codes (bcrypt). C├│digos one-time, removidos ao usar.',
  `mfa_setup_at` datetime DEFAULT NULL COMMENT 'Quando 2FA foi habilitado',
  `mfa_last_used_at` datetime DEFAULT NULL COMMENT '├Ültimo uso bem-sucedido de c├│digo TOTP ou backup',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_sa_user` (`user_id`,`ativo`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `task_attachments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_board_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `papel` enum('owner','editor','viewer') DEFAULT 'editor',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_board_user` (`board_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `task_board_members_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `task_boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_board_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_boards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `nome` varchar(150) NOT NULL,
  `descricao` text DEFAULT NULL,
  `tipo` enum('pessoal','compartilhado') NOT NULL DEFAULT 'pessoal',
  `owner_id` int(11) NOT NULL,
  `cor` varchar(20) DEFAULT '#6366f1',
  `ordem` int(11) DEFAULT 0,
  `ativo` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_taskboards_account` (`account_id`),
  CONSTRAINT `task_boards_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `descricao` varchar(500) NOT NULL,
  `concluido` tinyint(1) DEFAULT 0,
  `ordem` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `prazo` date DEFAULT NULL COMMENT 'Prazo individual do item do checklist (opcional)',
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`),
  CONSTRAINT `task_checklist_items_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `ordem` int(11) DEFAULT 0,
  `cor` varchar(20) DEFAULT '#94a3b8',
  `is_coluna_inicial` tinyint(1) DEFAULT 0,
  `is_coluna_concluido` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board` (`board_id`),
  CONSTRAINT `task_columns_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `task_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `mensagem` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `task_comments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `acao` varchar(80) NOT NULL,
  `antes_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`antes_json`)),
  `depois_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`depois_json`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `request_id` char(12) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_data` (`task_id`,`created_at`),
  KEY `idx_request_id` (`request_id`),
  CONSTRAINT `task_history_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=180 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_th_no_update BEFORE UPDATE ON task_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'task_history e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_th_no_delete BEFORE DELETE ON task_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'task_history e imutavel (LGPD Art. 37)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `link_type` enum('contato','processo','card','dre_account') NOT NULL,
  `link_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_lookup` (`link_type`,`link_id`),
  CONSTRAINT `task_links_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_recurrences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('diaria','semanal','quinzenal','mensal','anual','custom') NOT NULL,
  `intervalo` int(11) DEFAULT 1,
  `dias_semana` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dias_semana`)),
  `dia_mes` int(11) DEFAULT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date DEFAULT NULL,
  `ativa` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `lembrar_em` datetime NOT NULL,
  `canal` enum('sistema','whatsapp','email') DEFAULT 'sistema',
  `enviado` tinyint(1) DEFAULT 0,
  `enviado_em` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pendentes` (`enviado`,`lembrar_em`),
  KEY `task_id` (`task_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `task_reminders_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_reminders_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_time_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `inicio` datetime NOT NULL,
  `fim` datetime DEFAULT NULL,
  `duracao_minutos` int(11) DEFAULT NULL,
  `valor_hora` decimal(10,2) DEFAULT NULL,
  `descricao` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `task_time_entries_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_time_entries_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `board_id` int(11) NOT NULL,
  `column_id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `prioridade` enum('baixa','media','alta','urgente') DEFAULT 'media',
  `prazo` datetime DEFAULT NULL,
  `prazo_tipo` varchar(50) NOT NULL DEFAULT 'interno',
  `responsavel_id` int(11) DEFAULT NULL,
  `criado_por_id` int(11) NOT NULL,
  `status` enum('ativa','concluida','arquivada') DEFAULT 'ativa',
  `concluida_em` datetime DEFAULT NULL,
  `ordem` int(11) DEFAULT 0,
  `recorrencia_id` int(11) DEFAULT NULL,
  `push_event_id` int(10) unsigned DEFAULT NULL,
  `origem_task_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_board_column` (`board_id`,`column_id`),
  KEY `idx_responsavel` (`responsavel_id`),
  KEY `idx_prazo` (`prazo`),
  KEY `idx_status` (`status`),
  KEY `column_id` (`column_id`),
  KEY `recorrencia_id` (`recorrencia_id`),
  KEY `criado_por_id` (`criado_por_id`),
  KEY `idx_push_event` (`push_event_id`),
  CONSTRAINT `fk_tasks_push_event` FOREIGN KEY (`push_event_id`) REFERENCES `push_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`board_id`) REFERENCES `task_boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`column_id`) REFERENCES `task_columns` (`id`),
  CONSTRAINT `tasks_ibfk_3` FOREIGN KEY (`recorrencia_id`) REFERENCES `task_recurrences` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tasks_ibfk_4` FOREIGN KEY (`responsavel_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tasks_ibfk_5` FOREIGN KEY (`criado_por_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `taxes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `nome` varchar(100) NOT NULL DEFAULT '',
  `percentual` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_taxes_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `team_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_team_user` (`team_id`,`user_id`),
  KEY `idx_tm_team` (`team_id`),
  KEY `idx_tm_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `cor` varchar(7) NOT NULL DEFAULT '#3B82F6',
  `descricao` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_teams_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `term_acceptances` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `legal_document_id` int(11) NOT NULL COMMENT 'FK legal_documents.id',
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL se aceite anonimo (banner cookies sem login)',
  `account_id` int(11) DEFAULT NULL COMMENT 'Conta no momento do aceite',
  `titular_email` varchar(150) DEFAULT NULL COMMENT 'Para aceites anonimos',
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `accepted_at` datetime DEFAULT current_timestamp(),
  `contexto` varchar(80) NOT NULL DEFAULT 'unknown' COMMENT 'signup | login | checkout | cookies_banner | config_user | reaceite',
  PRIMARY KEY (`id`),
  KEY `idx_user_doc` (`user_id`,`legal_document_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_doc` (`legal_document_id`),
  KEY `idx_email_anon` (`titular_email`),
  KEY `idx_accepted_at` (`accepted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ta_no_update BEFORE UPDATE ON term_acceptances
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'term_acceptances e imutavel (LGPD Art. 8)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ta_no_delete BEFORE DELETE ON term_acceptances
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'term_acceptances e imutavel (LGPD Art. 8)';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usage_counters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `feature_key` varchar(80) NOT NULL,
  `periodo` varchar(10) NOT NULL,
  `count` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usage` (`account_id`,`feature_key`,`periodo`),
  KEY `idx_usage_account` (`account_id`,`periodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page` varchar(100) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_page` (`user_id`,`page`),
  KEY `idx_userperm_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `nome` varchar(191) NOT NULL,
  `login` varchar(100) NOT NULL,
  `telefone` varchar(30) DEFAULT NULL,
  `codigo_vinculo` varchar(19) DEFAULT NULL COMMENT 'Token ',
  `senha_hash` varchar(255) NOT NULL,
  `perfil` enum('admin','user') NOT NULL DEFAULT 'user',
  `role` varchar(50) NOT NULL DEFAULT 'user',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `codigo_advogado` varchar(20) DEFAULT NULL COMMENT 'Identificador ├║nico nacional do advogado no sistema (ADV-XXXXXX)',
  `oab` varchar(20) DEFAULT NULL,
  `oab_uf` char(2) DEFAULT NULL,
  `nome_advogado` varchar(200) DEFAULT NULL,
  `is_advogado` tinyint(1) NOT NULL DEFAULT 0,
  `anonymized_at` datetime DEFAULT NULL,
  `deletion_reason` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  UNIQUE KEY `uk_user_codigo_vinculo` (`codigo_vinculo`),
  UNIQUE KEY `uk_users_codigo_advogado` (`codigo_advogado`),
  UNIQUE KEY `codigo_vinculo` (`codigo_vinculo`),
  KEY `idx_users_account` (`account_id`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_oab` (`oab`),
  KEY `idx_users_is_advogado` (`is_advogado`),
  KEY `idx_anon` (`anonymized_at`),
  KEY `idx_nome_advogado` (`nome_advogado`)
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webhook_deliveries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `webhook_endpoint_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `event_code` varchar(100) NOT NULL,
  `event_id` varchar(64) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `request_url` varchar(500) NOT NULL,
  `request_headers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_headers`)),
  `response_status` int(11) DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `response_time_ms` int(11) DEFAULT NULL,
  `status` enum('pending','success','failed','retrying','canceled') NOT NULL DEFAULT 'pending',
  `tentativa` int(11) NOT NULL DEFAULT 1,
  `erro` text DEFAULT NULL,
  `scheduled_retry_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wd_endpoint_time` (`webhook_endpoint_id`,`created_at`),
  KEY `idx_wd_status_retry` (`status`,`scheduled_retry_at`),
  KEY `idx_wd_account_time` (`account_id`,`created_at`),
  KEY `idx_wd_event_id` (`event_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webhook_endpoints` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `escopo` enum('tenant_only','matriz_e_filiais','filial_only') NOT NULL DEFAULT 'tenant_only',
  `nome` varchar(191) NOT NULL,
  `url` varchar(500) NOT NULL,
  `metodo` enum('POST') NOT NULL DEFAULT 'POST',
  `secret` varchar(255) DEFAULT NULL,
  `secret_rotated_at` datetime DEFAULT NULL,
  `eventos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`eventos`)),
  `headers_customizados` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`headers_customizados`)),
  `timeout_segundos` int(11) NOT NULL DEFAULT 10,
  `retry_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `max_retries` int(11) NOT NULL DEFAULT 3,
  `payload_mode` enum('minimal','masked','full') NOT NULL DEFAULT 'masked',
  `created_by` int(11) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_webhooks_account` (`account_id`),
  KEY `idx_we_account_status` (`account_id`,`ativo`,`deleted_at`),
  KEY `idx_we_escopo` (`escopo`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webhook_event_queue` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `event_code` varchar(100) NOT NULL,
  `event_id` varchar(64) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status` enum('pending','processing','done','dead') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_weq_pickup` (`status`,`next_attempt_at`),
  KEY `idx_weq_account` (`account_id`,`created_at`),
  KEY `idx_weq_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webhook_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(100) NOT NULL,
  `nome` varchar(191) NOT NULL,
  `descricao` text DEFAULT NULL,
  `categoria` varchar(60) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `payload_exemplo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_exemplo`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `idx_we_cat` (`categoria`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=238 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webhook_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `webhook_id` int(11) DEFAULT NULL,
  `event_key` varchar(100) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `response_status` int(11) DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `success` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_whl_webhook` (`webhook_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `webhooks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(191) NOT NULL,
  `url` varchar(500) NOT NULL,
  `secret` varchar(255) DEFAULT NULL,
  `eventos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`eventos`)),
  `ativo` tinyint(1) DEFAULT 1,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_chat_processos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instance_id` int(11) NOT NULL,
  `remote_jid` varchar(120) NOT NULL,
  `processo_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chat_processo` (`instance_id`,`remote_jid`,`processo_id`),
  KEY `idx_instance_jid` (`instance_id`,`remote_jid`),
  KEY `idx_wacp_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `instance_id` int(11) NOT NULL,
  `remote_jid` varchar(120) NOT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `is_manual_name` tinyint(1) NOT NULL DEFAULT 0,
  `phone` varchar(30) DEFAULT NULL,
  `profile_pic_url` text DEFAULT NULL,
  `last_message_content` text DEFAULT NULL,
  `last_message_type` varchar(30) DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `last_message_from_me` tinyint(1) DEFAULT 0,
  `unread_count` int(11) DEFAULT 0,
  `is_pinned` tinyint(1) DEFAULT 0,
  `is_archived` tinyint(1) DEFAULT 0,
  `is_group` tinyint(1) DEFAULT 0,
  `linked_card_id` int(11) DEFAULT NULL,
  `linked_processo_id` int(11) DEFAULT NULL,
  `linked_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `contato_id` int(11) DEFAULT NULL,
  `team_id` int(11) DEFAULT NULL COMMENT 'Setor respons',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chat` (`instance_id`,`remote_jid`),
  KEY `idx_instance_updated` (`instance_id`,`last_message_at`),
  KEY `idx_chat_team` (`team_id`),
  KEY `idx_wachat_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=321189 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `instance_id` int(11) NOT NULL,
  `remote_jid` varchar(120) NOT NULL,
  `push_name` varchar(255) DEFAULT NULL,
  `is_manual_name` tinyint(1) NOT NULL DEFAULT 0,
  `phone` varchar(30) DEFAULT NULL,
  `profile_pic_url` text DEFAULT NULL,
  `is_group` tinyint(1) DEFAULT 0,
  `is_favorite` tinyint(1) DEFAULT 0,
  `linked_card_id` int(11) DEFAULT NULL,
  `linked_processo_id` int(11) DEFAULT NULL,
  `linked_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_contact` (`instance_id`,`remote_jid`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_wacont_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_group_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `instance_id` int(10) unsigned NOT NULL,
  `group_jid` varchar(80) NOT NULL,
  `participant_jid` varchar(80) NOT NULL,
  `push_name` varchar(200) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `role` enum('admin','superadmin','member') NOT NULL DEFAULT 'member',
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_member` (`instance_id`,`group_jid`,`participant_jid`),
  KEY `idx_group` (`instance_id`,`group_jid`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_instances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `instance_name` varchar(100) NOT NULL,
  `display_name` varchar(150) DEFAULT NULL,
  `status` enum('open','close','connecting','qr') DEFAULT 'close',
  `phone` varchar(30) DEFAULT NULL,
  `profile_name` varchar(150) DEFAULT NULL,
  `profile_pic_url` text DEFAULT NULL,
  `qr_code_base64` mediumtext DEFAULT NULL,
  `evolution_token` varchar(255) DEFAULT NULL,
  `webhook_url` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_instances_account_name` (`account_id`,`instance_name`),
  KEY `idx_wpinst_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `instance_id` int(11) NOT NULL,
  `wamid` varchar(120) DEFAULT NULL,
  `remote_jid` varchar(120) NOT NULL,
  `participant_jid` varchar(80) DEFAULT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `message_type` varchar(30) NOT NULL DEFAULT 'text',
  `message_content` text DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `media_url` text DEFAULT NULL,
  `media_mimetype` varchar(100) DEFAULT NULL,
  `media_filename` varchar(255) DEFAULT NULL,
  `media_base64` mediumtext DEFAULT NULL,
  `direction` enum('inbound','outbound') NOT NULL DEFAULT 'inbound',
  `status` enum('pending','sent','delivered','read','failed') DEFAULT 'pending',
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `quoted_wamid` varchar(120) DEFAULT NULL,
  `raw_payload` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `retention_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jid` (`instance_id`,`remote_jid`),
  KEY `idx_wamid` (`wamid`),
  KEY `idx_created` (`created_at`),
  KEY `idx_wamsg_account` (`account_id`),
  KEY `idx_deleted` (`deleted_at`),
  KEY `idx_retention` (`retention_until`),
  KEY `idx_participant` (`instance_id`,`participant_jid`)
) ENGINE=InnoDB AUTO_INCREMENT=1446 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_reactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `instance_id` int(10) unsigned NOT NULL,
  `target_wamid` varchar(255) NOT NULL,
  `reactor_jid` varchar(80) NOT NULL,
  `reactor_name` varchar(200) DEFAULT NULL,
  `emoji` varchar(16) NOT NULL,
  `is_from_me` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reaction` (`instance_id`,`target_wamid`,`reactor_jid`),
  KEY `idx_target` (`target_wamid`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `config_key` varchar(80) NOT NULL,
  `config_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_settings_account_key` (`account_id`,`config_key`),
  KEY `idx_whatsapp_settings_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `_add_index_safe`(tbl VARCHAR(100), idx VARCHAR(100), col VARCHAR(100))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX `', idx, '` (`', col, '`)');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

