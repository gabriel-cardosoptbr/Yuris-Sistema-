-- =============================================================================
-- MIGRATION 049 — LGPD: documentos legais, aceites e consentimentos
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, Etapa 5 (governança).
-- Cria as 3 tabelas centrais para gestão de Termos, Política de Privacidade,
-- Política de Cookies, e consentimentos granulares (cookies analíticos,
-- marketing, IA processar dados, envio WhatsApp etc).
--
-- LGPD: Art. 8º (consentimento), Art. 9º (transparência), Art. 18 IX
-- (revogação de consentimento).
--
-- IDEMPOTENTE: CREATE TABLE IF NOT EXISTS.
-- =============================================================================

-- ─── 1. legal_documents — versionamento de termos/políticas ──────────────────
CREATE TABLE IF NOT EXISTS legal_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM(
    'privacy_policy',
    'terms_of_use',
    'cookies_policy',
    'dpa',
    'security_policy',
    'privacy_notice'
  ) NOT NULL COMMENT 'Tipo do documento legal',
  versao VARCHAR(20) NOT NULL COMMENT 'Ex: 2026-05-23 ou 1.0.0',
  titulo VARCHAR(200) NOT NULL,
  conteudo_md LONGTEXT NOT NULL COMMENT 'Conteudo em Markdown',
  hash_conteudo CHAR(64) NOT NULL COMMENT 'SHA-256 do conteudo (detecta mudancas)',
  publicado_em DATETIME NULL,
  vigente_ate DATETIME NULL COMMENT 'NULL = vigente; data = arquivado',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_por_user_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_tipo_versao (tipo, versao),
  KEY idx_tipo_vigente (tipo, ativo, vigente_ate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 2. term_acceptances — quem aceitou, quando, qual versao ────────────────
CREATE TABLE IF NOT EXISTS term_acceptances (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  legal_document_id INT NOT NULL COMMENT 'FK legal_documents.id',
  user_id INT NULL COMMENT 'NULL se aceite anonimo (banner cookies sem login)',
  account_id INT NULL COMMENT 'Conta no momento do aceite',
  titular_email VARCHAR(150) NULL COMMENT 'Para aceites anonimos',
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  contexto VARCHAR(80) NOT NULL DEFAULT 'unknown'
    COMMENT 'signup | login | checkout | cookies_banner | config_user | reaceite',

  KEY idx_user_doc (user_id, legal_document_id),
  KEY idx_account (account_id),
  KEY idx_doc (legal_document_id),
  KEY idx_email_anon (titular_email),
  KEY idx_accepted_at (accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 3. lgpd_consents — consentimentos granulares ───────────────────────────
CREATE TABLE IF NOT EXISTS lgpd_consents (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  account_id INT NULL,
  titular_email VARCHAR(150) NULL COMMENT 'Para titulares nao-cadastrados',
  titular_telefone VARCHAR(30) NULL,
  finalidade VARCHAR(100) NOT NULL
    COMMENT 'cookies_analiticos | marketing_email | ia_processar_dados | envio_whatsapp etc',
  base_legal ENUM(
    'consentimento',
    'contrato',
    'obrigacao_legal',
    'exercicio_direito',
    'interesse_legitimo',
    'tutela_saude',
    'protecao_credito',
    'interesse_publico'
  ) NOT NULL DEFAULT 'consentimento',
  status ENUM('ativo','revogado','expirado') NOT NULL DEFAULT 'ativo',
  versao_termo_id INT NULL COMMENT 'FK opcional legal_documents.id (qual versao do termo)',
  concedido_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  revogado_em DATETIME NULL,
  fonte VARCHAR(80) COMMENT 'banner_cookie | signup | form | painel_config',
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  evidencia LONGTEXT COMMENT 'JSON: checkbox marcado, texto exibido, etc',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_user_fin (user_id, finalidade, status),
  KEY idx_account_fin (account_id, finalidade),
  KEY idx_email_fin (titular_email, finalidade),
  KEY idx_concedido (concedido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Confirmacao ────────────────────────────────────────────────────────────
SELECT 'Migration 049 (LGPD legal + consent) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM legal_documents)  AS docs_total,
       (SELECT COUNT(*) FROM term_acceptances) AS aceites_total,
       (SELECT COUNT(*) FROM lgpd_consents)    AS consents_total;
