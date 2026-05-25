-- ============================================================================
-- Migration 060 — Módulo Intimações/Publicações (DJEN + DataJud)
-- ============================================================================
--
-- Cria estrutura para o módulo Intimações inspirado no fluxo da AASP, com:
--   • Cache temporário do dia (push_today_cache) — expira em 24h, sem histórico
--   • Eventos persistentes (push_events) — gravados SÓ após interação do user
--   • Status por usuário (push_event_user_status) — lida/favorita/prazo/comentário
--   • Monitores (push_monitors) — fila de OABs/processos com varredura periódica
--   • Logs técnicos (push_query_logs) — auditoria de consultas
--
-- Fonte primária: DJEN (https://comunicaapi.pje.jus.br/api/v1/comunicacao)
-- Fonte secundária: DataJud (https://api-publica.datajud.cnj.jus.br) — enriquecimento
--
-- Regras LGPD:
--   • Cache curto, retenção 24h
--   • Persistência só com consentimento implícito (ação do user)
--   • Multi-tenant rígido via account_id
--   • Anonymizer expandido na Fase 3
--
-- IDEMPOTENTE: cada CREATE/ALTER consulta INFORMATION_SCHEMA ou usa IF NOT EXISTS.
-- ============================================================================

-- ─── 1) push_today_cache — cache do dia, expira automaticamente ────────────
CREATE TABLE IF NOT EXISTS push_today_cache (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id              INT UNSIGNED NOT NULL,
  source_id               VARCHAR(40)  NOT NULL DEFAULT 'djen',
  tribunal                VARCHAR(20)  NOT NULL,
  data_disponibilizacao   DATE         NOT NULL,
  data_publicacao         DATE         DEFAULT NULL,
  numero_processo         VARCHAR(40)  DEFAULT NULL,
  numero_processo_mascara VARCHAR(40)  DEFAULT NULL,
  orgao                   VARCHAR(255) DEFAULT NULL,
  id_orgao                INT UNSIGNED DEFAULT NULL,
  tipo_comunicacao        VARCHAR(80)  DEFAULT NULL,
  meio                    VARCHAR(8)   DEFAULT NULL,
  meio_completo           VARCHAR(150) DEFAULT NULL,
  classe_nome             VARCHAR(200) DEFAULT NULL,
  classe_codigo           VARCHAR(20)  DEFAULT NULL,
  titulo                  VARCHAR(255) DEFAULT NULL,
  resumo                  VARCHAR(500) DEFAULT NULL,
  conteudo                LONGTEXT     DEFAULT NULL,
  url_origem              VARCHAR(500) DEFAULT NULL,
  numero_comunicacao      BIGINT UNSIGNED DEFAULT NULL,
  hash_externo            VARCHAR(80)  DEFAULT NULL,
  hash_conteudo           CHAR(64)     NOT NULL,
  payload_original        LONGTEXT     DEFAULT NULL,
  encontrado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at              DATETIME     NOT NULL,
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_hash (account_id, hash_conteudo),
  KEY idx_expires (expires_at),
  KEY idx_account_data (account_id, data_disponibilizacao),
  KEY idx_tribunal (tribunal),
  KEY idx_numero_processo (numero_processo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 2) push_events — publicações persistentes (após interação) ────────────
CREATE TABLE IF NOT EXISTS push_events (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id              INT UNSIGNED NOT NULL,
  source_id               VARCHAR(40)  NOT NULL DEFAULT 'djen',
  advogado_id             INT UNSIGNED DEFAULT NULL,
  processo_id             INT UNSIGNED DEFAULT NULL,
  monitor_id              INT UNSIGNED DEFAULT NULL,
  tipo_evento             VARCHAR(50)  NOT NULL DEFAULT 'publicacao',
  titulo                  VARCHAR(255) DEFAULT NULL,
  resumo                  VARCHAR(500) DEFAULT NULL,
  conteudo                LONGTEXT     DEFAULT NULL,
  data_evento             DATETIME     NOT NULL,
  data_disponibilizacao   DATE         NOT NULL,
  data_publicacao         DATE         DEFAULT NULL,
  tribunal                VARCHAR(20)  NOT NULL,
  orgao                   VARCHAR(255) DEFAULT NULL,
  id_orgao                INT UNSIGNED DEFAULT NULL,
  numero_processo         VARCHAR(40)  DEFAULT NULL,
  numero_processo_mascara VARCHAR(40)  DEFAULT NULL,
  tipo_comunicacao        VARCHAR(80)  DEFAULT NULL,
  meio                    VARCHAR(8)   DEFAULT NULL,
  meio_completo           VARCHAR(150) DEFAULT NULL,
  classe_nome             VARCHAR(200) DEFAULT NULL,
  classe_codigo           VARCHAR(20)  DEFAULT NULL,
  advogado_nome           VARCHAR(200) DEFAULT NULL,
  advogado_oab            VARCHAR(20)  DEFAULT NULL,
  url_origem              VARCHAR(500) DEFAULT NULL,
  numero_comunicacao      BIGINT UNSIGNED DEFAULT NULL,
  hash_externo            VARCHAR(80)  DEFAULT NULL,
  hash_conteudo           CHAR(64)     NOT NULL,
  payload_original        LONGTEXT     DEFAULT NULL,
  origem_busca            ENUM('automatica','manual','cache_hoje') NOT NULL DEFAULT 'manual',
  status                  VARCHAR(20)  NOT NULL DEFAULT 'ativo',
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_hash (account_id, hash_conteudo),
  KEY idx_account_data (account_id, data_disponibilizacao),
  KEY idx_tribunal (tribunal),
  KEY idx_processo (numero_processo),
  KEY idx_monitor (monitor_id),
  KEY idx_advogado (advogado_id),
  KEY idx_processo_id (processo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3) push_event_user_status — lida/favorita/prazo/comentário por user ────
CREATE TABLE IF NOT EXISTS push_event_user_status (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id     INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  lida         TINYINT(1)   NOT NULL DEFAULT 0,
  lida_em      DATETIME     DEFAULT NULL,
  favorita     TINYINT(1)   NOT NULL DEFAULT 0,
  com_prazo    TINYINT(1)   NOT NULL DEFAULT 0,
  prazo_data   DATE         DEFAULT NULL,
  comentario   TEXT         DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_user (event_id, user_id),
  KEY idx_user_account (user_id, account_id),
  KEY idx_account_lida (account_id, lida),
  KEY idx_account_fav (account_id, favorita),
  KEY idx_account_prazo (account_id, com_prazo, prazo_data),
  CONSTRAINT fk_pues_event FOREIGN KEY (event_id) REFERENCES push_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 4) push_monitors — fila de monitoramento (OABs, processos, advogados) ──
CREATE TABLE IF NOT EXISTS push_monitors (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id              INT UNSIGNED NOT NULL,
  advogado_id             INT UNSIGNED DEFAULT NULL,
  processo_id             INT UNSIGNED DEFAULT NULL,
  source_id               VARCHAR(40)  NOT NULL DEFAULT 'djen',
  tipo_monitoramento      ENUM('oab','processo','nome','customizado') NOT NULL DEFAULT 'oab',
  valor_monitorado        VARCHAR(120) NOT NULL,
  uf                      CHAR(2)      DEFAULT NULL,
  tribunal                VARCHAR(20)  DEFAULT NULL,
  status                  ENUM('ativo','pausado','erro','arquivado') NOT NULL DEFAULT 'ativo',
  prioridade              TINYINT UNSIGNED NOT NULL DEFAULT 5,
  intervalo_minutos       INT UNSIGNED NOT NULL DEFAULT 120,
  ultima_consulta_em      DATETIME     DEFAULT NULL,
  proxima_consulta_em     DATETIME     DEFAULT NULL,
  ultimo_hash_resultado   VARCHAR(64)  DEFAULT NULL,
  ultimo_erro             VARCHAR(255) DEFAULT NULL,
  erros_consecutivos      INT UNSIGNED NOT NULL DEFAULT 0,
  created_by              INT UNSIGNED DEFAULT NULL,
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_account_status (account_id, status),
  KEY idx_proxima (status, proxima_consulta_em),
  KEY idx_advogado (advogado_id),
  KEY idx_processo (processo_id),
  KEY idx_valor (valor_monitorado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 5) push_query_logs — logs técnicos de consulta ────────────────────────
CREATE TABLE IF NOT EXISTS push_query_logs (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id          INT UNSIGNED DEFAULT NULL,
  monitor_id          INT UNSIGNED DEFAULT NULL,
  source_id           VARCHAR(40)  NOT NULL DEFAULT 'djen',
  origem_busca        ENUM('automatica','manual','cache_hoje','retry') NOT NULL DEFAULT 'manual',
  data_inicio_filtro  DATE         DEFAULT NULL,
  data_fim_filtro     DATE         DEFAULT NULL,
  request_url         VARCHAR(500) DEFAULT NULL,
  filtros_hash        CHAR(64)     DEFAULT NULL,
  response_status     SMALLINT UNSIGNED DEFAULT NULL,
  response_hash       VARCHAR(64)  DEFAULT NULL,
  total_resultados    INT UNSIGNED DEFAULT 0,
  status              ENUM('ok','erro','timeout','rate_limit') NOT NULL DEFAULT 'ok',
  erro                VARCHAR(500) DEFAULT NULL,
  duracao_ms          INT UNSIGNED DEFAULT NULL,
  started_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at         DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_account_started (account_id, started_at),
  KEY idx_monitor (monitor_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '[060] Migration aplicada: push_today_cache + push_events + push_event_user_status + push_monitors + push_query_logs' AS resultado;
