<?php
/**
 * Seed do BANCO DE PERGUNTAS POR AREA (migration 101) — o "segundo mundo" do motor de
 * pre-atendimento: alem das perguntas genericas (nome, relato, quando, processo, prazo,
 * documentos, cidade) que o IntakeEngine ja faz, cada AREA classificada tem um conjunto de
 * perguntas especificas que qualificam melhor o caso para o advogado.
 *
 * IMPORTANTE (premissas do projeto):
 *  - Estas perguntas sao DETERMINISTICAS (PHP/DB). NAO entram no prompt mestre (intocavel) e
 *    NAO custam tokens. O modelo so classifica a area; o backend escolhe a pergunta.
 *  - E PRE-ATENDIMENTO, nao consultivo: as perguntas coletam FATOS de triagem. Nunca dao
 *    parecer, nunca dizem se a pessoa "tem direito", nunca prometem resultado/prazo.
 *  - Portugues do Brasil, formal e cordial, frases curtas. NUNCA usar travessao (—); usar
 *    virgula ou dois-pontos.
 *  - area_code casa com ai_area_catalog.code (e com primary_practice_area do Structured Output).
 *  - question_key e estavel (snake_case, <=40 chars) e unico por area; o motor o prefixa com
 *    "area:" e nunca repete uma pergunta ja feita. Banco maior NAO alonga o atendimento: o
 *    motor sempre respeita max_questions + a pre-qualificacao; o banco grande so da mais
 *    municao para ativar/desativar/reordenar no Master.
 *
 * Profundidade ("expandir forte", decisao do Yuri 2026-06-20): 12 areas de alta demanda com
 * ~8 perguntas; as demais 23 com 5 a 6. Total ~213.
 *
 * 100% editavel no Painel Master (super_admin). Reaplicar o runner e seguro: usa INSERT IGNORE
 * (preserva edicoes do admin) e apenas adiciona perguntas novas.
 *
 * Estrutura: [ area_code => [ ['key'=>..,'text'=>..,'help'=>..], ... ], ... ]
 *   help = nota interna (aparece no editor do Master; explica por que a pergunta importa).
 */

return [

// ───────────────────────── ALTA DEMANDA (8 perguntas) ─────────────────────────

'previdenciario' => [
  ['key'=>'ja_requereu_inss',   'text'=>'Você já deu entrada nesse pedido no INSS?',                                 'help'=>'Concessão, revisão ou indeferimento a recorrer.'],
  ['key'=>'situacao_pedido',    'text'=>'O benefício foi negado, cessado ou ainda está em análise?',                 'help'=>'Status do benefício orienta a atuação.'],
  ['key'=>'qual_beneficio',     'text'=>'Qual benefício você busca, como aposentadoria, auxílio-doença, BPC/LOAS ou pensão?', 'help'=>'Identifica a espécie de benefício.'],
  ['key'=>'contribuiu_inss',    'text'=>'Você contribui ou já contribuiu para o INSS?',                              'help'=>'Indica qualidade de segurado.'],
  ['key'=>'tempo_contribuicao', 'text'=>'Você sabe, somando tudo, há quanto tempo já contribuiu?',                  'help'=>'Tempo de contribuição aproximado.'],
  ['key'=>'problema_saude_prev','text'=>'A situação envolve algum problema de saúde ou incapacidade para o trabalho?', 'help'=>'Direciona para benefícios por incapacidade.'],
  ['key'=>'trabalha_atualmente','text'=>'Atualmente você está trabalhando, afastado ou sem trabalhar?',              'help'=>'Situação ocupacional atual.'],
  ['key'=>'tem_carta_inss',     'text'=>'Você tem a carta de concessão ou de indeferimento do INSS?',               'help'=>'Documento-chave para a equipe.'],
],

'trabalhista' => [
  ['key'=>'vinculo_atual',      'text'=>'Você ainda trabalha na empresa ou já foi desligado?',                      'help'=>'Vínculo ativo ou encerrado.'],
  ['key'=>'carteira_assinada',  'text'=>'O seu trabalho tinha carteira assinada?',                                  'help'=>'Registro formal ou informal.'],
  ['key'=>'tipo_desligamento',  'text'=>'O desligamento foi pedido por você, sem justa causa ou por justa causa?',   'help'=>'Modalidade da rescisão.'],
  ['key'=>'tempo_servico',      'text'=>'Quanto tempo você trabalhou nessa empresa?',                               'help'=>'Tempo de casa.'],
  ['key'=>'funcao_salario',     'text'=>'Qual era a sua função e a faixa de salário?',                              'help'=>'Função e remuneração.'],
  ['key'=>'verbas_recebidas',   'text'=>'Ao sair, você recebeu as verbas rescisórias ou o acerto?',                'help'=>'Pagamento das verbas.'],
  ['key'=>'horas_extras',       'text'=>'Você fazia horas extras que não eram pagas corretamente?',                'help'=>'Jornada e horas extras.'],
  ['key'=>'assedio_acidente',   'text'=>'Houve assédio, acidente de trabalho ou problema de saúde ligado ao serviço?', 'help'=>'Assédio, acidente ou doença ocupacional.'],
],

'civel' => [
  ['key'=>'tipo_problema_civel','text'=>'A situação é de cobrança, indenização, contrato ou um dano que você sofreu?', 'help'=>'Natureza da demanda cível.'],
  ['key'=>'envolve_valor',      'text'=>'Esse caso envolve alguma cobrança ou valor em dinheiro?',                  'help'=>'Conteúdo patrimonial.'],
  ['key'=>'houve_prejuizo',     'text'=>'Você teve prejuízo material, moral ou os dois?',                          'help'=>'Tipo de dano.'],
  ['key'=>'valor_aproximado',   'text'=>'Você consegue estimar o valor aproximado envolvido?',                     'help'=>'Dimensiona a causa.'],
  ['key'=>'existe_contrato',    'text'=>'Existe algum contrato ou acordo escrito entre as partes?',                'help'=>'Base documental.'],
  ['key'=>'relacao_partes',     'text'=>'Qual a sua relação com a outra parte, por exemplo cliente, vizinho ou conhecido?', 'help'=>'Relação entre as partes.'],
  ['key'=>'quem_outra_parte',   'text'=>'A outra parte é uma pessoa, uma empresa ou um órgão público?',            'help'=>'Natureza do polo contrário.'],
  ['key'=>'ja_tentou_acordo',   'text'=>'Vocês já tentaram resolver por acordo?',                                  'help'=>'Tentativa prévia de solução.'],
],

'consumidor' => [
  ['key'=>'empresa_envolvida',  'text'=>'Qual empresa ou serviço está envolvido na situação?',                     'help'=>'Identifica o fornecedor.'],
  ['key'=>'produto_ou_servico', 'text'=>'O problema é com um produto ou com um serviço?',                          'help'=>'Produto x serviço.'],
  ['key'=>'o_que_aconteceu_cdc','text'=>'Houve defeito, cobrança indevida, atraso na entrega ou cancelamento?',     'help'=>'Tipo de problema de consumo.'],
  ['key'=>'cobranca_negativa',  'text'=>'Houve alguma cobrança indevida ou negativação do seu nome?',              'help'=>'Restrição de crédito ou cobrança.'],
  ['key'=>'valor_envolvido_cdc','text'=>'Qual o valor pago ou cobrado nessa situação?',                            'help'=>'Valor envolvido.'],
  ['key'=>'ja_tentou_resolver', 'text'=>'Você já tentou resolver diretamente com a empresa?',                      'help'=>'Tentativa prévia.'],
  ['key'=>'protocolo_reclamacao','text'=>'Você tem número de protocolo ou registro da reclamação?',                'help'=>'Rastreabilidade do contato.'],
  ['key'=>'tem_comprovantes',   'text'=>'Você guardou notas, contratos ou comprovantes?',                         'help'=>'Provas disponíveis.'],
],

'familia' => [
  ['key'=>'assunto_familia',    'text'=>'A questão é sobre divórcio, pensão, guarda ou partilha de bens?',         'help'=>'Tema de família.'],
  ['key'=>'tipo_uniao',         'text'=>'Vocês são casados, vivem em união estável ou já estão separados?',        'help'=>'Tipo de vínculo.'],
  ['key'=>'envolve_filhos',     'text'=>'A situação envolve filhos menores de idade?',                            'help'=>'Presença de menores.'],
  ['key'=>'idade_filhos',       'text'=>'Se há filhos, quais as idades deles?',                                   'help'=>'Idades dos filhos.'],
  ['key'=>'com_quem_moram',     'text'=>'Atualmente, com quem os filhos moram?',                                  'help'=>'Situação de convivência.'],
  ['key'=>'ja_existe_acordo',   'text'=>'Já existe algum acordo ou decisão sobre pensão ou guarda?',              'help'=>'Situação já definida ou não.'],
  ['key'=>'ha_bens_partilhar',  'text'=>'Existem bens em comum a partilhar?',                                     'help'=>'Patrimônio do casal.'],
  ['key'=>'ha_violencia_fam',   'text'=>'Existe alguma situação de violência ou ameaça envolvida?',               'help'=>'Sinal de urgência (medida protetiva).'],
],

'criminal' => [
  ['key'=>'ha_prisao',          'text'=>'Há alguém preso ou foi expedido mandado de prisão?',                     'help'=>'Sinal de urgência crítica.'],
  ['key'=>'papel_pessoa',       'text'=>'Você é a pessoa investigada, a vítima ou um familiar?',                  'help'=>'Posição no caso.'],
  ['key'=>'tipo_situacao_crim', 'text'=>'De que tipo de situação se trata, por exemplo trânsito, agressão, furto ou ameaça?', 'help'=>'Natureza do fato.'],
  ['key'=>'fase_caso',          'text'=>'O caso está na fase de inquérito policial ou já virou processo?',        'help'=>'Etapa do procedimento.'],
  ['key'=>'ja_intimado',        'text'=>'Você já foi intimado a depor ou recebeu alguma notificação?',            'help'=>'Diligências e prazos.'],
  ['key'=>'houve_audiencia',    'text'=>'Já houve audiência marcada ou alguma decisão no caso?',                  'help'=>'Andamento processual.'],
  ['key'=>'medida_cautelar',    'text'=>'Foi aplicada alguma medida, como fiança ou medida protetiva?',           'help'=>'Medidas cautelares.'],
  ['key'=>'ja_tem_advogado_crim','text'=>'Já existe um advogado atuando ou foi nomeado um defensor?',             'help'=>'Defesa já constituída.'],
],

'sucessoes' => [
  ['key'=>'ja_faleceu',         'text'=>'A pessoa já faleceu?',                                                   'help'=>'Inventário x planejamento em vida.'],
  ['key'=>'existe_testamento',  'text'=>'A pessoa deixou testamento?',                                            'help'=>'Sucessão testamentária x legítima.'],
  ['key'=>'existem_bens',       'text'=>'Há bens a partilhar?',                                                   'help'=>'Existência de patrimônio.'],
  ['key'=>'tipo_bens',          'text'=>'Quais bens existem, por exemplo imóveis, veículos, contas ou empresa?',   'help'=>'Composição do espólio.'],
  ['key'=>'numero_herdeiros',   'text'=>'Quantos herdeiros existem na família?',                                  'help'=>'Complexidade da partilha.'],
  ['key'=>'ha_inventario',      'text'=>'Já foi aberto inventário ou ainda não?',                                 'help'=>'Procedimento já iniciado.'],
  ['key'=>'ha_conflito_herd',   'text'=>'Há acordo entre os herdeiros ou existe conflito?',                       'help'=>'Consensual x litigioso.'],
  ['key'=>'existe_divida_suc',  'text'=>'O falecido deixou dívidas conhecidas?',                                  'help'=>'Passivo do espólio.'],
],

'bancario' => [
  ['key'=>'instituicao_banco',  'text'=>'Qual banco ou financeira está envolvido?',                               'help'=>'Identifica a instituição.'],
  ['key'=>'tipo_contrato_banco','text'=>'Envolve empréstimo, cartão, financiamento ou conta corrente?',           'help'=>'Tipo de contrato bancário.'],
  ['key'=>'tipo_problema_banco','text'=>'A questão é sobre juros, tarifas, fraude ou cobrança?',                  'help'=>'Natureza do problema.'],
  ['key'=>'reconhece_operacao', 'text'=>'Você reconhece a operação ou ela foi feita sem a sua autorização?',       'help'=>'Fraude x operação legítima.'],
  ['key'=>'houve_golpe',        'text'=>'Houve algum golpe, como Pix ou ligação se passando pelo banco?',         'help'=>'Indício de fraude.'],
  ['key'=>'desconto_beneficio', 'text'=>'Há descontos em conta, salário ou benefício do INSS?',                  'help'=>'Consignado e descontos indevidos.'],
  ['key'=>'valor_contrato_banco','text'=>'Qual o valor aproximado da operação ou da dívida?',                     'help'=>'Valor envolvido.'],
  ['key'=>'esta_em_dia_banco',  'text'=>'As parcelas estão em dia ou em atraso?',                                 'help'=>'Situação do contrato.'],
],

'saude' => [
  ['key'=>'plano_ou_sus',       'text'=>'O atendimento é pelo plano de saúde ou pelo SUS?',                       'help'=>'Define o responsável pela cobertura.'],
  ['key'=>'operadora_plano',    'text'=>'Qual é o plano de saúde ou a operadora?',                               'help'=>'Identifica a operadora.'],
  ['key'=>'o_que_foi_negado',   'text'=>'O que foi negado, um exame, cirurgia, medicamento ou internação?',       'help'=>'Objeto da negativa.'],
  ['key'=>'negativa_escrita',   'text'=>'A negativa veio por escrito ou apenas verbalmente?',                     'help'=>'Prova da recusa.'],
  ['key'=>'ha_urgencia_saude',  'text'=>'Há risco à saúde se o tratamento não acontecer logo?',                  'help'=>'Sinal de urgência.'],
  ['key'=>'tempo_espera_saude', 'text'=>'Há quanto tempo você aguarda esse atendimento?',                        'help'=>'Tempo de espera.'],
  ['key'=>'tem_pedido_medico',  'text'=>'Você tem o pedido ou relatório médico do tratamento?',                  'help'=>'Documento essencial.'],
  ['key'=>'condicao_paciente',  'text'=>'Qual a condição atual do paciente?',                                    'help'=>'Gravidade do quadro.'],
],

'imobiliario' => [
  ['key'=>'tipo_questao_imovel','text'=>'A situação envolve compra, venda, aluguel ou regularização de imóvel?',   'help'=>'Tipo de operação.'],
  ['key'=>'tipo_imovel',        'text'=>'É casa, apartamento, terreno ou imóvel comercial?',                      'help'=>'Tipo de imóvel.'],
  ['key'=>'imovel_registrado',  'text'=>'O imóvel está registrado em cartório no seu nome?',                      'help'=>'Situação registral.'],
  ['key'=>'tem_contrato_imovel','text'=>'Existe contrato assinado sobre esse imóvel?',                            'help'=>'Base documental.'],
  ['key'=>'ja_pagou_quanto',    'text'=>'Quanto do valor já foi pago?',                                          'help'=>'Estágio do pagamento.'],
  ['key'=>'ha_financiamento_im','text'=>'O imóvel tem financiamento envolvido?',                                 'help'=>'Financiamento.'],
  ['key'=>'envolve_construtora','text'=>'A outra parte é uma construtora, imobiliária ou particular?',            'help'=>'Polo contrário.'],
  ['key'=>'situacao_posse_im',  'text'=>'Você já está na posse do imóvel ou ainda não?',                         'help'=>'Posse atual.'],
],

'tributario' => [
  ['key'=>'tipo_tributo',       'text'=>'Qual imposto ou tributo está envolvido?',                                'help'=>'Identifica o tributo.'],
  ['key'=>'quem_cobra',         'text'=>'A cobrança é da Receita Federal, do estado ou do município?',            'help'=>'Esfera do ente cobrador.'],
  ['key'=>'pf_ou_pj_trib',      'text'=>'A dívida é sua como pessoa física ou da sua empresa?',                   'help'=>'PF x PJ.'],
  ['key'=>'ja_autuado_trib',    'text'=>'Você já recebeu uma autuação ou execução fiscal?',                      'help'=>'Procedimento formal.'],
  ['key'=>'fase_cobranca_trib', 'text'=>'A cobrança está em fase administrativa ou já virou execução fiscal?',     'help'=>'Fase da cobrança.'],
  ['key'=>'valor_divida_trib',  'text'=>'Você sabe o valor aproximado da cobrança ou da dívida?',                'help'=>'Dimensiona a causa.'],
  ['key'=>'objetivo_trib',      'text'=>'Você busca parcelar, contestar ou recuperar valores pagos?',            'help'=>'Objetivo do atendimento.'],
  ['key'=>'atividade_contrib',  'text'=>'Qual a sua atividade ou o ramo da empresa envolvida?',                  'help'=>'Perfil do contribuinte.'],
],

'execucao_penal' => [
  ['key'=>'parentesco_preso',   'text'=>'Qual a sua relação com a pessoa presa?',                                 'help'=>'Vínculo de quem procura.'],
  ['key'=>'regime_atual',       'text'=>'Em qual regime ela está, fechado, semiaberto ou aberto?',                'help'=>'Regime de cumprimento.'],
  ['key'=>'tempo_total_pena',   'text'=>'Qual o tempo total da pena?',                                            'help'=>'Pena total.'],
  ['key'=>'tempo_cumprido',     'text'=>'Você sabe quanto tempo da pena já foi cumprido?',                        'help'=>'Base para benefícios.'],
  ['key'=>'beneficio_buscado',  'text'=>'Qual benefício você busca, como progressão, livramento ou remição?',     'help'=>'Objetivo do atendimento.'],
  ['key'=>'onde_cumpre',        'text'=>'Em qual unidade prisional ela está?',                                   'help'=>'Localização e comarca.'],
  ['key'=>'trabalha_ou_estuda', 'text'=>'A pessoa trabalha ou estuda durante o cumprimento?',                    'help'=>'Remição por trabalho/estudo.'],
  ['key'=>'ja_tem_advogado_exec','text'=>'Já existe advogado ou defensor acompanhando a execução?',              'help'=>'Defesa já constituída.'],
],

// ───────────────────────── DEMAIS AREAS (5 a 6 perguntas) ─────────────────────────

'empresarial' => [
  ['key'=>'fala_pela_empresa',  'text'=>'Você fala em nome de uma empresa ou como pessoa física?',                'help'=>'Polo PJ x PF.'],
  ['key'=>'natureza_problema',  'text'=>'A questão envolve dívidas, contratos ou cobrança a receber?',            'help'=>'Natureza da demanda.'],
  ['key'=>'quem_outra_parte_emp','text'=>'A outra parte é cliente, fornecedor ou sócio?',                         'help'=>'Relação comercial.'],
  ['key'=>'porte_ramo_emp',     'text'=>'Qual o porte e o ramo da empresa?',                                     'help'=>'Dimensiona o caso.'],
  ['key'=>'valor_envolvido_emp','text'=>'Qual o valor aproximado envolvido?',                                    'help'=>'Valor da causa.'],
  ['key'=>'ha_contrato_emp',    'text'=>'Existe contrato ou documento que formalize o negócio?',                 'help'=>'Base documental.'],
],

'societario' => [
  ['key'=>'numero_socios',      'text'=>'Quantos sócios há na empresa?',                                         'help'=>'Estrutura societária.'],
  ['key'=>'tipo_conflito_soc',  'text'=>'A situação é de entrada, saída ou conflito entre sócios?',              'help'=>'Movimentação societária.'],
  ['key'=>'tipo_empresa_soc',   'text'=>'Qual o tipo de empresa, por exemplo LTDA, S/A ou MEI?',                'help'=>'Tipo societário.'],
  ['key'=>'sua_participacao',   'text'=>'Qual a sua participação na sociedade?',                                'help'=>'Percentual do sócio.'],
  ['key'=>'tem_contrato_social','text'=>'Existe contrato social ou acordo de sócios escrito?',                  'help'=>'Base documental.'],
],

'administrativo' => [
  ['key'=>'orgao_envolvido',    'text'=>'Qual órgão público está envolvido na situação?',                       'help'=>'Identifica a Administração.'],
  ['key'=>'esfera_orgao',       'text'=>'O órgão é municipal, estadual ou federal?',                            'help'=>'Esfera do ente.'],
  ['key'=>'tipo_questao_adm',   'text'=>'É uma multa, um processo administrativo ou uma questão de concurso?',    'help'=>'Natureza da demanda.'],
  ['key'=>'ja_recebeu_decisao', 'text'=>'Você já recebeu alguma decisão ou notificação do órgão?',             'help'=>'Existência de ato.'],
  ['key'=>'ha_prazo_recurso',   'text'=>'Existe prazo para recurso ou defesa em andamento?',                    'help'=>'Prazos administrativos.'],
],

'servidor_publico' => [
  ['key'=>'esfera_servidor',    'text'=>'Você é servidor municipal, estadual ou federal?',                      'help'=>'Esfera do vínculo.'],
  ['key'=>'cargo_servidor',     'text'=>'Qual o seu cargo ou função?',                                         'help'=>'Cargo ocupado.'],
  ['key'=>'situacao_funcional', 'text'=>'Você está na ativa, aposentado ou respondendo a um processo?',         'help'=>'Situação funcional.'],
  ['key'=>'tipo_direito_serv',  'text'=>'A questão é sobre remuneração, aposentadoria ou processo disciplinar?',  'help'=>'Tema do atendimento.'],
  ['key'=>'ha_pad',             'text'=>'Existe algum processo administrativo ou sindicância contra você?',      'help'=>'PAD em curso.'],
],

'constitucional' => [
  ['key'=>'direito_violado',    'text'=>'Qual direito você sente que foi violado?',                             'help'=>'Direito em discussão.'],
  ['key'=>'quem_violou',        'text'=>'Quem praticou a violação, um órgão público ou um particular?',         'help'=>'Origem da violação.'],
  ['key'=>'ja_buscou_orgao',    'text'=>'Você já buscou o órgão responsável antes?',                           'help'=>'Tentativa prévia.'],
  ['key'=>'afeta_outros',       'text'=>'A situação afeta só você ou um grupo de pessoas?',                    'help'=>'Individual x coletivo.'],
  ['key'=>'ha_urgencia_const',  'text'=>'Existe risco imediato que exija uma medida urgente?',                 'help'=>'Necessidade de medida urgente.'],
],

'eleitoral' => [
  ['key'=>'papel_eleitoral',    'text'=>'Você é candidato, partido ou eleitor nessa situação?',                'help'=>'Posição no caso.'],
  ['key'=>'fase_eleitoral',     'text'=>'A questão é sobre candidatura, campanha ou prestação de contas?',      'help'=>'Tema eleitoral.'],
  ['key'=>'cargo_disputado',    'text'=>'Se for candidatura, qual cargo está em questão?',                     'help'=>'Cargo disputado.'],
  ['key'=>'ha_processo_eleit',  'text'=>'Já existe processo ou representação na Justiça Eleitoral?',           'help'=>'Procedimento em curso.'],
  ['key'=>'ha_prazo_eleitoral', 'text'=>'Existe algum prazo ou notificação da Justiça Eleitoral?',            'help'=>'Prazos costumam ser curtos.'],
],

'militar' => [
  ['key'=>'forca_corporacao',   'text'=>'Você pertence a qual força ou corporação?',                           'help'=>'Instituição.'],
  ['key'=>'patente_funcao',     'text'=>'Qual a sua patente ou função na corporação?',                         'help'=>'Posição na carreira.'],
  ['key'=>'situacao_militar',   'text'=>'Você está na ativa, na reserva ou já foi licenciado?',                'help'=>'Situação atual.'],
  ['key'=>'tipo_questao_mil',   'text'=>'A questão é disciplinar, criminal militar ou sobre direitos da carreira?', 'help'=>'Natureza da demanda.'],
  ['key'=>'ha_punicao_mil',     'text'=>'Já houve alguma punição ou procedimento aplicado?',                   'help'=>'Procedimento aplicado.'],
],

'condominial' => [
  ['key'=>'papel_condominio',   'text'=>'Você é morador, síndico ou membro do condomínio?',                    'help'=>'Posição no condomínio.'],
  ['key'=>'tipo_condominio',    'text'=>'É um condomínio residencial ou comercial?',                          'help'=>'Tipo de condomínio.'],
  ['key'=>'tipo_questao_cond',  'text'=>'A questão é sobre cobrança, obras, barulho ou administração?',         'help'=>'Tema condominial.'],
  ['key'=>'houve_assembleia',   'text'=>'Houve alguma decisão de assembleia sobre o assunto?',                'help'=>'Deliberação existente.'],
  ['key'=>'valor_cobranca_cond','text'=>'Se for cobrança, qual o valor aproximado em discussão?',             'help'=>'Valor envolvido.'],
],

'medico' => [
  ['key'=>'papel_medico',       'text'=>'Você é o paciente, um familiar ou o profissional de saúde?',          'help'=>'Posição no caso.'],
  ['key'=>'onde_ocorreu_atend', 'text'=>'Onde o atendimento aconteceu, hospital, clínica ou consultório?',     'help'=>'Local do fato.'],
  ['key'=>'tipo_procedimento',  'text'=>'Qual procedimento ou tratamento foi realizado?',                     'help'=>'Procedimento envolvido.'],
  ['key'=>'houve_dano_saude',   'text'=>'Houve algum dano ou agravamento após o atendimento?',                'help'=>'Existência de dano.'],
  ['key'=>'tem_laudo_medico',   'text'=>'Você tem laudos, prontuário ou documentos do atendimento?',          'help'=>'Provas documentais.'],
],

'digital' => [
  ['key'=>'tipo_ocorrencia_dig','text'=>'A situação envolve ofensa, fraude, vazamento ou conteúdo na internet?',  'help'=>'Tipo de ocorrência.'],
  ['key'=>'onde_ocorreu_online','text'=>'Em qual plataforma ou aplicativo isso aconteceu?',                     'help'=>'Plataforma.'],
  ['key'=>'autor_identificado', 'text'=>'Você sabe quem é o responsável ou ele é desconhecido?',               'help'=>'Autoria.'],
  ['key'=>'houve_prejuizo_dig', 'text'=>'Houve prejuízo financeiro, moral ou à sua imagem?',                   'help'=>'Tipo de dano.'],
  ['key'=>'tem_provas_digitais','text'=>'Você guardou prints, links ou registros do ocorrido?',                'help'=>'Provas digitais.'],
],

'lgpd' => [
  ['key'=>'papel_lgpd',         'text'=>'Você é a pessoa cujos dados foram usados ou representa uma empresa?',   'help'=>'Titular x controlador.'],
  ['key'=>'o_que_houve_dados',  'text'=>'Houve vazamento, uso indevido ou recusa em excluir os dados?',         'help'=>'Tipo de incidente.'],
  ['key'=>'tipo_dado',          'text'=>'Que tipo de dado está envolvido, por exemplo CPF, dados bancários ou de saúde?', 'help'=>'Sensibilidade do dado.'],
  ['key'=>'empresa_dados',      'text'=>'Qual empresa ou órgão tratou esses dados?',                           'help'=>'Responsável pelo tratamento.'],
  ['key'=>'ja_reclamou_dados',  'text'=>'Você já solicitou à empresa a correção ou exclusão dos dados?',        'help'=>'Tentativa prévia.'],
],

'propriedade_intelectual' => [
  ['key'=>'tipo_pi',            'text'=>'A questão envolve marca, patente, direito autoral ou software?',        'help'=>'Tipo de PI.'],
  ['key'=>'ja_registrado_pi',   'text'=>'O seu direito já está registrado, por exemplo no INPI?',              'help'=>'Existência de registro.'],
  ['key'=>'uso_comercial_pi',   'text'=>'A sua criação é usada comercialmente?',                              'help'=>'Exploração comercial.'],
  ['key'=>'houve_copia',        'text'=>'Alguém copiou ou usou a sua criação sem autorização?',               'help'=>'Violação.'],
  ['key'=>'quem_copiou',        'text'=>'Você sabe quem está usando indevidamente?',                          'help'=>'Identificação do infrator.'],
],

'ambiental' => [
  ['key'=>'papel_ambiental',    'text'=>'Você fala como pessoa física, empresa ou produtor rural?',            'help'=>'Perfil de quem procura.'],
  ['key'=>'tipo_questao_amb',   'text'=>'A questão é uma multa, um licenciamento ou um dano ambiental?',        'help'=>'Tema ambiental.'],
  ['key'=>'orgao_ambiental',    'text'=>'Qual órgão está envolvido, como IBAMA ou secretaria de meio ambiente?', 'help'=>'Órgão.'],
  ['key'=>'ha_auto_infracao',   'text'=>'Já houve auto de infração, embargo ou multa?',                        'help'=>'Procedimento formal.'],
  ['key'=>'valor_multa_amb',    'text'=>'Se houver multa, qual o valor aproximado?',                          'help'=>'Valor envolvido.'],
],

'agrario' => [
  ['key'=>'tipo_area_rural',    'text'=>'A situação envolve posse, propriedade ou uso de terra rural?',         'help'=>'Tipo de questão fundiária.'],
  ['key'=>'imovel_rural_doc',   'text'=>'O imóvel rural possui documentação ou registro?',                     'help'=>'Situação documental.'],
  ['key'=>'tamanho_uso_terra',  'text'=>'Qual o tamanho aproximado e o uso da terra?',                        'help'=>'Dimensão e destinação.'],
  ['key'=>'ha_conflito_posse',  'text'=>'Há conflito ou disputa de posse com outra pessoa?',                   'help'=>'Litígio possessório.'],
  ['key'=>'orgao_agrario',      'text'=>'Há algum órgão envolvido, como INCRA ou prefeitura?',                 'help'=>'Órgão envolvido.'],
],

'internacional' => [
  ['key'=>'paises_envolvidos',  'text'=>'Quais países estão envolvidos na situação?',                          'help'=>'Elemento de estraneidade.'],
  ['key'=>'tipo_questao_intl',  'text'=>'A questão é sobre contrato, documento estrangeiro ou família no exterior?', 'help'=>'Tema internacional.'],
  ['key'=>'pf_ou_pj_intl',      'text'=>'A questão é sua como pessoa física ou de uma empresa?',               'help'=>'PF x PJ.'],
  ['key'=>'tem_doc_estrangeiro','text'=>'Existe algum documento ou decisão emitido em outro país?',            'help'=>'Necessidade de homologação.'],
  ['key'=>'idioma_documento',   'text'=>'Os documentos estão em português ou em outro idioma?',               'help'=>'Necessidade de tradução.'],
],

'migratorio' => [
  ['key'=>'nacionalidade',      'text'=>'Qual a sua nacionalidade?',                                          'help'=>'Enquadramento migratório.'],
  ['key'=>'situacao_atual_migr','text'=>'Você já está no Brasil ou ainda no exterior?',                       'help'=>'Localização atual.'],
  ['key'=>'objetivo_migratorio','text'=>'Você busca visto, residência, naturalização ou refúgio?',            'help'=>'Objetivo do atendimento.'],
  ['key'=>'tempo_no_brasil',    'text'=>'Há quanto tempo você está no Brasil, se já estiver aqui?',           'help'=>'Tempo de permanência.'],
  ['key'=>'vinculo_brasil',     'text'=>'Você tem vínculo no Brasil, como trabalho, estudo ou família?',       'help'=>'Vínculos locais.'],
],

'direitos_humanos' => [
  ['key'=>'tipo_violacao_dh',   'text'=>'A situação envolve discriminação, violência ou abuso de autoridade?',   'help'=>'Tipo de violação.'],
  ['key'=>'quem_praticou_dh',   'text'=>'Quem praticou, um agente público ou um particular?',                  'help'=>'Origem da violação.'],
  ['key'=>'houve_registro_dh',  'text'=>'Houve registro de ocorrência ou denúncia a algum órgão?',            'help'=>'Registro prévio.'],
  ['key'=>'ha_testemunhas_dh',  'text'=>'Existem testemunhas ou provas do ocorrido?',                         'help'=>'Provas disponíveis.'],
  ['key'=>'ha_risco_atual_dh',  'text'=>'Existe risco contínuo à sua integridade ou dignidade?',              'help'=>'Sinal de urgência.'],
],

'securitario' => [
  ['key'=>'tipo_seguro',        'text'=>'Qual tipo de seguro está envolvido, vida, automóvel, residencial ou outro?', 'help'=>'Tipo de seguro.'],
  ['key'=>'seguradora_nome',    'text'=>'Qual a seguradora envolvida?',                                       'help'=>'Identifica a seguradora.'],
  ['key'=>'houve_sinistro',     'text'=>'Já houve o sinistro e o pedido de pagamento à seguradora?',          'help'=>'Sinistro e pedido.'],
  ['key'=>'resposta_seguradora','text'=>'A seguradora negou, atrasou ou pagou a menor?',                      'help'=>'Conduta da seguradora.'],
  ['key'=>'valor_apolice',      'text'=>'Qual o valor aproximado da apólice ou da cobertura?',               'help'=>'Valor envolvido.'],
],

'maritimo' => [
  ['key'=>'tipo_questao_mar',   'text'=>'A questão envolve transporte de carga, embarcação ou trabalho a bordo?', 'help'=>'Tema marítimo.'],
  ['key'=>'tipo_carga_embarc',  'text'=>'Qual o tipo de carga ou embarcação envolvida?',                      'help'=>'Objeto envolvido.'],
  ['key'=>'houve_dano_carga',   'text'=>'Houve avaria, extravio ou atraso da carga?',                        'help'=>'Dano à carga.'],
  ['key'=>'trecho_transporte',  'text'=>'Qual o trecho ou a rota do transporte?',                            'help'=>'Rota.'],
  ['key'=>'tem_contrato_mar',   'text'=>'Existe contrato de transporte ou fretamento envolvido?',            'help'=>'Base contratual.'],
],

'energia' => [
  ['key'=>'tipo_servico_energia','text'=>'A questão é sobre energia elétrica, água ou gás?',                  'help'=>'Serviço essencial.'],
  ['key'=>'problema_energia',   'text'=>'O problema é cobrança, corte de fornecimento ou fraude no medidor?',  'help'=>'Natureza do problema.'],
  ['key'=>'concessionaria',     'text'=>'Qual concessionária está envolvida?',                               'help'=>'Identifica a concessionária.'],
  ['key'=>'valor_conta_energia','text'=>'Qual o valor da conta ou da cobrança em discussão?',               'help'=>'Valor envolvido.'],
  ['key'=>'ha_corte_atual',     'text'=>'O serviço está cortado neste momento?',                            'help'=>'Sinal de urgência.'],
],

'compliance' => [
  ['key'=>'demanda_compliance', 'text'=>'A empresa busca prevenção, investigação interna ou defesa?',         'help'=>'Objetivo do trabalho.'],
  ['key'=>'setor_atuacao',      'text'=>'Em qual setor a empresa atua?',                                    'help'=>'Setor de atuação.'],
  ['key'=>'porte_empresa',      'text'=>'Qual o porte da empresa?',                                         'help'=>'Dimensiona o programa.'],
  ['key'=>'ja_houve_denuncia',  'text'=>'Já houve alguma denúncia ou investigação em curso?',               'help'=>'Procedimento existente.'],
  ['key'=>'ha_orgao_investiga', 'text'=>'Há algum órgão público investigando a empresa?',                   'help'=>'Investigação oficial.'],
],

'mediacao' => [
  ['key'=>'partes_envolvidas_med','text'=>'Quem são as partes envolvidas no conflito?',                       'help'=>'Identifica os envolvidos.'],
  ['key'=>'tipo_conflito_med',  'text'=>'O conflito é familiar, empresarial ou entre vizinhos?',             'help'=>'Natureza do conflito.'],
  ['key'=>'valor_ou_objeto_med','text'=>'Qual o valor ou o objeto principal do conflito?',                  'help'=>'Objeto da disputa.'],
  ['key'=>'ja_existe_processo_med','text'=>'Já existe processo judicial sobre esse conflito?',               'help'=>'Judicial x extrajudicial.'],
  ['key'=>'abertura_acordo',    'text'=>'A outra parte está aberta a um acordo?',                           'help'=>'Viabilidade da mediação.'],
],

'arbitragem' => [
  ['key'=>'tem_clausula_arb',   'text'=>'O contrato possui cláusula de arbitragem?',                         'help'=>'Pré-requisito da via arbitral.'],
  ['key'=>'natureza_disputa_arb','text'=>'A disputa é empresarial, societária ou de construção?',            'help'=>'Natureza da disputa.'],
  ['key'=>'valor_envolvido_arb','text'=>'Você sabe qual o valor aproximado envolvido na disputa?',           'help'=>'Dimensiona a causa.'],
  ['key'=>'camara_definida',    'text'=>'O contrato indica uma câmara arbitral específica?',                'help'=>'Câmara eleita.'],
  ['key'=>'ja_iniciou_arb',     'text'=>'O procedimento de arbitragem já foi iniciado?',                    'help'=>'Estágio do procedimento.'],
],

];
