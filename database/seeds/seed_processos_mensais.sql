-- seed_processos_mensais.sql
-- Adiciona ~8 processos por mês (nov/2025 – mai/2026), vinculados a contatos,
-- com tipo_acao, numero_cnj e responsáveis variados.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `processos`
  (`id`, `account_id`, `numero`, `numero_cnj`, `cliente_nome`, `parte_contraria`,
   `cpf_cnpj_parte_contraria`, `tipo_acao`, `vara_comarca`, `responsavel_user_id`,
   `status`, `data_inicio`, `proximo_prazo`, `ultima_movimentacao`,
   `observacoes`, `card_id`, `contato_id`, `created_at`) VALUES

-- ══════════════════════════════════════════════════════════════
-- NOVEMBRO 2025 (completar até 8 — já existem IDs 1, 3, 6)
-- ══════════════════════════════════════════════════════════════
(16, 1, '00034512-1/2025', '0003451-21.2025.5.02.0005',
 'Thiago Augusto Pereira', 'Metalúrgica Omega LTDA',
 '33.445.556/0001-78', 'Trabalhista',
 '5ª Vara do Trabalho de São Paulo/SP', 4,
 'ativo', '2025-11-03', '2026-06-15', '2026-04-28 09:00:00',
 'Demissão por justa causa contestada. Reintegração + indenização por danos morais.',
 NULL, 7, '2025-11-03 09:00:00'),

(17, 1, '00045678-9/2025', '0004567-89.2025.8.26.0100',
 'Marcia Regina Fonseca', 'Construtora Delta LTDA',
 '44.556.667/0001-81', 'Imobiliário',
 '10ª Vara Cível de São Paulo/SP', 3,
 'ativo', '2025-11-07', '2026-06-20', '2026-05-02 10:00:00',
 'Ação de rescisão contratual de compra de imóvel na planta. Devolução integral + multa.',
 NULL, 16, '2025-11-07 10:00:00'),

(18, 1, '00056789-3/2025', '0005678-93.2025.8.26.0100',
 'Luciana Melo Santos', 'Santos & Ramos Participações LTDA',
 '55.667.778/0001-94', 'Societário',
 '1ª Vara Empresarial de São Paulo/SP', 2,
 'ativo', '2025-11-13', '2026-07-01', '2026-04-10 11:00:00',
 'Dissolução parcial de sociedade. Apuração de haveres e saída de sócio minoritário.',
 NULL, 8, '2025-11-13 10:00:00'),

(19, 1, '00067890-7/2025', '0006789-07.2025.3.82.9999',
 'André Luís Carvalho', 'INSS — Instituto Nacional do Seguro Social',
 '29.979.036/0001-40', 'Previdenciário',
 'JEF — Juizado Especial Federal de Brasília/DF', 5,
 'ativo', '2025-11-19', '2026-06-10', '2026-03-25 14:00:00',
 'Aposentadoria especial por exposição a agentes nocivos. Perícia agendada.',
 NULL, 17, '2025-11-19 09:00:00'),

(20, 1, '00078901-1/2025', '0007890-11.2025.8.26.0100',
 'Carla Beatriz Nunes', 'Banco Crédito Fácil S/A',
 '60.701.190/0002-85', 'Consumidor',
 '4ª Vara Cível de São Paulo/SP', 3,
 'concluido', '2025-11-25', NULL, '2026-02-10 15:00:00',
 'Ação de revisão de cláusulas abusivas em contrato de financiamento. Acordo homologado.',
 NULL, 20, '2025-11-25 10:00:00'),

-- ══════════════════════════════════════════════════════════════
-- DEZEMBRO 2025 (completar até 8 — já existem IDs 2, 5, 7, 14)
-- ══════════════════════════════════════════════════════════════
(21, 1, '00089012-5/2025', '0008901-25.2025.8.19.0038',
 'Rodrigo Batista Mendes', 'Imobiliária Centro RJ LTDA',
 '77.889.990/0001-07', 'Imobiliário',
 '3ª Vara Cível do Rio de Janeiro/RJ', 4,
 'ativo', '2025-12-03', '2026-07-15', '2026-04-20 10:00:00',
 'Ação revisional de aluguel comercial. Índice de reajuste IGPM contestado.',
 NULL, 19, '2025-12-03 10:00:00'),

(22, 1, '00090123-9/2025', '0009012-39.2025.5.02.0012',
 'Clínica São Lucas LTDA', 'Maria Aparecida Corrêa',
 '623.410.870-19', 'Trabalhista',
 '12ª Vara do Trabalho de São Paulo/SP', 3,
 'ativo', '2025-12-09', '2026-05-28', '2026-05-01 09:00:00',
 'Reclamação de ex-funcionária. Horas extras e assédio moral. Contestação protocolada.',
 NULL, 23, '2025-12-09 10:00:00'),

(23, 1, '00001234-3/2025', '0000123-43.2025.8.26.0482',
 'Fernanda Cristina Barbosa', 'Receita Federal do Brasil',
 '00.394.460/0001-41', 'Tributário',
 '1ª Vara Federal de Campinas/SP', 4,
 'ativo', '2025-12-12', '2026-06-30', '2026-04-15 14:00:00',
 'Mandado de segurança para suspender cobrança de IRPF sobre dividendos. Liminar deferida.',
 NULL, 4, '2025-12-12 09:00:00'),

(24, 1, '00012345-7/2025', '0001234-57.2025.8.26.0100',
 'Tatiana Cristina Lopes', 'Distribuidora Sudeste LTDA',
 '66.778.889/0001-10', 'Trabalhista',
 '8ª Vara do Trabalho de São Paulo/SP', 5,
 'concluido', '2025-12-18', NULL, '2026-03-20 15:00:00',
 'Rescisão indireta por falta de pagamento. Acordo extrajudicial R$ 28k firmado.',
 NULL, 18, '2025-12-18 10:00:00'),

-- ══════════════════════════════════════════════════════════════
-- JANEIRO 2026 (completar até 8 — já existem IDs 4,8,9,10,11,12,15)
-- ══════════════════════════════════════════════════════════════
(25, 1, '00023456-1/2026', '0002345-61.2026.8.26.0100',
 'Advocacia & Associados SS', 'Ex-Cliente Rodrigues ME',
 '88.990.001/0001-23', 'Cível',
 '15ª Vara Cível de São Paulo/SP', 2,
 'ativo', '2026-01-07', '2026-08-01', '2026-04-30 10:00:00',
 'Cobrança de honorários não pagos R$ 45k. Réu citado, prazo de contestação em curso.',
 NULL, 26, '2026-01-07 09:00:00'),

-- ══════════════════════════════════════════════════════════════
-- FEVEREIRO 2026 (completar até 8 — já existe ID 13)
-- ══════════════════════════════════════════════════════════════
(26, 1, '00034567-5/2026', '0003456-75.2026.5.02.0001',
 'Marcos Vinícius Teixeira', 'Empresa XYZ Comércio e Serviços LTDA',
 '11.223.334/0001-81', 'Trabalhista',
 '1ª Vara do Trabalho de São Paulo/SP — Execução', 3,
 'ativo', '2026-02-02', '2026-06-05', '2026-05-03 09:00:00',
 'Execução de sentença trabalhista. Empresa em processo de penhora de bens.',
 1, 1, '2026-02-02 09:00:00'),

(27, 1, '00045678-9/2026', '0004567-89.2026.8.19.0001',
 'Distribuidora Global LTDA', 'Supermercados Pão & Queijo LTDA',
 '99.001.112/0001-36', 'Cível',
 '7ª Vara Cível do Rio de Janeiro/RJ', 4,
 'ativo', '2026-02-06', '2026-07-20', '2026-04-22 11:00:00',
 'Ação monitória para cobrança de R$ 178k em mercadorias não pagas. Liminar deferida.',
 7, 30, '2026-02-06 10:00:00'),

(28, 1, '00056789-3/2026', '0005678-93.2026.8.26.0482',
 'Ricardo Henrique Souza', 'Prefeitura Municipal de Presidente Prudente',
 NULL, 'Administrativo',
 '2ª Vara da Fazenda Pública de Presidente Prudente/SP', 4,
 'ativo', '2026-02-10', '2026-07-10', '2026-04-18 14:00:00',
 'Mandado de segurança contra tombamento indevido de área rural. Liminar em análise.',
 NULL, 5, '2026-02-10 09:00:00'),

(29, 1, '00067890-7/2026', '0006789-07.2026.8.26.0100',
 'Marcia Regina Fonseca', 'Filhos e cônjuge — Partilha amigável',
 NULL, 'Família e Sucessões',
 'Cartório de Notas — Escritura Pública São Paulo/SP', 3,
 'ativo', '2026-02-12', '2026-06-30', '2026-05-05 10:00:00',
 'Testamento público + doação em vida de imóvel para filhos. Planejamento sucessório.',
 NULL, 16, '2026-02-12 09:00:00'),

(30, 1, '00078901-1/2026', '0007890-11.2026.5.02.0005',
 'Tatiana Cristina Lopes', 'Grupo Comercial Lopes LTDA',
 '77.889.001/0001-49', 'Trabalhista',
 '5ª Vara do Trabalho de São Paulo/SP', 5,
 'ativo', '2026-02-15', '2026-06-12', '2026-04-25 11:00:00',
 'Reclamação de ex-gerente comercial. Verbas variáveis + participação nos lucros.',
 NULL, 18, '2026-02-15 10:00:00'),

(31, 1, '00089012-5/2026', '0008901-25.2026.8.26.0100',
 'Comércio Estrela do Norte LTDA', 'SUFRAMA — Superintendência Franca Amazônica',
 NULL, 'Administrativo',
 '1ª Vara Federal de Manaus/AM', 2,
 'ativo', '2026-02-18', '2026-08-15', '2026-04-30 14:00:00',
 'Impugnação de ato administrativo de inabilitação em licitação. Recurso administrativo.',
 16, 25, '2026-02-18 09:00:00'),

(32, 1, '00090123-9/2026', '0009012-39.2026.8.26.0100',
 'Transportadora Rápida MEI', 'Banco Itau S/A + 3 Fornecedores',
 NULL, 'Societário',
 '1ª Vara de Recuperações Judiciais de Curitiba/PR', 4,
 'ativo', '2026-02-22', '2026-09-01', '2026-05-02 09:00:00',
 'Pedido de recuperação judicial deferido. Plano de recuperação em elaboração.',
 14, 24, '2026-02-22 09:00:00'),

-- ══════════════════════════════════════════════════════════════
-- MARÇO 2026
-- ══════════════════════════════════════════════════════════════
(33, 1, '00101234-3/2026', '0010123-43.2026.8.05.0001',
 'Patrícia Gonçalves Martins', 'João Carlos Alves de Souza',
 '391.847.290-55', 'Família e Sucessões',
 '1ª Vara de Família de Salvador/BA', 3,
 'ativo', '2026-03-02', '2026-07-20', '2026-05-04 10:00:00',
 'Revisão de alimentos. Pai com renda triplicada após mudança de emprego.',
 NULL, 6, '2026-03-02 09:00:00'),

(34, 1, '00112345-7/2026', '0011234-57.2026.8.26.0100',
 'Gustavo Henrique Correia', 'Márcia Souza Correia',
 '492.817.300-48', 'Família e Sucessões',
 'Cartório 5º Ofício São Paulo/SP — Divórcio Extrajudicial', 4,
 'concluido', '2026-03-05', NULL, '2026-04-28 15:00:00',
 'Divórcio extrajudicial consensual. Partilha de imóvel e veículo. Escritura lavrada.',
 NULL, 11, '2026-03-05 09:00:00'),

(35, 1, '00123456-1/2026', '0012345-61.2026.8.26.0100',
 'TechSolutions Informática SA', 'StartupX Tecnologia LTDA',
 '22.334.445/0001-56', 'Proprietário Intelectual',
 '1ª Vara de Propriedade Intelectual de São Paulo/SP', 2,
 'ativo', '2026-03-08', '2026-09-01', '2026-05-01 11:00:00',
 'Ação por violação de patente de software. Liminar para suspender uso solicitada.',
 6, 22, '2026-03-08 09:00:00'),

(36, 1, '00134567-5/2026', '0013456-75.2026.3.80.9999',
 'Amanda Rodrigues Lima', 'INSS — Instituto Nacional do Seguro Social',
 '29.979.036/0001-40', 'Previdenciário',
 'JEF — Juizado Especial Federal do Rio de Janeiro/RJ', 5,
 'ativo', '2026-03-10', '2026-06-25', '2026-04-20 14:00:00',
 'Benefício de Prestação Continuada (BPC/LOAS). Perícia social agendada.',
 NULL, 2, '2026-03-10 10:00:00'),

(37, 1, '00145678-9/2026', '0014567-89.2026.8.26.0100',
 'Indústria Metalúrgica Castro SA', 'CETESB / IBAMA',
 NULL, 'Ambiental',
 '1ª Vara do Meio Ambiente de São Paulo/SP', 3,
 'ativo', '2026-03-13', '2026-08-10', '2026-05-05 09:00:00',
 'Anulatória de auto de infração ambiental R$ 1,2MM. Laudo técnico favorável obtido.',
 11, 29, '2026-03-13 09:00:00'),

(38, 1, '00156789-3/2026', '0015678-93.2026.8.19.0038',
 'Rodrigo Batista Mendes', 'Condomínio Edifício Solar das Pedras',
 NULL, 'Cível',
 '5ª Vara Cível do Rio de Janeiro/RJ', 4,
 'concluido', '2026-03-15', NULL, '2026-05-02 15:00:00',
 'Ação de cobrança de cotas condominiais atrasadas. Acordo firmado + parcelamento.',
 NULL, 19, '2026-03-15 09:00:00'),

(39, 1, '00167890-7/2026', '0016789-07.2026.5.02.0001',
 'Thiago Augusto Pereira', 'Fábrica Metalúrgica Omega LTDA',
 '33.445.556/0001-78', 'Trabalhista',
 '1ª Vara do Trabalho de São Paulo/SP', 4,
 'ativo', '2026-03-18', '2026-06-25', '2026-04-30 11:00:00',
 'Acidente de trabalho com perda parcial de movimento. Danos materiais + morais.',
 NULL, 7, '2026-03-18 09:00:00'),

(40, 1, '00178901-1/2026', '0017890-11.2026.8.26.0100',
 'Imobiliária Planalto LTDA', 'Prefeitura do Distrito Federal',
 NULL, 'Imobiliário',
 '2ª Vara da Fazenda Pública de Brasília/DF', 2,
 'ativo', '2026-03-22', '2026-08-20', '2026-05-03 14:00:00',
 'Anulatória de embargo de obras em 12 unidades. Alvará irregular contestado.',
 4, 27, '2026-03-22 09:00:00'),

-- ══════════════════════════════════════════════════════════════
-- ABRIL 2026
-- ══════════════════════════════════════════════════════════════
(41, 1, '00189012-5/2026', '0018901-25.2026.8.26.0100',
 'Carla Beatriz Nunes', 'Escola Particular Horizonte LTDA',
 '88.990.112/0001-69', 'Consumidor',
 '6ª Vara Cível de São Paulo/SP', 3,
 'ativo', '2026-04-02', '2026-09-15', '2026-05-05 10:00:00',
 'Ação de dano moral por cobrança indevida de taxa escolar. Suspensão de matrícula.',
 NULL, 20, '2026-04-02 09:00:00'),

(42, 1, '00190123-9/2026', '0019012-39.2026.8.13.0079',
 'Carlos Eduardo Nascimento', 'Paulo Henrique Nascimento',
 '512.736.490-81', 'Família e Sucessões',
 '1ª Vara de Família de Belo Horizonte/MG', 3,
 'ativo', '2026-04-05', '2026-09-01', '2026-05-02 11:00:00',
 'Ação de reconhecimento e dissolução de união estável. Partilha de imóvel comercial.',
 NULL, 3, '2026-04-05 09:00:00'),

(43, 1, '00201234-3/2026', '0020123-43.2026.8.26.0050',
 'Diego Ferreira Cavalcanti', 'Ministério Público de Pernambuco',
 NULL, 'Criminal',
 'Tribunal de Justiça de Pernambuco — Progressão de Regime', 1,
 'ativo', '2026-04-07', '2026-07-30', '2026-05-04 09:00:00',
 'Pedido de progressão de regime semiaberto. Exame criminológico favorável.',
 NULL, 9, '2026-04-07 09:00:00'),

(44, 1, '00212345-7/2026', '0021234-57.2026.8.26.0100',
 'Consultoria Primus LTDA', 'Empresa Beta Consultoria LTDA',
 '66.778.890/0001-17', 'Societário',
 '1ª Vara Empresarial de São Paulo/SP', 2,
 'ativo', '2026-04-09', '2026-09-20', '2026-05-03 14:00:00',
 'Segunda fase da dissolução societária. Apuração de haveres e avaliação patrimonial.',
 10, 28, '2026-04-09 09:00:00'),

(45, 1, '00223456-1/2026', '0022345-61.2026.4.01.3400',
 'André Luís Carvalho', 'Ministério da Gestão e Inovação',
 NULL, 'Administrativo',
 '1ª Vara Federal de Brasília/DF', 5,
 'ativo', '2026-04-11', '2026-09-01', '2026-05-01 10:00:00',
 'Mandado de segurança contra desclassificação em licitação de TI R$ 3,2MM.',
 NULL, 17, '2026-04-11 09:00:00'),

(46, 1, '00234567-5/2026', '0023456-75.2026.8.26.0100',
 'Luciana Melo Santos', 'Junta Comercial do Estado de São Paulo — JUCESP',
 NULL, 'Administrativo',
 '1ª Vara da Fazenda Pública de São Paulo/SP', 5,
 'ativo', '2026-04-14', '2026-08-30', '2026-04-30 11:00:00',
 'MS para regularização de nome empresarial. JUCESP negou registro por similaridade.',
 NULL, 8, '2026-04-14 09:00:00'),

(47, 1, '00245678-9/2026', '0024567-89.2026.3.82.9999',
 'Leonardo José Andrade', 'INSS — Instituto Nacional do Seguro Social',
 '29.979.036/0001-40', 'Previdenciário',
 'JEF — Juizado Especial Federal de Campinas/SP', 5,
 'ativo', '2026-04-16', '2026-09-10', '2026-05-02 14:00:00',
 'Ação previdenciária de aposentadoria por contribuição. Retomada após novo escritório.',
 NULL, 15, '2026-04-16 09:00:00'),

(48, 1, '00256789-3/2026', '0025678-93.2026.8.26.0100',
 'Construtora Horizonte LTDA', 'Município de São Paulo',
 NULL, 'Administrativo',
 '3ª Vara da Fazenda Pública de São Paulo/SP', 4,
 'ativo', '2026-04-20', '2026-09-30', '2026-05-04 10:00:00',
 'Ação anulatória de auto de infração urbanística. Obra embargada indevidamente.',
 2, 21, '2026-04-20 09:00:00'),

-- ══════════════════════════════════════════════════════════════
-- MAIO 2026
-- ══════════════════════════════════════════════════════════════
(49, 1, '00267890-7/2026', '0026789-07.2026.8.26.0100',
 'Fernanda Cristina Barbosa', 'Receita Federal do Brasil',
 '00.394.460/0001-41', 'Tributário',
 '2ª Vara Federal de Curitiba/PR', 4,
 'ativo', '2026-05-02', '2026-10-15', '2026-05-08 10:00:00',
 'Ação anulatória de auto de lançamento IRPF. Revisão de holding familiar.',
 12, 4, '2026-05-02 09:00:00'),

(50, 1, '00278901-1/2026', '0027890-11.2026.8.26.0224',
 'Renata Oliveira Dias', 'Fernando Andrade Dias',
 '582.139.470-22', 'Família e Sucessões',
 '1ª Vara de Família de Santo André/SP', 5,
 'ativo', '2026-05-03', '2026-08-20', '2026-05-09 11:00:00',
 'Guarda compartilhada — regulamentação de visitas e ajuste de alimentos.',
 18, 10, '2026-05-03 09:00:00'),

(51, 1, '00289012-5/2026', '0028901-25.2026.8.19.0001',
 'Distribuidora Global LTDA', 'Transportes Sul Fluminense LTDA',
 '11.223.445/0001-92', 'Cível',
 '9ª Vara Cível do Rio de Janeiro/RJ', 4,
 'ativo', '2026-05-05', '2026-10-01', '2026-05-09 14:00:00',
 'Execução de título extrajudicial R$ 220k. Penhora de veículos requerida.',
 7, 30, '2026-05-05 09:00:00'),

(52, 1, '00290123-9/2026', '0029012-39.2026.8.26.0100',
 'Marcia Regina Fonseca', 'Tabelionato 3º Ofício São Paulo/SP',
 NULL, 'Família e Sucessões',
 'Cartório de Notas — Testamento Público São Paulo/SP', 3,
 'ativo', '2026-05-06', '2026-07-15', '2026-05-08 09:00:00',
 'Testamento público com usufruto de imóvel e cláusula de inalienabilidade.',
 NULL, 16, '2026-05-06 09:00:00'),

(53, 1, '00301234-3/2026', '0030123-43.2026.8.26.0100',
 'Clínica São Lucas LTDA', 'ANVISA / Vigilância Sanitária SP',
 NULL, 'Administrativo',
 '2ª Vara da Fazenda Pública de São Paulo/SP', 5,
 'ativo', '2026-05-07', '2026-10-20', '2026-05-09 10:00:00',
 'MS contra interdição de equipamento médico por auto de infração ANVISA.',
 22, 23, '2026-05-07 09:00:00'),

(54, 1, '00312345-7/2026', '0031234-57.2026.8.13.0024',
 'Andressa Cunha Machado', 'Estado de Minas Gerais',
 NULL, 'Administrativo',
 'Tribunal de Justiça de Minas Gerais — Apelação', 3,
 'ativo', '2026-05-08', '2026-09-01', '2026-05-09 11:00:00',
 'Apelação do MS de concurso público. Primeiro grau favorável, Estado recorreu.',
 NULL, 12, '2026-05-08 09:00:00'),

(55, 1, '00323456-1/2026', '0032345-61.2026.8.26.0100',
 'Advocacia & Associados SS', 'Creative Labs Tecnologia LTDA',
 '22.334.556/0001-69', 'Proprietário Intelectual',
 '2ª Vara de Propriedade Intelectual de São Paulo/SP', 2,
 'ativo', '2026-05-09', '2026-10-30', '2026-05-10 09:00:00',
 'Proteção de marca e obra intelectual — violação por concorrente direto.',
 NULL, 26, '2026-05-09 09:00:00'),

(56, 1, '00334567-5/2026', '0033456-75.2026.5.02.0012',
 'Indústria Metalúrgica Castro SA', 'Sindicato dos Metalúrgicos de SP',
 NULL, 'Trabalhista',
 '12ª Vara do Trabalho de São Paulo/SP', 3,
 'ativo', '2026-05-10', '2026-06-20', '2026-05-10 15:00:00',
 'Ação de consignação em pagamento — dissídio coletivo. Valores depositados em juízo.',
 14, 29, '2026-05-10 09:00:00');

SET FOREIGN_KEY_CHECKS = 1;
