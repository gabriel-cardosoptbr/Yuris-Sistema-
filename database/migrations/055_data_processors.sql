-- =============================================================================
-- MIGRATION 055 — LGPD: inventário de operadores + DPA (Art. 33 + 39)
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, Etapa 9 (terceiros + transferência internacional).
--
-- Cria as tabelas para registrar todos os terceiros (operadores, suboperadores,
-- subcontratados) que tratam dados pessoais em nome da Yuris, com:
--   • Status do DPA (Data Processing Agreement / Art. 39)
--   • Transferência internacional + base legal (Art. 33)
--   • Categorias de dados tratados + finalidade
--   • Certificações de segurança do terceiro
--   • Histórico imutável de alterações (auditoria)
--
-- TABELAS:
--   • data_processors          — registro principal (mutável: workflow)
--   • data_processor_history   — auditoria imutável (trigger SIGNAL 45000)
--
-- IDEMPOTENTE: CREATE TABLE IF NOT EXISTS / DROP TRIGGER IF EXISTS.
-- =============================================================================

-- ─── 1. data_processors — inventário ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS data_processors (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Identificação
  nome VARCHAR(150) NOT NULL,
  categoria ENUM(
    'api_externa',
    'hospedagem',
    'cdn',
    'gateway_pagamento',
    'smtp',
    'llm_ia',
    'monitoramento',
    'suporte',
    'analytics',
    'backup',
    'outro'
  ) NOT NULL,
  papel ENUM('operador','suboperador','controlador_conjunto') NOT NULL DEFAULT 'operador'
    COMMENT 'LGPD Art. 5: operador trata em nome do controlador; suboperador é terceiro contratado pelo operador',
  cnpj_ou_id VARCHAR(100) NULL COMMENT 'CNPJ Brasil ou identificador estrangeiro (EIN, VAT, etc.)',
  pais VARCHAR(2) NULL COMMENT 'Codigo ISO 3166-1 alpha-2 (ex: BR, US, DE)',

  -- Tratamento
  dados_tratados JSON NULL COMMENT 'Estrutura: {categorias:[pii_basica|documentos|...],sensiveis:bool,volume_estimado:N}',
  finalidade TEXT NULL COMMENT 'Para qual finalidade os dados sao compartilhados',
  retencao_terceiro VARCHAR(255) NULL COMMENT 'Politica de retencao do terceiro (ex: "90 dias", "ate revogacao")',

  -- Transferência internacional (Art. 33)
  transferencia_internacional TINYINT(1) NOT NULL DEFAULT 0,
  base_legal_transferencia ENUM(
    'clausulas_contratuais_padrao',
    'regras_corporativas_globais',
    'decisao_anpd_adequacao',
    'autorizacao_anpd_especifica',
    'cooperacao_juridica_internacional',
    'protecao_vida',
    'cumprimento_obrigacao_legal',
    'execucao_contrato_titular',
    'consentimento_especifico',
    'garantias_outras',
    'nao_aplicavel'
  ) NULL,

  -- DPA (Art. 39 — contrato com operador)
  dpa_status ENUM('pendente','em_negociacao','assinado','vencido','dispensado','rejeitado')
    NOT NULL DEFAULT 'pendente',
  dpa_assinado_em DATE NULL,
  dpa_validade    DATE NULL COMMENT 'NULL = sem prazo (renovacao automatica ou indefinido)',
  dpa_url         VARCHAR(500) NULL COMMENT 'Path/URL do PDF do contrato (storage interno)',

  -- Conformidade do terceiro
  certificacoes TEXT NULL COMMENT 'Ex: ISO 27001, SOC 2 Type II, ISO 27701',
  contato_dpo_terceiro VARCHAR(150) NULL COMMENT 'E-mail do encarregado do terceiro',
  url_politica_privacidade VARCHAR(500) NULL,

  -- Operacional
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  desativado_em DATETIME NULL,
  motivo_desativacao TEXT NULL,
  notas TEXT NULL,

  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_nome (nome),
  KEY idx_categoria (categoria),
  KEY idx_dpa_status (dpa_status),
  KEY idx_dpa_validade (dpa_validade),
  KEY idx_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 2. data_processor_history — auditoria imutável ─────────────────────────
CREATE TABLE IF NOT EXISTS data_processor_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  processor_id INT NOT NULL,
  acao VARCHAR(60) NOT NULL
    COMMENT 'criado | atualizado | dpa_assinado | dpa_renovado | dpa_vencido | desativado | reativado',
  descricao TEXT,
  payload JSON NULL COMMENT 'Snapshot dos campos alterados (antes/depois)',
  by_user_id INT NULL COMMENT 'Operador que executou a acao (NULL = sistema/cron)',
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  request_id VARCHAR(32) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  KEY idx_proc_history (processor_id, created_at),
  KEY idx_acao (acao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 3. Triggers de imutabilidade em history ────────────────────────────────
DROP TRIGGER IF EXISTS trg_dph_no_update;
DROP TRIGGER IF EXISTS trg_dph_no_delete;
DELIMITER //
CREATE TRIGGER trg_dph_no_update BEFORE UPDATE ON data_processor_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'data_processor_history e imutavel (LGPD Art. 37)';
END //
CREATE TRIGGER trg_dph_no_delete BEFORE DELETE ON data_processor_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'data_processor_history e imutavel (LGPD Art. 37)';
END //
DELIMITER ;


-- ─── 4. Seeds — operadores conhecidos da Yuris ──────────────────────────────
-- INSERT IGNORE: se rodar de novo, nao duplica.
INSERT IGNORE INTO data_processors
  (nome, categoria, papel, cnpj_ou_id, pais, dados_tratados, finalidade,
   retencao_terceiro, transferencia_internacional, base_legal_transferencia,
   dpa_status, certificacoes, url_politica_privacidade, ativo, notas)
VALUES
-- 1) Evolution API (WhatsApp)
(
  'Evolution API (WhatsApp)',
  'api_externa', 'operador', NULL, 'BR',
  '{"categorias":["pii_basica","comunicacoes"],"sensiveis":false,"volume_estimado":"variavel por tenant"}',
  'Envio e recebimento de mensagens WhatsApp em nome dos tenants. Cada tenant configura sua propria instancia.',
  'Conforme configuracao do tenant na propria Evolution API.',
  0, 'nao_aplicavel',
  'pendente',
  NULL,
  'https://doc.evolution-api.com/',
  1,
  'Open-source self-hosted. Avaliar se cada deploy de tenant tem politica propria. Verificar se conexao usa TLS verificado (EVOLUTION_TLS_VERIFY no .env).'
),

-- 2) MariaDB / InnoDB (storage primario)
(
  'MariaDB (storage primario)',
  'hospedagem', 'operador', NULL, 'BR',
  '{"categorias":["pii_basica","documentos","financeiro","juridico","autenticacao","comunicacoes"],"sensiveis":true,"volume_estimado":"todos os dados do sistema"}',
  'Armazenamento primario de toda a base de dados da plataforma.',
  'Definido pelas politicas de retencao (Etapa 7) + backups conforme infraestrutura.',
  0, 'nao_aplicavel',
  'dispensado',
  'Open-source (GPL).',
  'https://mariadb.com/kb/en/mariadb-server-data-protection/',
  1,
  'Auto-hospedado. Politica de seguranca depende da infraestrutura onde rodamos (servidor proprio ou cloud). MFA + ACLs aplicados.'
),

-- 3) Apache HTTP Server
(
  'Apache HTTP Server',
  'hospedagem', 'operador', NULL, 'BR',
  '{"categorias":["pii_basica","comunicacoes"],"sensiveis":false,"volume_estimado":"logs de acesso"}',
  'Servidor web que processa requisicoes HTTP/HTTPS.',
  'Logs rotacionados conforme infraestrutura (tipicamente 30-90 dias).',
  0, 'nao_aplicavel',
  'dispensado',
  'Apache Software Foundation.',
  'https://httpd.apache.org/security_report.html',
  1,
  'Auto-hospedado. Logs contem IPs + user-agents (PII basica).'
),

-- 4) Gateway de pagamento (atualmente NullGateway / dev)
(
  'Gateway de Pagamento',
  'gateway_pagamento', 'operador', NULL, 'BR',
  '{"categorias":["pii_basica","documentos","financeiro"],"sensiveis":false,"volume_estimado":"todos os pagamentos"}',
  'Processamento de cobrancas, assinaturas e pagamentos das contas.',
  'Definido pelo gateway escolhido.',
  0, 'cumprimento_obrigacao_legal',
  'pendente',
  NULL,
  NULL,
  1,
  'Atualmente NullGateway (dev). Em producao, contratar Stripe / MercadoPago / Asaas — atualizar este registro com CNPJ, pais, DPA assinado e politica.'
),

-- 5) Provedor SMTP (Mailer)
(
  'Provedor SMTP',
  'smtp', 'operador', NULL, 'BR',
  '{"categorias":["pii_basica"],"sensiveis":false,"volume_estimado":"transacional"}',
  'Envio de e-mails transacionais (confirmacao, recuperacao de senha, notificacoes LGPD).',
  'Definido pelo provedor escolhido.',
  0, 'cumprimento_obrigacao_legal',
  'pendente',
  NULL,
  NULL,
  1,
  'Atualmente driver log (dev). Em producao, contratar SendGrid / SES / Mailgun — atualizar com pais (US, geralmente), base legal de transferencia internacional.'
),

-- 6) LLM / Agente IA (opcional, configurado por tenant)
(
  'LLM / Agente IA (OpenAI/Anthropic/etc)',
  'llm_ia', 'operador', NULL, 'US',
  '{"categorias":["pii_basica","comunicacoes","juridico"],"sensiveis":false,"volume_estimado":"variavel por tenant"}',
  'Geracao de respostas automaticas no agente IA do WhatsApp. Cada tenant configura sua chave (agent_configs).',
  'Geralmente 30 dias (politica do provedor).',
  1, 'clausulas_contratuais_padrao',
  'pendente',
  'OpenAI: SOC 2 Type II. Anthropic: SOC 2 Type II.',
  'https://openai.com/policies/privacy-policy / https://www.anthropic.com/legal/privacy',
  1,
  'IMPORTANTE: transferencia internacional (EUA). Cada tenant deve informar seus titulares ou obter consentimento especifico. Recomendar contratos zero-retention quando possivel.'
);


-- ─── 5. Confirmação ─────────────────────────────────────────────────────────
SELECT 'Migration 055 (data_processors + history) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM data_processors) AS processors_total,
       (SELECT COUNT(*) FROM data_processor_history) AS history_total,
       (SELECT COUNT(*) FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE()
          AND TRIGGER_NAME IN ('trg_dph_no_update','trg_dph_no_delete')) AS triggers_imutabilidade;
