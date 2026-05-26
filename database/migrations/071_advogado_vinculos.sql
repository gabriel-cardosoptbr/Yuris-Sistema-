-- ============================================================================
-- Migration 071 — advogado_vinculos: vínculo de CONTA matriz/filial ↔ advogado
-- ============================================================================
-- RENUMERAÇÃO 2026-05-26 (auditoria pré-deploy):
--   Arquivo era 067_advogado_vinculos.sql, colidindo numericamente com
--   067_rename_webhooks_to_endpoints.sql. Em lex sort `_advogado` vem antes
--   de `_rename`, então rodava primeiro — funcionou no nosso local. Mas o
--   risco era real em deploy zero rodando filenames diferente: 067_rename
--   precisa rodar ANTES de 069_create_webhook_deliveries (que usa
--   webhook_endpoints). Como advogado_vinculos é independente do chain de
--   webhooks, foi movido pra 071 sem perda de semântica.
-- ============================================================================
--
-- CONTEXTO:
--   Antes desta migration, o sistema só tinha:
--     - account_vinculos (matriz ↔ filial, com sync_enabled + sync_<modulo>)
--     - resource_shares (compartilhamento POR RECURSO: 1 processo, 1 card)
--   E `advogado_convites` foi deprecada (410 Gone).
--
--   Não existia o conceito de "matriz/filial puxa TODOS os dados do advogado
--   sincronizado por módulo". Compartilhamento era 1 processo por vez.
--
-- O QUE ESTA TABELA FAZ:
--   Espelha account_vinculos mas para o relacionamento de CONTA com advogado:
--     - host_account_id = matriz ou filial que convidou
--     - advogado_account_id = conta tipo='advogado'
--     - sync_enabled + sync_cards/processos/tarefas = quais módulos a matriz/filial
--       vê do advogado (analogamente, quais o advogado vê da host)
--
-- PORQUE NÃO REUSAR account_vinculos:
--   1. Nome das colunas (matriz_account_id / filial_account_id) ficaria mentiroso
--   2. Constraint UNIQUE entre (matriz, filial) conflitaria com (host, advogado)
--      em estruturas semânticas diferentes
--   3. Auditoria/logs ficaram presos no conceito matriz↔filial
--   Mantemos as 2 tabelas paralelas; AccountContext consulta as DUAS.
--
-- IDEMPOTENTE: CREATE TABLE IF NOT EXISTS — pode rodar várias vezes.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `advogado_vinculos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,

  `host_account_id`     int(11) NOT NULL COMMENT 'Matriz ou filial que vinculou o advogado',
  `advogado_account_id` int(11) NOT NULL COMMENT 'Conta tipo=advogado que foi vinculada',

  `status` enum('pending','active','suspended','rejected') DEFAULT 'pending',

  `solicitado_por` int(11) DEFAULT NULL COMMENT 'user_id que iniciou a solicitacao',
  `aprovado_por`   int(11) DEFAULT NULL COMMENT 'user_id que aprovou (advogado ou host)',
  `solicitado_em`  datetime DEFAULT current_timestamp(),
  `aprovado_em`    datetime DEFAULT NULL,
  `suspenso_em`    datetime DEFAULT NULL,
  `motivo_suspensao` text DEFAULT NULL,

  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  -- Flags de sincronização (espelham account_vinculos)
  `sync_enabled`   tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Toggle mestre: host puxa dados do advogado?',
  `sync_processos` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sincronizar processos do advogado?',
  `sync_cards`     tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sincronizar cards/leads?',
  `sync_tarefas`   tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Sincronizar tarefas (boards)?',
  `sync_updated_at` datetime DEFAULT NULL COMMENT 'Ultima alteracao dos flags',

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_adv_vinculo` (`host_account_id`,`advogado_account_id`),
  KEY `idx_adv_advogado` (`advogado_account_id`),
  KEY `idx_adv_status`   (`status`),
  CONSTRAINT `fk_adv_host`     FOREIGN KEY (`host_account_id`)     REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_adv_advogado` FOREIGN KEY (`advogado_account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
