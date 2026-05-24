-- =============================================================================
-- MIGRATION 056 — Painel interno: pendências de revisão (uso DPO/Diretoria)
-- Data: 2026-05-23
--
-- ENDEREÇA: necessidade de track interno do que ainda precisa ser revisado
-- antes do go-live, sem deixar essas pendências visíveis ao cliente final.
--
-- USO: aba "Revisões" no Painel Master, gerenciada pelo super_admin/DPO.
-- Substitui o disclaimer público "(modelo inicial — pendente revisão jurídica)"
-- que era visível nas páginas legais.
--
-- IDEMPOTENTE: CREATE TABLE IF NOT EXISTS + INSERT IGNORE nos seeds.
-- =============================================================================

CREATE TABLE IF NOT EXISTS pending_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Classificação
  categoria ENUM(
    'documento_legal',
    'operador_dpa',
    'configuracao_env',
    'seguranca_tecnica',
    'treinamento',
    'auditoria_externa',
    'designacao_dpo',
    'outro'
  ) NOT NULL,
  item_key VARCHAR(100) NOT NULL UNIQUE COMMENT 'Slug identificador (ex: doc_privacidade, contrato_smtp)',
  titulo VARCHAR(200) NOT NULL,
  descricao TEXT NULL,

  -- Responsabilidade e prazo
  responsavel VARCHAR(80) NULL COMMENT 'DPO | juridico_externo | diretoria | equipe_tecnica | RH',
  prioridade ENUM('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
  prazo DATE NULL COMMENT 'Quando idealmente deve estar concluido (NULL = sem prazo definido)',

  -- Estado
  status ENUM('pendente','em_revisao','concluido','dispensado','bloqueado') NOT NULL DEFAULT 'pendente',
  bloqueia_producao TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se 1, bloqueia go-live ate concluir',

  -- Auditoria
  concluido_em DATETIME NULL,
  concluido_por_user_id INT NULL,
  notas_internas TEXT NULL COMMENT 'Notas livres do DPO/Diretoria (so visiveis no painel master)',
  link_referencia VARCHAR(500) NULL COMMENT 'URL/path para documento relacionado (ex: /privacidade.php)',

  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_status (status),
  KEY idx_categoria (categoria),
  KEY idx_prazo (prazo),
  KEY idx_bloqueia (bloqueia_producao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Seed inicial: pendências conhecidas ─────────────────────────────────────
-- Baseado em CHECKLIST_DEPLOY_PRODUCAO.md + RELATORIO_FINAL_LGPD.md §5

-- Categoria: documento_legal (5 páginas legais que são modelos)
INSERT IGNORE INTO pending_reviews
  (categoria, item_key, titulo, descricao, responsavel, prioridade, bloqueia_producao, link_referencia)
VALUES
('documento_legal', 'doc_privacidade',
 'Revisão jurídica: Política de Privacidade',
 'Texto atual e modelo inicial elaborado pela equipe tecnica. Submeter a advogado especialista em protecao de dados antes do go-live publico.',
 'juridico_externo', 'alta', 1, '/sistema_vendas/public/privacidade.php'),

('documento_legal', 'doc_termos',
 'Revisão jurídica: Termos de Uso',
 'Texto atual e modelo inicial. Revisao por advogado de direito digital + contratual antes do go-live.',
 'juridico_externo', 'alta', 1, '/sistema_vendas/public/termos.php'),

('documento_legal', 'doc_cookies',
 'Revisão jurídica: Política de Cookies',
 'Atualizar lista de cookies efetivamente em uso quando contratar analytics/CDN reais.',
 'juridico_externo', 'media', 0, '/sistema_vendas/public/cookies.php'),

('documento_legal', 'doc_lgpd',
 'Revisão jurídica: página LGPD & Segurança',
 'Garantir que afirmacoes sejam responsaveis (sem promessa de conformidade total). Validar com juridico.',
 'juridico_externo', 'media', 0, '/sistema_vendas/public/lgpd.php'),

('documento_legal', 'doc_dpo',
 'Revisão jurídica: página DPO',
 'Atualizar com nome e contato reais apos designacao formal do Encarregado.',
 'DPO', 'alta', 1, '/sistema_vendas/public/dpo.php'),

-- Categoria: designacao_dpo
('designacao_dpo', 'dpo_designacao_formal',
 'Designar Encarregado (DPO) formalmente',
 'Indicar pessoa fisica (nome + CPF + cargo), publicar contato (dpo@dominio), atualizar pagina /dpo.php e .env (LGPD_DPO_EMAIL).',
 'diretoria', 'critica', 1, NULL),

-- Categoria: operador_dpa
('operador_dpa', 'gateway_contratar_dpa',
 'Contratar Gateway de Pagamento + DPA',
 'Atualmente NullGateway (dev). Em producao: contratar Stripe/MercadoPago/Asaas, assinar DPA usando DPA_TEMPLATE.md, registrar em data_processors.',
 'diretoria', 'alta', 1, '/sistema_vendas/public/master.php#operators'),

('operador_dpa', 'smtp_contratar',
 'Contratar Provedor SMTP + DPA',
 'Atualmente driver log (dev). Contratar SendGrid/SES/Mailgun, implementar driver real em Mailer.php, assinar DPA, registrar.',
 'equipe_tecnica', 'alta', 1, NULL),

('operador_dpa', 'evolution_dpa_avaliar',
 'Definir modelo contratual: Evolution API',
 'Decidir se cada tenant assina seu proprio DPA com seu provedor Evolution (modelo recomendado) ou se Yuris assume responsabilidade. Documentar.',
 'DPO', 'media', 0, NULL),

-- Categoria: configuracao_env
('configuracao_env', 'env_validar_producao',
 'Validar .env de produção (todas variáveis)',
 'Conferir DB_PASS forte, MFA_ENCRYPTION_KEY 256 bits, CRON_TOKEN >=32 chars, BILLING_GATEWAY real, EVOLUTION_TLS_VERIFY=true, APP_ENV=production, APP_DEBUG=false.',
 'equipe_tecnica', 'critica', 1, NULL),

('configuracao_env', 'env_rotacao_inicial',
 'Rotação inicial de credenciais e chaves',
 'Garantir que senhas/chaves usadas em dev nao sao reutilizadas em prod. Senha root MariaDB, MFA_ENCRYPTION_KEY, CRON_TOKEN novos.',
 'equipe_tecnica', 'alta', 1, NULL),

-- Categoria: seguranca_tecnica
('seguranca_tecnica', 'backup_offsite',
 'Configurar backup off-site cifrado',
 'Implementar regra 3-2-1 conforme POLITICA_BACKUP_RECUPERACAO.md. Off-site cifrado, chave custodiada separadamente, teste mensal de restore.',
 'equipe_tecnica', 'alta', 1, NULL),

('seguranca_tecnica', 'pentest_externo',
 'Pentest externo pré go-live',
 'Contratar empresa especializada para pentest antes da exposicao publica. Repetir anualmente.',
 'diretoria', 'alta', 1, NULL),

('seguranca_tecnica', 'mfa_opt_in_geral',
 'MFA opt-in para usuários (não só super_admin)',
 'Adicionar fluxo para que owners/admins de tenants possam habilitar TOTP. Atualmente so super_admin tem MFA obrigatorio.',
 'equipe_tecnica', 'media', 0, NULL),

('seguranca_tecnica', 'headers_seguranca_apache',
 'Headers de segurança em produção (Apache)',
 'Configurar HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Content-Security-Policy. Detalhes em CHECKLIST_DEPLOY_PRODUCAO.md §C.',
 'equipe_tecnica', 'alta', 1, NULL),

-- Categoria: treinamento
('treinamento', 'treinamento_equipe_inicial',
 'Treinamento básico em proteção de dados (equipe atual)',
 'Antes do go-live: capacitar TODA equipe atual conforme POLITICA_TREINAMENTO_PRIVACIDADE.md. Validar com quiz >= 70%.',
 'RH', 'alta', 1, NULL),

('treinamento', 'nda_funcionarios_atuais',
 'NDA assinado por funcionários atuais',
 'Todos os colaboradores atuais devem assinar NDA_FUNCIONARIO.md. Arquivar copias.',
 'RH', 'alta', 1, NULL),

-- Categoria: auditoria_externa
('auditoria_externa', 'auditoria_juridica_completa',
 'Auditoria jurídica completa pré-lancamento',
 'Alem dos documentos individuais, contratar revisao geral de conformidade LGPD por escritorio especializado.',
 'diretoria', 'media', 0, NULL);


-- ─── Confirmação ────────────────────────────────────────────────────────────
SELECT 'Migration 056 (pending_reviews) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM pending_reviews) AS total_itens,
       (SELECT COUNT(*) FROM pending_reviews WHERE bloqueia_producao = 1) AS bloqueadores_go_live,
       (SELECT COUNT(*) FROM pending_reviews WHERE prioridade = 'critica') AS criticos;
