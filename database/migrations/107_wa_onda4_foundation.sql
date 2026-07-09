-- 107_wa_onda4_foundation.sql
--
-- Onda 4 (Bloco 4A - Fundacao). Migration ADITIVA, sem FK, sem risco: so adiciona
-- colunas/tabela que os blocos seguintes (4B tempo real, 4C controle/custo, 4D
-- observabilidade) vao consumir. Nao altera nenhum dado existente alem de popular
-- os precos do catalogo ai_models (antes NULL).
--
-- Ref.: cofre YURIS -> "2026-07-09 - Onda 4 - Design de arquitetura WhatsApp (Fable)".

-- ---------------------------------------------------------------------------
-- 4B (tempo real por CURSOR DE EVENTOS): contador monotonico por instancia.
-- Todo caminho que ESCREVE mensagem/chat vai bumpar events_seq (+1) e carimbar
-- last_event_at. O chat.js pergunta "mudou?" com 1 SELECT por PK (barato) e so
-- roda as queries pesadas quando o seq muda. last_event_at tambem alimenta o
-- alerta de "webhook silencioso" (4D). Nao confundir com last_discovered_at (105).
-- ---------------------------------------------------------------------------
ALTER TABLE whatsapp_instances
  ADD COLUMN events_seq    BIGINT   NOT NULL DEFAULT 0,
  ADD COLUMN last_event_at DATETIME NULL;

-- ---------------------------------------------------------------------------
-- 4C (fonte unica da pausa): auditoria de QUEM assumiu a conversa e QUANDO.
-- agent_paused ja existe (082). paused_by=NULL = pausa implicita por envio manual
-- (o webhook nao sabe o usuario); o botao "Assumir conversa" passa o usuario logado.
-- Escrito SOMENTE por IntakeSessionRepository::pauseForHuman/resumeBot (escritor unico).
-- ---------------------------------------------------------------------------
ALTER TABLE whatsapp_chats
  ADD COLUMN agent_paused_by INT      NULL,
  ADD COLUMN agent_paused_at DATETIME NULL;

-- ---------------------------------------------------------------------------
-- 6 (precos do LLM em DB): mata a tabela COST hardcoded do IntakeEngine.
-- ai_models (100) ja e o catalogo GLOBAL gerenciavel no Master; agora carrega o
-- preco por 1M tokens. IntakeEngine::cost() le daqui (cache por request) e so cai
-- no COST const se o modelo nao estiver aqui (e registra price_fallback em eventos).
-- price_cached_in = preco do input que veio do cache (OpenAI ~0.5x; Anthropic ~0.1x).
-- ---------------------------------------------------------------------------
ALTER TABLE ai_models
  ADD COLUMN price_in_per_mtok        DECIMAL(10,4) NULL,
  ADD COLUMN price_out_per_mtok       DECIMAL(10,4) NULL,
  ADD COLUMN price_cached_in_per_mtok DECIMAL(10,4) NULL;

-- Preenche os 4 modelos OpenAI ja seedados (100) com os precos que estavam no COST.
UPDATE ai_models SET price_in_per_mtok=0.15, price_out_per_mtok=0.60, price_cached_in_per_mtok=0.075 WHERE provider='openai' AND code='gpt-4o-mini';
UPDATE ai_models SET price_in_per_mtok=0.40, price_out_per_mtok=1.60, price_cached_in_per_mtok=0.20  WHERE provider='openai' AND code='gpt-4.1-mini';
UPDATE ai_models SET price_in_per_mtok=0.75, price_out_per_mtok=4.50, price_cached_in_per_mtok=0.375 WHERE provider='openai' AND code='gpt-5.4-mini';
UPDATE ai_models SET price_in_per_mtok=0.20, price_out_per_mtok=1.25, price_cached_in_per_mtok=0.10  WHERE provider='openai' AND code='gpt-5.4-nano';

-- Modelos Anthropic entram CATALOGADOS PARA CUSTO (active=0): o cost() acha o preco,
-- mas eles nao aparecem no dropdown do agente ate o Master ativar (nao muda a oferta).
INSERT IGNORE INTO ai_models (provider, code, label, active, sort, price_in_per_mtok, price_out_per_mtok, price_cached_in_per_mtok) VALUES
  ('anthropic', 'claude-3-5-haiku-20241022',  'Claude 3.5 Haiku',  0, 50, 0.80,  4.00,  0.08),
  ('anthropic', 'claude-3-5-haiku',           'Claude 3.5 Haiku',  0, 51, 0.80,  4.00,  0.08),
  ('anthropic', 'claude-haiku-4-5',           'Claude Haiku 4.5',  0, 52, 1.00,  5.00,  0.10),
  ('anthropic', 'claude-3-5-sonnet-20241022', 'Claude 3.5 Sonnet', 0, 53, 3.00, 15.00,  0.30),
  ('anthropic', 'claude-3-5-sonnet',          'Claude 3.5 Sonnet', 0, 54, 3.00, 15.00,  0.30),
  ('anthropic', 'claude-sonnet-4-5',          'Claude Sonnet 4.5', 0, 55, 3.00, 15.00,  0.30);

-- ---------------------------------------------------------------------------
-- 4D (observabilidade): eventos estruturados do agente. Hoje o IntakeEngine so faz
-- error_log truncado a 240 chars (diagnostico por grep). Aqui entram: provider_error,
-- price_fallback, superseded, circuit_breaker, paused, resumed, webhook_silent, etc.
-- Escrito best-effort (AgentEvent::log; telemetria NUNCA derruba o fluxo).
-- detail e JSON e NAO deve conter PII crua (telefone/jid mascarado antes de logar).
-- Expurgo de >30 dias entra no tick de retencao quando F1/F2 chegarem.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_agent_events (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  account_id  INT NULL,
  session_id  INT NULL,
  instance_id INT NULL,
  level       ENUM('info','warn','error') NOT NULL DEFAULT 'info',
  code        VARCHAR(40) NOT NULL,
  detail      JSON NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_acc_time  (account_id, created_at),
  KEY idx_code_time (code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
