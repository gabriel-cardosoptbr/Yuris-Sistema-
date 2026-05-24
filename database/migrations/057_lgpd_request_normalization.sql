-- =============================================================================
-- MIGRATION 057 — LGPD: normalização da Central LGPD v2
-- Data: 2026-05-23
--
-- ENDEREÇA: relatório de validação LGPD apontou que a Central trata a
-- solicitação como linha única denormalizada, faltando:
--   • tipo do titular (lead/cliente/advogado/parte/...)
--   • registro estruturado de quais módulos foram pesquisados
--   • registro estruturado dos dados encontrados (com mascaramento)
--   • suporte a múltiplos anexos/evidências
--   • justificativa de retenção (Art. 7 LGPD) quando dado não pode ser excluído
--   • prazo customizado por tipo + suporte a prorrogação Art. 19 par. 3
--
-- CRIA 4 TABELAS NOVAS:
--   • lgpd_request_modules                  — módulos consultados
--   • lgpd_request_findings                 — dados encontrados (com mascaramento)
--   • lgpd_request_attachments              — múltiplos anexos por solicitação
--   • lgpd_request_retention_justifications — justificativa de retenção
--
-- ESTENDE 2 TABELAS EXISTENTES:
--   • lgpd_requests        — +13 colunas (titular_tipo, documento_*, matriz_*,
--                            responsavel_dpo, canal, prioridade, prorrogação,
--                            atendimento_parcial)
--   • lgpd_request_events  — +3 colunas (tipo_acao, status_anterior, status_novo)
--
-- IMUTABILIDADE: triggers SIGNAL 45000 nas 4 tabelas novas:
--   • modules / attachments / retention_justifications  → UPDATE+DELETE bloqueados
--   • findings  → UPDATE permitido APENAS em campos de revisao; DELETE bloqueado
--
-- BACKWARD COMPAT: solicitações existentes continuam funcionando. Colunas
-- novas em lgpd_requests são todas NULL ou com DEFAULT. UI antiga continua
-- renderizando.
--
-- IDEMPOTENTE:
--   CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS + DROP TRIGGER IF EXISTS
--   (requer MariaDB >= 10.0 para ADD COLUMN IF NOT EXISTS)
-- =============================================================================


-- ─── 1. lgpd_request_modules — quais módulos foram pesquisados ──────────────
CREATE TABLE IF NOT EXISTS lgpd_request_modules (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT NOT NULL,
  modulo VARCHAR(50) NOT NULL
    COMMENT 'usuarios | contatos | leads_cards | processos | whatsapp | chat_interno | financeiro | task_attachments | logs_auditoria | consentimentos | aceites_termos | outros',

  pesquisado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  pesquisado_por_user_id INT NOT NULL COMMENT 'DPO que disparou a busca',

  resultado_resumo VARCHAR(255) NULL
    COMMENT 'Ex: 2 registros encontrados / Nenhum dado / Acesso negado por sigilo',
  total_registros INT NOT NULL DEFAULT 0 COMMENT 'Quantos registros bateram a busca',

  observacao TEXT NULL,
  request_id_correlacao VARCHAR(32) NULL COMMENT 'RequestId helper - correlaciona com master_audit_log',

  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  KEY idx_request (request_id, modulo),
  KEY idx_modulo (modulo),
  KEY idx_data (pesquisado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 2. lgpd_request_findings — dados encontrados (com mascaramento) ────────
CREATE TABLE IF NOT EXISTS lgpd_request_findings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT NOT NULL,

  modulo VARCHAR(50) NOT NULL,
  entidade VARCHAR(60) NOT NULL
    COMMENT 'users | contatos | cards | processos | whatsapp_messages | chat_mensagens | invoices | etc',
  entidade_id BIGINT NOT NULL,
  account_id INT NULL COMMENT 'Tenant onde o dado foi encontrado (NULL se global)',

  tipo_dado ENUM(
    'pii_basica','documento','juridico_processual','financeiro',
    'autenticacao','comunicacao','sensivel_lgpd','metadado','outro'
  ) NOT NULL,
  campo VARCHAR(80) NULL COMMENT 'Nome da coluna onde achou (nome, email, etc)',

  valor_mascarado VARCHAR(255) NULL
    COMMENT 'Ex: ana***@gmail.com, (11) ****-5678, ***.456.***-**',
  hash_valor CHAR(64) NULL COMMENT 'SHA-256 do valor cru + pepper',

  base_legal ENUM(
    'consentimento','contrato','obrigacao_legal','exercicio_direito',
    'interesse_legitimo','tutela_saude','protecao_credito','interesse_publico'
  ) NULL,
  finalidade VARCHAR(200) NULL,

  pode_excluir TINYINT(1) NULL COMMENT 'NULL=nao analisado | 1=pode | 0=retem',
  motivo_retencao VARCHAR(255) NULL,

  incluido_no_export TINYINT(1) NOT NULL DEFAULT 0,

  revisado_em DATETIME NULL,
  revisado_por_user_id INT NULL,

  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  KEY idx_request (request_id),
  KEY idx_entidade (entidade, entidade_id),
  KEY idx_tipo_dado (tipo_dado),
  KEY idx_pendente_revisao (request_id, revisado_em),
  KEY idx_hash (hash_valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 3. lgpd_request_attachments — múltiplos anexos por solicitação ─────────
CREATE TABLE IF NOT EXISTS lgpd_request_attachments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT NOT NULL,

  nome_arquivo VARCHAR(200) NOT NULL,
  caminho_arquivo VARCHAR(500) NOT NULL,
  tipo_arquivo VARCHAR(100) NOT NULL COMMENT 'MIME validado via finfo_file',
  tamanho INT NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,

  categoria ENUM(
    'documento_titular','procuracao','export_dados','comprovante_envio',
    'evidencia_analise','justificativa_juridica','outro'
  ) NOT NULL,

  visibilidade ENUM('interno','entregue_titular') NOT NULL DEFAULT 'interno',
  descricao TEXT NULL,
  uploaded_by_user_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  KEY idx_request (request_id),
  KEY idx_categoria (categoria),
  KEY idx_visibilidade (visibilidade),
  KEY idx_sha256 (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 4. lgpd_request_retention_justifications — base legal de retenção ──────
CREATE TABLE IF NOT EXISTS lgpd_request_retention_justifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT NOT NULL,

  entidade VARCHAR(60) NOT NULL,
  entidade_id BIGINT NOT NULL,
  finding_id BIGINT NULL COMMENT 'FK opcional para lgpd_request_findings.id',

  base_legal_retencao ENUM(
    'obrigacao_legal',
    'exercicio_direitos_processo',
    'cumprimento_contrato',
    'dados_terceiros',
    'sigilo_profissional',
    'seguranca_sistema',
    'auditoria_obrigatoria',
    'anonimizacao_inviavel',
    'outro'
  ) NOT NULL,

  prazo_retencao_ate DATE NULL,
  justificativa TEXT NOT NULL,
  fundamentacao_juridica TEXT NULL,

  responsavel_user_id INT NOT NULL,
  aprovado_por_user_id INT NULL,
  aprovado_em DATETIME NULL,

  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  KEY idx_request (request_id),
  KEY idx_entidade (entidade, entidade_id),
  KEY idx_finding (finding_id),
  KEY idx_base_legal (base_legal_retencao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 5. ALTER lgpd_request_events — +3 colunas ──────────────────────────────
-- NOTA: MariaDB nao aceita COMMENT inline em "ADD COLUMN IF NOT EXISTS" em batch
--       (limitacao do parser). COMMENTs sao aplicados no bloco 6.1 abaixo.
ALTER TABLE lgpd_request_events
  ADD COLUMN IF NOT EXISTS tipo_acao VARCHAR(50) NULL AFTER evento,
  ADD COLUMN IF NOT EXISTS status_anterior VARCHAR(40) NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS status_novo     VARCHAR(40) NULL AFTER status_anterior,
  ADD KEY IF NOT EXISTS idx_tipo_acao (tipo_acao);


-- ─── 6. ALTER lgpd_requests — +13 colunas ───────────────────────────────────
ALTER TABLE lgpd_requests
  ADD COLUMN IF NOT EXISTS titular_tipo ENUM(
    'lead','cliente','advogado_associado','usuario_interno',
    'parte_processual','contato_whatsapp','responsavel_financeiro',
    'visitante','outro'
  ) NULL AFTER titular_telefone,
  ADD COLUMN IF NOT EXISTS titular_referencia_entidade VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS titular_referencia_id BIGINT NULL,
  ADD COLUMN IF NOT EXISTS documento_tipo ENUM('cpf','cnpj','rg','cnh','oab','passaporte','outro') NULL,
  ADD COLUMN IF NOT EXISTS documento_numero_mascarado VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS documento_hash CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS matriz_account_id INT NULL,
  ADD COLUMN IF NOT EXISTS responsavel_dpo_user_id INT NULL,
  ADD COLUMN IF NOT EXISTS canal_origem ENUM(
    'formulario_publico','email','telefone','presencial',
    'ouvidoria_anpd','painel_interno','outro'
  ) NOT NULL DEFAULT 'formulario_publico',
  ADD COLUMN IF NOT EXISTS prioridade ENUM('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
  ADD COLUMN IF NOT EXISTS prazo_prorrogado_em DATE NULL,
  ADD COLUMN IF NOT EXISTS motivo_prorrogacao TEXT NULL,
  ADD COLUMN IF NOT EXISTS atendimento_parcial TINYINT(1) NOT NULL DEFAULT 0,
  ADD KEY IF NOT EXISTS idx_titular_tipo (titular_tipo),
  ADD KEY IF NOT EXISTS idx_documento_hash (documento_hash),
  ADD KEY IF NOT EXISTS idx_responsavel (responsavel_dpo_user_id),
  ADD KEY IF NOT EXISTS idx_matriz (matriz_account_id),
  ADD KEY IF NOT EXISTS idx_prioridade (prioridade);


-- ─── 6.1 Aplica COMMENTs via MODIFY COLUMN (idempotente — sempre executa) ───
-- MODIFY sem IF NOT EXISTS funciona com COMMENT em batch.
ALTER TABLE lgpd_request_events
  MODIFY COLUMN tipo_acao VARCHAR(50) NULL
    COMMENT 'criacao | atualizacao | consulta | busca_modulo | export | anonimizacao | rejeicao | aprovacao | comentario | acesso_drawer | prorrogacao',
  MODIFY COLUMN status_anterior VARCHAR(40) NULL,
  MODIFY COLUMN status_novo     VARCHAR(40) NULL;

ALTER TABLE lgpd_requests
  MODIFY COLUMN titular_referencia_entidade VARCHAR(40) NULL
    COMMENT 'Tabela onde titular ja esta cadastrado (users, contatos, cards...)',
  MODIFY COLUMN titular_referencia_id BIGINT NULL
    COMMENT 'ID na tabela acima (polimorfico)',
  MODIFY COLUMN documento_numero_mascarado VARCHAR(40) NULL
    COMMENT 'Ex: 123.***.***-45',
  MODIFY COLUMN documento_hash CHAR(64) NULL
    COMMENT 'SHA-256(numero_completo + LGPD_DOC_HASH_PEPPER) para busca sem PII',
  MODIFY COLUMN matriz_account_id INT NULL
    COMMENT 'Derivado de accounts.matriz_id quando account_id e filial',
  MODIFY COLUMN responsavel_dpo_user_id INT NULL
    COMMENT 'DPO atribuido (tenant-specific ou plataforma)',
  MODIFY COLUMN prazo_prorrogado_em DATE NULL
    COMMENT 'Art. 19 par. 3 - quando o prazo original foi prorrogado',
  MODIFY COLUMN atendimento_parcial TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Marca quando parte foi concedida e parte negada';


-- ─── 7. Triggers de imutabilidade ───────────────────────────────────────────

-- 7.1 modules: UPDATE + DELETE bloqueados totalmente
DROP TRIGGER IF EXISTS trg_lrm_no_update;
DROP TRIGGER IF EXISTS trg_lrm_no_delete;
DELIMITER //
CREATE TRIGGER trg_lrm_no_update BEFORE UPDATE ON lgpd_request_modules
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_modules e imutavel (LGPD Art. 37)';
END //
CREATE TRIGGER trg_lrm_no_delete BEFORE DELETE ON lgpd_request_modules
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_modules e imutavel (LGPD Art. 37)';
END //
DELIMITER ;

-- 7.2 findings: UPDATE permitido APENAS em campos de revisão; DELETE bloqueado
DROP TRIGGER IF EXISTS trg_lrf_partial_update;
DROP TRIGGER IF EXISTS trg_lrf_no_delete;
DELIMITER //
CREATE TRIGGER trg_lrf_partial_update BEFORE UPDATE ON lgpd_request_findings
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
END //
CREATE TRIGGER trg_lrf_no_delete BEFORE DELETE ON lgpd_request_findings
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_findings e imutavel (DELETE bloqueado)';
END //
DELIMITER ;

-- 7.3 attachments: UPDATE PARCIAL (visibilidade + descricao); DELETE bloqueado
DROP TRIGGER IF EXISTS trg_lra_partial_update;
DROP TRIGGER IF EXISTS trg_lra_no_delete;
DELIMITER //
CREATE TRIGGER trg_lra_partial_update BEFORE UPDATE ON lgpd_request_attachments
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
END //
CREATE TRIGGER trg_lra_no_delete BEFORE DELETE ON lgpd_request_attachments
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_attachments e imutavel (DELETE bloqueado)';
END //
DELIMITER ;

-- 7.4 retention_justifications: UPDATE PARCIAL (apenas aprovacao); DELETE bloqueado
DROP TRIGGER IF EXISTS trg_lrj_partial_update;
DROP TRIGGER IF EXISTS trg_lrj_no_delete;
DELIMITER //
CREATE TRIGGER trg_lrj_partial_update BEFORE UPDATE ON lgpd_request_retention_justifications
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
END //
CREATE TRIGGER trg_lrj_no_delete BEFORE DELETE ON lgpd_request_retention_justifications
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_retention_justifications e imutavel (DELETE bloqueado)';
END //
DELIMITER ;


-- ─── 8. Confirmação ────────────────────────────────────────────────────────
SELECT 'Migration 057 (LGPD normalizacao v2) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('lgpd_request_modules','lgpd_request_findings',
                             'lgpd_request_attachments','lgpd_request_retention_justifications')) AS tabelas_novas,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'lgpd_requests'
          AND COLUMN_NAME IN ('titular_tipo','titular_referencia_entidade','titular_referencia_id',
                              'documento_tipo','documento_numero_mascarado','documento_hash',
                              'matriz_account_id','responsavel_dpo_user_id','canal_origem',
                              'prioridade','prazo_prorrogado_em','motivo_prorrogacao',
                              'atendimento_parcial')) AS colunas_novas_em_requests,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'lgpd_request_events'
          AND COLUMN_NAME IN ('tipo_acao','status_anterior','status_novo')) AS colunas_novas_em_events,
       (SELECT COUNT(*) FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE()
          AND TRIGGER_NAME LIKE 'trg_lr%') AS triggers_imutabilidade;
