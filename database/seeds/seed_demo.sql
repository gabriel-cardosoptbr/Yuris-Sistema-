-- =============================================================================
-- seed_demo.sql — Dados fictícios para demonstração do Yuris CRM
-- Escritório Jurídico "Monteiro & Associados" — 6 meses de operação simulada
--
-- Aplicar com:
--   "C:\xampp\mysql\bin\mysql.exe" -u root sistema_vendas < database/seeds/seed_demo.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- =============================================================================
-- LIMPAR DADOS ANTERIORES (ordem: filhos antes dos pais)
-- =============================================================================
TRUNCATE TABLE whatsapp_messages;
TRUNCATE TABLE whatsapp_chats;
TRUNCATE TABLE whatsapp_contacts;
TRUNCATE TABLE whatsapp_instances;
TRUNCATE TABLE whatsapp_chat_processos;
TRUNCATE TABLE whatsapp_settings;
TRUNCATE TABLE task_time_entries;
TRUNCATE TABLE task_reminders;
TRUNCATE TABLE task_comments;
TRUNCATE TABLE task_links;
TRUNCATE TABLE task_history;
TRUNCATE TABLE task_checklist_items;
TRUNCATE TABLE task_attachments;
TRUNCATE TABLE task_board_members;
TRUNCATE TABLE task_recurrences;
TRUNCATE TABLE tasks;
TRUNCATE TABLE task_columns;
TRUNCATE TABLE task_boards;
TRUNCATE TABLE processo_tarefas;
TRUNCATE TABLE processo_prazos;
TRUNCATE TABLE processo_history;
TRUNCATE TABLE processos;
TRUNCATE TABLE card_checklist_items;
TRUNCATE TABLE card_history;
TRUNCATE TABLE cards;
TRUNCATE TABLE contato_vinculos;
TRUNCATE TABLE contatos;
TRUNCATE TABLE goals;
TRUNCATE TABLE dre_accounts;
TRUNCATE TABLE user_permissions;
TRUNCATE TABLE resource_shares;
TRUNCATE TABLE account_vinculos;
TRUNCATE TABLE account_notifications;
TRUNCATE TABLE advogado_convites;
TRUNCATE TABLE users;
TRUNCATE TABLE pipeline_columns;
TRUNCATE TABLE accounts;


-- =============================================================================
-- 1. ACCOUNTS
-- =============================================================================
INSERT INTO `accounts` (`id`, `nome`, `tipo`, `codigo_vinculo`, `status`, `created_at`) VALUES
(1, 'Monteiro & Associados Advocacia', 'matriz', 'mont2026abc', 'active', '2025-11-01 08:00:00');


-- =============================================================================
-- 2. PIPELINE COLUMNS
-- =============================================================================
INSERT INTO `pipeline_columns` (`id`, `account_id`, `nome`, `slug`, `cor`, `ordem`, `conta_funil`, `conta_oportunidade`, `conta_fechado`, `conta_perdido`, `peso_projecao`) VALUES
(1, 1, 'Prospecção',  'prospeccao', '#6366f1', 1, 1, 0, 0, 0, 10),
(2, 1, 'Proposta',    'proposta',   '#f59e0b', 2, 1, 1, 0, 0, 30),
(3, 1, 'Negociação',  'negociacao', '#0ea5e9', 3, 1, 1, 0, 0, 60),
(4, 1, 'Fechado',     'fechado',    '#22c55e', 4, 0, 0, 1, 0, 100),
(5, 1, 'Perdido',     'perdido',    '#ef4444', 5, 0, 0, 0, 1, 0);


-- =============================================================================
-- 3. USERS (admin + 5 membros da equipe)
-- =============================================================================
-- Senha padrão de todos: Yuris@2026 (hash bcrypt de exemplo)
INSERT INTO `users` (`id`, `account_id`, `nome`, `login`, `senha_hash`, `senha_texto`, `perfil`, `role`, `status`, `created_at`) VALUES
(1, 1, 'Administrador', 'admin@admin.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'password',
 'admin', 'owner', 'active', '2025-11-01 08:00:00'),
(2, 1, 'Gabriel Monteiro', 'gabriel@monteiro.adv.br',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'password',
 'admin', 'admin', 'active', '2025-11-02 09:00:00'),
(3, 1, 'Mariana Cardoso Pereira', 'mariana@monteiro.adv.br',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'password',
 'user', 'member', 'active', '2025-11-05 09:30:00'),
(4, 1, 'Rafael Albuquerque Costa', 'rafael@monteiro.adv.br',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'password',
 'user', 'member', 'active', '2025-11-05 10:00:00'),
(5, 1, 'Juliana Ferreira Santos', 'juliana@monteiro.adv.br',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'password',
 'user', 'member', 'active', '2025-12-01 09:00:00'),
(6, 1, 'Pedro Henrique Oliveira', 'pedro@monteiro.adv.br',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'password',
 'user', 'member', 'active', '2026-01-15 09:00:00');

-- Permissões de acesso por página
INSERT INTO `user_permissions` (`user_id`, `page`) VALUES
-- Gabriel (admin — acesso total implícito, mas registramos)
(2, 'dashboard'), (2, 'processos'), (2, 'pipeline'), (2, 'tarefas'),
(2, 'contatos'), (2, 'financeiro'), (2, 'relatorios'), (2, 'usuarios'),
(2, 'configuracoes'), (2, 'whatsapp'), (2, 'webhooks'),
-- Mariana — advogada sênior
(3, 'dashboard'), (3, 'processos'), (3, 'pipeline'), (3, 'tarefas'),
(3, 'contatos'), (3, 'relatorios'), (3, 'whatsapp'),
-- Rafael — advogado
(4, 'dashboard'), (4, 'processos'), (4, 'pipeline'), (4, 'tarefas'),
(4, 'contatos'), (4, 'whatsapp'),
-- Juliana — paralegal
(5, 'dashboard'), (5, 'processos'), (5, 'tarefas'), (5, 'contatos'),
-- Pedro — estagiário
(6, 'dashboard'), (6, 'tarefas'), (6, 'contatos');


-- =============================================================================
-- 4. CONTATOS (30: 20 PF + 10 PJ)
-- =============================================================================
INSERT INTO `contatos` (`id`, `account_id`, `nome`, `telefone`, `email`, `observacoes`, `created_at`) VALUES
-- Pessoas Físicas
(1,  1, 'Marcos Vinícius Teixeira',      '(11) 99823-4571', 'marcos.teixeira@gmail.com',       'CPF: 382.941.570-08 — Cliente desde nov/2025. Ação trabalhista contra ex-empregador.', '2025-11-10 10:00:00'),
(2,  1, 'Amanda Rodrigues Lima',         '(21) 98741-3209', 'amanda.lima@outlook.com',          'CPF: 504.217.830-61 — Processo previdenciário aposentadoria por invalidez.', '2025-11-15 14:00:00'),
(3,  1, 'Carlos Eduardo Nascimento',     '(31) 99512-8834', 'carlos.nascimento@hotmail.com',    'CPF: 619.083.240-77 — Divórcio litigioso, processo em andamento BH.', '2025-11-18 09:30:00'),
(4,  1, 'Fernanda Cristina Barbosa',     '(41) 98623-1108', 'fernanda.barbosa@gmail.com',       'CPF: 743.526.190-34 — Holding familiar. Patrimônio estimado R$ 1,2MM.', '2025-12-02 11:00:00'),
(5,  1, 'Ricardo Henrique Souza',        '(11) 97834-5590', 'ricardo.souza@empresa.com.br',     'CPF: 251.874.630-52 — Usucapião área rural interior SP. Documentação ok.', '2025-12-05 15:00:00'),
(6,  1, 'Patrícia Gonçalves Martins',    '(71) 99201-6643', 'patricia.martins@gmail.com',       'CPF: 867.340.210-19 — Ação de indenização por acidente de trânsito. Salvador/BA.', '2025-12-10 10:30:00'),
(7,  1, 'Thiago Augusto Pereira',        '(51) 98734-2201', 'thiago.pereira@gmail.com',         'CPF: 134.682.950-08 — Consulta sobre rescisão contratual. Sem processo aberto.', '2025-12-15 16:00:00'),
(8,  1, 'Luciana Melo Santos',           '(11) 99034-7782', 'luciana.santos@gmail.com',         'CPF: 490.253.810-40 — Interesse em assessoria jurídica mensal.', '2025-12-18 09:00:00'),
(9,  1, 'Diego Ferreira Cavalcanti',     '(81) 97823-0056', 'diego.cavalcanti@outlook.com',     'CPF: 328.715.940-63 — Habeas corpus. Preso preventivamente Recife/PE.', '2026-01-05 08:30:00'),
(10, 1, 'Renata Oliveira Dias',          '(11) 98456-3317', 'renata.dias@gmail.com',            'CPF: 571.039.280-95 — Ação de alimentos — filho menor. Processo em neg.', '2026-01-08 14:00:00'),
(11, 1, 'Gustavo Henrique Correia',      '(21) 99812-5503', 'gustavo.correia@gmail.com',        'CPF: 042.867.530-71 — Inventário judicial. Herdeiro único. RJ.', '2026-01-10 10:00:00'),
(12, 1, 'Andressa Cunha Machado',        '(31) 98234-4498', 'andressa.machado@gmail.com',       'CPF: 785.120.360-28 — Mandado de segurança concurso público. BH.', '2026-01-15 11:00:00'),
(13, 1, 'Felipe Augusto Moreira',        '(48) 99723-8861', 'felipe.moreira@outlook.com',       'CPF: 213.560.870-00 — Inventário extrajudicial. 3 herdeiros. Florianópolis.', '2026-01-20 09:30:00'),
(14, 1, 'Simone Aparecida Rocha',        '(11) 98345-2279', 'simone.rocha@gmail.com',           'CPF: 659.034.120-83 — Defesa criminal. Processo suspenso aguardando recurso.', '2026-02-01 08:00:00'),
(15, 1, 'Leonardo José Andrade',         '(19) 97912-3304', 'leonardo.andrade@hotmail.com',     'CPF: 428.791.530-17 — Optou por outro escritório. Lead perdido.', '2026-02-05 15:00:00'),
(16, 1, 'Marcia Regina Fonseca',         '(11) 99034-5521', 'marcia.fonseca@gmail.com',         'CPF: 836.215.490-62 — Consulta inicial. Interesse em testamento.', '2026-02-10 10:00:00'),
(17, 1, 'André Luís Carvalho',           '(61) 98712-9934', 'andre.carvalho@gmail.com',         'CPF: 174.930.820-55 — Licitação pública. Empresa de TI Brasília/DF.', '2026-02-15 14:00:00'),
(18, 1, 'Tatiana Cristina Lopes',        '(11) 97823-6640', 'tatiana.lopes@gmail.com',          'CPF: 963.418.270-31 — Revisão de contrato de trabalho. Negociação direta.', '2026-02-20 09:00:00'),
(19, 1, 'Rodrigo Batista Mendes',        '(21) 99456-7723', 'rodrigo.mendes@outlook.com',       'CPF: 317.845.060-92 — Ação revisional de aluguel. RJ.', '2026-03-01 11:00:00'),
(20, 1, 'Carla Beatriz Nunes',           '(11) 98234-8891', 'carla.nunes@gmail.com',            'CPF: 541.270.930-48 — Consultoria tributária pessoa física.', '2026-03-05 15:00:00'),
-- Pessoas Jurídicas
(21, 1, 'Construtora Horizonte LTDA',    '(11) 3029-4411', 'juridico@construtora-horizonte.com.br', 'CNPJ: 12.345.678/0001-95 — Execução fiscal PGFN. Dívida R$ 380k.', '2025-11-12 10:00:00'),
(22, 1, 'TechSolutions Informática SA',  '(11) 3812-7700', 'legal@techsolutions.com.br',        'CNPJ: 23.456.789/0001-08 — Recuperação de crédito + contrato de franquia.', '2025-11-20 14:00:00'),
(23, 1, 'Clínica São Lucas LTDA',        '(11) 3901-5534', 'adm@clinicasaolucas.com.br',        'CNPJ: 34.567.890/0001-21 — Regularização fiscal + contrato societário.', '2025-12-01 09:00:00'),
(24, 1, 'Transportadora Rápida MEI',     '(41) 98823-5517', 'contato@transportadorarapida.com.br','CNPJ: 45.678.901/0001-34 — Recuperação judicial. Passivo ~R$ 1,8MM.', '2026-01-02 08:00:00'),
(25, 1, 'Comércio Estrela do Norte LTDA','(92) 3245-8870', 'fiscal@estreladorte.com.br',        'CNPJ: 56.789.012/0001-47 — Licitação pública Manaus/AM. Impugnação edital.', '2026-01-12 10:00:00'),
(26, 1, 'Advocacia & Associados SS',     '(11) 3344-5566', 'contato@advocaciaassociados.com.br','CNPJ: 67.890.123/0001-60 — Revisão contratual de honorários. Parceria.', '2026-02-03 11:00:00'),
(27, 1, 'Imobiliária Planalto LTDA',     '(61) 3201-4488', 'juridico@imobiliariaplanalto.com.br','CNPJ: 78.901.234/0001-73 — Regularização imobiliária. 12 unidades.', '2025-12-08 09:30:00'),
(28, 1, 'Consultoria Primus LTDA',       '(11) 3765-9920', 'diretoria@consultoriaprimus.com.br', 'CNPJ: 89.012.345/0001-86 — Fusão societária + regularização. Cliente estratégico.', '2026-01-18 14:00:00'),
(29, 1, 'Indústria Metalúrgica Castro SA','(11) 4013-7722', 'juridico@metalurgicacastro.com.br',  'CNPJ: 90.123.456/0001-99 — Ação coletiva trabalhista 47 reclamantes + defesa ambiental.', '2025-12-20 10:00:00'),
(30, 1, 'Distribuidora Global LTDA',     '(21) 3567-8834', 'contato@distribuidoraglobal.com.br',  'CNPJ: 01.234.567/0001-02 — Recuperação de crédito R$ 220k. Cliente inadimplente.', '2026-02-12 09:00:00');


-- =============================================================================
-- 5. CARDS (25 oportunidades no pipeline)
-- =============================================================================
INSERT INTO `cards`
  (`id`, `account_id`, `titulo`, `cliente_nome`, `empresa_nome`, `telefone_whatsapp`, `email`,
   `responsavel_user_id`, `coluna_id`, `ordem_na_coluna`,
   `valor_estimado`, `valor_proposta`, `valor_fechado_final`,
   `data_prevista_fechamento`, `data_fechamento`,
   `descricao`, `status`, `contato_id`, `checklist_percentual`, `created_at`, `updated_at`) VALUES

-- ── PROSPECÇÃO (coluna 1) ────────────────────────────────────────────────────
(1,  1, 'Assessoria Trabalhista — Marcos Teixeira',
 'Marcos Vinícius Teixeira', NULL, '(11) 99823-4571', 'marcos.teixeira@gmail.com',
 3, 1, 1, 5000.00, 0.00, 0.00, '2026-06-30', NULL,
 'Cliente demitido sem justa causa após 8 anos. Verbas rescisórias incompletas. FGTS retido.',
 'aberto', 1, 0.00, '2025-11-10 10:30:00', '2025-12-01 14:00:00'),

(2,  1, 'Execução Fiscal PGFN — Construtora Horizonte',
 'Construtora Horizonte LTDA', 'Construtora Horizonte LTDA', '(11) 3029-4411', 'juridico@construtora-horizonte.com.br',
 4, 1, 2, 12000.00, 0.00, 0.00, '2026-07-31', NULL,
 'Execução fiscal em curso. Dívida R$ 380k com PGFN. Avaliar parcelamento REFIS ou embargos.',
 'aberto', 21, 0.00, '2025-11-12 11:00:00', '2026-01-05 09:00:00'),

(3,  1, 'Divórcio Consensual — Amanda Lima',
 'Amanda Rodrigues Lima', NULL, '(21) 98741-3209', 'amanda.lima@outlook.com',
 3, 1, 3, 4500.00, 0.00, 0.00, '2026-06-15', NULL,
 'Casal sem filhos menores. Bens partilhados de comum acordo. Aguarda documentação.',
 'aberto', 2, 0.00, '2025-11-15 14:30:00', '2025-12-10 10:00:00'),

(4,  1, 'Regularização Imobiliária — Imobiliária Planalto',
 'Imobiliária Planalto LTDA', 'Imobiliária Planalto LTDA', '(61) 3201-4488', 'juridico@imobiliariaplanalto.com.br',
 4, 1, 4, 8000.00, 0.00, 0.00, '2026-08-31', NULL,
 '12 unidades sem registro em cartório. Levantamento documental em andamento.',
 'aberto', 27, 0.00, '2025-12-08 09:30:00', '2026-02-01 11:00:00'),

(5,  1, 'Inventário Extrajudicial — Carlos Nascimento',
 'Carlos Eduardo Nascimento', NULL, '(31) 99512-8834', 'carlos.nascimento@hotmail.com',
 5, 1, 5, 7500.00, 0.00, 0.00, '2026-07-15', NULL,
 'Pai falecido em out/2025. 2 herdeiros maiores. Inventário via cartório BH. Imóvel + veículo.',
 'aberto', 3, 33.00, '2025-11-18 09:30:00', '2026-01-20 14:00:00'),

(6,  1, 'Proteção de Marca — TechSolutions',
 'TechSolutions Informática SA', 'TechSolutions Informática SA', '(11) 3812-7700', 'legal@techsolutions.com.br',
 3, 1, 6, 9000.00, 0.00, 0.00, '2026-09-30', NULL,
 'Registro de 3 marcas no INPI. Uma marca já em uso, outra em processo de criação.',
 'aberto', 22, 0.00, '2025-11-20 15:00:00', '2026-02-10 09:00:00'),

(7,  1, 'Recuperação de Crédito — Distribuidora Global',
 'Distribuidora Global LTDA', 'Distribuidora Global LTDA', '(21) 3567-8834', 'contato@distribuidoraglobal.com.br',
 4, 1, 7, 15000.00, 0.00, 0.00, '2026-08-31', NULL,
 'Crédito de R$ 220k. Cliente inadimplente há 14 meses. Avaliando ação de cobrança vs. acordo.',
 'aberto', 30, 0.00, '2026-02-12 09:30:00', '2026-04-01 10:00:00'),

(8,  1, 'Contrato Societário — Clínica São Lucas',
 'Clínica São Lucas LTDA', 'Clínica São Lucas LTDA', '(11) 3901-5534', 'adm@clinicasaolucas.com.br',
 3, 1, 8, 6000.00, 0.00, 0.00, '2026-07-31', NULL,
 'Revisão e atualização do contrato social. Entrada de novo sócio com 30% do capital.',
 'aberto', 23, 0.00, '2025-12-01 09:00:00', '2026-01-15 11:00:00'),

-- ── PROPOSTA (coluna 2) ──────────────────────────────────────────────────────
(9,  1, 'Ação Trabalhista — Ricardo Souza',
 'Ricardo Henrique Souza', NULL, '(11) 97834-5590', 'ricardo.souza@empresa.com.br',
 4, 2, 1, 18000.00, 18000.00, 0.00, '2026-07-31', NULL,
 'Reclamação trabalhista horas extras + assédio moral. 3 anos de contrato. Proposta enviada.',
 'aberto', 5, 0.00, '2025-12-05 15:30:00', '2026-02-20 14:00:00'),

(10, 1, 'Fusão Societária — Consultoria Primus',
 'Consultoria Primus LTDA', 'Consultoria Primus LTDA', '(11) 3765-9920', 'diretoria@consultoriaprimus.com.br',
 2, 2, 2, 25000.00, 25000.00, 0.00, '2026-08-31', NULL,
 'Due diligence + estruturação societária fusão com empresa do RJ. Proposta apresentada.',
 'aberto', 28, 0.00, '2026-01-18 14:30:00', '2026-03-15 10:00:00'),

(11, 1, 'Defesa Ambiental — Metalúrgica Castro',
 'Indústria Metalúrgica Castro SA', 'Indústria Metalúrgica Castro SA', '(11) 4013-7722', 'juridico@metalurgicacastro.com.br',
 3, 2, 3, 22000.00, 22000.00, 0.00, '2026-09-30', NULL,
 'Auto de infração IBAMA. Defesa administrativa + eventual ação anulatória. Proposta aceita parcialmente.',
 'aberto', 29, 0.00, '2025-12-20 10:30:00', '2026-02-28 14:00:00'),

(12, 1, 'Holding Familiar — Fernanda Barbosa',
 'Fernanda Cristina Barbosa', NULL, '(41) 98623-1108', 'fernanda.barbosa@gmail.com',
 4, 2, 4, 28000.00, 28000.00, 0.00, '2026-10-31', NULL,
 'Patrimônio R$ 1,2MM (imóveis + participações). Estruturação holding + planejamento sucessório.',
 'aberto', 4, 0.00, '2025-12-02 11:30:00', '2026-03-01 09:00:00'),

(13, 1, 'Processo Previdenciário — Patrícia Martins',
 'Patrícia Gonçalves Martins', NULL, '(71) 99201-6643', 'patricia.martins@gmail.com',
 5, 2, 5, 15000.00, 15000.00, 0.00, '2026-06-30', NULL,
 'Aposentadoria por tempo de contribuição + revisão BPC. Proposta enviada. Aguardando aprovação.',
 'aberto', 6, 0.00, '2025-12-10 10:30:00', '2026-02-10 11:00:00'),

(14, 1, 'Recuperação Judicial — Transportadora Rápida',
 'Transportadora Rápida MEI', 'Transportadora Rápida MEI', '(41) 98823-5517', 'contato@transportadorarapida.com.br',
 4, 2, 6, 20000.00, 20000.00, 0.00, '2026-07-31', NULL,
 'Pedido de recuperação judicial. Passivo aprox. R$ 1,8MM. Credores: fornecedores + bancos.',
 'aberto', 24, 0.00, '2026-01-02 08:30:00', '2026-02-25 10:00:00'),

-- ── NEGOCIAÇÃO (coluna 3) ────────────────────────────────────────────────────
(15, 1, 'Indenização Acidente — Diego Cavalcanti',
 'Diego Ferreira Cavalcanti', NULL, '(81) 97823-0056', 'diego.cavalcanti@outlook.com',
 3, 3, 1, 35000.00, 35000.00, 0.00, '2026-08-31', NULL,
 'Acidente de trânsito grave. Lesão permanente. Negociando acordo extrajudicial com seguradora.',
 'aberto', 9, 0.00, '2026-01-05 08:30:00', '2026-04-10 14:00:00'),

(16, 1, 'Licitação Pública — Estrela do Norte',
 'Comércio Estrela do Norte LTDA', 'Comércio Estrela do Norte LTDA', '(92) 3245-8870', 'fiscal@estreladorte.com.br',
 2, 3, 2, 45000.00, 45000.00, 0.00, '2026-09-30', NULL,
 'Impugnação de edital licitação SUFRAMA. Em negociação sobre escopo dos serviços.',
 'aberto', 25, 0.00, '2026-01-12 10:30:00', '2026-04-15 09:00:00'),

(17, 1, 'Revisão Contratual — Advocacia Associados',
 'Advocacia & Associados SS', 'Advocacia & Associados SS', '(11) 3344-5566', 'contato@advocaciaassociados.com.br',
 3, 3, 3, 12000.00, 12000.00, 0.00, '2026-06-30', NULL,
 'Revisão contrato de parceria e honorários. Negociando cláusula de exclusividade.',
 'aberto', 26, 0.00, '2026-02-03 11:30:00', '2026-04-20 10:00:00'),

(18, 1, 'Ação de Alimentos — Renata Dias',
 'Renata Oliveira Dias', NULL, '(11) 98456-3317', 'renata.dias@gmail.com',
 5, 3, 4, 18000.00, 18000.00, 0.00, '2026-07-31', NULL,
 'Majoração de alimentos. Pai com renda aumentada. Em negociação para acordo antes da audiência.',
 'aberto', 10, 0.00, '2026-01-08 14:30:00', '2026-04-25 14:00:00'),

(19, 1, 'Contrato de Franquia — TechSolutions',
 'TechSolutions Informática SA', 'TechSolutions Informática SA', '(11) 3812-7700', 'legal@techsolutions.com.br',
 4, 3, 5, 30000.00, 30000.00, 0.00, '2026-08-31', NULL,
 'Elaboração contrato master franquia para expansão 15 unidades. Negociando royalties e suporte.',
 'aberto', 22, 0.00, '2026-02-15 09:30:00', '2026-05-01 10:00:00'),

-- ── FECHADO (coluna 4) ───────────────────────────────────────────────────────
(20, 1, 'Consultoria Tributária — Gustavo Correia',
 'Gustavo Henrique Correia', NULL, '(21) 99812-5503', 'gustavo.correia@gmail.com',
 4, 4, 1, 22000.00, 22000.00, 22000.00, '2026-03-31', '2026-03-28',
 'Planejamento tributário PF + PJ. Economia fiscal estimada R$ 48k/ano. Contrato assinado.',
 'fechado', 11, 100.00, '2026-01-10 10:30:00', '2026-03-28 15:00:00'),

(21, 1, 'Assessoria Trabalhista — Andressa Machado',
 'Andressa Cunha Machado', NULL, '(31) 98234-4498', 'andressa.machado@gmail.com',
 3, 4, 2, 16500.00, 16500.00, 16500.00, '2026-04-30', '2026-04-15',
 'Defesa em reclamação trabalhista. Acordo homologado R$ 12k. Encerrado com sucesso.',
 'fechado', 12, 100.00, '2026-01-15 11:30:00', '2026-04-15 16:00:00'),

(22, 1, 'Regularização Fiscal — Clínica São Lucas',
 'Clínica São Lucas LTDA', 'Clínica São Lucas LTDA', '(11) 3901-5534', 'adm@clinicasaolucas.com.br',
 2, 4, 3, 19800.00, 19800.00, 19800.00, '2026-04-30', '2026-04-22',
 'Regularização de 5 anos de DCTF e obrigações acessórias. Parcelamento aprovado.',
 'fechado', 23, 100.00, '2026-02-01 09:30:00', '2026-04-22 14:00:00'),

(23, 1, 'Inventário Extrajudicial — Felipe Moreira',
 'Felipe Augusto Moreira', NULL, '(48) 99723-8861', 'felipe.moreira@outlook.com',
 5, 4, 4, 14000.00, 14000.00, 14000.00, '2026-05-15', '2026-05-02',
 'Inventário concluído. 3 herdeiros. Escritura lavrada em cartório Florianópolis. Êxito total.',
 'fechado', 13, 100.00, '2026-01-20 09:30:00', '2026-05-02 11:00:00'),

-- ── PERDIDO (coluna 5) ───────────────────────────────────────────────────────
(24, 1, 'Defesa Criminal — Simone Rocha',
 'Simone Aparecida Rocha', NULL, '(11) 98345-2279', 'simone.rocha@gmail.com',
 2, 5, 1, 25000.00, 25000.00, 0.00, '2026-04-30', NULL,
 'Motivo perda: cliente sem condições de arcar com honorários. Indicado para Defensoria Pública.',
 'perdido', 14, 0.00, '2026-02-01 08:30:00', '2026-03-10 09:00:00'),

(25, 1, 'Ação Previdenciária — Leonardo Andrade',
 'Leonardo José Andrade', NULL, '(19) 97912-3304', 'leonardo.andrade@hotmail.com',
 3, 5, 2, 12000.00, 12000.00, 0.00, '2026-04-30', NULL,
 'Motivo perda: cliente optou por escritório com escritório em Campinas. Concorrência local.',
 'perdido', 15, 0.00, '2026-02-05 15:30:00', '2026-03-15 10:00:00');


-- =============================================================================
-- 6. CARD_CHECKLIST_ITEMS (cards 1, 5, 10, 20, 21, 22, 23)
-- =============================================================================
INSERT INTO `card_checklist_items` (`card_id`, `titulo`, `concluido`, `ordem`, `created_at`) VALUES
-- Card 1 — Assessoria Trabalhista Marcos Teixeira
(1, 'Reunião inicial com o cliente',            1, 1, '2025-11-11 10:00:00'),
(1, 'Levantar documentação rescisória',          1, 2, '2025-11-11 10:00:00'),
(1, 'Calcular verbas devidas',                   0, 3, '2025-11-11 10:00:00'),
(1, 'Protocolar reclamação trabalhista',         0, 4, '2025-11-11 10:00:00'),
-- Card 5 — Inventário Carlos Nascimento
(5, 'Certidão de óbito',                         1, 1, '2025-11-20 09:00:00'),
(5, 'Certidões negativas dos herdeiros',         0, 2, '2025-11-20 09:00:00'),
(5, 'Avaliação imóvel',                          0, 3, '2025-11-20 09:00:00'),
-- Card 10 — Fusão Societária Consultoria Primus
(10, 'Due diligence documental',                 1, 1, '2026-01-20 09:00:00'),
(10, 'Relatório de passivos',                    1, 2, '2026-01-20 09:00:00'),
(10, 'Minuta do protocolo de fusão',             1, 3, '2026-01-20 09:00:00'),
(10, 'Aprovação assembleias',                    0, 4, '2026-01-20 09:00:00'),
(10, 'Registro JUCERJA',                         0, 5, '2026-01-20 09:00:00'),
-- Card 20 — Consultoria Tributária Gustavo Correia (fechado, 100%)
(20, 'Diagnóstico tributário PF',                1, 1, '2026-01-12 09:00:00'),
(20, 'Diagnóstico tributário PJ',                1, 2, '2026-01-12 09:00:00'),
(20, 'Relatório de oportunidades',               1, 3, '2026-01-12 09:00:00'),
(20, 'Implementação do planejamento',            1, 4, '2026-01-12 09:00:00'),
-- Card 21 — Andressa Machado (fechado, 100%)
(21, 'Análise da reclamação',                    1, 1, '2026-01-17 09:00:00'),
(21, 'Defesa e documentação',                    1, 2, '2026-01-17 09:00:00'),
(21, 'Audiência de conciliação',                 1, 3, '2026-01-17 09:00:00'),
(21, 'Acordo homologado',                        1, 4, '2026-01-17 09:00:00'),
-- Card 22 — Clínica São Lucas (fechado, 100%)
(22, 'Diagnóstico das pendências fiscais',       1, 1, '2026-02-03 09:00:00'),
(22, 'Elaboração DCTFs retroativas',             1, 2, '2026-02-03 09:00:00'),
(22, 'Pedido de parcelamento PERT',              1, 3, '2026-02-03 09:00:00'),
(22, 'Acompanhamento aprovação',                 1, 4, '2026-02-03 09:00:00'),
-- Card 23 — Felipe Moreira (fechado, 100%)
(23, 'Documentação dos herdeiros',               1, 1, '2026-01-22 09:00:00'),
(23, 'Avaliação dos bens',                       1, 2, '2026-01-22 09:00:00'),
(23, 'Escritura de inventário',                  1, 3, '2026-01-22 09:00:00');


-- =============================================================================
-- 7. CARD_HISTORY (movimentações nos últimos 6 meses)
-- =============================================================================
INSERT INTO `card_history`
  (`card_id`, `usuario_id`, `acao`, `campo_alterado`, `valor_anterior`, `valor_novo`,
   `de_coluna_id`, `para_coluna_id`, `created_at`) VALUES
-- Card 9 (Ricardo Souza): Prospecção → Proposta
(9,  3, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-01-10 11:00:00'),
-- Card 10 (Consultoria Primus): Prospecção → Proposta
(10, 2, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-02-05 14:00:00'),
-- Card 11 (Metalúrgica): Prospecção → Proposta
(11, 3, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-01-28 10:00:00'),
-- Card 12 (Fernanda Barbosa): Prospecção → Proposta
(12, 4, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-02-01 09:00:00'),
-- Card 13 (Patrícia Martins): Prospecção → Proposta
(13, 5, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-01-25 14:00:00'),
-- Card 14 (Transportadora): Prospecção → Proposta
(14, 4, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-02-10 10:00:00'),
-- Card 15 (Diego): Prospecção → Proposta → Negociação
(15, 3, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-01-20 11:00:00'),
(15, 3, 'moveu_coluna', 'coluna_id', 'Proposta',   'Negociação',2, 3, '2026-02-15 15:00:00'),
-- Card 16 (Estrela Norte): Prospecção → Proposta → Negociação
(16, 2, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-01-25 09:00:00'),
(16, 2, 'moveu_coluna', 'coluna_id', 'Proposta',   'Negociação',2, 3, '2026-02-20 14:00:00'),
-- Card 17 (Advocacia Associados): Prospecção → Negociação
(17, 3, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Negociação',1, 3, '2026-03-01 10:00:00'),
-- Card 18 (Renata Dias): Prospecção → Proposta → Negociação
(18, 5, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-01-20 14:00:00'),
(18, 5, 'moveu_coluna', 'coluna_id', 'Proposta',   'Negociação',2, 3, '2026-03-10 11:00:00'),
-- Card 19 (TechSolutions franquia): Prospecção → Proposta → Negociação
(19, 4, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-03-01 09:00:00'),
(19, 4, 'moveu_coluna', 'coluna_id', 'Proposta',   'Negociação',2, 3, '2026-04-01 14:00:00'),
-- Card 20 (Gustavo Correia): trajetória completa → Fechado
(20, 4, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-01-18 09:00:00'),
(20, 4, 'moveu_coluna', 'coluna_id', 'Proposta',   'Negociação',2, 3, '2026-02-10 11:00:00'),
(20, 4, 'moveu_coluna', 'coluna_id', 'Negociação', 'Fechado',   3, 4, '2026-03-28 15:00:00'),
-- Card 21 (Andressa Machado): Prospecção → Proposta → Fechado
(21, 3, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-01-28 10:00:00'),
(21, 3, 'moveu_coluna', 'coluna_id', 'Proposta',   'Negociação',2, 3, '2026-03-01 14:00:00'),
(21, 3, 'moveu_coluna', 'coluna_id', 'Negociação', 'Fechado',   3, 4, '2026-04-15 16:00:00'),
-- Card 22 (Clínica São Lucas): trajetória completa → Fechado
(22, 2, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-02-15 09:00:00'),
(22, 2, 'moveu_coluna', 'coluna_id', 'Proposta',   'Negociação',2, 3, '2026-03-15 11:00:00'),
(22, 2, 'moveu_coluna', 'coluna_id', 'Negociação', 'Fechado',   3, 4, '2026-04-22 14:00:00'),
-- Card 23 (Felipe Moreira): Prospecção → Proposta → Fechado
(23, 5, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-02-01 09:00:00'),
(23, 5, 'moveu_coluna', 'coluna_id', 'Proposta',   'Negociação',2, 3, '2026-03-20 14:00:00'),
(23, 5, 'moveu_coluna', 'coluna_id', 'Negociação', 'Fechado',   3, 4, '2026-05-02 11:00:00'),
-- Card 24 (Simone Rocha): Prospecção → Proposta → Perdido
(24, 2, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-02-10 10:00:00'),
(24, 2, 'moveu_coluna', 'coluna_id', 'Proposta',   'Perdido',   2, 5, '2026-03-10 09:00:00'),
-- Card 25 (Leonardo Andrade): Prospecção → Proposta → Perdido
(25, 3, 'moveu_coluna', 'coluna_id', 'Prospecção', 'Proposta',  1, 2, '2026-02-12 11:00:00'),
(25, 3, 'moveu_coluna', 'coluna_id', 'Proposta',   'Perdido',   2, 5, '2026-03-15 10:00:00');


-- =============================================================================
-- 8. PROCESSOS (15 processos judiciais)
-- =============================================================================
INSERT INTO `processos`
  (`id`, `account_id`, `numero`, `numero_cnj`, `cliente_nome`, `parte_contraria`,
   `cpf_cnpj_parte_contraria`, `tipo_acao`, `vara_comarca`, `responsavel_user_id`,
   `status`, `data_inicio`, `proximo_prazo`, `ultima_movimentacao`,
   `observacoes`, `card_id`, `contato_id`, `created_at`) VALUES

(1,  1, '00012345-7/2025', '0001234-57.2025.5.02.0001',
 'Marcos Vinícius Teixeira', 'Empresa XYZ Comércio e Serviços LTDA',
 '11.223.334/0001-81', 'Trabalhista',
 '1ª Vara do Trabalho de São Paulo/SP', 3,
 'ativo', '2025-11-20', '2026-05-25', '2026-04-30 10:00:00',
 'Verbas rescisórias + FGTS + horas extras. Audiência de instrução marcada.',
 1, 1, '2025-11-20 09:00:00'),

(2,  1, '00045678-3/2025', '0004567-83.2025.3.01.9999',
 'Amanda Rodrigues Lima', 'INSS — Instituto Nacional do Seguro Social',
 '29.979.036/0001-40', 'Previdenciário',
 'JEF — Juizado Especial Federal do Rio de Janeiro/RJ', 5,
 'ativo', '2025-12-01', '2026-06-10', '2026-04-15 14:00:00',
 'Aposentadoria por invalidez. Perícia médica realizada. Aguardando sentença.',
 3, 2, '2025-12-01 10:00:00'),

(3,  1, '00089123-4/2025', '0008912-34.2025.8.13.0024',
 'Carlos Eduardo Nascimento', 'Ana Paula Ferreira Nascimento',
 '748.392.610-00', 'Família e Sucessões',
 '3ª Vara de Família de Belo Horizonte/MG', 3,
 'ativo', '2025-11-25', '2026-05-30', '2026-05-02 09:00:00',
 'Divórcio litigioso. Disputa de guarda compartilhada e partilha de imóvel.',
 NULL, 3, '2025-11-25 10:00:00'),

(4,  1, '00056781-2/2026', '0005678-12.2026.8.26.0482',
 'Ricardo Henrique Souza', 'Espólio de José Carlos Ferreira',
 NULL, 'Imobiliário',
 '1ª Vara Cível de Presidente Prudente/SP', 4,
 'ativo', '2026-01-15', '2026-06-20', '2026-04-20 11:00:00',
 'Usucapião área rural 45 hectares. Contestação dos confrontantes em andamento.',
 9, 5, '2026-01-15 09:00:00'),

(5,  1, '00034512-8/2025', '0003451-28.2025.8.05.0001',
 'Patrícia Gonçalves Martins', 'João Carlos Alves de Souza',
 '391.847.290-55', 'Cível',
 '5ª Vara Cível de Salvador/BA', 4,
 'ativo', '2025-12-15', '2026-06-15', '2026-04-28 14:00:00',
 'Indenização por danos materiais e morais. Acidente veículo. Laudo pericial favorável.',
 13, 6, '2025-12-15 10:00:00'),

(6,  1, '00078934-1/2025', '0007893-41.2025.4.03.6182',
 'Construtora Horizonte LTDA', 'União Federal — PGFN',
 '00.394.460/0001-41', 'Tributário',
 '1ª Vara Federal de São Paulo/SP', 4,
 'ativo', '2025-11-15', '2026-05-20', '2026-05-05 09:00:00',
 'Execução fiscal R$ 380k. Embargos à execução interpostos. Penhora de conta bancária.',
 2, 21, '2025-11-15 09:00:00'),

(7,  1, '00023456-9/2025', '0002345-69.2025.8.26.0100',
 'TechSolutions Informática SA', 'Distribuidora Mega Tech LTDA',
 '44.556.678/0001-91', 'Cível',
 '12ª Vara Cível de São Paulo/SP', 3,
 'ativo', '2025-12-10', '2026-05-28', '2026-04-10 10:00:00',
 'Cobrança contrato não cumprido R$ 185k. Contestação apresentada. Audiência marcada.',
 6, 22, '2025-12-10 10:00:00'),

(8,  1, '00067890-5/2026', '0006789-05.2026.8.26.0224',
 'Renata Oliveira Dias', 'Fernando Andrade Dias',
 '582.139.470-22', 'Família e Sucessões',
 '2ª Vara de Família de Santo André/SP', 5,
 'ativo', '2026-01-10', '2026-05-18', '2026-05-01 14:00:00',
 'Ação de alimentos. Requerido declarou renda de R$ 8k. Extratos bancários solicitados.',
 18, 10, '2026-01-10 10:00:00'),

(9,  1, '00012378-6/2026', '0001237-86.2026.8.19.0001',
 'Gustavo Henrique Correia', 'Massa inventariada de Paulo Correia',
 NULL, 'Família e Sucessões',
 '4ª Vara de Órfãos e Sucessões do Rio de Janeiro/RJ', 4,
 'concluido', '2026-01-12', NULL, '2026-04-30 16:00:00',
 'Inventário judicial concluído. Formal de partilha expedido. Transferência imóvel realizada.',
 20, 11, '2026-01-12 09:00:00'),

(10, 1, '00098234-7/2026', '0009823-47.2026.8.26.0050',
 'Diego Ferreira Cavalcanti', 'Ministério Público de Pernambuco',
 NULL, 'Criminal',
 'Tribunal de Justiça de Pernambuco — Habeas Corpus', 3,
 'ativo', '2026-01-08', '2026-05-15', '2026-04-25 10:00:00',
 'HC preventivo. Decisão de primeiro grau denegou. Impetrado STJ. Aguardando julgamento.',
 15, 9, '2026-01-08 09:00:00'),

(11, 1, '00045123-2/2026', '0004512-32.2026.8.13.0024',
 'Andressa Cunha Machado', 'Estado de Minas Gerais — Secretaria de Educação',
 NULL, 'Administrativo',
 'Tribunal de Justiça de Minas Gerais — MS', 3,
 'ativo', '2026-01-18', '2026-06-01', '2026-04-20 11:00:00',
 'MS impetrado após reprovação em concurso público. Questão nulidade por erro na gabarito.',
 NULL, 12, '2026-01-18 10:00:00'),

(12, 1, '00034789-0/2026', '0003478-90.2026.8.24.0064',
 'Felipe Augusto Moreira', 'Empresa Floripa Serviços LTDA',
 '55.667.789/0001-04', 'Trabalhista',
 'Tribunal Regional do Trabalho 12ª Região/SC', 5,
 'ativo', '2026-01-22', '2026-06-10', '2026-04-15 14:00:00',
 'Ação rescisória de acordo trabalhista. Alegação de vício de consentimento.',
 NULL, 13, '2026-01-22 09:00:00'),

(13, 1, '00056234-1/2026', '0005623-41.2026.8.26.0100',
 'Simone Aparecida Rocha', 'Banco Nacional LTDA',
 '60.701.190/0001-04', 'Cível',
 '18ª Vara Cível de São Paulo/SP', 2,
 'suspenso', '2026-02-03', '2026-07-01', '2026-04-01 09:00:00',
 'Tutela antecipada para suspender negativação indevida. Liminar concedida. Processo suspenso.',
 24, 14, '2026-02-03 10:00:00'),

(14, 1, '00078512-3/2025', '0007851-23.2025.5.02.0012',
 'Indústria Metalúrgica Castro SA', '47 Reclamantes — Classe Trabalhadora',
 NULL, 'Trabalhista',
 '12ª Vara do Trabalho de São Paulo/SP', 3,
 'ativo', '2025-12-20', '2026-05-22', '2026-05-04 10:00:00',
 'Ação coletiva. 47 reclamantes. Horas extras, insalubridade e adicional de periculosidade.',
 11, 29, '2025-12-20 09:00:00'),

(15, 1, '00023145-8/2026', '0002314-58.2026.8.26.0100',
 'Consultoria Primus LTDA', 'Empresa Beta Consultoria LTDA',
 '66.778.890/0001-17', 'Societário',
 '1ª Vara Empresarial de São Paulo/SP', 2,
 'ativo', '2026-01-20', '2026-06-30', '2026-04-10 14:00:00',
 'Dissolução parcial por desentendimento societário. Apuração de haveres em andamento.',
 10, 28, '2026-01-20 10:00:00');


-- =============================================================================
-- 9. PROCESSO_PRAZOS
-- =============================================================================
INSERT INTO `processo_prazos` (`processo_id`, `descricao`, `data_limite`, `responsavel`, `status`, `prioridade`) VALUES
(1,  'Protocolar petição de instrução',           '2026-05-25', 'Mariana Cardoso Pereira',  'pendente',  'alta'),
(1,  'Juntar documentos comprobatórios',          '2026-05-20', 'Rafael Albuquerque Costa', 'pendente',  'alta'),
(2,  'Manifestação sobre laudo médico',           '2026-06-10', 'Juliana Ferreira Santos',  'pendente',  'media'),
(3,  'Audiência de mediação familiar',            '2026-05-30', 'Mariana Cardoso Pereira',  'pendente',  'alta'),
(4,  'Intimação aos confrontantes',               '2026-06-20', 'Rafael Albuquerque Costa', 'pendente',  'media'),
(5,  'Requerer laudo complementar',               '2026-06-15', 'Rafael Albuquerque Costa', 'pendente',  'media'),
(6,  'Contrarrazões dos embargos',                '2026-05-20', 'Rafael Albuquerque Costa', 'pendente',  'alta'),
(7,  'Audiência de conciliação',                  '2026-05-28', 'Mariana Cardoso Pereira',  'pendente',  'alta'),
(8,  'Juntada de extratos bancários',             '2026-05-18', 'Juliana Ferreira Santos',  'pendente',  'alta'),
(10, 'Apresentar memorias ao STJ',                '2026-05-15', 'Mariana Cardoso Pereira',  'pendente',  'alta'),
(11, 'Contrarrazões do recurso ordinário',        '2026-06-01', 'Mariana Cardoso Pereira',  'pendente',  'media'),
(14, 'Audiência de instrução — ação coletiva',    '2026-05-22', 'Mariana Cardoso Pereira',  'pendente',  'alta'),
(14, 'Juntar laudos de insalubridade',            '2026-05-10', 'Pedro Henrique Oliveira',  'concluido', 'media'),
(6,  'Petição de desbloqueio conta bancária',     '2026-04-15', 'Rafael Albuquerque Costa', 'concluido', 'alta'),
(1,  'Notificação extrajudicial pré-processo',    '2025-11-25', 'Mariana Cardoso Pereira',  'concluido', 'media');


-- =============================================================================
-- 10. PROCESSO_HISTORY
-- =============================================================================
INSERT INTO `processo_history` (`processo_id`, `user_email`, `acao`, `descricao`, `created_at`) VALUES
(1,  'mariana@monteiro.adv.br', 'criacao',     'Processo cadastrado no sistema',                                       '2025-11-20 09:00:00'),
(1,  'mariana@monteiro.adv.br', 'notificacao', 'Notificação extrajudicial enviada ao ex-empregador',                  '2025-11-25 11:00:00'),
(1,  'mariana@monteiro.adv.br', 'protocolo',   'Reclamação trabalhista protocolada na 1ª VT de SP',                   '2025-12-10 14:00:00'),
(1,  'rafael@monteiro.adv.br',  'audiencia',   'Audiência de conciliação realizada — sem acordo',                      '2026-02-20 10:00:00'),
(2,  'juliana@monteiro.adv.br', 'criacao',     'Processo previdenciário cadastrado',                                   '2025-12-01 10:00:00'),
(2,  'juliana@monteiro.adv.br', 'pericia',     'Perícia médica judicial realizada',                                    '2026-03-10 14:00:00'),
(3,  'mariana@monteiro.adv.br', 'criacao',     'Processo de divórcio cadastrado',                                      '2025-11-25 10:00:00'),
(3,  'mariana@monteiro.adv.br', 'despacho',    'Despacho recebido: designada audiência para mai/2026',                 '2026-04-05 11:00:00'),
(6,  'rafael@monteiro.adv.br',  'criacao',     'Execução fiscal cadastrada',                                           '2025-11-15 09:00:00'),
(6,  'rafael@monteiro.adv.br',  'embargo',     'Embargos à execução interpostos — suspensão automática',               '2025-12-20 10:00:00'),
(6,  'rafael@monteiro.adv.br',  'decisao',     'Penhora online decretada — R$ 50k bloqueados',                        '2026-02-28 14:00:00'),
(6,  'rafael@monteiro.adv.br',  'peticao',     'Petição de desbloqueio parcial deferida — R$ 50k liberados',           '2026-04-20 15:00:00'),
(9,  'rafael@monteiro.adv.br',  'criacao',     'Inventário judicial iniciado',                                         '2026-01-12 09:00:00'),
(9,  'rafael@monteiro.adv.br',  'conclusao',   'Formal de partilha expedido. Processo concluído com êxito.',           '2026-04-30 16:00:00'),
(10, 'mariana@monteiro.adv.br', 'criacao',     'Habeas corpus impetrado no TJ/PE',                                     '2026-01-08 09:00:00'),
(10, 'mariana@monteiro.adv.br', 'decisao',     'HC denegado em 1ª instância — impetrado no STJ',                      '2026-02-25 11:00:00'),
(14, 'mariana@monteiro.adv.br', 'criacao',     'Ação coletiva cadastrada — 47 reclamantes',                           '2025-12-20 09:00:00'),
(14, 'pedro@monteiro.adv.br',   'documento',   'Laudos de insalubridade juntados',                                    '2026-05-08 10:00:00');


-- =============================================================================
-- 11. CONTATO_VINCULOS (links contato → card e contato → processo)
-- =============================================================================
INSERT INTO `contato_vinculos` (`contato_id`, `tipo_vinculo`, `referencia_id`, `origem`, `ativo`) VALUES
(1,  'card',     1,  'manual', 1),
(1,  'processo', 1,  'manual', 1),
(2,  'card',     3,  'manual', 1),
(2,  'processo', 2,  'manual', 1),
(3,  'card',     5,  'manual', 1),
(3,  'processo', 3,  'manual', 1),
(4,  'card',     12, 'manual', 1),
(5,  'card',     9,  'manual', 1),
(5,  'processo', 4,  'manual', 1),
(6,  'card',     13, 'manual', 1),
(6,  'processo', 5,  'manual', 1),
(9,  'card',     15, 'manual', 1),
(9,  'processo', 10, 'manual', 1),
(10, 'card',     18, 'manual', 1),
(10, 'processo', 8,  'manual', 1),
(11, 'card',     20, 'manual', 1),
(11, 'processo', 9,  'manual', 1),
(12, 'card',     21, 'manual', 1),
(12, 'processo', 11, 'manual', 1),
(13, 'card',     23, 'manual', 1),
(14, 'card',     24, 'manual', 1),
(14, 'processo', 13, 'manual', 1),
(21, 'card',     2,  'manual', 1),
(21, 'processo', 6,  'manual', 1),
(22, 'card',     6,  'manual', 1),
(22, 'card',     19, 'manual', 1),
(22, 'processo', 7,  'manual', 1),
(23, 'card',     8,  'manual', 1),
(23, 'card',     22, 'manual', 1),
(28, 'card',     10, 'manual', 1),
(28, 'processo', 15, 'manual', 1),
(29, 'card',     11, 'manual', 1),
(29, 'processo', 14, 'manual', 1),
(30, 'card',     7,  'manual', 1);


-- =============================================================================
-- 12. PROCESSO_TAREFAS (tarefas vinculadas a processos)
-- =============================================================================
INSERT INTO `processo_tarefas` (`processo_id`, `titulo`, `concluido`, `prioridade`, `responsavel`, `data_tarefa`, `ordem`) VALUES
(1, 'Levantar cálculos de verbas rescisórias',    1, 'alta',  'Rafael Albuquerque Costa', '2025-12-05', 1),
(1, 'Protocolar reclamação trabalhista',           1, 'alta',  'Mariana Cardoso Pereira',  '2025-12-10', 2),
(1, 'Preparar petição de instrução',               0, 'alta',  'Mariana Cardoso Pereira',  '2026-05-20', 3),
(6, 'Preparar embargos à execução',               1, 'alta',  'Rafael Albuquerque Costa', '2025-12-18', 1),
(6, 'Requerer desbloqueio conta bancária',        1, 'alta',  'Rafael Albuquerque Costa', '2026-04-10', 2),
(8, 'Requerer extratos bancários do requerido',    0, 'alta',  'Juliana Ferreira Santos',  '2026-05-15', 1),
(10,'Preparar petição STJ',                        1, 'alta',  'Mariana Cardoso Pereira',  '2026-03-01', 1),
(10,'Protocolar HC no STJ',                        1, 'alta',  'Mariana Cardoso Pereira',  '2026-03-05', 2),
(14,'Catalogar todos os 47 reclamantes',           1, 'media', 'Pedro Henrique Oliveira',  '2026-01-10', 1),
(14,'Juntar laudos de insalubridade e perigo',    1, 'alta',  'Pedro Henrique Oliveira',  '2026-05-08', 2),
(14,'Preparar sustentação oral audiência',         0, 'alta',  'Mariana Cardoso Pereira',  '2026-05-20', 3);


-- =============================================================================
-- 13. TASK BOARDS E COLUNAS
-- =============================================================================
INSERT INTO `task_boards` (`id`, `account_id`, `nome`, `descricao`, `tipo`, `owner_id`, `cor`, `ordem`, `ativo`, `created_at`) VALUES
(1, 1, 'Jurídico',       'Tarefas de acompanhamento jurídico e processual', 'compartilhado', 1, '#6366f1', 1, 1, '2025-11-05 08:00:00'),
(2, 1, 'Administrativo', 'Gestão administrativa e rotinas do escritório',   'compartilhado', 2, '#10b981', 2, 1, '2025-11-05 08:30:00');

INSERT INTO `task_columns` (`id`, `board_id`, `nome`, `ordem`, `cor`, `is_coluna_inicial`, `is_coluna_concluido`) VALUES
-- Board 1 — Jurídico
(1, 1, 'A Fazer',       1, '#64748b', 1, 0),
(2, 1, 'Em Andamento',  2, '#0ea5e9', 0, 0),
(3, 1, 'Em Revisão',    3, '#f59e0b', 0, 0),
(4, 1, 'Concluído',     4, '#22c55e', 0, 1),
-- Board 2 — Administrativo
(5, 2, 'Pendente',      1, '#64748b', 1, 0),
(6, 2, 'Executando',    2, '#0ea5e9', 0, 0),
(7, 2, 'Finalizado',    3, '#22c55e', 0, 1);


-- =============================================================================
-- 14. TASKS (20 tarefas)
-- =============================================================================
INSERT INTO `tasks`
  (`id`, `board_id`, `column_id`, `titulo`, `descricao`, `prioridade`, `prazo`, `prazo_tipo`,
   `responsavel_id`, `criado_por_id`, `status`, `concluida_em`, `ordem`, `created_at`) VALUES

-- Board 1 — Jurídico / A Fazer (col 1)
(1,  1, 1, 'Peça inicial — Trabalhista Marcos Teixeira',
 'Elaborar petição inicial da reclamação trabalhista contra ex-empregador.',
 'alta', '2026-05-20 18:00:00', 'legal', 4, 3, 'ativa', NULL, 1, '2025-12-12 09:00:00'),

(2,  1, 1, 'Contestação — Execução Fiscal Construtora Horizonte',
 'Preparar contestação e embargos de segunda fase da execução fiscal.',
 'urgente', '2026-05-15 18:00:00', 'legal', 3, 2, 'ativa', NULL, 2, '2026-01-08 09:00:00'),

(3,  1, 1, 'Recurso — Previdenciário Amanda Lima',
 'Elaborar recurso após denegação parcial da sentença previdenciária.',
 'media', '2026-06-01 18:00:00', 'legal', 4, 3, 'ativa', NULL, 3, '2026-03-15 09:00:00'),

-- Board 1 — Jurídico / Em Andamento (col 2)
(4,  1, 2, 'Preparar para audiência — Divórcio Nascimento',
 'Levantar documentação, testemunhas e alegações para audiência de mediação.',
 'alta', '2026-05-14 12:00:00', 'legal', 3, 2, 'ativa', NULL, 1, '2026-04-10 09:00:00'),

(5,  1, 2, 'Protocolar memórias — Habeas Corpus Cavalcanti STJ',
 'Protocolar memoriais escritos no STJ até o prazo regimental.',
 'urgente', '2026-05-13 18:00:00', 'legal', 1, 1, 'ativa', NULL, 2, '2026-04-20 08:30:00'),

(6,  1, 2, 'Pesquisa jurisprudencial — Metalúrgica Castro',
 'Mapear jurisprudência TST sobre insalubridade em ambiente metalúrgico.',
 'baixa', '2026-05-25 18:00:00', 'interno', 6, 3, 'ativa', NULL, 3, '2026-04-25 09:00:00'),

(7,  1, 2, 'Notificação extrajudicial — TechSolutions',
 'Redigir e enviar notificação extrajudicial sobre inadimplemento contratual.',
 'media', '2026-05-18 18:00:00', 'interno', 5, 3, 'ativa', NULL, 4, '2026-04-28 09:00:00'),

-- Board 1 — Jurídico / Em Revisão (col 3)
(8,  1, 3, 'Revisar minuta de contrato — Clínica São Lucas',
 'Revisar cláusulas do novo contrato societário antes de enviar ao cliente.',
 'alta', '2026-05-16 18:00:00', 'interno', 3, 2, 'ativa', NULL, 1, '2026-05-01 09:00:00'),

(9,  1, 3, 'Checklist documentos — Inventário Correia',
 'Conferir documentação recebida contra checklist para regularização do inventário.',
 'media', '2026-05-20 18:00:00', 'interno', 5, 5, 'ativa', NULL, 2, '2026-05-02 09:00:00'),

-- Board 1 — Jurídico / Concluído (col 4)
(10, 1, 4, 'Procuração — Holding Familiar Fernanda Barbosa',
 'Redigir e autenticar procuração ad judicia et extra para a holding.',
 'alta', '2026-03-15 18:00:00', 'administrativo', 4, 2, 'concluida', '2026-03-12 16:00:00', 1, '2026-02-20 09:00:00'),

(11, 1, 4, 'Petição majoração de alimentos — Renata Dias',
 'Elaborar petição inicial de majoração de alimentos com provas da nova renda.',
 'urgente', '2026-02-28 18:00:00', 'legal', 3, 3, 'concluida', '2026-02-25 15:00:00', 2, '2026-01-20 09:00:00'),

(12, 1, 4, 'Contrato de honorários — Construtora Horizonte',
 'Formalizar contrato de honorários advocatícios para a defesa na execução fiscal.',
 'media', '2025-12-15 18:00:00', 'administrativo', 5, 2, 'concluida', '2025-12-13 14:00:00', 3, '2025-12-01 09:00:00'),

-- Board 2 — Administrativo / Pendente (col 5)
(13, 2, 5, 'Renovar certidão OAB — Rafael',
 'Verificar validade e renovar certidão de regularidade OAB de Rafael.',
 'media', '2026-06-30 18:00:00', 'administrativo', 4, 2, 'ativa', NULL, 1, '2026-04-01 09:00:00'),

(14, 2, 5, 'Atualizar contratos de honorários (revisão anual)',
 'Revisar e atualizar cláusulas de reajuste em todos os contratos ativos.',
 'baixa', '2026-05-31 18:00:00', 'administrativo', 1, 1, 'ativa', NULL, 2, '2026-04-15 09:00:00'),

-- Board 2 — Administrativo / Executando (col 6)
(15, 2, 6, 'Reunião de apresentação para novos clientes',
 'Preparar apresentação institucional do escritório para potenciais clientes.',
 'alta', '2026-05-15 18:00:00', 'administrativo', 3, 2, 'ativa', NULL, 1, '2026-05-01 09:00:00'),

(16, 2, 6, 'Controle de prazos processuais — maio/2026',
 'Revisar todos os prazos processuais de maio e atualizar agenda da equipe.',
 'urgente', '2026-05-11 18:00:00', 'legal', 1, 1, 'ativa', NULL, 2, '2026-05-04 08:00:00'),

(17, 2, 6, 'Comprar suprimentos de escritório',
 'Papel A4, cartuchos impressora, materiais básicos — aprovação já dada.',
 'baixa', '2026-05-13 18:00:00', 'administrativo', 5, 2, 'ativa', NULL, 3, '2026-05-05 09:00:00'),

-- Board 2 — Administrativo / Finalizado (col 7)
(18, 2, 7, 'Evento OAB — Congresso de Direito Trabalhista',
 'Inscrição e participação no congresso OAB/SP em abril/2026.',
 'media', '2026-04-20 18:00:00', 'administrativo', 4, 2, 'concluida', '2026-04-20 17:00:00', 1, '2026-03-15 09:00:00'),

(19, 2, 7, 'Declarar IR da sociedade — ano-base 2025',
 'Preparar e entregar declaração de IRPJ/CSLL da sociedade de advocacia.',
 'alta', '2026-04-30 23:59:00', 'legal', 1, 1, 'concluida', '2026-04-28 16:00:00', 2, '2026-03-01 09:00:00'),

(20, 2, 7, 'Backup completo do sistema jurídico',
 'Realizar backup de todos os documentos digitais e banco de dados.',
 'baixa', '2026-04-15 18:00:00', 'administrativo', 6, 2, 'concluida', '2026-04-14 15:00:00', 3, '2026-04-01 09:00:00');


-- =============================================================================
-- 15. TASK_CHECKLIST_ITEMS (checklists de algumas tarefas)
-- =============================================================================
INSERT INTO `task_checklist_items` (`task_id`, `descricao`, `concluido`, `ordem`, `prazo`) VALUES
-- Task 1 — Peça inicial trabalhista
(1, 'Reunir CTPS, contracheques e TRCT do cliente',          1, 1, '2026-05-10'),
(1, 'Calcular todas as verbas devidas (FGTS, férias, 13º)',   1, 2, '2026-05-12'),
(1, 'Redigir a petição inicial',                              0, 3, '2026-05-17'),
(1, 'Revisar e protocolar',                                   0, 4, '2026-05-20'),
-- Task 2 — Contestação execução fiscal
(2, 'Levantar documentos de regularidade fiscal',             1, 1, '2026-05-10'),
(2, 'Analisar prescrição dos créditos',                       1, 2, '2026-05-12'),
(2, 'Elaborar embargos segunda fase',                         0, 3, '2026-05-14'),
(2, 'Protocolar nos autos',                                   0, 4, '2026-05-15'),
-- Task 4 — Audiência divórcio
(4, 'Confirmar presença do cliente na audiência',             1, 1, '2026-05-12'),
(4, 'Preparar lista de bens a partilhar',                     1, 2, '2026-05-13'),
(4, 'Definir estratégia de guarda compartilhada',             0, 3, '2026-05-13'),
-- Task 5 — HC Cavalcanti
(5, 'Redigir memoriais escritos STJ',                         1, 1, '2026-05-11'),
(5, 'Protocolar via peticionamento eletrônico',               0, 2, '2026-05-13'),
-- Task 11 — Petição alimentos (concluída)
(11,'Calcular renda atual do requerido',                       1, 1, NULL),
(11,'Juntar extratos e holerites',                             1, 2, NULL),
(11,'Protocolar petição inicial',                              1, 3, NULL),
-- Task 16 — Controle de prazos
(16,'Revisar prazos processo trabalhista Teixeira',            1, 1, '2026-05-11'),
(16,'Revisar prazos execução fiscal Construtora',              1, 2, '2026-05-11'),
(16,'Revisar prazos ação coletiva Metalúrgica',                0, 3, '2026-05-11'),
(16,'Atualizar Google Agenda da equipe',                       0, 4, '2026-05-11');


-- =============================================================================
-- 16. DRE_ACCOUNTS (lançamentos financeiros — 8 receita + 12 despesa)
-- =============================================================================
INSERT INTO `dre_accounts`
  (`id`, `codigo`, `nome`, `tipo`, `valor_fixo`, `ativo`, `recorrencia`, `data_referencia`, `descricao`, `created_at`) VALUES

-- ── RECEITAS ─────────────────────────────────────────────────────────────────
(1,  'REC-001', 'Honorários Advocatícios — Ações Trabalhistas',
 'receita', 8500.00, 1, 'mensal', '2026-05-01',
 'Honorários mensais referentes aos processos trabalhistas em andamento.', '2025-11-01 08:00:00'),

(2,  'REC-002', 'Honorários Advocatícios — Ações Cíveis e Tributárias',
 'receita', 12000.00, 1, 'mensal', '2026-05-01',
 'Honorários mensais de ações cíveis, tributárias e empresariais.', '2025-11-01 08:00:00'),

(3,  'REC-003', 'Honorários de Êxito',
 'receita', 5200.00, 1, 'variavel', '2026-04-01',
 'Parcela de êxito recebida no processo inventário Correia (20%).', '2026-04-02 10:00:00'),

(4,  'REC-004', 'Consultas e Pareceres Jurídicos',
 'receita', 3800.00, 1, 'mensal', '2026-05-01',
 'Consultas avulsas e pareceres emitidos no mês.', '2025-11-01 08:00:00'),

(5,  'REC-005', 'Assessoria Contratual Mensal — Clínica São Lucas',
 'receita', 4500.00, 1, 'mensal', '2026-05-01',
 'Retainer mensal de assessoria jurídica contratual para a Clínica São Lucas.', '2026-03-01 09:00:00'),

(6,  'REC-006', 'Regularizações Cartoriais e Notariais',
 'receita', 2100.00, 1, 'variavel', '2026-05-01',
 'Valores recebidos de escrituras, procurações e registros cartoriais.', '2025-11-01 08:00:00'),

(7,  'REC-007', 'Taxas de Mediação e Conciliação Extrajudicial',
 'receita', 1800.00, 1, 'variavel', '2026-04-01',
 'Mediação entre construtora e proprietários de imóveis.', '2026-04-10 11:00:00'),

(8,  'REC-008', 'Reembolso de Custas Judiciais Adiantadas',
 'receita', 950.00, 1, 'variavel', '2026-05-01',
 'Reembolso de custas adiantadas pelo escritório — guias, taxas, certidões.', '2025-11-01 08:00:00'),

-- ── DESPESAS ─────────────────────────────────────────────────────────────────
(9,  'DEP-001', 'Aluguel do Escritório — Pinheiros/SP',
 'despesa', 3200.00, 1, 'mensal', '2026-05-01',
 'Aluguel da sala comercial 120m² + IPTU proporcional. Reajuste anual IGPM.', '2025-11-01 08:00:00'),

(10, 'DEP-002', 'Salários — Equipe Jurídica',
 'despesa', 18500.00, 1, 'mensal', '2026-05-01',
 'Folha de pagamento: 2 advogados + 1 paralegal + 1 estagiário.', '2025-11-01 08:00:00'),

(11, 'DEP-003', 'Encargos Sociais e Trabalhistas',
 'despesa', 6200.00, 1, 'mensal', '2026-05-01',
 'INSS patronal, FGTS, vale-transporte, vale-refeição da equipe.', '2025-11-01 08:00:00'),

(12, 'DEP-004', 'Software Jurídico e Licenças',
 'despesa', 890.00, 1, 'mensal', '2026-05-01',
 'Licença sistema CRM + PJ online + Thomson Reuters + storage em nuvem.', '2025-11-01 08:00:00'),

(13, 'DEP-005', 'Marketing Digital e Redes Sociais',
 'despesa', 1500.00, 1, 'mensal', '2026-05-01',
 'Gestão de redes sociais, Google Ads e produção de conteúdo jurídico.', '2026-01-01 08:00:00'),

(14, 'DEP-006', 'Energia Elétrica e Condomínio',
 'despesa', 780.00, 1, 'mensal', '2026-05-01',
 'Energia elétrica + taxa de condomínio comercial.', '2025-11-01 08:00:00'),

(15, 'DEP-007', 'Custas Judiciais Adiantadas',
 'despesa', 2300.00, 1, 'variavel', '2026-05-01',
 'Guias de custas judiciais, intimações e diligências adiantadas pelo escritório.', '2025-11-01 08:00:00'),

(16, 'DEP-008', 'Material de Escritório e Papelaria',
 'despesa', 350.00, 1, 'variavel', '2026-05-01',
 'Papel, toner, pasta, etiquetas e demais suprimentos.', '2025-11-01 08:00:00'),

(17, 'DEP-009', 'Honorários Contador',
 'despesa', 750.00, 1, 'mensal', '2026-05-01',
 'Escritório de contabilidade — folha + obrigações fiscais mensais.', '2025-11-01 08:00:00'),

(18, 'DEP-010', 'Internet e Telefonia',
 'despesa', 320.00, 1, 'mensal', '2026-05-01',
 'Fibra ótica 500MB + planos corporativos de celular da equipe.', '2025-11-01 08:00:00'),

(19, 'DEP-011', 'Viagens e Deslocamentos',
 'despesa', 680.00, 1, 'variavel', '2026-04-01',
 'Passagens + hospedagem para audiências em outras comarcas (Campinas, Santos, BH).', '2026-04-05 10:00:00'),

(20, 'DEP-012', 'Cursos, Capacitação e OAB',
 'despesa', 1200.00, 1, 'variavel', '2026-04-01',
 'Congresso OAB Trabalhista + curso tributário online + anuidade OAB equipe.', '2026-04-20 11:00:00');


-- =============================================================================
-- 17. GOALS (meta mensal de receita)
-- =============================================================================
INSERT INTO `goals` (`id`, `account_id`, `user_id`, `valor_meta`, `tipo_meta`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 50000.00, 'global', '2026-01-01 08:00:00', '2026-05-01 08:00:00');


-- =============================================================================
-- 18. WHATSAPP INSTANCE + CHATS + MENSAGENS
-- =============================================================================
INSERT INTO `whatsapp_instances`
  (`id`, `account_id`, `instance_name`, `display_name`, `status`, `phone`, `profile_name`, `evolution_token`, `created_at`) VALUES
(1, 1, 'monteiro-escritorio', 'Monteiro & Associados — WhatsApp',
 'open', '5511991234567', 'Monteiro Advocacia',
 'evo_token_demo_2026_monteiro', '2025-11-10 09:00:00');

-- Contatos WhatsApp (5 contatos vinculados aos contatos principais)
INSERT INTO `whatsapp_contacts`
  (`id`, `instance_id`, `remote_jid`, `push_name`, `phone`, `is_favorite`,
   `linked_card_id`, `linked_processo_id`, `created_at`) VALUES
(1, 1, '5511998234571@s.whatsapp.net', 'Marcos Teixeira',    '5511998234571', 1, 1,  1,  '2025-11-10 10:00:00'),
(2, 1, '5521987413209@s.whatsapp.net', 'Amanda Lima',         '5521987413209', 0, 3,  2,  '2025-11-15 14:00:00'),
(3, 1, '5511978345590@s.whatsapp.net', 'Ricardo Souza',       '5511978345590', 0, 9,  4,  '2025-12-05 15:00:00'),
(4, 1, '5511990347782@s.whatsapp.net', 'Luciana Santos',      '5511990347782', 0, NULL, NULL, '2025-12-18 09:00:00'),
(5, 1, '5511030294411@s.whatsapp.net', 'Construtora Horiz.',  '5511030294411', 1, 2,  6,  '2025-11-12 11:00:00');

-- Chats WhatsApp
INSERT INTO `whatsapp_chats`
  (`id`, `instance_id`, `remote_jid`, `contact_name`, `phone`,
   `last_message_content`, `last_message_type`, `last_message_at`, `last_message_from_me`,
   `unread_count`, `is_pinned`, `linked_card_id`, `linked_processo_id`, `contato_id`, `created_at`) VALUES
(1, 1, '5511998234571@s.whatsapp.net', 'Marcos Vinícius Teixeira', '5511998234571',
 'Perfeito, vou separar os documentos ainda hoje.', 'text', '2026-05-09 14:32:00', 0,
 0, 1, 1, 1, 1, '2025-11-10 10:30:00'),
(2, 1, '5521987413209@s.whatsapp.net', 'Amanda Rodrigues Lima', '5521987413209',
 'Dra. Mariana, quando sai a decisão?', 'text', '2026-05-10 09:15:00', 0,
 2, 0, 3, 2, 2, '2025-11-15 15:00:00'),
(3, 1, '5511978345590@s.whatsapp.net', 'Ricardo Henrique Souza', '5511978345590',
 'Obrigado Dr. Rafael! Aguardo o retorno.', 'text', '2026-05-11 08:44:00', 0,
 0, 0, 9, 4, 5, '2025-12-06 10:00:00'),
(4, 1, '5511990347782@s.whatsapp.net', 'Luciana Melo Santos', '5511990347782',
 'Pode me ligar esta semana para conversarmos?', 'text', '2026-05-06 16:20:00', 0,
 1, 0, NULL, NULL, 8, '2025-12-18 09:30:00'),
(5, 1, '5511030294411@s.whatsapp.net', 'Construtora Horizonte LTDA', '5511030294411',
 'Combinado. Reunião na quinta às 14h.', 'text', '2026-05-04 17:05:00', 1,
 0, 1, 2, 6, 21, '2025-11-12 11:30:00');

-- Mensagens WhatsApp (10 por chat = 50 mensagens)
INSERT INTO `whatsapp_messages`
  (`instance_id`, `remote_jid`, `contact_name`, `phone`, `message_type`, `message_content`,
   `direction`, `status`, `created_at`) VALUES

-- Chat 1 — Marcos Teixeira
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Boa tarde, Dr.! Quero saber como está meu processo.',
 'inbound','read','2025-11-10 10:32:00'),
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Boa tarde, Marcos! Seu processo foi protocolado. Estamos aguardando distribuição.',
 'outbound','read','2025-11-10 10:45:00'),
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Quanto tempo leva em média?',
 'inbound','read','2025-11-10 11:00:00'),
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Geralmente 30-60 dias para a primeira audiência. Fique tranquilo, estamos acompanhando.',
 'outbound','read','2025-11-10 11:10:00'),
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Marcos, você pode me enviar a CTPS pelo WhatsApp para digitalizar?',
 'outbound','read','2026-01-15 14:00:00'),
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Claro, vou fotografar e mandar agora.',
 'inbound','read','2026-01-15 14:15:00'),
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Recebemos! A audiência de instrução foi marcada para 25/05. Confirma presença?',
 'outbound','read','2026-04-20 10:00:00'),
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Sim, confirmo! Preciso levar mais alguma documentação?',
 'inbound','read','2026-04-20 10:30:00'),
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Pode vir apenas com RG e CPF. Vamos orientá-lo presencialmente antes.',
 'outbound','read','2026-04-20 11:00:00'),
(1,'5511998234571@s.whatsapp.net','Marcos Vinícius Teixeira','5511998234571','text',
 'Perfeito, vou separar os documentos ainda hoje.',
 'inbound','read','2026-05-09 14:32:00'),

-- Chat 2 — Amanda Lima
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'Olá, gostaria de saber sobre minha aposentadoria.',
 'inbound','read','2025-11-15 15:10:00'),
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'Olá Amanda! Sua ação foi distribuída ao JEF. Número: 0004567-83.2025.',
 'outbound','read','2025-11-15 15:30:00'),
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'A perícia foi agendada. Você receberá comunicação pelo correio também.',
 'outbound','read','2026-02-10 09:00:00'),
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'Ok! Qual médico vou ver na perícia?',
 'inbound','read','2026-02-10 09:20:00'),
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'Será o perito do juízo, independente. Leve todos os laudos e relatórios médicos.',
 'outbound','read','2026-02-10 09:35:00'),
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'A perícia foi realizada com sucesso. Aguardamos laudo em 30 dias.',
 'outbound','read','2026-03-10 15:00:00'),
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'Que alívio! Muito obrigada Dra. Juliana!',
 'inbound','read','2026-03-10 15:20:00'),
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'O laudo do perito foi favorável! Estamos otimistas. Aguardando sentença.',
 'outbound','read','2026-04-15 11:00:00'),
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'Dra. Mariana, quando sai a decisão?',
 'inbound','read','2026-05-10 09:15:00'),
(1,'5521987413209@s.whatsapp.net','Amanda Rodrigues Lima','5521987413209','text',
 'Estamos acompanhando diariamente. Assim que sair, avisamos imediatamente!',
 'outbound','delivered','2026-05-10 09:30:00'),

-- Chat 3 — Ricardo Souza
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Bom dia! Quando podemos começar com a questão da usucapião?',
 'inbound','read','2025-12-06 08:30:00'),
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Bom dia Ricardo! Já protocolamos a ação. Número: 0005678-12.2026.',
 'outbound','read','2026-01-16 10:00:00'),
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Os confrontantes foram intimados. Prazo de contestação: 15 dias.',
 'outbound','read','2026-02-05 14:00:00'),
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Dois dos confrontantes apresentaram contestação. Estamos preparando a réplica.',
 'outbound','read','2026-03-01 10:00:00'),
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Que impacto isso tem no prazo, Dr. Rafael?',
 'inbound','read','2026-03-01 11:00:00'),
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Adiciona ~90 dias ao processo, mas é normal. Não muda o mérito da ação.',
 'outbound','read','2026-03-01 11:30:00'),
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Réplica protocolada. Aguardamos designação de audiência.',
 'outbound','read','2026-04-01 14:00:00'),
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Entendido. A proposta de honorários cobre até o final?',
 'inbound','read','2026-04-20 09:00:00'),
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Sim, o contrato cobre até o trânsito em julgado, inclusive eventuais recursos.',
 'outbound','read','2026-04-20 10:00:00'),
(1,'5511978345590@s.whatsapp.net','Ricardo Henrique Souza','5511978345590','text',
 'Obrigado Dr. Rafael! Aguardo o retorno.',
 'inbound','read','2026-05-11 08:44:00'),

-- Chat 4 — Luciana Santos
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Boa tarde! Vi a indicação de vocês de uma amiga. Preciso de assessoria jurídica.',
 'inbound','read','2025-12-18 09:30:00'),
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Olá Luciana! Seja bem-vinda. Pode me contar mais sobre o que você precisa?',
 'outbound','read','2025-12-18 10:00:00'),
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Tenho uma empresa pequena e quero regularizar tudo. Contratos, CNPJ, etc.',
 'inbound','read','2025-12-18 10:30:00'),
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Perfeito! Trabalhamos com assessoria empresarial. Vou agendar uma reunião.',
 'outbound','read','2025-12-18 10:45:00'),
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Ótimo! Qual o valor de uma assessoria mensal?',
 'inbound','read','2026-01-10 14:00:00'),
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Depende do porte. Para MEI/ME, trabalhamos a partir de R$ 850/mês. Posso te enviar uma proposta.',
 'outbound','read','2026-01-10 14:30:00'),
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Por favor! Meu e-mail é luciana.santos@gmail.com',
 'inbound','read','2026-01-10 15:00:00'),
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Proposta enviada no e-mail! Qualquer dúvida, só falar.',
 'outbound','read','2026-01-11 09:00:00'),
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Recebi! Vou analisar e dou um retorno.',
 'inbound','read','2026-01-11 10:00:00'),
(1,'5511990347782@s.whatsapp.net','Luciana Melo Santos','5511990347782','text',
 'Pode me ligar esta semana para conversarmos?',
 'inbound','read','2026-05-06 16:20:00'),

-- Chat 5 — Construtora Horizonte
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Bom dia! Precisamos falar sobre a execução fiscal com urgência.',
 'inbound','read','2025-11-12 11:30:00'),
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Bom dia! Já recebi o auto. Vamos preparar os embargos. Pode reunir amanhã às 14h?',
 'outbound','read','2025-11-12 11:45:00'),
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Sim, estaremos lá. Dr. Rafael, temos documentos para entregar.',
 'inbound','read','2025-11-12 12:00:00'),
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Os embargos foram protocolados com sucesso. A execução está suspensa.',
 'outbound','read','2025-12-21 10:00:00'),
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Que alívio! E quanto à penhora da conta?',
 'inbound','read','2025-12-21 10:30:00'),
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Infelizmente a penhora online foi decretada: R$ 50k bloqueados. Estamos pedindo desbloqueio.',
 'outbound','read','2026-03-01 14:00:00'),
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Isso impacta nosso fluxo de caixa. Quando sai a decisão?',
 'inbound','read','2026-03-01 14:30:00'),
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Prazo do juiz é de 30 dias. Estamos acompanhando diariamente.',
 'outbound','read','2026-03-01 15:00:00'),
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Boa notícia! Desbloqueio dos R$ 50k foi deferido. Depósito em 48h úteis.',
 'outbound','read','2026-04-21 09:00:00'),
(1,'5511030294411@s.whatsapp.net','Construtora Horizonte LTDA','5511030294411','text',
 'Combinado. Reunião na quinta às 14h.',
 'inbound','read','2026-05-04 17:05:00');


-- =============================================================================
-- 19. ACCOUNT_NOTIFICATIONS (notificações internas de demonstração)
-- =============================================================================
INSERT INTO `account_notifications`
  (`account_id`, `user_id`, `tipo`, `titulo`, `mensagem`, `lida`, `created_at`) VALUES
(1, 3, 'prazo',   'Audiência amanhã — Divórcio Nascimento',
 'Audiência de mediação familiar marcada para 30/05. Confirmar presença do cliente.',
 0, '2026-05-10 08:00:00'),
(1, 4, 'prazo',   'Prazo urgente — Embargos Construtora Horizonte',
 'Prazo para protocolar contrarrazões dos embargos vence em 20/05.',
 0, '2026-05-10 08:00:00'),
(1, 1, 'sistema', 'Novo card criado — Recuperação de Crédito',
 'Rafael cadastrou novo card: Recuperação de Crédito — Distribuidora Global LTDA.',
 1, '2026-02-12 09:35:00'),
(1, 2, 'vinculo', 'Proposta aceita — Metalúrgica Castro',
 'Cliente Metalúrgica Castro confirmou interesse na proposta de defesa ambiental.',
 1, '2026-02-28 10:00:00'),
(1, 3, 'sistema', 'Processo concluído — Inventário Felipe Moreira',
 'Inventário extrajudicial concluído com sucesso. Escritura lavrada.',
 1, '2026-05-02 11:05:00');


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- RESUMO DOS DADOS INSERIDOS:
--   accounts:                  1 registro
--   pipeline_columns:          5 colunas
--   users:                     6 usuários (1 admin + 5 equipe)
--   user_permissions:         30 permissões
--   contatos:                 30 (20 PF + 10 PJ)
--   cards:                    25 (8 Prosp. + 6 Prop. + 5 Neg. + 4 Fech. + 2 Perd.)
--   card_checklist_items:     27 itens em 7 cards
--   card_history:             26 movimentações
--   processos:                15 processos com numero_cnj
--   processo_prazos:          15 prazos processuais
--   processo_history:         18 movimentações
--   processo_tarefas:         11 tarefas vinculadas a processos
--   contato_vinculos:         34 vínculos contato ↔ card/processo
--   task_boards:               2 quadros
--   task_columns:              7 colunas
--   tasks:                    20 tarefas (variados status e prioridades)
--   task_checklist_items:     19 itens
--   dre_accounts:             20 (8 receita + 12 despesa)
--   goals:                     1 meta global R$ 50.000/mês
--   whatsapp_instances:        1 instância conectada
--   whatsapp_contacts:         5 contatos WhatsApp
--   whatsapp_chats:            5 conversas
--   whatsapp_messages:        50 mensagens (10/conversa)
--   account_notifications:     5 notificações internas
-- =============================================================================
