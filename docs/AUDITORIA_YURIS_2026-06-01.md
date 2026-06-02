# Auditoria Completa YURIS — 2026-06-01

> Gerada por workflow de 17 agentes (14 módulos + 3 transversais) + verificação adversarial.
> **110 achados** · 2 críticas · 31 altas · 41 médias · 36 baixas.
> Dos 33 alto/crítico verificados adversarialmente: **33 confirmados reais, 0 falso-positivo**.

Legenda: [CONF] = confirmado por verificador adversarial lendo o código real.

---

## CRÍTICA (2)

### 1. Salvar config do Agente IA falha sempre — agent.js nao envia CSRF que o endpoint exige `[CONF]`
- **Módulo**: config · **Tipo**: ponta_solta · sev. revisada: critica
- **Arquivo**: `public/assets/agent.js:229`
- **Problema**: agent_settings.php (POST) rejeita a requisicao com HTTP 400 'CSRF invalido' quando nao recebe o token nem no header X-CSRF-Token nem em input['csrf_token']/['_csrf']. Mas agent.js monta o payload manualmente SEM csrf_token e faz o fetch SEM o header X-CSRF-Token. agente.php tem um <input hidden name="csrf_token"> (linha 462), porem agent.js ignora esse campo (constroi o objeto payload a mao). Resultado: clicar 'Salvar configuracao' SEMPRE retorna 400 e mostra 'Erro ao salvar' — o fluxo principal da pagina Agente esta 100% quebrado.
- **Evidência**: agent.js linha 229-242: const payload = { name:..., enabled:..., whatsapp_number:..., provider:..., api_key:..., prompt:... }; const res = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }); // sem csrf_token no payload e sem header X-CSRF-Token. Endpoint agent_settings.php linha 101-106: $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input[
- **Correção**: Em agent.js, ler o token (ex: do input hidden form.csrf_token.value ou de uma meta tag) e envia-lo: headers: {'Content-Type':'application/json','X-CSRF-Token': form.csrf_token.value} OU incluir csrf_token no payload. Confirmar que o nome do header bate com HTTP_X_CSRF_TOKEN.

### 2. Filtro de Origem (Matriz/Filiais) usa accounts.matriz_id (sempre NULL) em vez de account_vinculos — dropdown nunca aparece e filtro fica morto `[CONF]`
- **Módulo**: dashboard · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/dashboard.php:36`
- **Problema**: Para montar a lista de contas do filtro de Origem, a matriz consulta accounts WHERE (id = :self OR matriz_id = :self). No YURIS o vinculo matriz<->filial e via tabela account_vinculos; accounts.matriz_id e sempre NULL. Confirmei no banco: a conta id=9 'Filial Sao Paulo' tem tipo='filial' porem matriz_id=NULL, e o vinculo real esta em account_vinculos (matriz_account_id=1, filial_account_id=9, status=active). Consequencia: $origin_accounts retorna SO a propria matriz (count=1), entao a condicao count($origin_accounts) > 1 e falsa — o <select id='dashFilterOrigin'> (linha 410) NUNCA e renderizado e todo o bloco de recorte de $tenantIds por origem (linha 47) e ignorado. Uma matriz com filial ativa fica SEM o filtro 'Apenas Matriz / Apenas Filiais / Filial especifica'. Os KPIs agregados ainda funcionam porque a linha 18 usa getAccessibleAccountIds('dashboard') (que le account_vinculos), mas a feature de filtrar a origem esta completamente inoperante.
- **Evidência**: Linha 36: "AND (id = :self OR matriz_id = :self)" ; Linha 48-49: $matrizIds/$filiaisIds derivados de $origin_accounts ; Linha 387: <?php if (count($origin_accounts) > 1): ?> (dropdown so renderiza se >1). Banco: accounts id=9 filial matriz_id=NULL; account_vinculos (1,9,active).
- **Correção**: Trocar a query de $origin_accounts para derivar as filiais de account_vinculos (ex.: Account::listFiliaisVinculadas($ctx->getAccountId()) ou JOIN account_vinculos av ON av.filial_account_id=a.id WHERE av.matriz_account_id=:self AND av.status='active'), em vez de accounts.matriz_id. Assim o dropdown volta a aparecer e o recorte __matriz__/__filiais__/<id> passa a funcionar.

---

## ALTA (31)

### 1. Tabela 'clientes' nao esta plugada na busca de mencoes (so cards/processos) `[CONF]`
- **Módulo**: chat_interno · **Tipo**: feature_orfa · sev. revisada: media
- **Arquivo**: `public/api/chat/mencoes.php:52`
- **Problema**: A tabela 'clientes' EXISTE (migrations 090_create_clientes.sql / 092_clientes_origens.sql, e no banco vivo: clientes, clientes_history, clientes_origens, clientes_setores) e ha pagina public/clientes.php com auto-open via ?open=. Porem a busca de mencoes (@) nunca consulta a tabela clientes: ela so faz SELECT em users, processos e cards. Pior: o proprio codigo PROMETE clientes. O comentario diz '@cli... -> cards(clientes)' e o branch de prefixo (linhas 60-64) trata 'cli' desligando processos e usuarios e deixando SO cards ($showUsers=false; $showProcessos=false), mas cards != clientes. Resultado: ao digitar '@cliente Fulano' o usuario nao acha nenhum cliente real; cai numa busca de cards (prospeccao). O enum chat_mencoes.tipo so tem ('usuario','processo','card') — nao existe tipo 'cliente'. chat_interno.js nao tem nenhuma referencia a clientes. E exatamente o caso de referencia: entidade criada mas conectada so numa fonte (cards/prospeccao) e esquecida na outra (clientes).
- **Evidência**: mencoes.php:52 // @pro... -> processos | @card... -> cards | @cli... -> cards(clientes) | resto -> usuarios mencoes.php:60-63 if (str_starts_with($qLower, 'pro')) {...} elseif (str_starts_with($qLower,'card')||str_starts_with($qLower,'cli')) { $showUsers=false; $showProcessos=false; } // 'cli' cai em cards, nunca consulta a tabela clientes DB enum: chat_mencoes.tipo enum('usuario','processo','card
- **Correção**: Adicionar um 4o bloco de busca em mencoes.php consultando a tabela clientes (escopada por account_id IN tenants, respeitando o modulo 'clientes' como faz api/clientes.php), com tipo 'cliente', token '@[cli|<id>|<nome>]' e url '/clientes.php?open=<id>'. Estender o enum chat_mencoes.tipo para incluir 'cliente', e tratar 'cli'/'cliente' em chat_interno.js (parseMentions, extractMencoes, whitelist em mensagens.php). Hoje o branch 'cli' engana o usuario apontando pra cards.

### 2. Busca de mencoes ignora flags de sync por modulo (vaza cards/processos de filial com sync desligado) `[CONF]`
- **Módulo**: chat_interno · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/api/chat/mencoes.php:27`
- **Problema**: mencoes.php escopa por getAccessibleAccountIds() SEM passar modulo (linha 27: 'sem modulo: so matriz+filiais ativas'). Esse mesmo conjunto de account_ids e usado para buscar USUARIOS, PROCESSOS e CARDS. Mas os modulos reais usam escopo POR MODULO: api/cards.php usa getAccessibleAccountIds('prospeccao') (respeita sync_cards) e api/processes.php usa getAccessibleAccountIds('processos') (respeita sync_processos). Como mencoes.php usa o escopo base (so sync_enabled mestre), uma matriz que tem uma filial com sync_cards=0 (ou sync_processos=0) ainda enxerga os cards/processos dessa filial na busca de @mencao — dados que o usuario NAO ve nas telas/endpoints daqueles modulos. E um vazamento de dados de filial atraves do canal de mencoes, contornando a granularidade de sync.
- **Evidência**: mencoes.php:27 $accessibleIds = $ctx->getAccessibleAccountIds(); // sem modulo: so matriz+filiais ativas mencoes.php:99-105 (processos) e 123-130 (cards) reutilizam $accIn derivado desse base, sem flag de modulo. Comparar: api/cards.php:40 getAccessibleAccountIds('prospeccao'); api/processes.php:58 getAccessibleAccountIds('processos').
- **Correção**: Usar escopos por modulo distintos em mencoes.php: processos -> getAccessibleAccountIds('processos'); cards -> getAccessibleAccountIds('prospeccao'); clientes -> getAccessibleAccountIds('clientes'); usuarios pode manter o base. Construir um conjunto de placeholders por bloco em vez de um $accIn unico, para respeitar sync_cards/sync_processos por filial como o resto do sistema.

### 3. Tabela clientes (PII pesada) totalmente fora da anonimização LGPD `[CONF]`
- **Módulo**: clientes · **Tipo**: seguranca · sev. revisada: alta
- **Arquivo**: `public/api/master/lgpd_anonymize.php:246`
- **Problema**: O endpoint de anonimização LGPD só aceita entidade user|contato|card|processo, e o helper Anonymizer só tem os métodos user(), contato(), card(), processoParte(). NÃO existe Anonymizer::cliente() nem case 'cliente' que toque a tabela `clientes`. Porém a tabela `clientes` armazena PII sensível (cpf_cnpj, rg, nome_mae, endereço completo, telefone, whatsapp, email) e foi criada JÁ com as colunas `anonymized_at` e `deletion_reason` (confirmado no schema) — colunas que NENHUM código escreve. Resultado: quando um titular do módulo Clientes pede exclusão (LGPD Art.18), seus dados pessoais em `clientes` jamais são anonimizados. É o padrão 'feature criada num módulo mas não plugada onde deveria': contatos/cards/processos/users estão na máquina LGPD, Clientes ficou de fora.
- **Evidência**: lgpd_anonymize.php:246 -> ApiResponse::badRequest('entidade desconhecida (use user|contato|card|processo)'); // Anonymizer.php tem user()/contato()/card()/processoParte(), nenhum método cliente() nem 'FROM clientes'. Schema clientes: colunas anonymized_at datetime e deletion_reason varchar(200) existem mas nenhum SQL no codebase as escreve.
- **Correção**: Criar Anonymizer::cliente(int $id, ...) que faz UPDATE clientes SET nome/cpf_cnpj/rg/nome_mae/telefone/whatsapp/email/cep/logradouro... = valores anonimizados, anonymized_at = NOW(), deletion_reason = motivo WHERE id = ?; adicionar case 'cliente' (ou 'cliente_modulo') no switch do lgpd_anonymize.php e no preview_impact. Conferir também o roteamento de tipo em LgpdRequest (hoje 'cliente' aponta para a tabela `contatos`, não para `clientes`).

### 4. Export de portabilidade LGPD não inclui dados da tabela clientes `[CONF]`
- **Módulo**: clientes · **Tipo**: seguranca · sev. revisada: alta
- **Arquivo**: `app/Helpers/Anonymizer.php:238`
- **Problema**: Anonymizer::exportTitular() monta o ZIP de portabilidade (LGPD Art.18 V) coletando de users, contatos, cards, whatsapp_messages, aceites, consentimentos e solicitações LGPD — mas NÃO lê a tabela `clientes`. Não existe collectClientes(). Logo, o pacote de dados entregue ao titular omite todo o cadastro do módulo Clientes (CPF, RG, nome da mãe, endereço, telefones, email). Mesma raiz do achado anterior: o módulo Clientes não foi propagado para a maquinaria LGPD.
- **Evidência**: Anonymizer.php:238-246 'dados' => ['users'=>collectUsers(...), 'contatos'=>collectContatos(...), 'cards'=>collectCards(...), 'mensagens_whatsapp'=>..., 'aceites_termos'=>..., 'consentimentos'=>..., 'solicitacoes_lgpd'=>...] — nenhuma chave 'clientes' / collectClientes().
- **Correção**: Adicionar private static function collectClientes(\PDO $pdo, string $email) com SELECT dos campos PII de `clientes` WHERE LOWER(email)=? (e idealmente também por cpf_cnpj quando disponível) e incluí-la em exportTitular() sob a chave 'clientes'.

### 5. Agente IA e feature orfa — provider/api_key/prompt salvos mas nunca consumidos `[CONF]`
- **Módulo**: config · **Tipo**: feature_orfa · sev. revisada: media
- **Arquivo**: `public/api/agent_settings.php`
- **Problema**: A pagina agente.php + agent_settings.php coletam e cifram provider, api_key e prompt em agent_configs (migration 048), mas NENHUM codigo le essa tabela para de fato atender no WhatsApp. A integracao real de WhatsApp e a Evolution API (app/Services/EvolutionApiService.php + public/api/whatsapp/webhook.php), que usa whatsapp_settings.evolution_api_key — uma config totalmente separada — e o webhook nao tem nenhuma logica de LLM/prompt/auto-resposta. Grep por agent_configs/api_key_enc/prompt/openai/anthropic dentro de public/api/whatsapp/ retorna zero. Ou seja: o usuario configura provedor de IA + chave + prompt achando que ativa o atendimento automatico (o toggle diz 'Ative para iniciar o atendimento automatico'), mas isso nao tem efeito algum no sistema.
- **Evidência**: Grep 'agent_settings.php|agent_configs|api_key_enc' em **/*.php retorna apenas public/configuracoes.php e o proprio public/api/agent_settings.php. Grep 'agent_configs|api_key_enc|prompt|provider' em public/api/whatsapp/ => No matches found. EvolutionApiService.php linha 18: $this->apiKey = $settings['evolution_api_key'] ?? ''; (usa whatsapp_settings, nao agent_configs). webhook.php nao referencia 
- **Correção**: Decidir o destino: (a) plugar agent_configs no fluxo de inbound do webhook.php (ler prompt/provider/api_key do tenant e gerar resposta via LLM antes de EvolutionApiService::sendText), ou (b) se o atendimento automatico ainda nao existe, deixar claro na UI que e 'configuracao para uso futuro' para nao induzir o usuario ao erro. Hoje a feature passa impressao de funcionar sem funcionar.

### 6. API do dashboard tambem resolve filiais por matriz_id (sempre NULL) — filtro ?origin=__filiais__/<id> retorna vazio `[CONF]`
- **Módulo**: dashboard · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/api/dashboard.php:26`
- **Problema**: Espelhando o bug do dashboard.php, quando a API recebe ?origin=... e a sessao e matriz, ela consulta accounts WHERE (id = :self OR matriz_id = :self) para validar/recortar os tenantIds. Como matriz_id e sempre NULL, $filiaisIds fica vazio e $allowed contem so a matriz. Resultado: ?origin=__filiais__ vira [0] (zera tudo) e ?origin=<id_da_filial> tambem cai em [0] porque o id da filial nao esta em $allowed. Mesmo que o dropdown fosse corrigido so no front, a API devolveria dados zerados/errados para qualquer recorte que envolva filial.
- **Evidência**: Linha 25-27: "SELECT id, tipo FROM accounts WHERE deleted_at IS NULL AND status='active' AND (id = :self OR matriz_id = :self)" ; Linha 32: $filiaisIds = ...filter(tipo==='filial') ; Linha 40: in_array($sid,$allowed) senao [0].
- **Correção**: Derivar matrizIds/filiaisIds/allowed a partir de account_vinculos (mesma fonte do getAccessibleAccountIds), nao de accounts.matriz_id. Idealmente reaproveitar getAccessibleAccountIds('dashboard') como conjunto base e classificar matriz vs filial consultando accounts.tipo dos ids retornados.

### 7. goals.php aceita POST/PUT/DELETE sem validar X-CSRF-Token `[CONF]`
- **Módulo**: dashboard · **Tipo**: seguranca · sev. revisada: media
- **Arquivo**: `public/api/goals.php:85`
- **Problema**: O endpoint de metas grava/atualiza/exclui registros na tabela goals (upsert no POST/PUT, DELETE por id) mas em nenhum ponto valida o cabecalho X-CSRF-Token contra $_SESSION['csrf_token']. A convencao do YURIS exige CSRF em toda mutacao, e endpoints equivalentes (ex.: api/taxes.php linha 38-43, api/master/create_filial.php linha 46-49) fazem essa checagem. Sem ela, a meta mensal de qualquer conta logada pode ser alterada/apagada via CSRF. O dashboard.js ja chama goals.php apenas com credentials:'same-origin' e sem enviar token (linha 603), entao adicionar a validacao exige tambem passar o token no fetch.
- **Evidência**: goals.php nao contem nenhuma ocorrencia de csrf/HTTP_X_CSRF_TOKEN; linha 85 "if ($method === 'POST' || $method === 'PUT') {" e linha 118 "if ($method === 'DELETE')" executam direto apos session_start, sem guard de CSRF. Comparar com taxes.php:38 "$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ...".
- **Correção**: Adicionar, antes das ramificacoes de POST/PUT/DELETE, a validacao padrao: ler X-CSRF-Token (ou csrf_token do body) e comparar com $_SESSION['csrf_token']; abortar 400 se invalido. Atualizar o fetch em dashboard.js (submitGoalForm) para enviar o header X-CSRF-Token.

### 8. Filtro de Origem (matriz/filiais) usa accounts.matriz_id (sempre NULL) — fix nao propagado `[CONF]`
- **Módulo**: escritorios · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/dashboard.php, public/processos.php, public/tarefas.php, public/api/dashboard.php:36`
- **Problema**: O dropdown/recorte 'Origem' (matriz vs filiais) e montado com query 'WHERE (id = :self OR matriz_id = :self)'. Como o vinculo matriz<->filial no YURIS e via account_vinculos e accounts.matriz_id e SEMPRE NULL para filiais vinculadas, essa query so retorna a propria matriz e NENHUMA filial. Consequencia: o filtro de Origem aparece vazio/inutil (opcoes '__filiais__' e por-filial nao listam nada) para matrizes que vinculam filiais via account_vinculos. O proprio escopo dos dados esta correto (usa getAccessibleAccountIds), mas o filtro de recorte por filial quebra. Esse exato bug ja foi corrigido em clientes.php (linha 56) e prospeccao.php (linha 45) trocando por getAccessibleAccountIds — mas a correcao NAO foi propagada para dashboard/processos/tarefas/api dashboard.
- **Evidência**: public/dashboard.php:36 "SELECT id, nome, tipo FROM accounts WHERE deleted_at IS NULL AND status = 'active' AND (id = :self OR matriz_id = :self) ..." | comparar com public/clientes.php:56 "// FIX: query antiga usava matriz_id direto na tabela accounts, mas o vinculo real no YURIS e via account_vinculos. Usamos getAccessibleAccountIds que ja sabe ler dos dois lugares (canonico)."
- **Correção**: Substituir as 4 queries por $ctx->getAccessibleAccountIds(<modulo>) + um IN (...) sobre accounts (mesmo padrao ja aplicado em clientes.php/prospeccao.php). Para dashboard usar modulo 'dashboard', processos 'processos', tarefas 'tarefas'.

### 9. Chat le da tabela advogado_convites ja DEPRECADA — advogados vinculados pelo fluxo atual nao aparecem no chat `[CONF]`
- **Módulo**: escritorios · **Tipo**: feature_orfa · sev. revisada: media
- **Arquivo**: `public/api/chat/conversas.php:83`
- **Problema**: O modulo de Chat resolve quais advogados externos (cross-tenant) podem conversar e aparecem na lista de usuarios consultando 'SELECT ... FROM advogado_convites WHERE status='accepted''. Porem o endpoint /api/advogado_convites.php retorna HTTP 410 (descontinuado) e o Model AdvogadoConvite esta marcado @deprecated; o fluxo atual de colaboracao com advogado e via advogado_vinculos (vinculo de conta) e resource_shares (compartilhamento pontual). Nada no codigo atual grava status='accepted' em advogado_convites. Resultado: advogados vinculados HOJE (pela tela Escritorios -> Vincular Advogado) NUNCA aparecem como participantes no chat, pois o chat olha apenas a tabela morta. Feature de colaboracao por chat ficou orfa da migracao convites->vinculos.
- **Evidência**: public/api/chat/conversas.php:83 "$sqlAdv = \"SELECT DISTINCT convidado_user_id FROM advogado_convites WHERE from_account_id = :from_acc AND status = 'accepted' ...\"" e :329 "FROM advogado_convites ac ... WHERE ac.from_account_id = ? AND ac.status = 'accepted' ..." | enquanto public/api/advogado_convites.php:2 "http_response_code(410)" e app/Models/AdvogadoConvite.php:6 "@deprecated Substituido p
- **Correção**: Migrar a logica de participantes/advogados do chat para ler de advogado_vinculos (status='active') e/ou resource_shares, alinhando com o fluxo atual. Manter advogado_convites apenas para historico, nao como fonte de autorizacao viva.

### 10. Coluna dre_accounts.descricao e lida pelo TaskLink mas NUNCA escrita pelo modulo DRE (vinculo em tarefas mostra '#ID') `[CONF]`
- **Módulo**: financas · **Tipo**: feature_orfa · sev. revisada: media
- **Arquivo**: `app/Models/TaskLink.php:42`
- **Problema**: A migration 020 adicionou a coluna `descricao` em dre_accounts JUSTAMENTE para o TaskLink resolver o nome de um vinculo do tipo 'dre_account' (comentario da propria migration: 'Sem essa coluna ... o vinculo exibe apenas #ID'). TaskLink::resolveNome faz `SELECT descricao FROM dre_accounts`. POReM o modulo DRE nunca grava `descricao`: DREAccount::create (DREAccount.php:70) e DREAccount::update (DREAccount.php:105) so inserem/atualizam `nome`, e o form em dre.js/financas.php so tem o campo `nome` (id=nome). Logo `descricao` fica SEMPRE NULL e o TaskLink cai no fallback `?: "#{$id}"`, exibindo '#ID' em vez do nome — o sintoma que a migration dizia ter corrigido continua. Pior: task_link_search.php:83 (a busca para CRIAR o vinculo) retorna `nome AS label`, entao o usuario escolhe pelo nome e depois ve '#ID' — incoerencia search(nome) x resolve(descricao). O proprio database/RELATORIO_DIVERGENCIAS.md secao 4.3 ja registra essa divergencia.
- **Evidência**: TaskLink.php:41-44: `case 'dre_account': $s = $pdo->prepare('SELECT descricao FROM dre_accounts WHERE id = ? LIMIT 1'); $s->execute([$id]); return [(string)($s->fetchColumn() ?: "#{$id}"), ''];` // vs DREAccount.php:70 INSERT ... (account_id, codigo, nome, tipo, valor_fixo, recorrencia, data_referencia, ativo) — sem descricao
- **Correção**: Trocar o SELECT do TaskLink para `SELECT nome FROM dre_accounts` (alinha com task_link_search que usa nome AS label e nome e NOT NULL), OU passar a gravar `descricao` no DREAccount::create/update. A 1a opcao e a correta e imediata.

### 11. financas.php consolida filiais no render server-side, mas as APIs do DRE/impostos sao 100% por conta — KPIs da matriz mudam sozinhos apos o load do JS `[CONF]`
- **Módulo**: financas · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/api/dre_accounts.php:19`
- **Problema**: Inconsistencia de escopo de tenant dentro do mesmo modulo. O render server-side de financas.php usa `getAccessibleAccountIds('financas')` (financas.php:20), que para uma MATRIZ inclui filiais vinculadas + advogados; com isso os KPIs iniciais (receita, despesa, impostos, cards fechados) saem CONSOLIDADOS. Mas as APIs que o dre.js chama logo em seguida hardcodam `$tenantIds = [$accountId]` (dre_accounts.php:19, taxes.php:16, dre_codes.php:17) — so a propria conta. Quando dre.js load() roda, ele SOBRESCREVE os KPIs (dre.js:146-151 _s('sumReceita'...), e o painel de impostos recalcula com a receita propria), entao os numeros da matriz ENCOLHEM visivelmente no carregamento (consolidado -> so a matriz). Alem disso e incoerente com dashboard.php, que consolida o DRE das filiais (dashboard.php:101 `account_id IN $inSql` montado de getAccessibleAccountIds('dashboard')). Ou seja: dashboard mostra DRE consolidado da matriz, financas mostra consolidado no 1o paint e depois so-a-matriz — o usuario ve totais diferentes para o mesmo dado.
- **Evidência**: dre_accounts.php:17-19: `// Matriz NAO consolida automaticamente o DRE das filiais. $tenantIds = [$accountId];` vs financas.php:20: `$tenantIds = $ctx->getAccessibleAccountIds('financas');` vs dashboard.php:120 `SELECT tipo, ... FROM dre_accounts $dreWhere` com $dreWhere usando IN($inSql) de getAccessibleAccountIds('dashboard')
- **Correção**: Decidir UMA regra e aplicar nos 3 lugares. Se DRE/impostos sao isolados por conta (como dizem os comentarios das APIs), entao financas.php deve usar [$accountId] no render (nao getAccessibleAccountIds) para nao consolidar no 1o paint; e o dashboard tambem precisaria revisar. Se a matriz DEVE consolidar, entao as 3 APIs (dre_accounts, taxes, dre_codes) devem usar getAccessibleAccountIds('financas'). Hoje os tres discordam entre si.

### 12. Vincular intimacao nao ve processos de filiais `[CONF]`
- **Módulo**: intimacoes · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/api/push/search_processos.php:67`
- **Problema**: Filtra so p.account_id=:acc; falta getAccessibleAccountIds(processos) como processes.php:58. Matriz nao acha processo de filial ao vincular.
- **Evidência**: l.67 where p.account_id = :acc
- **Correção**: usar getAccessibleAccountIds(processos)

### 13. link_process valida so a conta propria `[CONF]`
- **Módulo**: intimacoes · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/api/push/persist.php:229`
- **Problema**: l.229 e 394 usam account_id=:acc; create_prazo l.415 usa getAccessibleAccountIds. Vincular processo de filial da 403.
- **Evidência**: l.229 id=:id AND account_id=:acc
- **Correção**: assertProcessoAcessivel ou getAccessibleAccountIds

### 14. advogados.php usa accounts.matriz_id (sempre NULL) para achar a matriz — campo 'Matriz' do advogado nunca aparece `[CONF]`
- **Módulo**: master · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/api/master/advogados.php:66`
- **Problema**: No GET ?id=X o endpoint faz LEFT JOIN accounts am ON am.id = a.matriz_id para trazer matriz_id/matriz_nome do advogado. A coluna accounts.matriz_id é SEMPRE NULL no YURIS (vínculo real está em account_vinculos). Confirmado no banco: a conta #9 'Filial São Paulo' (que tem os advogados users #14 e #15) tem matriz_id=NULL, embora exista account_vinculos (matriz_account_id=1, filial_account_id=9, status=active). Resultado: ao abrir um advogado de uma filial no Painel Master, matriz_nome volta NULL e a linha 'Matriz' (master.php:2691, renderizada só quando d.matriz_nome existe) NUNCA aparece.
- **Evidência**: LEFT JOIN accounts am ON am.id = a.matriz_id (advogados.php:66) — am.id AS matriz_id, am.nome AS matriz_nome (linha 63). DB: SELECT id,nome,tipo,matriz_id FROM accounts WHERE tipo='filial' → 9, Filial São Paulo, filial, NULL
- **Correção**: Trocar o JOIN por account_vinculos: LEFT JOIN account_vinculos av ON av.filial_account_id = a.id AND av.status='active' LEFT JOIN accounts am ON am.id = av.matriz_account_id. Reaproveitar a lógica de App\Models\Account::listFiliaisVinculadas.

### 15. Botão 'Editar assinatura' no detalhe da conta chama openEditSub() — função inexistente (ReferenceError) `[CONF]`
- **Módulo**: master · **Tipo**: ponta_solta · sev. revisada: media
- **Arquivo**: `public/master.php:2655`
- **Problema**: No rodapé do modal de detalhe da conta (viewAcc) o botão 'Editar assinatura' tem onclick="openEditSub(${sub.id})". Não existe nenhuma função openEditSub definida em master.php (varredura de todos os onclick confirmou que é a única referência sem definição). A função real para editar assinatura é openSubModal(id) (linha 3041), usada pela aba Assinaturas. Clicar no botão dispara ReferenceError: openEditSub is not defined e não faz nada — quebra silenciosa, mesmo padrão do antigo window._applyCardSelection.
- **Evidência**: foot += `<button class="btn-mst btn-mst-primary" onclick="openEditSub(${sub.id})">Editar assinatura</button>`; (master.php:2655). Definição existente: async function openSubModal(id) { ... } (master.php:3041). grep openEditSub → só 1 ocorrência (a chamada).
- **Correção**: Trocar openEditSub(${sub.id}) por openSubModal(${sub.id}). Atenção: openSubModal lê de _subsCache, populada ao carregar a aba Assinaturas; pode ser necessário garantir o cache (await loadSubscriptions/loadBilling) antes de abrir a partir do detalhe da conta.

### 16. processos.php usa accounts.matriz_id (sempre NULL) para montar origem matriz/filial — fix existe em Clientes e Prospecção mas não foi propagado `[CONF]`
- **Módulo**: processos · **Tipo**: gap_matriz_filial · sev. revisada: alta
- **Arquivo**: `public/processos.php:38`
- **Problema**: O bloco que monta $origin_accounts (linhas 32-43) consulta accounts WHERE (id = :self OR matriz_id = :self). Pela convenção do YURIS, accounts.matriz_id é SEMPRE NULL — o vínculo matriz<->filial é via account_vinculos. Logo a query só retorna a própria matriz, nunca as filiais. Este é EXATAMENTE o mesmo bug que já foi corrigido nos módulos irmãos: clientes.php:56 ('FIX: query antiga usava matriz_id direto na tabela accounts, mas o vínculo real no YURIS é via account_vinculos. Usamos getAccessibleAccountIds') e prospeccao.php:45-46 ('FIX: usa getAccessibleAccountIds... Query antiga com matriz_id falhava porque vínculo real é via account_vinculos'). A correção não foi propagada para processos.php. Consequência prática: como $origin_accounts fica com 1 item, o filtro de Origem (Matriz/Filial) — renderizado só quando count($origin_accounts) > 1 (linha 1052) — NUNCA aparece para a matriz na tela de Processos, embora apareça em Clientes e Prospecção. A matriz perde a capacidade de filtrar o board por filial específica.
- **Evidência**: public/processos.php:34-43 → $stmt_o = $pdo_o->prepare("SELECT id, nome, tipo FROM accounts WHERE deleted_at IS NULL AND status = 'active' AND (id = :self OR matriz_id = :self) ORDER BY ..."); $stmt_o->execute(['self' => $ctx_users->getAccountId()]); $origin_accounts = $stmt_o->fetchAll(...). Comparar com clientes.php:62 ($accessibleIds = $ctx->getAccessibleAccountIds('clientes'); ... WHERE id IN 
- **Correção**: Substituir a query por accountId/matriz_id pelo padrão canônico já usado em clientes.php/prospeccao.php: obter $accessibleIds = $ctx_users->getAccessibleAccountIds('processos') e, se count > 1, SELECT id, nome, tipo FROM accounts WHERE id IN (...placeholders...) AND deleted_at IS NULL AND status IN ('active','trial','overdue') ORDER BY CASE WHEN tipo='matriz' THEN 0 ELSE 1 END, nome ASC. Isso reativa o filtro de Origem para a matriz e alinha o módulo aos irmãos.

### 17. Vincular processo grava card_id sem validar tenant do card (vaza contato_id cross-tenant) `[CONF]`
- **Módulo**: prospeccao · **Tipo**: seguranca · sev. revisada: alta
- **Arquivo**: `public/api/processes.php:94`
- **Problema**: A feature 'Processos do Cliente' da Prospeccao chama window._vincularProcesso/_desvincularProcesso (prospeccao.php linhas 2511-2516 e 2461-2467) fazendo PUT /api/processes.php com body {id: processId, card_id: _currentCardId}. No handler PUT, processes.php so valida o PROCESSO via TenantGuard::assertProcessoAcessivel($ctx, $id) (linha 94), mas NUNCA valida o card_id recebido. Processo::update grava card_id (esta no $allowed, Processo.php linha 195) e, pior, executa 'SELECT contato_id FROM cards WHERE id = ?' SEM filtro de tenant (Processo.php linhas 215-217) e copia esse contato_id pro processo do atacante. Um usuario do tenant A pode montar PUT {id:<processo proprio>, card_id:<card do tenant B>} e (a) criar vinculo cross-tenant e (b) herdar o contato_id de um card alheio. Falta um TenantGuard::assertCardAcessivel($ctx, (int)$input['card_id']) antes do update. Note que assertCardAcessivel ja existe em TenantGuard.php (linha 30), so nao foi chamado aqui.
- **Evidência**: if ($method === 'PUT') { ... $id = $input['id'] ?? null; ... TenantGuard::assertProcessoAcessivel($ctx, (int)$id); unset($input['account_id'], $input['account_seq']); ... $ok = Processo::update((int)$id, $input); // <-- $input['card_id'] entra sem validacao de tenant do card
- **Correção**: No handler PUT (e POST) de processes.php, se isset($input['card_id']) && $input['card_id'], chamar TenantGuard::assertCardAcessivel($ctx, (int)$input['card_id']) antes de Processo::update/create. E em Processo.php, ao herdar contato_id do card (linha 215) e do cliente (linha 227), restringir o SELECT aos account_ids acessiveis.

### 18. Filtro de Origem usa accounts.matriz_id (sempre NULL) — filiais nunca aparecem para a matriz `[CONF]`
- **Módulo**: tarefas · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/tarefas.php:31`
- **Problema**: Ao montar o dropdown de filtro de Origem (Matriz/Filiais), a query busca filiais via `WHERE (id = :self OR matriz_id = :self)`. Pela convenção do YURIS, accounts.matriz_id e SEMPRE NULL — o vinculo matriz<->filial vive em account_vinculos. Logo $origin_accounts nunca traz filiais; como o select so e renderizado quando count($origin_accounts) > 1 (linha 145), o filtro de Origem NUNCA aparece para uma matriz, mesmo ela tendo filiais. Pior: o Kanban EXIBE tarefas de filiais corretamente (task_boards.php usa getAccessibleAccountIds('tarefas')), entao o usuario ve tarefas de filiais mas nao tem como filtra-las por origem. O proprio clientes.php (linhas 55-78) ja corrigiu exatamente este bug e documenta: 'query antiga usava matriz_id direto na tabela accounts, mas o vinculo real no YURIS e via account_vinculos. Usamos getAccessibleAccountIds que ja sabe ler dos dois lugares (canonico).'
- **Evidência**: FROM accounts WHERE deleted_at IS NULL AND status = 'active' AND (id = :self OR matriz_id = :self)
- **Correção**: Trocar pela mesma abordagem do clientes.php: obter $accessibleIds = $ctx->getAccessibleAccountIds('tarefas') e fazer SELECT id,nome,tipo FROM accounts WHERE id IN (...$accessibleIds). Assim filiais vinculadas via account_vinculos (respeitando sync_tarefas) aparecem no filtro, alinhando o filtro com o que o Kanban realmente mostra.

### 19. Recorrencia personalizada (custom): unidade (semana/mes/ano) nunca e persistida — vira sempre 'dia' `[CONF]`
- **Módulo**: tarefas · **Tipo**: ponta_solta · sev. revisada: alta
- **Arquivo**: `app/Models/TaskRecurrence.php:18`
- **Problema**: No fluxo de tarefa recorrente custom, o frontend envia `unidade` (day/week/month/year) tanto na criacao (tarefas.js linha ~1479) quanto na edicao (linha ~610). O calculo calcularProximaData() le $this->data['unidade'] (linha 87: $unit = $this->data['unidade'] ?? 'day'). Porem: (1) TaskRecurrence::create NAO insere unidade no INSERT; (2) o UPDATE de recorrencia em tasks.php (PUT, linha 183) so percorre ['tipo','intervalo','dias_semana','dia_mes','data_inicio','data_fim'] — unidade fica de fora; (3) a tabela task_recurrences NAO tem coluna `unidade` (schema.sql linhas 2077-2088). Consequencia: toda recorrencia 'custom' cai no fallback 'day'. 'A cada 2 semanas/meses/anos' se comporta silenciosamente como 'a cada 2 dias' — gera instancias na cadencia errada.
- **Evidência**: INSERT INTO task_recurrences (tipo, intervalo, dias_semana, dia_mes, data_inicio, data_fim) VALUES (:tipo, :intervalo, :dias_semana, :dia_mes, :data_inicio, :data_fim) // sem 'unidade' --- e em calcularProximaData(): case 'custom': $unit = $this->data['unidade'] ?? 'day'; $dt->modify("+{$int} {$unit}");
- **Correção**: Adicionar coluna `unidade` (enum/ varchar) em task_recurrences via migration; incluir unidade no INSERT de TaskRecurrence::create e no array de campos atualizaveis da recorrencia em tasks.php (PUT). Sem isso o tipo 'custom' nunca respeita a unidade escolhida.

### 20. GET /api/users.php lista usuarios de UMA conta so — matriz nao ve usuarios das filiais `[CONF]`
- **Módulo**: usuarios · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `public/api/users.php:81`
- **Problema**: A listagem de usuarios filtra rigidamente por uma unica conta (WHERE deleted_at IS NULL AND account_id = :acc). Diferente de praticamente todo o resto do sistema, NAO usa getAccessibleAccountIds/getAccessibleUsers. Consequencia: quando a sessao e uma MATRIZ, a tela Gestao de Usuarios (usuarios.php, que faz fetch(apiUsers) em loadUsers) mostra apenas os usuarios da propria matriz e omite todos os usuarios das filiais vinculadas. O mesmo _allUsers alimenta o seletor de membros de Setores (renderTeamMembersGrid), entao a matriz NAO consegue adicionar colaboradores de filial a um setor. No mesmo modulo, public/api/task_users.php ja faz o certo: $users = $ctx->getAccessibleUsers(true, 'tarefas'). E o caso classico 'feature existe num lugar (task_users) mas nao foi propagada para o endpoint irmao (users.php)'.
- **Evidência**: public/api/users.php:76-83 — "SELECT id, ROW_NUMBER() OVER (PARTITION BY account_id ORDER BY id ASC) AS display_id, nome, login AS email, perfil, $selRole, status, $selAccount, $selCodigo, created_at, updated_at FROM users WHERE deleted_at IS NULL AND account_id = :acc ORDER BY nome ASC"; $stmt->execute(['acc' => $accountId]); — contrasta com task_users.php:20 "$users = $ctx->getAccessibleUsers(tr
- **Correção**: Para sessao matriz, escopar a listagem por $ctx->getAccessibleAccountIds() (account_id IN (...)) e adicionar JOIN em accounts para retornar account_nome e account_tipo, espelhando getAccessibleUsers(). Alternativamente, fazer usuarios.php consumir um endpoint que ja retorne matriz+filiais. Avaliar regra de negocio: a tela de gestao pode querer ser estritamente por-conta, mas o seletor de membros de Setores claramente precisa enxergar as filiais.

### 21. Coluna 'escopo' (matriz_e_filiais) é totalmente órfã — matriz NUNCA recebe eventos das filiais `[CONF]`
- **Módulo**: webhooks · **Tipo**: feature_orfa · sev. revisada: media
- **Arquivo**: `app/Services/WebhookDispatcher.php:360`
- **Problema**: A migration 067 criou a coluna webhook_endpoints.escopo ENUM('tenant_only','matriz_e_filiais','filial_only') 'para suportar escopo matriz/filial', com índice idx_we_escopo. A API (public/api/webhooks.php) valida e grava esse campo no INSERT (l.286-287,301) e UPDATE (l.361-364), e a UI tem o <select id='whEscopo'> com a opção 'Matriz + filiais vinculadas' (webhooks.php l.655-659). MAS findSubscribers() — o único ponto que escolhe quem recebe o evento — filtra apenas 'WHERE ativo = 1 AND deleted_at IS NULL AND account_id = ?', ignorando 'escopo' por completo. Como todo fire() é chamado com o accountId do tenant originador (ex.: public/api/cards.php:114 fire($accountId,'card.created',...), processo_prazos.php:120 fire($ownerAcc,...)), um webhook de matriz configurado como 'matriz_e_filiais' NUNCA será encontrado quando uma FILIAL disparar um evento (que vem com o account_id da filial). O recurso vendido na UI não faz absolutamente nada. É o mesmo bug-classe já documentado em app/Helpers/AccountContext.php (cabeçalho: 'Era a causa raiz do bug: a matriz não puxava processos/cards da filial'), aqui re-introduzido na camada de webhooks. A infra para corrigir já existe (account_vinculos status='active' + AccountContext::getAccessibleAccountIds()).
- **Evidência**: WebhookDispatcher.php:360 $stmt = $pdo->prepare("SELECT * FROM webhook_endpoints WHERE ativo = 1 AND deleted_at IS NULL AND account_id = ?"); // escopo nunca é consultado
- **Correção**: Em findSubscribers(), quando o webhook tem escopo='matriz_e_filiais' (ou para qualquer tenant que seja matriz), expandir a busca para incluir os account_ids das filiais vinculadas (SELECT filial_account_id FROM account_vinculos WHERE matriz_account_id = :acc AND status='active'), ou inversamente: ao disparar de uma filial, também localizar webhooks da matriz vinculada cujo escopo='matriz_e_filiais'. Reaproveitar a lógica de AccountVinculo/AccountContext. Enquanto não houver consumo do campo, remover a opção da UI para não prometer o que não funciona.

### 22. Vinculo de chat aceita card_id/user_id de outro tenant sem validacao (IDOR de escrita) `[CONF]`
- **Módulo**: whatsapp · **Tipo**: gap_matriz_filial · sev. revisada: alta
- **Arquivo**: `public/api/whatsapp/chats.php:118`
- **Problema**: Na acao 'link', o team_id e validado contra o tenant (Team::findById($teamId,$accountId)) logo acima, mas linked_card_id (card_id) e linked_user_id (user_id) sao gravados direto do payload do cliente SEM nenhuma checagem de propriedade/tenant. Pior: WhatsAppMessage::linkChat() le 'SELECT contato_id, cliente_nome FROM cards WHERE id = ?' SEM filtro de account_id (Model linha 857), entao um card de OUTRA conta pode ser lido e seu contato_id (e cliente_nome, no caminho findOrCreateByJid) anexado ao chat do atacante, criando ainda linhas em contato_vinculos. Inconsistente com a validacao de team_id imediatamente acima e com o padrao IDOR-fix aplicado em users.php/processes.php.
- **Evidência**: $teamId = ...; if ($teamId !== null) { if (!Team::findById($teamId, $accountId)) $teamId = null; } $linkData = ['linked_card_id' => $payload['card_id'] ?? null, 'linked_user_id' => $payload['user_id'] ?? null, ...]; // card_id e user_id SEM validacao de tenant
- **Correção**: Validar card_id via AccountContext (assertCanWrite('card',$id) ou checar cards.account_id IN getAccessibleAccountIds('chat')) e user_id contra getAccessibleUsers()/account_id acessivel antes de gravar. Adicionar filtro de account_id no SELECT de cards dentro de linkChat().

### 23. Modulo Clientes nao mostra processos vinculados (reverse lookup orfao da migration 093) `[CONF]`
- **Módulo**: x_features_orfas · **Tipo**: feature_orfa · sev. revisada: media
- **Arquivo**: `public/clientes.php`
- **Problema**: A migration 093 adicionou processos.cliente_id + index idx_processos_cliente declaradamente 'pra lookup reverso (quais processos desse cliente)'. A Prospeccao (public/prospeccao.php) ja tem essa feature completa para cards (loadProcessosDoCliente via /api/processes.php?card_id=, secao 'Processos vinculados' no modal do lead). O modulo Clientes — que e a 'base real de clientes do escritorio' e o destino natural desse vinculo — NAO tem nada disso: clientes.php (1507 linhas) tem ZERO ocorrencias de 'processo', 'processes.php' ou 'cliente_id'. O modal do cliente (modalCliente, linha 518) so tem Historico; nao ha bloco de processos. Resultado: quando um processo e vinculado a um cliente pela aba Clientes, o usuario nunca consegue ver esse vinculo de volta a partir do cliente. E exatamente o padrao do bug de referencia (clientes-em-processo), so que do lado inverso. O dado existe no banco mas a feature nao esta plugada onde faria sentido.
- **Evidência**: Migration 093: '-- 2) Index pra lookup reverso ("quais processos desse cliente").' + 'ALTER TABLE processos ADD INDEX idx_processos_cliente (cliente_id)'. Em prospeccao.php:2435 existe 'async function loadProcessosDoCliente(cardId){... fetch(`/api/processes.php?card_id=${...}`)...}'. Grep por 'processo|processes.php|cliente_id' em public/clientes.php = 0 ocorrencias.
- **Correção**: No modal do cliente (clientes.php), adicionar bloco 'Processos vinculados' espelhando prospeccao.php: chamar /api/processes.php?cliente_id=ID (depois de adicionar esse filtro — ver achado relacionado) e listar os processos com link 'Ver ->'. Reaproveitar o markup/JS de loadProcessosDoCliente da prospeccao.

### 24. Processo::list()/API de processos nao aceita filtro por cliente_id (so card_id) `[CONF]`
- **Módulo**: x_features_orfas · **Tipo**: ponta_solta · sev. revisada: media
- **Arquivo**: `app/Models/Processo.php:91`
- **Problema**: Apesar de processos.cliente_id existir (migration 093) e ser gravado em Processo::create/update, NAO ha como consultar processos por cliente_id. Processo::list() so implementa o filtro card_id (linhas 91-94) e public/api/processes.php so le $_GET['card_id'] (linha 64). Grep em todo public/api por filtro WHERE/GET de cliente_id retorna vazio. Isso e a ponta solta que impede o achado anterior (reverse lookup no modulo Clientes) de ser implementado sem antes corrigir aqui — e indica que metade da feature 093 (o lado de leitura) nunca foi escrita.
- **Evidência**: Processo.php:91-93: 'if (isset($filters[\'card_id\'])) { $sql .= \' AND p.card_id = :card_id\'; $params[\'card_id\'] = $filters[\'card_id\']; }'. processes.php:64: 'if (isset($_GET[\'card_id\']) && $_GET[\'card_id\'] !== \'\') $filters[\'card_id\'] = (int)$_GET[\'card_id\'];'. Nao existe equivalente para cliente_id.
- **Correção**: Em Processo::list() adicionar bloco simetrico: if (isset($filters['cliente_id'])) { $sql .= ' AND p.cliente_id = :cliente_id'; $params['cliente_id'] = (int)$filters['cliente_id']; } e em processes.php ler $_GET['cliente_id']. Sem isso o vinculo 093 e write-only.

### 25. Filtro de Origem em Tarefas usa accounts.matriz_id (sempre NULL) — filtro nunca aparece pro matriz `[CONF]`
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\tarefas.php:31`
- **Problema**: A query que alimenta o dropdown de Origem (matriz/filiais) filtra por '(id = :self OR matriz_id = :self)'. Como accounts.matriz_id é SEMPRE NULL (vínculo real é via account_vinculos), a query retorna apenas a própria conta do matriz. O dropdown de Origem é renderizado só quando count($origin_accounts) > 1 (tarefas.php:145), então ele NUNCA aparece pro matriz. Resultado: o matriz não consegue filtrar tarefas por filial. Confirmado no banco: account #9 (Filial São Paulo) tem matriz_id=NULL; o vínculo está em account_vinculos (matriz #1 <-> filial #9, active). As telas Prospecção (prospeccao.php:47) e Clientes (clientes.php:62) já foram corrigidas pra usar getAccessibleAccountIds — Tarefas não recebeu o fix. A listagem de tarefas em si funciona (usa getAccessibleAccountIds via API), só o filtro de origem está morto.
- **Evidência**: "SELECT id, nome, tipo FROM accounts WHERE deleted_at IS NULL AND status = 'active' AND (id = :self OR matriz_id = :self) ORDER BY CASE WHEN tipo = 'matriz' THEN 0 ELSE 1 END, nome ASC" ... $stmt_t->execute(['self' => $ctx_t->getAccountId()]); // e em tarefas.php:145: <?php if (count($origin_accounts) > 1): ?>
- **Correção**: Substituir a query por getAccessibleAccountIds('tarefas') + SELECT ... WHERE id IN (...), exatamente como feito em clientes.php:62-75 e prospeccao.php:47-60.

### 26. Filtro de Origem em Processos usa accounts.matriz_id (sempre NULL) — filtro nunca aparece pro matriz `[CONF]`
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial · sev. revisada: alta
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\processos.php:38`
- **Problema**: Mesmo bug de tarefas.php: a query do dropdown de Origem usa '(id = :self OR matriz_id = :self)', que só retorna a própria conta porque accounts.matriz_id é sempre NULL. O filtro de Origem em processos.php:1052 é gated por count($origin_accounts) > 1, então nunca renderiza pro matriz. window.YURIS_ORIGIN_ACCOUNTS (processos.php:1386) também fica vazio. O matriz não consegue filtrar processos por filial, embora as telas Prospecção e Clientes já tenham sido corrigidas pra usar getAccessibleAccountIds.
- **Evidência**: "SELECT id, nome, tipo FROM accounts WHERE deleted_at IS NULL AND status = 'active' AND (id = :self OR matriz_id = :self) ORDER BY CASE WHEN tipo = 'matriz' THEN 0 ELSE 1 END, nome ASC" ... $stmt_o->execute(['self' => $ctx_users->getAccountId()]);
- **Correção**: Trocar pela mesma abordagem de clientes.php:62-75: getAccessibleAccountIds('processos') -> SELECT id,nome,tipo FROM accounts WHERE id IN (?).

### 27. Filtro de Origem no Dashboard usa accounts.matriz_id (sempre NULL) — escopo Matriz/Filiais quebrado `[CONF]`
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial · sev. revisada: media
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\dashboard.php:36`
- **Problema**: A query que monta $origin_accounts usa '(id = :self OR matriz_id = :self)' e só retorna a própria conta (matriz_id sempre NULL). Como o filtro inteiro é gated por count($origin_accounts) > 1 (dashboard.php:387, 407), ele nunca aparece. Pior: mesmo via querystring ?origin=__filiais__, o cálculo em dashboard.php:48-49 ($matrizIds/$filiaisIds derivados dessa query quebrada) resulta em $filiaisIds vazio -> $tenantIds = [0] -> KPIs zerados. O label de escopo (dashboard.php:397) também conta filiais errado.
- **Evidência**: "SELECT id, nome, tipo FROM accounts WHERE deleted_at IS NULL AND status = 'active' AND (id = :self OR matriz_id = :self) ..." ... $filiaisIds = array_map(fn($a) => (int)$a['id'], array_filter($origin_accounts, fn($a) => $a['tipo'] === 'filial'));
- **Correção**: Substituir a query por getAccessibleAccountIds('dashboard') + SELECT WHERE id IN (...), igual clientes.php. Reaproveitar $tenantIds já calculado em vez de refazer a query.

### 28. API do Dashboard usa accounts.matriz_id (sempre NULL) — filtro de origem nos KPIs via API retorna vazio `[CONF]`
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial · sev. revisada: alta
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\api\dashboard.php:27`
- **Problema**: Quando o frontend manda ?origin=__filiais__ ou ?origin=<id_filial>, a API resolve as contas com 'SELECT id, tipo FROM accounts WHERE ... AND (id = :self OR matriz_id = :self)'. Como matriz_id é sempre NULL, $accs só contém o matriz; logo $filiaisIds fica vazio e $tenantIds vira [0] (api/dashboard.php:35-40), zerando os KPIs ao filtrar por filial. A listagem base usa getAccessibleAccountIds corretamente (linha 15), mas o recorte por origem refaz a query quebrada.
- **Evidência**: "SELECT id, tipo FROM accounts WHERE deleted_at IS NULL AND status='active' AND (id = :self OR matriz_id = :self)" ... $filiaisIds = array_map(fn($a) => (int)$a['id'], array_filter($accs, fn($a) => $a['tipo'] === 'filial'));
- **Correção**: Derivar $matrizIds/$filiaisIds a partir de getAccessibleAccountIds('dashboard') + consulta do tipo por id IN (...), ou consultar accounts via account_vinculos. Nunca usar accounts.matriz_id.

### 29. Central de notificacoes (account_notifications) e escrita mas nunca lida pela UI `[CONF]`
- **Módulo**: x_pontas_soltas · **Tipo**: feature_orfa · sev. revisada: media
- **Arquivo**: `public/api/account_notifications.php:1`
- **Problema**: Existe API completa (GET lista, GET ?count=1 badge, PATCH marcar lida/todas) com model AccountNotification, e a tabela account_notifications RECEBE INSERTs quando ha solicitacoes/decisoes de alocacao de monitoramento (push/requests.php:528 e :571). Porem NENHUM JS/PHP do frontend faz fetch em account_notifications.php — nao existe sino/contador/central que leia essas notificacoes. As notificacoes acumulam no banco e o usuario nunca as ve. E o mesmo padrao do caso de referencia (feature criada mas nao plugada onde deveria).
- **Evidência**: push/requests.php:528 `INSERT INTO account_notifications ...` (e :571) gravam notificacoes; account_notifications.php expoe GET/PATCH; grep 'account_notifications.php' no frontend = 0 callers (so o proprio endpoint). CLAUDE.md:97 lista o endpoint mas nenhum JS o consome.
- **Correção**: Plugar a central de notificacoes na UI: adicionar um sino no header (ex.: includes/topbar/sidebar) que faz GET /api/account_notifications.php?count=1 pra badge e GET pra lista, com PATCH ao marcar lida. Sem isso, todo o pipeline de notificacao de monitoramentos esta invisivel ao usuario.

### 30. Anexos de tarefa (task_attachments) — API completa sem nenhum caller na UI de Tarefas `[CONF]`
- **Módulo**: x_pontas_soltas · **Tipo**: feature_orfa · sev. revisada: alta
- **Arquivo**: `public/api/task_attachments.php:1`
- **Problema**: task_attachments.php implementa upload, listagem, download (?action=download&id=) e delete de anexos, com tabela task_attachments. Mas tarefas.js (que centraliza TODAS as chamadas via GET/POST/PUT/PATCH/DEL em BASE '/api') nunca chama task_attachments.php, e tarefas.php nao tem UI de anexo (grep por anexo/attach/upload no JS e no PHP = 0, exceto CSS background-attachment). A feature de anexar arquivos em tarefas existe no backend mas e inalcancavel pelo usuario.
- **Evidência**: task_attachments.php:139 `INSERT INTO task_attachments (...)`, :104 monta download_url; tarefas.js mapeia /task_users, /task_checklist, /task_comments, /task_time_entries, /task_columns, /task_links, /task_boards, /tasks, /processo_tarefas — mas NUNCA /task_attachments. grep 'task_attachments' no frontend so acha .htaccess + o proprio endpoint.
- **Correção**: Adicionar no modal de tarefa (tarefas.php + tarefas.js) a secao de anexos: input file -> POST /api/task_attachments.php, listagem com link de download, botao remover. Ou, se a feature foi descontinuada, remover endpoint+tabela+model pra nao deixar superficie de upload sem uso.

### 31. Lembretes de tarefa (task_reminders) — API completa sem nenhum caller `[CONF]`
- **Módulo**: x_pontas_soltas · **Tipo**: feature_orfa · sev. revisada: media
- **Arquivo**: `public/api/task_reminders.php:1`
- **Problema**: task_reminders.php implementa GET (findByTask), POST (cria lembrete com lembrar_em + canal) e DELETE, com model TaskReminder e TenantGuard. Nenhum codigo do frontend chama esse endpoint: grep 'task_reminders' em todo o repo (fora docs) retorna apenas o proprio arquivo; tarefas.js/tarefas.php nao tem UI de lembrete (grep lembrete/remind = 0). Feature de lembrete construida e inacessivel.
- **Evidência**: task_reminders.php:42 `TaskReminder::create($taskId,$userId,$input['lembrar_em'],...)`; grep 'task_reminders.php' repo-wide (excl. docs) = 0 arquivos alem do endpoint. tarefas.js nao tem path '/task_reminders.php'.
- **Correção**: Expor no modal de tarefa um controle de lembrete (data/hora + canal) que faca POST /api/task_reminders.php e liste/remova via GET/DELETE. Conferir tambem se ha um tick/cron que dispara os lembretes — se nao houver consumidor, a feature esta morta nas duas pontas.

---

## MÉDIA (41)

### 1. Validacao de referencia de mencao em mensagens.php nao escopa por tenant (IDOR/enumeracao cross-tenant)
- **Módulo**: chat_interno · **Tipo**: seguranca
- **Arquivo**: `public/api/chat/mensagens.php:114`
- **Problema**: Ao salvar mencoes, mensagens.php valida a EXISTENCIA da referencia (usuario/processo/card) apenas por id, SEM filtrar por account_id/tenant: 'SELECT id FROM processos WHERE id = ? LIMIT 1', 'SELECT id FROM cards WHERE id = ? LIMIT 1', 'SELECT id FROM users WHERE id = ? AND deleted_at IS NULL'. O texto_exibido e url_destino vem do cliente. Um usuario autenticado pode enviar um POST forjado mencionando IDs de OUTRO tenant: a mensagem e aceita se o id existir em qualquer conta, confirmando a existencia do recurso alheio (enumeracao), e persiste um chip com rotulo arbitrario controlado pelo atacante para todos os participantes. A busca (mencoes.php) e escopada, entao o fluxo de UI normal nao expoe esses ids — mas a validacao server-side nao impoe o mesmo limite. Diferente dos POSTs de participantes, que passam por _validParticipantIds (escopo de tenant); aqui a referencia nao tem barreira equivalente.
- **Evidência**: mensagens.php:114-119 $valid = match($tipo) { 'usuario' => ...WHERE id = ? AND deleted_at IS NULL..., 'processo' => 'SELECT id FROM processos WHERE id = ? LIMIT 1', 'card' => 'SELECT id FROM cards WHERE id = ? LIMIT 1' }; (nenhum AND account_id IN (...)) mensagens.php nao importa AccountContext (grep AccountContext/getAccessibleAccountIds = vazio).
- **Correção**: Escopar a validacao da referencia por tenant: instanciar AccountContext::fromSession() em mensagens.php e exigir que processos/cards/clientes pertencam a getAccessibleAccountIds(<modulo>) (e users a usuarios acessiveis). Ignorar/descartar mencoes cujo referencia_id nao seja acessivel, em vez de aceitar qualquer id global.

### 2. URL de mencao de CARD gravada errada (?card=) — link morto persistido
- **Módulo**: chat_interno · **Tipo**: hardcode_erro
- **Arquivo**: `public/assets/chat_interno.js:981`
- **Problema**: extractMencoes (usado no ENVIO da mensagem, monta o url_destino que vai pro banco chat_mencoes.url_destino) gera '/prospeccao.php?card=' + id. Mas a pagina de prospeccao so faz auto-open via ?open= (prospeccao.php:2613 history.replaceState ?open=, linha 3108 auto-abre quando ?open=X). Nao existe handler para ?card= (grep get('card')/_GET['card'] = vazio). Logo o url_destino salvo para mencoes de card aponta para um link que NAO abre o card. Inconsistencia interna: parseMentions (render) usa o correto ?open= (linha 122) e mencoes.php:140 tambem retorna ?open=. Como o render usa URL hardcoded propria, o chip visivel funciona, mas a coluna url_destino fica com link quebrado para qualquer consumidor que a use.
- **Evidência**: chat_interno.js:981 card: id => '/prospeccao.php?card=' + id, chat_interno.js:122 (parseMentions) card: '/prospeccao.php?open=' + id, mencoes.php:140 'url' => '/prospeccao.php?open=' . $row['id'], prospeccao.php:3108 // ainda tenha ?open=X. So auto-abre quando o usuario CHEGA na pagina via link.
- **Correção**: Trocar em extractMencoes para '/prospeccao.php?open=' + id, alinhando com parseMentions e mencoes.php. Idealmente, reaproveitar o item.url retornado por mencoes.php (que ja vem correto) em vez de remontar a URL no cliente.

### 3. URL de mencao de PROCESSO gravada errada (?id=) — link morto persistido
- **Módulo**: chat_interno · **Tipo**: hardcode_erro
- **Arquivo**: `public/assets/chat_interno.js:981`
- **Problema**: extractMencoes gera '/processos.php?id=' + id para o url_destino salvo em chat_mencoes. A pagina de processos so auto-abre com ?open= (processos.php:1397-1398 le params.get('open')). Nao ha auto-open por ?id=. Mesma inconsistencia do card: parseMentions (linha 121) e mencoes.php (linha 116/url) usam ?open=. O chip renderizado funciona (parseMentions hardcoda ?open=), mas a url_destino persistida no banco para mencoes de processo fica quebrada.
- **Evidência**: chat_interno.js:981 proc: id => '/processos.php?id=' + id, chat_interno.js:121 (parseMentions) proc: '/processos.php?open=' + id, processos.php:1397-1398 const params = new URLSearchParams(location.search); const openId = params.get('open');
- **Correção**: Trocar em extractMencoes para '/processos.php?open=' + id (ou reusar item.url de mencoes.php). Garante que a url_destino persistida abra o processo.

### 4. Webhooks cliente.* registrados/documentados mas nunca disparados
- **Módulo**: clientes · **Tipo**: feature_orfa
- **Arquivo**: `app/Services/WebhookDispatcher.php:16`
- **Problema**: Os eventos cliente.created, cliente.updated, cliente.deleted e cliente.converted_to_processo estão registrados no WebhookDispatcher (têm rótulo), documentados em docs/DOCUMENTACAO_WEBHOOKS_YURIS.md e ANUNCIADOS ao usuário na UI de webhooks (public/webhooks.php:1064-1067, com descrição de payload). Mas NENHUM código chama WebhookDispatcher::fire(...,'cliente.created',...) etc. — confirmado: grep por WebhookDispatcher::fire em public/api/clientes.php = 0 ocorrências, e grep global por fire(...cliente...) = 0. Comparado a processos.php, que dispara processo.created/updated/deleted. Resultado: o usuário pode assinar esses webhooks no painel e nunca recebe nada (integração silenciosamente quebrada).
- **Evidência**: WebhookDispatcher.php:16-19 define 'cliente.created'=>'Cliente criado', etc.; webhooks.php:1064 expõe 'cliente.created' ['mod'=>'Prospecção — Clientes', 'quando'=>'Disparado quando um novo cliente é cadastrado no CRM.', ...]. Em public/api/clientes.php (POST/PUT/DELETE) não há nenhuma chamada a WebhookDispatcher.
- **Correção**: Em public/api/clientes.php, após Cliente::create/update/archive bem-sucedidos, chamar WebhookDispatcher::fire($accountId, 'cliente.created'|'cliente.updated'|'cliente.deleted', WebhookDispatcher::buildPayload(...)) — espelhando o que processes.php já faz. Senão, remover os eventos da UI/doc para não prometer o que não entrega.

### 5. Link 'Ver ficha na aba Clientes' (/clientes.php?open=ID) não abre o cliente
- **Módulo**: clientes · **Tipo**: ponta_solta
- **Arquivo**: `public/clientes.php`
- **Problema**: No vínculo de processo com cliente, processos.js gera o link de retorno '/clientes.php?open=' + item.id com o texto 'Ver ficha na aba Clientes →'. Porém clientes.php NÃO lê nenhum parâmetro de query (open): não há params.get('open')/URLSearchParams/location.search no arquivo (os únicos 'open' são a classe CSS .modal-shell.open e showModal/hideModal). Logo o link leva à página de Clientes mas o cliente específico nunca é aberto. A Prospecção tem o comportamento equivalente funcionando (prospeccao.php:3131 const openId = params.get('open') auto-abre o card), evidenciando que a feature existe num lado e ficou faltando no outro.
- **Evidência**: processos.js:1111 linkBtn.href = isProsp ? `/prospeccao.php?open=${item.id}` : `/clientes.php?open=${item.id}`; // clientes.php não possui params.get('open') — grep por [?&]open=/searchParams/location.search em clientes.php = 0 (só CSS .open). prospeccao.php:3131 const openId = params.get('open').
- **Correção**: No DOMContentLoaded de clientes.php (dentro do IIFE window.Clientes, após loadAll), ler new URLSearchParams(location.search).get('open') e, se presente, chamar Clientes.openEditModal(Number(id)) — e idealmente history.replaceState para limpar a URL, como a Prospecção faz.

### 6. Link quebrado para funil.php (arquivo nao existe) na aba Atalhos
- **Módulo**: config · **Tipo**: hardcode_erro
- **Arquivo**: `public/configuracoes.php:621`
- **Problema**: O card 'Planejamento Comercial' aponta para funil.php, mas esse arquivo NAO existe em public/. A tela correta e planejamento.php (o sidebar usa href='planejamento.php' com active='funil'). Clicar no atalho leva a um 404.
- **Evidência**: configuracoes.php linha 621: <a href="funil.php" class="link-card"> ... Planejamento Comercial ... Meta mensal, honorario medio, simulacoes de funil. Glob public/funil*.php => No files found. public/planejamento.php existe. sidebar.php linha 121: ['perm'=>'planejamento','href'=>'planejamento.php','active'=>'funil', ...].
- **Correção**: Trocar href="funil.php" por href="planejamento.php" no card de Planejamento Comercial.

### 7. Endpoint account_notifications.php (GET/PATCH) sem nenhum consumidor na UI
- **Módulo**: config · **Tipo**: feature_orfa
- **Arquivo**: `public/api/account_notifications.php`
- **Problema**: Notificacoes da conta SAO criadas (AccountNotification::criar e chamado em account_vinculos.php, advogado_vinculos.php, resource_shares.php, PushMonitorRunner, AaspSyncRunner), mas o endpoint que LE/marca essas notificacoes (account_notifications.php) nao e chamado por nenhum JS/HTML — nao existe sino/central que faca fetch('/api/account_notifications.php'). Logo as notificacoes gravadas ficam invisiveis ao usuario por essa via. A aba 'Notificacoes' de configuracoes.php so manipula toggles em localStorage, sem relacao com este endpoint.
- **Evidência**: Grep da string 'account_notifications.php' em **/*.{php,js} retorna SOMENTE public/api/account_notifications.php (o proprio endpoint). Grep 'account_notif|countNaoLidas|notification' em public/assets/*.js => No files found. configuracoes.php so tem toggles localStorage (wireToggles cfg_notif_*), nada consome a API.
- **Correção**: Plugar um sino/central de notificacoes (no sidebar/topbar) que faca GET ?count=1 para o badge e GET para a lista, e PATCH para marcar lida — ou remover o endpoint se a central foi substituida pelo sistema 'push'. Hoje ha esforco gravando notificacoes que o usuario nunca ve.

### 8. dashboard_settings.php aceita POST sem validar CSRF
- **Módulo**: config · **Tipo**: seguranca
- **Arquivo**: `public/api/dashboard_settings.php:26`
- **Problema**: O POST grava estado (datas do dashboard em $_SESSION) e ainda registra trilha de auditoria (Account::audit dashboard_settings.updated), mas NAO valida X-CSRF-Token — checa apenas se ha sessao. Viola a convencao do YURIS de que toda mutacao (POST/PUT/PATCH/DELETE) exige X-CSRF-Token. O dashboard.js tambem chama esse endpoint sem enviar o header. Permite CSRF: um site malicioso pode forcar gravacao do filtro de datas / poluir o log de auditoria do tenant. (Obs secundaria: a config so vai para $_SESSION, nao e persistida por tenant/usuario em DB apesar de o audit ser per-tenant — perde no logout.)
- **Evidência**: dashboard_settings.php linha 8-12 so verifica empty($_SESSION['user_id']); o bloco POST (linha 26-51) le start/end e grava $_SESSION + Account::audit SEM qualquer checagem de csrf_token. dashboard.js linha 329: await fetch('/api/dashboard_settings.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({start, end})}); // sem X-CSRF-Token.
- **Correção**: Adicionar validacao de CSRF identica a do agent_settings.php (ler HTTP_X_CSRF_TOKEN/input e comparar com $_SESSION['csrf_token']) e fazer o dashboard.js enviar o header. Considerar persistir as preferencias em tabela per (account_id,user_id) para nao perder no logout.

### 9. Classificacao de origem so trata matriz e filial — contas tipo 'advogado' vinculadas ficam de fora do filtro
- **Módulo**: dashboard · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/dashboard.php:48`
- **Problema**: Mesmo corrigindo a fonte das filiais, a logica do filtro de Origem so mapeia dois tipos: array_filter(tipo==='matriz') e array_filter(tipo==='filial'). O YURIS tem 3 tipos de conta (matriz | filial | advogado) e getAccessibleAccountIds inclui advogados vinculados (AdvogadoVinculo::advogadosAtivosDeHost) nos KPIs do dashboard. Assim, um advogado vinculado contribui para os numeros agregados mas nao pode ser isolado nem aparece classificado no filtro; o optgroup 'Filial especifica' (linha 415-419) tambem pula tudo que nao for matriz, rotulando implicitamente qualquer nao-matriz como filial. A API repete o mesmo (api/dashboard.php linha 31-32).
- **Evidência**: dashboard.php linha 48-49: "$matrizIds = ...filter(fn($a)=>$a['tipo']==='matriz'); $filiaisIds = ...filter(fn($a)=>$a['tipo']==='filial');" ; linha 416: "if ($oa['tipo'] === 'matriz') continue;" (todo o resto cai no grupo de filial).
- **Correção**: Incluir o tipo 'advogado' na classificacao e no dropdown (ex.: grupo 'Advogados vinculados'), e rotular cada conta pelo tipo real em vez de assumir filial para tudo que nao e matriz.

### 10. dashboard_settings.php grava periodo na sessao via POST sem validar CSRF
- **Módulo**: dashboard · **Tipo**: seguranca
- **Arquivo**: `public/api/dashboard_settings.php:26`
- **Problema**: O POST persiste $_SESSION['dashboard_start']/['dashboard_end'] (que afetam o calculo server-side do DRE e dos KPIs) e ainda dispara Account::audit, mas nao valida X-CSRF-Token. E uma mutacao de estado do usuario sem protecao CSRF, fora do padrao dos demais endpoints. Impacto menor que goals (so muda o range de visualizacao), mas ainda assim viola a convencao de CSRF em mutacoes.
- **Evidência**: Arquivo nao referencia csrf em lugar nenhum; linha 26 "if ($method === 'POST') {" grava $_SESSION['dashboard_start']/['dashboard_end'] (linhas 38-39) direto, sem guard de token.
- **Correção**: Validar X-CSRF-Token no ramo POST (mesmo padrao de taxes.php) e enviar o header no fetch de saveAndLoad() em dashboard.js (linha 329).

### 11. Escopo do DRE inconsistente: render inicial inclui filiais, refresh AJAX usa so a propria conta (sobrescreve no load)
- **Módulo**: dashboard · **Tipo**: ponta_solta
- **Arquivo**: `public/api/dre_accounts.php:19`
- **Problema**: No carregamento da pagina, dashboard.php calcula o DRE server-side com DREAccount::summary(['account_ids' => $tenantIds]) onde $tenantIds vem de getAccessibleAccountIds('dashboard') — ou seja, INCLUI as filiais (e advogados) vinculados (dashboard.php linha 128). Porem o refresh via AJAX (loadDRE em dashboard.php linha 1044) chama api/dre_accounts.php, que fixa $tenantIds = [$accountId] com o comentario 'Matriz NAO consolida automaticamente o DRE das filiais' (dre_accounts.php linha 19). Como o evento dashboard:rangeChanged dispara tambem no load inicial (dashboard.js linha 399 -> listener dashboard.php linha 1152 -> loadDRE), o valor renderizado com filiais e imediatamente substituido pelo valor so-da-matriz. Resultado: os cards Receita/Despesa/Lucro/Margem 'piscam' um numero (com filiais) e assentam noutro (sem filiais) — duas regras de negocio conflitantes para o mesmo KPI.
- **Evidência**: dashboard.php:128 "$dre_summary = DREAccount::summary(['account_ids' => $tenantIds]);" (tenantIds = getAccessibleAccountIds('dashboard'), inclui filiais) vs dre_accounts.php:19 "$tenantIds = [$accountId];" com comentario 'Matriz NAO consolida automaticamente o DRE das filiais'. Disparo no load: dashboard.js:399 dispatch dashboard:rangeChanged + dashboard.php:1152-1155 loadDRE.
- **Correção**: Definir uma unica regra de escopo do DRE no dashboard e aplica-la nos dois lugares. Se o DRE deve consolidar filiais para a matriz, dre_accounts.php precisa aceitar um modo consolidado (ou um endpoint dedicado) usando getAccessibleAccountIds; se NAO deve, o render inicial em dashboard.php tem que usar [$accountId] tambem. Hoje os dois discordam.

### 12. Vinculo de advogado auto-ativa sincronizacao TOTAL sem consentimento do advogado
- **Módulo**: escritorios · **Tipo**: seguranca
- **Arquivo**: `public/api/advogado_vinculos.php:144`
- **Problema**: No POST de advogado_vinculos a host cria o vinculo ja como 'active' (autoActivate=true). O INSERT em AdvogadoVinculo::solicitar nao define as colunas sync_* e elas tem DEFAULT 1 no schema. Como AccountContext::getAccessibleAccountIds inclui advogadosAtivosDeHost (status='active' + sync_enabled=1 + sync_<modulo>=1), a host passa a enxergar TODOS os cards/processos/tarefas do advogado imediatamente, sem o advogado aprovar nada. Basta a host conhecer o codigo ADV-XXXXXX (que e exibido na area do advogado) para puxar a base inteira dele. O comentario assume 'host esta convidando ativamente', mas o advogado e tenant separado e nao consente — risco de exposicao de dados entre contas. (O caminho inverso matriz->filial exige a filial SOLICITAR e a matriz aprovar; aqui nao ha aprovacao do lado dono dos dados.)
- **Evidência**: public/api/advogado_vinculos.php:144 "$vinculoId = AdvogadoVinculo::solicitar($ctx->getAccountId(), (int)$advogado['id'], $ctx->getUserId(), true // autoActivate);" + app/Models/AdvogadoVinculo.php:139 INSERT sem sync_* (DEFAULT 1) + schema.sql:270 "`sync_enabled` tinyint(1) NOT NULL DEFAULT 1" + AdvogadoVinculo.php:238 "AND status = 'active' AND sync_enabled = 1"
- **Correção**: Exigir aceite do advogado antes de ativar sync (criar como pending e so liberar getAccessibleAccountIds quando o advogado aprovar), ou inserir o vinculo com sync_enabled=0 por padrao ate consentimento explicito. No minimo notificar e permitir opt-out imediato pelo advogado.

### 13. Filtros 'Tipo' (filter_tipo_extra) e 'Busca rapida' (filter_search_dre) existem na UI mas nao sao lidos pelo dre.js — nao fazem nada
- **Módulo**: financas · **Tipo**: ponta_solta
- **Arquivo**: `public/assets/dre.js:56`
- **Problema**: financas.php renderiza dois controles de filtro: <select id="filter_tipo_extra"> (Receita/Despesa) na linha 489 e <input id="filter_search_dre"> (Busca rapida por descricao) na linha 511. Mas o dre.js so le filter_month, filter_recorrencia e filter_code: getFilters() (dre.js:56-61) nunca acessa filter_tipo_extra nem filter_search_dre, e applyFiltersAndRender() nao aplica filtro por tipo nem por texto. Tambem nao ha addEventListener para esses dois (dre.js:351-356 so registra change em filter_month/recorrencia/code e o clearBtn nem limpa esses dois). Resultado: selecionar 'Receita'/'Despesa' ou digitar na busca nao tem efeito nenhum na tabela — UX quebrada silenciosamente.
- **Evidência**: dre.js:56-61: `function getFilters(){ const month=...filter_month..; const recorr=...filter_recorrencia..; const code=...filter_code..; return { month, recorr, code }; }` — nenhuma mencao a filter_tipo_extra/filter_search_dre. Grep do projeto: 'filter_search_dre|filter_tipo_extra' so aparece em financas.php (HTML), nunca no JS.
- **Correção**: Em getFilters() ler tambem filter_tipo_extra e filter_search_dre; em applyFiltersAndRender() filtrar por r.tipo e por substring em r.nome; registrar listeners de change/input e incluir o reset no clearFiltersBtn. Ou remover os dois controles se nao forem usados.

### 14. dashboard.php usa accounts.matriz_id (sempre NULL) para montar o filtro de Origem que recorta o DRE consolidado
- **Módulo**: financas · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/dashboard.php:36`
- **Problema**: Adjacente ao modulo (dashboard consome/consolida o DRE). A query que monta $origin_accounts — usada para filtrar o DRE por Origem (matriz/filiais/conta especifica) — busca filiais por `matriz_id = :self`. Pela convencao do YURIS, accounts.matriz_id e SEMPRE NULL; o vinculo matriz<->filial vive em account_vinculos (matriz_account_id/filial_account_id, status='active'). Logo, para uma matriz, $origin_accounts so retorna ela mesma (a clausula matriz_id=:self nunca casa), o filtro de Origem do dashboard nunca lista as filiais, e o recorte '__filiais__' resulta em [0] (nada). E exatamente o tipo de bug ja encontrado antes no projeto.
- **Evidência**: dashboard.php:33-37: `SELECT id, nome, tipo FROM accounts WHERE deleted_at IS NULL AND status = 'active' AND (id = :self OR matriz_id = :self) ORDER BY ...` — usa matriz_id em vez de account_vinculos. (getAccessibleAccountIds em AccountContext.php:226 corretamente usa account_vinculos.)
- **Correção**: Derivar as filiais de account_vinculos (igual Account::listFiliaisVinculadas / getAccessibleAccountIds) em vez de accounts.matriz_id. Como esta em dashboard.php (fora do escopo direto), abrir tarefa separada se preferir.

### 15. search.php usa accounts.matriz_id (sempre NULL) — busca global nunca mostra 'matriz: X' para filiais
- **Módulo**: master · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/api/master/search.php:68`
- **Problema**: Na busca global cross-tenant, o resultado de uma filial deveria exibir o sublabel 'matriz: <nome>'. O código depende de LEFT JOIN accounts am ON am.id = a.matriz_id e de a.matriz_id, ambos sempre NULL. Portanto o ramo if ($r['tipo']==='filial' && $r['matriz_nome']) (linha 78) nunca é verdadeiro e a filial aparece na busca sem indicar a qual matriz pertence.
- **Evidência**: SELECT a.id, ..., a.matriz_id, ..., am.nome AS matriz_nome FROM accounts a LEFT JOIN accounts am ON am.id = a.matriz_id (search.php:65-68); if ($r['tipo'] === 'filial' && $r['matriz_nome']) { $sub[] = 'matriz: ' . $r['matriz_nome']; } (linha 78-80)
- **Correção**: Resolver a matriz via account_vinculos (LEFT JOIN account_vinculos av ON av.filial_account_id=a.id AND av.status='active' LEFT JOIN accounts am ON am.id=av.matriz_account_id) em vez de a.matriz_id.

### 16. Endpoint consents.php (aceites de termos LGPD) é órfão — sem aba/caller na UI
- **Módulo**: master · **Tipo**: feature_orfa
- **Arquivo**: `public/api/master/consents.php`
- **Problema**: O endpoint /api/master/consents.php lista os aceites de termos (lgpd_consents, finalidade=termos_uso_login) e expõe ?counts=1 para badge. Está completo e funcional (há 4 registros ativos no banco), mas NÃO existe nenhuma aba nem fetch que o consuma. As abas do Painel Master (master.php:372-385) são overview/dashboard/accounts/plans/billing/invoices/payments/expenses/audit/lgpd/retencao/incidents/operators/reviews — não há 'Consentimentos/Termos'. grep por consents/aceites/termos_uso no JS de master.php = 0. Como o master_login.php (linhas 504-515) e o check_terms.php coletam esses aceites, o dado é gravado mas o DPO não tem como visualizá-lo no painel. Mesmo padrão do caso 'clientes criado mas não plugado'.
- **Evidência**: consents.php existe e roda; grep 'consents.php' em public/ retorna apenas o próprio arquivo (nenhum fetch). DB: SELECT COUNT(*) FROM lgpd_consents → 4; finalidade=termos_uso_login status=ativo → 4. Abas em master.php:372-385 não incluem consentimentos.
- **Correção**: Adicionar uma aba/sub-seção (ex.: dentro de LGPD) que faça fetch em consents.php (lista) e consents.php?counts=1 (badge), ou remover o endpoint se a feature foi descontinuada. Hoje há trabalho de backend sem ponto de entrada.

### 17. Opção 'Trimestral' (quarterly) oferecida na UI de assinatura mas rejeitada/coerced pelo backend
- **Módulo**: master · **Tipo**: ponta_solta
- **Arquivo**: `public/master.php:1338`
- **Problema**: Vários selects de ciclo da UI Master oferecem 'Trimestral' (value=quarterly): o modal de editar assinatura (subCycle, master.php:1336-1339) e o form de nova conta (sub_cycle). Porém o backend de assinaturas só aceita monthly/yearly: billing.php PATCH (linha 111-113) faz badRequest('billing_cycle inválido') para quarterly → salvar uma assinatura como 'Trimestral' retorna erro 400; create_account.php (linha 162) faz in_array(... ['monthly','yearly']) ? : 'monthly' → criar conta com 'Trimestral' vira monthly silenciosamente (cobrança/MRR errados). Só o add-on de monitoramento (create_account.php:280-283) trata quarterly, e ainda assim convertendo para monthly com nota textual.
- **Evidência**: <option value="quarterly">Trimestral</option> (master.php:1338, e 994/1030/1418/1636/1697). Submit envia subCycle: billing_cycle: document.getElementById('subCycle').value (master.php:3072) → billing.php:111 if (!in_array($input['billing_cycle'], ['monthly','yearly'], true)) ApiResponse::badRequest('billing_cycle inválido'). create_account.php:162 $cycle = in_array($sub['billing_cycle'] ?? 'monthl
- **Correção**: Decidir o suporte a trimestral: ou (a) remover a opção 'quarterly' dos selects de assinatura (subCycle/sub_cycle/editAccSubCycle) já que o ciclo de assinatura só suporta monthly/yearly, ou (b) adicionar 'quarterly' nas validações de billing.php e create_account.php e tratar o cálculo de período/MRR. Hoje a UI promete algo que o backend não cumpre.

### 18. Autocomplete de processos (processes_search.php) escopa só por account_id próprio — matriz não encontra processos das filiais
- **Módulo**: processos · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/api/processes_search.php:32`
- **Problema**: O endpoint de busca/autocomplete de processos filtra apenas WHERE account_id = :account_id, usando $ctx->getAccountId() (id da conta logada), em vez de $ctx->getAccessibleAccountIds('processos'). Todo o resto do módulo (Processo::list, TenantGuard, o board) dá à matriz acesso aos processos das filiais vinculadas, mas esta busca não. Resultado: na tela escritorios.php (função buscarProcessos → selecionarProcesso, que alimenta advProcessoId ao vincular um advogado a um processo), uma matriz não consegue localizar/selecionar processos pertencentes às filiais. É inconsistência de escopo (matriz deveria ver filial), não um vazamento. Note que getAccessibleAccountIds já existe e é importado implicitamente; a correção é trocar o filtro.
- **Evidência**: public/api/processes_search.php:30-34 → $sql = 'SELECT id, numero, cliente_nome FROM processos WHERE account_id = :account_id AND deleted_at IS NULL'; $params = ['account_id' => $ctx->getAccountId()]; (chamado em public/escritorios.php:1709: fetch(`/api/processes_search.php?q=...`)).
- **Correção**: Trocar o filtro fixo por account_id por uma cláusula IN baseada em $ctx->getAccessibleAccountIds('processos') (ex.: usar $ctx->buildAccountInClause('processos') ou montar placeholders manualmente). Assim a busca passa a respeitar a herança matriz→filiais como o restante do módulo. Opcionalmente incluir resource_shares, mas o mínimo é o IN dos account_ids acessíveis.

### 19. Filtro de Origem 'Apenas Filiais' ignora cards de tipo advogado
- **Módulo**: prospeccao · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/prospeccao.php:1928`
- **Problema**: getAccessibleAccountIds('prospeccao') inclui contas advogado vinculadas (AccountContext.php linhas 377-396), entao a matriz VE no board cards com origin_account_tipo='advogado' (a faixa do card ate rotula 'ADVOGADO' corretamente, linhas 1851-1853). Mas o filtro de Origem so trata matriz/filial: a pseudo-opcao '__filiais__' faz 'tipo !== filial -> return false' (linha 1928), excluindo os cards de advogado; e nao existe opcao 'Apenas Advogados'. Resultado: cards de advogados vinculados somem ao filtrar por '__filiais__' e por '__matriz__', so aparecem em 'Todas as origens'. Alem disso o optgroup 'Filial especifica' (linhas 1165-1169) lista contas advogado sob o rotulo 'Filial', rotulando errado o tipo advogado — mesma classe de bug do 'tipo===matriz?X:FILIAL' descrito nas convencoes.
- **Evidência**: if (origin === '__matriz__' && tipo !== 'matriz') return false; if (origin === '__filiais__' && tipo !== 'filial') return false; // advogado tambem deveria contar como nao-matriz aqui if (origin !== '__matriz__' && origin !== '__filiais__' && oid !== origin) return false;
- **Correção**: Trocar a semantica de '__filiais__' para 'todas as origens nao-matriz' (tipo !== 'matriz') OU adicionar opcao '__advogados__' (tipo==='advogado') e um optgroup separado 'Advogado especifico'. No template (linhas 1165-1169) separar advogados das filiais para nao rotular advogado como 'Filial'.

### 20. cliente_id<->processo existe no model mas modulo Clientes nao tem a feature de vinculo que a Prospeccao tem
- **Módulo**: prospeccao · **Tipo**: feature_orfa
- **Arquivo**: `public/clientes.php`
- **Problema**: Espelho exato do caso de referencia (tabela criada mas nao plugada em todas as fontes). A Prospeccao implementa 'Processos do Cliente' completo: lista (GET /api/processes.php?card_id=), vincula e desvincula via card_id (prospeccao.php linhas 1524-1535, 2435-2526). O model Processo suporta tambem cliente_id como 2a fonte: create herda contato_id de clientes (Processo.php linhas 133-137) e update idem (linhas 225-233), e existe tabela clientes + Cliente.php (434 linhas) + clientes.php (1507 linhas, Kanban completo que se diz 'Base real de clientes, separada da Prospeccao'). Porem clientes.php NAO tem nenhuma referencia a processo/card_id/vincular (grep retornou zero) — ou seja, a capacidade cliente_id no backend esta orfa: nenhuma UF do modulo Clientes cria/lista processos por cliente_id. A feature de vincular processo so existe de um lado (Prospeccao via card_id).
- **Evidência**: Processo.php: if (!$contatoId && !empty($data['cliente_id'])) { $cliRow = $pdo->prepare('SELECT contato_id FROM clientes WHERE id = ? LIMIT 1'); ... } // suporte a cliente_id existe; grep por 'processo|card_id|vincul' em clientes.php = No matches found
- **Correção**: Confirmar com produto se Clientes deveria ter 'Processos do Cliente' como na Prospeccao. Se sim, plugar no clientes.php a mesma UI usando cliente_id (GET /api/processes.php precisaria aceitar ?cliente_id= no filtro, que hoje so aceita card_id — Processo::list linha 91). Se nao, remover o ramo cliente_id morto do model.

### 21. Modulo Clientes nao e vinculavel a tarefas (so cards/prospeccao esta plugado) — mesmo padrao do bug de referencia
- **Módulo**: tarefas · **Tipo**: feature_orfa
- **Arquivo**: `public/api/task_link_search.php:33`
- **Problema**: Clientes e um modulo first-class do YURIS (tabela `clientes`, app/Models/Cliente.php, public/api/clientes.php, pagina public/clientes.php). Os vinculos de tarefa suportam 'processo', 'card' (Prospeccao/CRM), 'contato' e 'dre_account' — mas NAO 'cliente'. Isso e exatamente o padrao do caso de referencia (a tabela clientes foi criada mas o vinculo so buscava cards de prospeccao): o modulo irmao Prospeccao (cards) esta plugado como fonte de vinculo, mas o modulo Clientes nao. Um usuario nao consegue amarrar uma tarefa a um cliente do modulo Clientes, apenas a um lead do funil (card) ou a um contato.
- **Evidência**: switch ($type) { case 'contato': ... FROM contatos ... case 'processo': ... FROM processos ... case 'card': ... FROM cards ... case 'dre_account': ... FROM dre_accounts ... default: ... 'Tipo invalido' (sem case 'cliente' / tabela clientes)
- **Correção**: Avaliar adicionar link_type 'cliente' buscando a tabela `clientes` (escopada por account_id IN tenantIn), espelhando o case 'card'. Requer tambem: incluir 'cliente' no whitelist de task_links.php (in_array linha 47), adicionar resolveNome em TaskLink.php, um botao no seletor de tipo em tarefas.php e config em renderLinks (tarefas.js). Confirmar com o produto se a intencao e clientes OU se 'contato' ja cobre o caso.

### 22. Vinculo a 'contato/Cliente' tem backend e render prontos mas NAO tem botao na UI para adicionar
- **Módulo**: tarefas · **Tipo**: ponta_solta
- **Arquivo**: `public/tarefas.php:323`
- **Problema**: O backend task_link_search.php trata `type=contato` (busca em contatos) e o whitelist de task_links.php aceita 'contato'. O frontend tarefas.js (renderLinks, ~linha 1007) tem config completa para exibir vinculos do tipo 'contato' com label 'Clientes' e URL prospeccao.php?contato=. O spec (docs/TAREFAS_SPEC.md linha 231) lista explicitamente 'vinculo a cliente usa Contato.php/contatos' como feature. Porem o seletor de tipo na UI so renderiza 3 botoes: Processo, Card CRM, Conta DRE. Falta o botao data-type='contato'. Resultado: vinculos contato existentes APARECEM no drawer, mas o usuario nao tem como CRIAR um novo vinculo de contato — a busca so e disparada por searchVinculos() a partir do clique nos .tk-link-type-btn, e nao existe botao para 'contato'. Feature meio-plugada.
- **Evidência**: <button class="tk-link-type-btn active" data-type="processo">Processo</button> <button class="tk-link-type-btn" data-type="card">Card CRM</button> <button class="tk-link-type-btn" data-type="dre_account">Conta DRE</button>
- **Correção**: Adicionar <button class="tk-link-type-btn" data-type="contato">Cliente</button> no bloco de botoes (linha 322-326 de tarefas.php). O resto da cadeia (search, add, render) ja existe e funciona.

### 23. task_columns.php PUT/DELETE chamam canEdit sem escopo de tenant ($accIds)
- **Módulo**: tarefas · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/api/task_columns.php:70`
- **Problema**: Nos handlers PUT (linha 70) e DELETE (linha 78), a checagem de permissao chama TaskBoard::canEdit($col['board_id'], $userId) SEM passar o 3o argumento $accIds, diferente de todos os outros pontos do mesmo arquivo (GET linha 39, POST linhas 50 e 55 passam $accIds). canEdit com $accountIds=null faz findById sem restringir tenant (TaskBoard.php:57-60). O comentario '(2B.3)' em outros arquivos diz que esse argumento foi adicionado justamente para fechar IDOR cross-tenant (admin do tenant A editando board do tenant B por id conhecido). Aqui, embora canEdit ainda exija ownership/membership no board, perde-se a camada de escopo por tenant que o resto do modulo aplica — inconsistencia que reabre parcialmente a superficie que o 2B.3 fechou.
- **Evidência**: if ($method === 'PUT') { $col = TaskColumn::findById($id); if (!$col || !TaskBoard::canEdit($col['board_id'], $userId)) fail('Sem permissao', 403); // sem $accIds } if ($method === 'DELETE') { ... if (!$col || !TaskBoard::canEdit($col['board_id'], $userId)) fail('Sem permissao', 403); // sem $accIds
- **Correção**: Passar $accIds como 3o argumento em ambos: TaskBoard::canEdit($col['board_id'], $userId, $accIds), igual aos handlers GET/POST do mesmo arquivo.

### 24. /api/users.php GET nao retorna account_nome/account_tipo — quebra agrupamento Matriz/Filial em populateUserSelect
- **Módulo**: usuarios · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/api/users.php:80`
- **Problema**: O SELECT da listagem retorna account_id mas NAO retorna account_nome nem account_tipo. Yuris.populateUserSelect e Yuris.buildGroupedOptionsByName (user_select.js) dependem de account_nome/account_tipo para montar os <optgroup label="Matriz: X"/"Filial: Y">. Quem alimenta esses helpers com o retorno cru de /api/users.php perde o agrupamento (cai em 'Outros' sem prefixo) alem de so ver 1 conta. Os proprios devs documentaram isso: chat_interno.js:373 troca explicitamente /api/users.php por outro endpoint 'pra ter account_id/nome/tipo de TODAS as contas vinculadas — necessario pra agrupar Matriz/Filial'. Ou seja, o contrato do endpoint e reconhecidamente insuficiente, mas continua sendo consumido por populateUserSelect em processos.js (fallback) e por chat.js.
- **Evidência**: public/api/users.php:78-80 retorna apenas "$selAccount" (= account_id) sem account_nome/account_tipo; grep por account_nome|account_tipo em users.php => No matches. chat_interno.js:373-374: "// Carrega modal_data (em vez de /api/users.php) pra ter account_id/nome/tipo de TODAS as contas vinculadas — necessario pra agrupar Matriz/Filial."
- **Correção**: Adicionar INNER JOIN accounts a ON a.id = u.account_id e selecionar a.nome AS account_nome, a.tipo AS account_tipo no GET de /api/users.php (igual getAccessibleUsers em AccountContext.php:505-517), para que o endpoint sirva diretamente os helpers de agrupamento.

### 25. teams.php nao valida que os user_ids dos membros pertencem ao tenant
- **Módulo**: usuarios · **Tipo**: ponta_solta
- **Arquivo**: `public/api/teams.php:77`
- **Problema**: No POST/PUT de times, $input['members'] (array de ints arbitrarios) e repassado direto para Team::setMembers, que faz INSERT IGNORE em team_members sem checar se cada user_id pertence ao account_id da sessao. A tabela team_members nao tem coluna account_id (so team_id+user_id). Um owner/admin do tenant A pode, via requisicao forjada, associar a um setor do tenant A um user_id de outro tenant. Pela UI normal o picker so lista usuarios da propria conta, entao o risco e por chamada manual; o impacto e baixo (so cria linha em team_members; a renderizacao mostraria '?' pois _allUsers nao tem o id), mas e uma quebra de isolamento de integridade que merece validacao defensiva.
- **Evidência**: teams.php:77-78 "$members = is_array($input['members'] ?? null) ? $input['members'] : []; Team::setMembers($id, $members);" e Team.php:130-140 setMembers faz "INSERT IGNORE INTO team_members (team_id, user_id) VALUES (?, ?)" sem filtrar por account_id.
- **Correção**: Em setMembers (ou no endpoint) filtrar os user_ids contra os usuarios acessiveis do tenant: SELECT id FROM users WHERE id IN (...) AND account_id IN (<acessiveis>) AND deleted_at IS NULL, e inserir apenas o intersecto.

### 26. Gestao de Usuarios so gerencia 'perfil' (admin/user) e ignora o 'role' (owner/manager/viewer)
- **Módulo**: usuarios · **Tipo**: feature_orfa
- **Arquivo**: `public/usuarios.php:1207`
- **Problema**: O sistema tem uma coluna users.role com hierarquia owner>admin>manager>user>viewer (AccountContext.hasMinRole, e /api/users.php ate aceita role no POST/PUT validando contra ['owner','admin','manager','user','viewer']). Mas a tela usuarios.php so expoe 'perfil' com duas opcoes (admin/user) no dropdown inline 'Alterar perfil', no filtro e nos modais. Nao ha NENHUMA UI para ver/definir role: usuarios com role owner/manager/viewer ficam invisiveis/ingerenciaveis por aqui, e o badge/dropdown rotula todos como Admin ou User. Feature (RBAC por role) existe no backend mas nao foi plugada na tela de gestao — usuario nao consegue gerir os papeis reais.
- **Evidência**: usuarios.php:1207-1210 dropdown so com "<option value=admin>...<option value=user>"; perfilBadge (1123-1127) e perfilLabel (1165-1169) so tratam admin/user; vs api/users.php:145 "if (!in_array($role, ['owner','admin','manager','user','viewer'])) $role = 'user';" e AccountContext.php:533 hierarquia com manager/viewer.
- **Correção**: Decidir o modelo: se role e a fonte de verdade de permissao, expor role na tela (com os 5 niveis e agrupamento adequado) ou documentar que perfil e o unico papel usado no tenant e role e interno. Hoje ha duas dimensoes (perfil e role) parcialmente desconectadas, o que gera inconsistencia de permissao.

### 27. Envelope v2 usa accounts.matriz_id (sempre NULL) e assume 'matriz' como default — type/matriz_id sempre errados; esquece advogado
- **Módulo**: webhooks · **Tipo**: gap_matriz_filial
- **Arquivo**: `app/Services/WebhookPayloadBuilder.php:93`
- **Problema**: fetchTenantInfo() faz 'SELECT id, nome, tipo, matriz_id FROM accounts' e o envelope expõe organization.type = tipo ?? 'matriz' e organization.matriz_id = matriz_id (l.56-61). Pelas convenções do YURIS, accounts.matriz_id é SEMPRE NULL — o vínculo real é via account_vinculos. Logo: (1) organization.matriz_id sempre sai null mesmo para filiais (a própria doc do payload em webhooks.php:780/830 promete 'preenchido se type=filial', o que nunca ocorre); (2) o fallback ?? 'matriz' rotula como 'matriz' qualquer conta cujo tipo venha vazio. Embora aqui o 'tipo' venha da coluna accounts.tipo (que distingue matriz|filial|advogado), o campo matriz_id é um dado morto e enganoso para o integrador, e a doc declara um comportamento inexistente.
- **Evidência**: WebhookPayloadBuilder.php:93 $st = $pdo->prepare('SELECT id, nome, tipo, matriz_id FROM accounts WHERE id = ? LIMIT 1'); WebhookPayloadBuilder.php:60 'matriz_id' => $tenant['matriz_id'] ?? null,
- **Correção**: Resolver a matriz real via account_vinculos (SELECT matriz_account_id FROM account_vinculos WHERE filial_account_id = :acc AND status='active') em vez de accounts.matriz_id. Para organization.type, garantir os 3 valores (matriz|filial|advogado) sem default cego para 'matriz'. Corrigir a doc do payload se o campo continuar sem fonte confiável.

### 28. Botão 'Testar' não entrega nada se o webhook não assina o evento 'webhook.test' (toast de sucesso enganoso)
- **Módulo**: webhooks · **Tipo**: ponta_solta
- **Arquivo**: `public/api/webhooks.php:187`
- **Problema**: A ação 'test' monta o payload e chama WebhookDispatcher::fire($hook['account_id'],'webhook.test',$payload). Dentro de fire(), findSubscribers() só retorna hooks cujo array 'eventos' contém '*' OU 'webhook.test' (WebhookDispatcher.php:364-367). Um webhook recém-criado que assina apenas, por ex., 'card.created' NÃO está inscrito em 'webhook.test', então fire() encontra zero subscribers e retorna sem enviar (l.310 'if (empty($hooks)) return;'). Mesmo assim a API responde {success:true,'Evento de teste enviado'} e o front mostra 'Teste enviado! Verifique os logs' (webhooks.php:1687), mas nenhuma entrega aparece nos logs — usuário acha que o endpoint está quebrado. O 'Testar' deveria entregar ao endpoint independentemente das assinaturas.
- **Evidência**: public/api/webhooks.php:187 WebhookDispatcher::fire((int)$hook['account_id'], 'webhook.test', $payload); WebhookDispatcher.php:310 if (empty($hooks)) return; WebhookDispatcher.php:366 return in_array('*', $eventos, true) || in_array($eventKey, $eventos, true);
- **Correção**: Para a ação 'test', entregar diretamente ao endpoint específico (chamar tryDeliver/processDelivery para aquele hook) em vez de passar por findSubscribers() filtrado por assinatura — ou injetar 'webhook.test' como sempre-aceito no teste. Assim o teste reflete a saúde real do endpoint.

### 29. Auto-create de tabelas cria 'webhooks' (nome legado) em vez de 'webhook_endpoints' — banco resetado quebra o módulo
- **Módulo**: webhooks · **Tipo**: hardcode_erro
- **Arquivo**: `public/api/webhooks.php:49`
- **Problema**: O bloco 'auto-cria tabelas se não existirem (banco resetado)' faz CREATE TABLE IF NOT EXISTS webhooks (...) e webhook_logs (...), mas a migration 067 RENOMEOU 'webhooks' para 'webhook_endpoints' e TODO o restante do arquivo (list l.152, get l.117, create l.300, update l.402, delete l.423) e o WebhookDispatcher operam sobre 'webhook_endpoints'. Pior: logo abaixo (l.72-76) o guard de coluna verifica/altera 'webhook_endpoints' (deleted_at). Ou seja, em um deploy/reset onde as tabelas não existem, este bloco cria a tabela ERRADA ('webhooks') e o SELECT em 'webhook_endpoints' (l.73) cai no catch que tenta ALTER numa tabela inexistente — todas as queries seguintes do módulo falham. As colunas do CREATE também estão defasadas (sem account_id, escopo, payload_mode, etc.).
- **Evidência**: public/api/webhooks.php:49 $pdo->exec("CREATE TABLE IF NOT EXISTS webhooks ( id INT AUTO_INCREMENT PRIMARY KEY, nome ... )"); // deveria ser webhook_endpoints public/api/webhooks.php:73 $pdo->query('SELECT deleted_at FROM webhook_endpoints LIMIT 0');
- **Correção**: Trocar o CREATE para 'webhook_endpoints' com o schema completo da migration 067 (account_id, escopo, payload_mode, timeout_segundos, retry_enabled, max_retries, headers_customizados, created_by, secret_rotated_at) e criar 'webhook_deliveries' (não 'webhook_logs', já que as leituras vêm de webhook_deliveries). Idealmente remover o auto-create do request path e confiar nas migrations.

### 30. Dropdown de Responsavel do chat usa /api/users.php (so a propria conta) e nao agrupa Matriz/Filial/advogado
- **Módulo**: whatsapp · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/assets/chat.js:1499`
- **Problema**: Tanto o filtro de responsavel da sidebar (_loadUsersForFilter, linha 1499) quanto o picker de Responsavel do modal Vincular (openLinkModal, linha 2059) carregam /api/users.php e renderizam uma lista CHAPADA (so u.nome), sem agrupamento Matriz/Filial. /api/users.php (GET list) filtra estritamente por account_id = :acc (so a propria conta) e NAO retorna account_tipo/account_nome. Isso viola a convencao do YURIS: o resto do sistema (prospeccao.php, processos.php, clientes.php, intimacoes.php, tarefas via /api/task_users.php) usa AccountContext::getAccessibleUsers() + Yuris.populateUserSelect/buildGroupedOptionsByName pra agrupar por Matriz/Filial. Resultado: numa matriz, o chat nao lista usuarios de filiais/advogados vinculados como responsaveis e nao os agrupa.
- **Evidência**: const r = await fetch('/api/users.php', { credentials: 'same-origin' }).then(r => r.json()); _linkData.users = Array.isArray(r) ? r : (r.data || []); // sem account_tipo, sem agrupamento; users.php usa WHERE account_id = :acc
- **Correção**: Criar endpoint dedicado (espelho de /api/task_users.php) que retorne $ctx->getAccessibleUsers(true,'chat') com account_id/account_nome/account_tipo, e renderizar o select de responsavel com Yuris.populateUserSelect (agrupamento Matriz/Filial). Aplicar nos dois pontos (filtro sidebar e modal Vincular).

### 31. Botao 'Membros do grupo' chama ChatApp.openGroupMembers() que nao existe
- **Módulo**: whatsapp · **Tipo**: ponta_solta
- **Arquivo**: `public/chat.php:2016`
- **Problema**: O botao do header #chatMembersBtn tem onclick="ChatApp.openGroupMembers()", mas essa funcao NAO existe em chat.js (so existem showGroupMembersModal e closeGroupMembers, e o objeto exportado em chat.js linha 2867 nao inclui openGroupMembers). Clicar dispara TypeError silencioso. Alem disso o botao esta hardcoded style="display:none" e chat.js nunca referencia 'chatMembersBtn' pra exibi-lo. Mesmo padrao do bug historico window._applyCardSelection (chamado mas nunca exposto). Hoje o modal so abre pelo clique no subtitulo (subEl.onclick = () => showGroupMembersModal(jid, members), chat.js linha 2321) — o botao dedicado e morto/quebrado.
- **Evidência**: <button class="chat-icon-btn" id="chatMembersBtn" onclick="ChatApp.openGroupMembers()" title="Membros do grupo" style="display:none"> // openGroupMembers nao definido nem exportado; showGroupMembersModal e que existe
- **Correção**: Trocar onclick por ChatApp.showGroupMembersModal(state.currentJid, ...) (ou expor um alias openGroupMembers) e fazer openChat/updateGroupHeader exibir o botao (display:flex) quando isGroup; ou remover o botao morto e manter so o clique no subtitulo.

### 32. Vazamento de mensagem de excecao (getMessage) em endpoints WhatsApp — viola padrao LGPD ErrorReporter
- **Módulo**: whatsapp · **Tipo**: hardcode_erro
- **Arquivo**: `public/api/whatsapp/webhook.php:287`
- **Problema**: Tres endpoints do modulo retornam a mensagem crua da excecao ao cliente, em vez de usar App\Helpers\ErrorReporter::handle($e) como o restante do modulo (chats.php, sync.php, media.php debug, etc.). webhook.php linha 287: echo json_encode(['error'=>'Erro interno','msg'=>$e->getMessage()]); contato_vinculos.php linhas 120-121: echo json_encode(['error'=>$e->getMessage()]); media.php linha 255 (ramo nao-debug): echo 'Erro: ' . $e->getMessage(); Isso pode expor caminhos/SQL/detalhes internos (P1 LGPD 2D.1 que o proprio modulo diz seguir).
- **Evidência**: webhook.php: http_response_code(500); echo json_encode(['error' => 'Erro interno', 'msg' => $e->getMessage()]);
- **Correção**: Substituir os tres pontos por require ErrorReporter + \App\Helpers\ErrorReporter::handle($e); (ja usado nos outros endpoints do modulo), que esconde getMessage/file/line em producao.

### 33. Deep-link 'clientes.php?open=ID' gerado pelos processos nao abre nada (clientes.php nao trata ?open)
- **Módulo**: x_features_orfas · **Tipo**: ponta_solta
- **Arquivo**: `public/clientes.php`
- **Problema**: Quando um processo esta vinculado a um cliente da aba Clientes, processos.js monta o link 'Ver ficha na aba Clientes ->' apontando para clientes.php?open=ID (assets/processos.js:1111). Mas clientes.php NAO tem nenhum handler de query string: grep por 'URLSearchParams', 'location.search', '?open', '$_GET' e 'new URL(' so retorna a search box client-side (#searchInput). Comparativamente, prospeccao.php trata ?open (prospeccao.php:3130-3131 'const params = new URLSearchParams(location.search); const openId = params.get(\'open\')') e processos.php trata ?open/?new_card_id (processos.php:1396-1399). Logo o botao leva o usuario para a aba Clientes mas a ficha do cliente nunca abre — link morto.
- **Evidência**: processos.js:1111: 'linkBtn.href = isProsp ? `/prospeccao.php?open=${item.id}` : `/clientes.php?open=${item.id}`;'. Em clientes.php nao ha leitura de location.search/URLSearchParams/?open (verificado por grep).
- **Correção**: Adicionar em clientes.php (no init) leitura de URLSearchParams('open') e chamar Clientes.openEditModal(openId), espelhando prospeccao.php:3130. Limpar o param via history.replaceState depois.

### 34. Falta 'Criar novo processo para este cliente' no modulo Clientes (existe so na Prospeccao)
- **Módulo**: x_features_orfas · **Tipo**: feature_orfa
- **Arquivo**: `public/clientes.php`
- **Problema**: A Prospeccao tem o botao 'Criar novo processo para este cliente' (prospeccao.php:1533, handler btnCriarProcessoCliente em :2561 navega para /processos.php?new_card_id=ID, pre-selecionando o lead no processo). O modulo Clientes nao tem entrada equivalente para criar um processo ja vinculado ao cliente, e processos.php so sabe tratar new_card_id (processos.js:1399 'params.get(\'new_card_id\')'), nao existe new_cliente_id. Mesmo padrao de assimetria do achado #1: a jornada 'cliente -> abrir processo' existe na Prospeccao mas nao na aba Clientes, apesar de cliente_id ter sido criado pra isso.
- **Evidência**: prospeccao.php:2560-2565: 'document.getElementById(\'btnCriarProcessoCliente\')?.addEventListener(\'click\', () => { ... window.location.href = `/processos.php?new_card_id=${_currentCardId}`; });'. processos.php trata apenas new_card_id (linha 1399/1415). Nenhuma referencia a new_cliente_id em todo o codigo.
- **Correção**: Adicionar botao no modal do cliente que navegue para /processos.php?new_cliente_id=ID e fazer processos.php/processos.js tratarem new_cliente_id chamando _applySelection('cliente', ...) (a infra _ensureVinculoData/_selecionarVinculo ja existe e suporta a fonte 'cliente').

### 35. Strip de origem 'advogado' renderiza com cor de FILIAL na Prospecção (classe is-filial + sem CSS is-advogado)
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\prospeccao.php:1849`
- **Problema**: A classe da faixa de origem é definida por 'originTipo === 'matriz' ? 'is-matriz' : 'is-filial'', tratando conta tipo 'advogado' como is-filial. O rótulo logo abaixo (linhas 1851-1853) já cobre os 3 tipos (MATRIZ/ADVOGADO/FILIAL), mas o CSS só tem .card-origin-strip.is-matriz e .is-filial (prospeccao.php:482,486) — não existe .is-advogado. Resultado: card de origem advogado aparece com a cor roxa de FILIAL mas com o texto 'ADVOGADO'. O Card model expõe origin_account_tipo='advogado' (Card.php:41), então o caso ocorre de verdade quando há advogado vinculado. processos.js já faz certo via ACCOUNT_TIPO_CLASS (is-advogado).
- **Evidência**: const cls = originTipo === 'matriz' ? 'is-matriz' : 'is-filial'; // Label reflete o TIPO real da conta — conta solo é 'advogado', nunca 'filial'. const label = originTipo === 'matriz' ? 'MATRIZ' : originTipo === 'advogado' ? 'ADVOGADO' : 'FILIAL';
- **Correção**: Mapear cls pelos 3 tipos (ex.: {matriz:'is-matriz',filial:'is-filial',advogado:'is-advogado'}) e adicionar regra CSS .card-origin-strip.is-advogado (e variante light), como já existe em processos.php.

### 36. Strip de origem 'advogado' renderiza com cor de FILIAL em Clientes (classe is-filial + sem CSS is-advogado)
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\clientes.php:941`
- **Problema**: Idêntico ao caso de prospeccao: stripCls = origemTipo === 'matriz' ? 'is-matriz' : 'is-filial' atribui is-filial a contas advogado. O rótulo (clientes.php:943-945) cobre os 3 tipos, mas o CSS só define .origin-strip.is-matriz e .is-filial (clientes.php:290,294); não há .is-advogado. Card/cliente de origem advogado aparece com a cor de FILIAL e texto 'ADVOGADO'.
- **Evidência**: const stripCls = origemTipo === 'matriz' ? 'is-matriz' : 'is-filial'; // Label reflete o TIPO real da conta — conta solo é 'advogado', nunca 'filial'. const stripLabel = origemTipo === 'matriz' ? 'MATRIZ' : origemTipo === 'advogado' ? 'ADVOGADO' : 'FILIAL';
- **Correção**: Mapear stripCls pelos 3 tipos e adicionar CSS .cli-card .origin-strip.is-advogado (dark + light).

### 37. Strip de origem 'advogado' renderiza com cor de FILIAL em Tarefas (classe is-filial + sem CSS is-advogado)
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\assets\tarefas.js:385`
- **Problema**: A classe da faixa de origem é 'tipo === 'matriz' ? 'is-matriz' : 'is-filial'', tratando advogado como is-filial. O rótulo (tarefas.js:387-389) cobre os 3 tipos, mas o arquivo public/assets/tarefas.css não tem nenhuma regra .is-advogado (só .is-matriz/.is-filial em tarefas.css:199+). Tarefa de origem advogado fica com cor de FILIAL e texto 'ADVOGADO'.
- **Evidência**: const cls = tipo === 'matriz' ? 'is-matriz' : 'is-filial'; // Label reflete o TIPO real da conta — conta solo é 'advogado', nunca 'filial'. const label = tipo === 'matriz' ? 'MATRIZ' : tipo === 'advogado' ? 'ADVOGADO' : 'FILIAL';
- **Correção**: Mapear cls pelos 3 tipos e adicionar .tk-card .origin-strip.is-advogado em tarefas.css.

### 38. Endpoint media_upload.php (WhatsApp) declarado no mapa de API mas nunca chamado
- **Módulo**: x_pontas_soltas · **Tipo**: ponta_solta
- **Arquivo**: `public/api/whatsapp/media_upload.php:1`
- **Problema**: O endpoint media_upload.php recebe arquivo (multipart/base64), valida MIME via finfo e retorna base64 pra Evolution. Esta declarado no mapa de endpoints de chat.php como 'upload' (chat.php:2341), mas chat.js NUNCA referencia API.upload nem o path /api/whatsapp/media_upload.php. O envio de arquivo foi reescrito: chat.js le o File client-side via FileReader.readAsDataURL (chat.js:1305-1317), guarda em state.pendingFile e manda o base64 direto pelo send.php (chat.js:1248-1265). Resultado: media_upload.php e codigo morto e o config 'upload' aponta pra um endpoint que ninguem usa.
- **Evidência**: chat.php:2341 `upload : '/api/whatsapp/media_upload.php',` — porem grep por 'media_upload'/'API.upload' em chat.js = 0 ocorrencias; chat.js:1248 envia via API.send com `media: file.base64`. media_upload.php so e referenciado pelo proprio arquivo + pelo mapa em chat.php.
- **Correção**: Decidir o caminho unico: ou remover o endpoint media_upload.php + a entrada 'upload' do mapa (limpar codigo morto), ou migrar chat.js pra usar media_upload.php (vantagem: validacao server-side de MIME via finfo, que o fluxo atual base64->send.php pode estar pulando). Verificar se send.php revalida o MIME server-side; se nao, o bypass do media_upload.php pode ser tambem um gap de seguranca.

### 39. Endpoint master/overview.php (overview global do Painel Master) sem caller — substituido por dashboard.php
- **Módulo**: x_pontas_soltas · **Tipo**: ponta_solta
- **Arquivo**: `public/api/master/overview.php:1`
- **Problema**: overview.php e um endpoint super_admin completo que agrega contas/assinaturas/MRR/faturas/totais globais. O Painel Master (master.php) nunca o chama — usa ${API}/dashboard.php (master.php:2772) pra os mesmos KPIs. master/dashboard.php:5 inclusive comenta 'Complementa overview.php', confirmando que overview.php ficou pra tras. E endpoint morto.
- **Evidência**: master.php usa `fj(`${API}/dashboard.php`)` (linha 2772) mas NUNCA `${API}/overview.php`; grep 'overview.php' so acha o comentario em master/dashboard.php:5 + o proprio overview.php. overview.php:56 retorna payload completo via ApiResponse::ok.
- **Correção**: Remover public/api/master/overview.php (codigo morto) OU, se a intencao era consolidar, migrar master.php pra consumir overview.php e aposentar a parte redundante de dashboard.php. Manter os dois gera divergencia de numeros entre telas.

### 40. Endpoint master/consents.php (aceites LGPD) implementado mas sem caller no Painel Master
- **Módulo**: x_pontas_soltas · **Tipo**: ponta_solta
- **Arquivo**: `public/api/master/consents.php:1`
- **Problema**: consents.php lista entradas de lgpd_consents (com filtros e modo ?counts=1 pra badge), restrito a super_admin + master_mode. Nenhuma tela chama esse endpoint: master.php nao faz fetch em consents.php (os hits de 'consent' em master.php sao apenas <option> de dropdown de finalidade, nao chamadas). Endpoint LGPD pronto mas nao plugado em nenhuma aba do Painel Master.
- **Evidência**: consents.php:40 implementa ?counts=1 e listagem; grep 'consents.php' repo-wide = so o proprio arquivo. master.php so tem `revogacao_consentimento`/`consentimento_especifico` como valores de <option> (linhas 737, 1905, 2031), nenhum fetch.
- **Correção**: Adicionar aba/painel 'Aceites LGPD' em master.php que consuma /api/master/consents.php (badge via ?counts=1 + lista). Se ja existe outra tela cobrindo aceites (ex.: via lgpd_requests), remover consents.php pra evitar endpoint orfao.

### 41. Endpoint push/users.php (select de responsavel do modulo Push) sem caller
- **Módulo**: x_pontas_soltas · **Tipo**: ponta_solta
- **Arquivo**: `public/api/push/users.php:1`
- **Problema**: push/users.php lista usuarios do tenant 'pra select de responsavel' (filtrado por account_id). intimacoes.js (modulo Push, apiBase '/api/push') nunca chama '/users.php' — grep por users.php/getUsers/loadUsers em intimacoes.js = 0. escritorios.php (que tambem mexe com alocacoes de monitoramento) tambem nao chama push/users.php. Endpoint pronto e sem consumidor.
- **Evidência**: push/users.php:3-6 doc 'Lista usuarios do tenant pra select de responsavel — GET /api/push/users.php'; grep '/users.php' em intimacoes.js = 0; grep 'push/users' em escritorios.php/intimacoes.php = 0.
- **Correção**: Se o select de responsavel do Push deve listar usuarios da conta, ligar o componente a /api/push/users.php (idealmente via Yuris.populateUserSelect agrupando Matriz/Filial). Caso o select ja seja populado por outro endpoint, remover push/users.php.

---

## BAIXA (36)

### 1. chat_mencoes.account_id nunca preenchido no INSERT (mencoes nao sao taggeadas por tenant)
- **Módulo**: chat_interno · **Tipo**: ponta_solta
- **Arquivo**: `public/api/chat/mensagens.php:101`
- **Problema**: A tabela chat_mencoes possui a coluna account_id (int, nullable, indexada/MUL), sugerindo intencao de escopar mencoes por tenant. Porem o INSERT em mensagens.php nao inclui account_id na lista de colunas, deixando-o sempre NULL. Coluna criada mas nao escrita por nenhum codigo do modulo — ponta solta. Sem isso, qualquer auditoria/relatorio/filtro multi-tenant sobre mencoes fica sem o tenant de origem.
- **Evidência**: DB: chat_mencoes.account_id int(11) YES MUL NULL mensagens.php:101 'INSERT INTO chat_mencoes (mensagem_id, tipo, referencia_id, texto_exibido, url_destino) VALUES (?, ?, ?, ?, ?)' // account_id ausente
- **Correção**: Incluir account_id no INSERT de chat_mencoes (o aid da conta da conversa / sessao via AccountContext) ou, se a coluna for de fato inutil, remover via migration para nao deixar a feature pela metade.

### 2. api_key do agente cifrada com MFA_ENCRYPTION_KEY em vez de App\Helpers\Crypto/APP_ENCRYPTION_KEY
- **Módulo**: config · **Tipo**: outro
- **Arquivo**: `public/api/agent_settings.php:27`
- **Problema**: A convencao do YURIS para cifrar credenciais at-rest e App\Helpers\Crypto (AES-256-GCM, APP_ENCRYPTION_KEY). agent_settings.php cifra a api_key reaproveitando TotpHelper::encryptSecret/decryptSecret, que usa AES-256-CBC com MFA_ENCRYPTION_KEY (chave do TOTP/MFA). Funciona, mas: (1) acopla a credencial do agente a chave de MFA — rotacionar a chave de MFA inutiliza as api_keys salvas; (2) usa CBC sem tag de autenticacao (Crypto usa GCM autenticado); (3) inconsistente com aasp_integrations e demais credenciais. Risco mais de manutenibilidade/criptografia do que exploravel.
- **Evidência**: agent_settings.php linha 27: require TotpHelper '// reusa encryptSecret/decryptSecret'; linha 121: $apiKeyEnc = TotpHelper::encryptSecret($apiKey); TotpHelper.php linha 108-117 usa openssl_encrypt(..., 'AES-256-CBC', $key, ...) com getEncryptionKey() lendo MFA_ENCRYPTION_KEY (linha 182). Crypto.php (padrao) usa aes-256-gcm com APP_ENCRYPTION_KEY.
- **Correção**: Migrar a cifragem da api_key do agente para App\Helpers\Crypto (APP_ENCRYPTION_KEY, GCM autenticado), alinhando com o resto do sistema; ou ao menos usar uma chave dedicada e documentar o acoplamento. Prever migracao dos valores ja cifrados em CBC.

### 3. marcarLida nao escopa por account_id — risco teorico de marcar notificacao de outro tenant
- **Módulo**: config · **Tipo**: gap_matriz_filial
- **Arquivo**: `app/Models/AccountNotification.php:68`
- **Problema**: marcarLida($id, $userId) filtra apenas por id e (user_id = :uid OR user_id IS NULL), sem account_id. Para notificacoes de conta (user_id IS NULL), um usuario de outro tenant poderia, adivinhando o id, marcar como lida uma notificacao que pertence a outro account. O PATCH em account_notifications.php nao passa o account_id para esse metodo. Impacto e baixo (so altera lida=1/lida_em, nao vaza conteudo nem altera dados de negocio), mas e uma escrita cross-tenant que foge do padrao getAccessibleAccountIds/escopo por account.
- **Evidência**: AccountNotification.php linha 68-77: marcarLida(int $id, int $userId): UPDATE account_notifications SET lida=1, lida_em=NOW() WHERE id = :id AND (user_id = :uid OR user_id IS NULL); // sem account_id. account_notifications.php linha 48: AccountNotification::marcarLida($id, $ctx->getUserId()); // account_id do ctx nao e repassado.
- **Correção**: Adicionar AND account_id = :acc em marcarLida e passar $ctx->getAccountId() a partir do endpoint, garantindo que so notificacoes do proprio tenant sejam alteradas.

### 4. juridico_metrics.php retorna varios campos que o Dashboard nunca consome (active_count tenant-scoped ignorado; hearings_month sempre vazio)
- **Módulo**: dashboard · **Tipo**: feature_orfa
- **Arquivo**: `public/api/juridico_metrics.php:158`
- **Problema**: O dashboard (loadExtended) faz fetch de juridico_metrics.php mas o unico uso de metricsData sao os campos deadlines_today/7/15/30 (dashboard.php linhas 1099, 1108, 1112, 1116). Os demais campos calculados e escopados por tenant — active_count, by_lawyer, deadlines_week, urgent, no_update — sao retornados e descartados no Dashboard. Em particular o card 'Processos Ativos' (jurAtivos) e preenchido por computeJurKPIs(processes) (recomputo client-side a partir de /api/processes.php, dashboard.php linha 1092), ignorando o active_count ja pronto e ja filtrado por conta da API. Alem disso 'hearings_month' e hardcoded como [] (linha 162) — campo stub que nunca traz dado. Nao ha vazamento nem quebra, mas ha trabalho de servidor desperdicado e fonte de verdade duplicada para 'Processos Ativos'.
- **Evidência**: juridico_metrics.php:158-168 retorna active_count/by_lawyer/deadlines_week/urgent/no_update/hearings_month; dashboard.php so referencia metricsData.deadlines_today/_7/_15/_30 (grep: 4 ocorrencias) e seta jurAtivos via computeJurKPIs(processes) na linha 1092. juridico_metrics.php:162 "'hearings_month' => [],".
- **Correção**: Ou consumir active_count (e demais campos) da API no Dashboard em vez de recomputar de processes.php, ou enxugar o payload de juridico_metrics.php para o que o Dashboard realmente usa. Remover/implementar o stub hearings_month.

### 5. Dropdown 'Tipo de conta' em Escritorios omite a opcao 'advogado'
- **Módulo**: escritorios · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/escritorios.php:555`
- **Problema**: O select de edicao 'Tipo de conta' (Minha Conta) so oferece <option matriz> e <option filial>; nao ha opcao 'advogado', embora accounts.tipo seja enum('matriz','filial','advogado'). Para uma conta tipo='advogado', toggleEditConta faz editTipo.value='advogado', que nao existe no select e cai silenciosamente na 1a opcao ('matriz'), mostrando o tipo errado ao admin advogado. O salvamento de 'tipo' ja foi removido do allowed em accounts.php (LGPD P1), entao nao ha escalonamento de privilegio — e inconsistencia/UX enganosa, nao falha de seguranca.
- **Evidência**: public/escritorios.php:555 "<select id=\"editTipo\" class=\"es-input\"><option value=\"matriz\">Matriz...</option><option value=\"filial\">Filial...</option></select>" (sem advogado) + :1130 "document.getElementById('editTipo').value = _contaData.tipo || 'matriz';"
- **Correção**: Tornar o select read-only/desabilitado (ja que tipo nao e editavel pelo tenant) ou incluir <option value=\"advogado\">Advogado (solo)</option> para refletir corretamente o tipo da conta advogado.

### 6. Falta classe CSS badge-advogado — badge de tipo do advogado renderiza sem estilo
- **Módulo**: escritorios · **Tipo**: ponta_solta
- **Arquivo**: `public/escritorios.php:1113`
- **Problema**: carregarConta() renderiza o tipo da conta como '<span class="badge badge-${c.tipo}">', e buscarContaAdvogado tambem usa 'badge badge-${r.data.tipo}'. Para tipo='advogado' a classe gerada e .badge-advogado, que nao esta definida no CSS da pagina (so existem .badge-matriz e .badge-filial). O badge do advogado aparece sem cor/borda de tipo (apenas o .badge generico). Cosmetico, mas inconsistente com a existencia legitima do tipo advogado.
- **Evidência**: public/escritorios.php:1113 "document.getElementById('cTipo').innerHTML = `<span class=\"badge badge-${c.tipo}\">${c.tipo}</span>`;" — CSS define apenas (:59) .badge-matriz e (:60) .badge-filial, sem .badge-advogado
- **Correção**: Adicionar uma regra .badge-advogado (ex: paleta verde/ambar) no bloco de estilos de escritorios.php, espelhando badge-matriz/badge-filial, inclusive no tema claro.

### 7. buscarDestinoModulo le r.data.account_id que /api/lookup.php nao retorna para advogado
- **Módulo**: escritorios · **Tipo**: ponta_solta
- **Arquivo**: `public/escritorios.php:1893`
- **Problema**: No modal 'Liberar modulo', quando o codigo resolve para um advogado, o JS faz '_moduleAlvo = { kind:'advogado', userId:r.data.user_id, accountId:r.data.account_id, ... }'. Porem /api/lookup.php no ramo advogado retorna em data apenas { user_id, nome, codigo_advogado } — NAO inclui account_id (foi removido por LGPD anti-enumeracao). Logo _moduleAlvo.accountId fica undefined e confirmarLiberarModulo envia to_account_id: undefined. Na pratica nao quebra o acesso porque o share de modulo e resolvido por to_user_id em AccountContext (to_account_id OR to_user_id), mas o registro fica sem to_account_id (inconsistente com o ramo 'conta') e qualquer logica futura que dependa de to_account_id do share de modulo do advogado falhara.
- **Evidência**: public/escritorios.php:1893 "_moduleAlvo = { kind: 'advogado', userId: r.data.user_id, accountId: r.data.account_id, nome: r.data.nome };" + public/api/lookup.php:120-127 retorna data => { user_id, nome, codigo_advogado } (sem account_id) + AccountContext.php:413 "(to_account_id = :acc OR to_user_id = :uid)" (por isso ainda funciona)
- **Correção**: Ou incluir account_id no payload do advogado em lookup.php (se aceitavel pela politica anti-enumeracao), ou no JS nao tentar setar to_account_id no ramo advogado (deixar apenas to_user_id), removendo a leitura de campo inexistente.

### 8. Branch closed_until em dre_accounts.php nao tem nenhum caller
- **Módulo**: financas · **Tipo**: ponta_solta
- **Arquivo**: `public/api/dre_accounts.php:66`
- **Problema**: O GET de dre_accounts.php trata tres modos de filtro temporal de cards fechados: ?start/?end, ?closed_month e ?closed_until. Mas o unico caller (dre.js) so envia ?closed_month (dre.js:123). Nenhum lugar do front envia closed_until nem start/end para este endpoint. O ramo closed_until (linha 66-69) e codigo morto — risco baixo, mas indica feature parcialmente implementada/abandonada.
- **Evidência**: dre_accounts.php:66: `} elseif (isset($_GET['closed_until'])) {` ... vs dre.js:123: `if (month) url += '?closed_month=' + encodeURIComponent(month);` (unico parametro enviado). Grep 'closed_until' no front: sem matches em JS.
- **Correção**: Remover o ramo closed_until se nao houver plano de uso, ou plugar um caller (ex.: filtro de range no financas.php). Sem impacto funcional imediato.

### 9. dre.js: funcao renderSummary() e elemento #dreSummary mortos (resto de layout antigo)
- **Módulo**: financas · **Tipo**: ponta_solta
- **Arquivo**: `public/assets/dre.js:275`
- **Problema**: renderSummary(s) (dre.js:275-279) acessa #sumReceita/#sumDespesa/#sumResultado via .textContent SEM guarda de null e nunca e chamada por ninguem (codigo morto — se fosse chamada antes desses elementos existirem, quebraria). Alem disso, applyFiltersAndRender referencia document.getElementById('dreSummary') (dre.js:131 e 190), elemento que nao existe no layout atual de financas.php (la os KPIs sao cards com ids proprios e o status fica em #dashboardStatus). Os trechos sao protegidos por `if (wrap)`, entao o ramo de criacao do closedOnlyRow e dead branch inofensivo, mas e legado confuso.
- **Evidência**: dre.js:275-279: `function renderSummary(s){ document.getElementById('sumReceita').textContent = fmtMoney(s.receita || 0); ... }` (sem null-check e sem caller). dre.js:131: `const wrap = document.getElementById('dreSummary');` — #dreSummary inexistente em financas.php.
- **Correção**: Remover renderSummary() e os ramos baseados em #dreSummary/closedOnlyRow, ja que o layout atual usa cards com ids dedicados (sumReceita etc. atualizados em dre.js:146-151).

### 10. DDL inline (CREATE TABLE taxes) dentro do render de financas.php
- **Módulo**: financas · **Tipo**: hardcode_erro
- **Arquivo**: `public/financas.php:46`
- **Problema**: financas.php executa um CREATE TABLE IF NOT EXISTS taxes (...) a cada carregamento da pagina (dentro de try/catch). A tabela ja foi formalizada pela migration 031_create_taxes.sql e existe no schema.sql. Rodar DDL em todo request de uma tela e fragil (depende de privilegio CREATE do usuario do app, custo por request) e o schema inline pode divergir do schema oficial com o tempo. Risco baixo porque ha try/catch, mas e um residuo de quando a tabela era criada on-the-fly.
- **Evidência**: financas.php:46-54: `$pdo->exec("CREATE TABLE IF NOT EXISTS taxes ( id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NULL, nome VARCHAR(100) ... ) ENGINE=InnoDB ...");` (comentario da migration 031: 'Formaliza a tabela taxes que antes era criada inline pelo endpoint public/api/taxes.php').
- **Correção**: Remover o CREATE TABLE inline de financas.php (e o equivalente, se houver, em taxes.php) e confiar na migration 031. Mantem o codigo de tela so com leitura.

### 11. Lista de contas e detalhe exibem 'matriz #<id>' a partir de accounts.matriz_id (sempre NULL) — hint nunca aparece
- **Módulo**: master · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/master.php:2847`
- **Problema**: A tabela de contas (loadAccounts) e o accounts.php expõem a.matriz_id e a UI tenta mostrar '<small>matriz #X</small>' ao lado do tipo. Como a coluna é sempre NULL, esse indicador de vínculo nunca é renderizado para filiais. É cosmético (não quebra fluxo), mas a informação de a qual matriz a filial pertence some da listagem.
- **Evidência**: master.php:2847 → <td><span class="pill pill-${esc(a.tipo)}">${esc(a.tipo)}</span>${a.matriz_id?' <small>matriz #'+a.matriz_id+'</small>':''}</td>. accounts.php:136 seleciona a.matriz_id. DB confirma matriz_id NULL em todas as contas.
- **Correção**: Quando o backend (accounts.php) passar a resolver a matriz via account_vinculos, expor um campo tipo matriz_nome/matriz_id real e usá-lo aqui; ou remover o hint enquanto depender de a.matriz_id.

### 12. Dropdown 'conta' ao criar advogado rotula advogado-solo e outras contas como [F] (Filial)
- **Módulo**: master · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/master.php:2385`
- **Problema**: openModalAdvogado popula o select de conta destino com TODAS as contas e rotula cada uma com o ternário a.tipo === 'matriz' ? 'M' : 'F'. Esse ternário binário esquece o terceiro tipo: uma conta tipo 'advogado' (e qualquer não-matriz) é exibida como [F] (Filial), rótulo incorreto. É o padrão exato que a convenção alerta (matriz?X:'FILIAL' esquece advogado).
- **Evidência**: o.textContent = `[${a.tipo === 'matriz' ? 'M' : 'F'}] ${a.nome} (#${a.id})`; (master.php:2385). r.data.accounts.forEach(...) sem filtro de tipo (linha 2382).
- **Correção**: Mapear os 3 tipos, ex.: const tag = a.tipo==='matriz'?'M':a.tipo==='filial'?'F':'A'; ou usar o label completo do tipo (já existe o dicionário em master.php:2223-2226). Idealmente agrupar via Yuris.buildGroupedOptionsByName.

### 13. viewAcc usa ícone/cor binários (matriz vs 'store') tratando advogado-solo como filial
- **Módulo**: master · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/master.php:2548`
- **Problema**: No cabeçalho do detalhe da conta, const isMatriz = d.tipo === 'matriz' e depois ico(isMatriz ? 'building' : 'store', { color: isMatriz?'#60a5fa':'#c084fc' }). Uma conta tipo 'advogado' cai no ramo 'else' e recebe o ícone/cor de filial. O pill do tipo em si está correto (pill-${d.tipo}), então é só inconsistência visual de ícone, mas reforça o padrão de esquecer o 3º tipo.
- **Evidência**: const isMatriz = d.tipo === 'matriz'; (master.php:2535) ... ${ico(isMatriz ? 'building' : 'store', {... color:'+(isMatriz?'#60a5fa':'#c084fc')})} (master.php:2548)
- **Correção**: Escolher ícone/cor por d.tipo cobrindo matriz/filial/advogado (ex.: scale/verde para advogado, como em viewAdvogado), em vez do binário isMatriz.

### 14. create_filial.php grava accounts.matriz_id (coluna vestigial sempre NULL)
- **Módulo**: master · **Tipo**: ponta_solta
- **Arquivo**: `public/api/master/create_filial.php:118`
- **Problema**: create_filial.php insere a filial preenchendo a coluna matriz_id e, corretamente, também cria a linha em account_vinculos. Como o restante do sistema trata accounts.matriz_id como sempre NULL (e a filial pré-existente #9 está com NULL), gravar matriz_id aqui cria inconsistência: contas novas teriam matriz_id preenchido enquanto as antigas não, e queries que (erroneamente) leem matriz_id passariam a funcionar só para as novas — mascarando o bug. O vínculo canônico é o account_vinculos.
- **Evidência**: INSERT INTO accounts (..., tipo, matriz_id, codigo_vinculo, ...) VALUES (..., 'filial', :mid, ...) com 'mid' => $matrizId (create_filial.php:116-135). Logo abaixo cria account_vinculos (linhas 140-152). DB: filial #9 tem matriz_id NULL.
- **Correção**: Padronizar: ou remover a escrita de matriz_id (fonte única = account_vinculos) ou adotar matriz_id em todo o app e fazer backfill. Não manter os dois caminhos meio-implementados.

### 15. PATCH de accounts permite trocar tipo (matriz/filial/advogado) sem ajustar account_vinculos
- **Módulo**: master · **Tipo**: ponta_solta
- **Arquivo**: `public/api/master/accounts.php:181`
- **Problema**: O PATCH de accounts aceita atualizar 'tipo' para matriz|filial|advogado, mas não há nenhum tratamento dos vínculos em account_vinculos. Ex.: rebaixar uma matriz para 'filial' (ou promover uma filial para 'matriz') deixa as linhas de account_vinculos órfãs/incoerentes — a herança matriz↔filial passa a divergir do tipo. Como o vínculo é o que governa herança de pipeline/cards/processos (AccountContext), uma troca de tipo pelo Master pode deixar o tenant num estado inconsistente.
- **Evidência**: if (isset($input['tipo'])) { if (!in_array($input['tipo'], ['matriz','filial','advogado'], true)) ApiResponse::badRequest('tipo inválido'); $fields[] = 'tipo = :tipo'; ... } (accounts.php:181-186) — nenhum UPDATE/DELETE em account_vinculos no método PATCH.
- **Correção**: Ao alterar tipo, validar/ajustar account_vinculos (ex.: bloquear rebaixar matriz que tem filiais ativas; ao virar filial exigir/avisar sobre vínculo; ao virar matriz remover vínculo como filial). No mínimo, alertar o operador da inconsistência.

### 16. CREATE TABLE IF NOT EXISTS de processo_history (inline em 3 endpoints) omite colunas author_account_* usadas pelo INSERT
- **Módulo**: processos · **Tipo**: ponta_solta
- **Arquivo**: `public/api/processo_history.php:26`
- **Problema**: processo_history.php, processo_tarefas.php e processo_prazos.php executam, no topo, um CREATE TABLE IF NOT EXISTS processo_history com apenas (id, processo_id, user_email, acao, descricao, created_at) — sem author_account_id/tipo/nome (nem ip/user_agent/request_id). Porém o POST de processo_history.php (linhas 82-95) faz um INSERT RAW nessas colunas author_account_*, e o ProcessoAudit insere também ip/user_agent/request_id. As colunas são adicionadas pela migration 040_processo_history_author_account.sql. Em um banco já migrado funciona; mas num banco fresco onde algum desses endpoints rode ANTES da migration 040, a tabela é criada incompleta e o INSERT raw do POST (que, diferente do ProcessoAudit, não tem try/catch isolado — depende do catch externo que devolve 500 via ErrorReporter) falha ao adicionar observação manual. Fragilidade latente / dependência de ordem de migração.
- **Evidência**: processo_history.php:26-34 cria a tabela sem author_account_*, mas processo_history.php:82-86 faz INSERT INTO processo_history (... author_account_id, author_account_tipo, author_account_nome) VALUES (...). Colunas só existem via database/migrations/040_processo_history_author_account.sql:20-24 (ADD COLUMN author_account_id/tipo/nome).
- **Correção**: Alinhar o CREATE TABLE IF NOT EXISTS inline ao schema real (incluir author_account_id/tipo/nome, ip, user_agent, request_id) OU remover os CREATE TABLE inline e confiar nas migrations. Idealmente centralizar a criação/escrita em ProcessoAudit (que já trata falha graciosamente) e não duplicar o DDL em 3 arquivos.

### 17. Codigo morto de checklist/historico aponta para IDs de DOM inexistentes
- **Módulo**: prospeccao · **Tipo**: ponta_solta
- **Arquivo**: `public/prospeccao.php:2833`
- **Problema**: As funcoes loadChecklist (2343), loadCardHistory (2378) e bindChecklistEvents (2833) referenciam byId('checklistItems'), byId('checklistProgress'), byId('checklistProgressBar'), byId('cardHistory'), byId('addChecklist'), byId('newChecklistItem') — nenhum desses IDs existe no HTML da pagina (grep por id="checklistItems" etc retornou zero). O recurso foi 'Movido para Gestao Processual': bindChecklistEvents esta comentado em bindAll (linha 3101) e as chamadas em openEditModal estao comentadas (linhas 2606-2607). Logo nunca executa em runtime (sem erro visivel ao usuario), mas e codigo morto com referencias penduradas que quebrariam se reativado as cegas (ex.: byId('addChecklist').addEventListener em null lanca TypeError).
- **Evidência**: function bindChecklistEvents() { byId('addChecklist').addEventListener('click', async function() { // 'addChecklist' nao existe no DOM ... byId('checklistItems').addEventListener(...) // 'checklistItems' nao existe no DOM
- **Correção**: Remover bindChecklistEvents, loadChecklist, loadCardHistory e referencias relacionadas da prospeccao.php (a logica vive agora em Gestao Processual), ou, se forem reativar, recriar os elementos no modal de edicao antes.

### 18. Endpoints task_attachments.php e task_reminders.php sem nenhum caller no frontend (orfaos)
- **Módulo**: tarefas · **Tipo**: ponta_solta
- **Arquivo**: `public/api/task_attachments.php:1`
- **Problema**: tarefas.js e a unica UI do modulo e nao referencia task_attachments.php nem task_reminders.php (grep por 'task_attachments|task_reminders|anexo|lembrete|reminder' em tarefas.js: zero matches). O drawer (tarefas.php) tem abas geral/checklist/proc-tarefas/vinculos/comentarios/historico — nao ha aba de Anexos nem de Lembretes, nem botao de upload. Os anexos sao ate carregados em Task::withDetails ($task['anexos']) mas nunca renderizados. Ou seja: upload de anexo e criacao de lembrete de tarefa estao implementados no backend (com hardening LGPD caprichado) mas inacessiveis pelo usuario. task_reminders so e consumido pelo cron (tasks_recurrence_tick.php) — entao lembretes nunca podem ser criados pela UI, so existiriam se inseridos manualmente no banco.
- **Evidência**: grep 'task_attachments|task_reminders|anexo|lembrete|reminder' em public/assets/tarefas.js => No matches found. Drawer tabs em tarefas.php: data-tab="geral|checklist|proc-tarefas|vinculos|comentarios|historico" (sem anexos/lembretes).
- **Correção**: Decidir: ou plugar UI (aba Anexos com upload usando o download_url ja exposto, e aba/controle de Lembretes), ou marcar os endpoints como nao expostos. Hoje e codigo morto do ponto de vista do usuario final.

### 19. task_link_search.php escopa tenant sem o modulo 'tarefas' (inconsistente com o resto do modulo)
- **Módulo**: tarefas · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/api/task_link_search.php:14`
- **Problema**: Todos os endpoints do modulo Tarefas escopam por getAccessibleAccountIds('tarefas') (tasks.php:31, task_boards.php:19, task_columns.php:20, task_users.php:20). Mas task_link_search.php usa getAccessibleAccountIds() SEM o argumento de modulo. Sem modulo, getAccessibleAccountIds (AccountContext.php:359-363) so checa o toggle mestre sync_enabled e ignora a flag sync_tarefas. Efeito: uma matriz com uma filial cujo sync_tarefas esta DESLIGADO (mas sync_enabled ligado) ainda consegue buscar e vincular processos/cards/contatos daquela filial a uma tarefa — vazando alvos de vinculo de um modulo que deveria estar desativado para tarefas. Risco baixo/teorico (depende de config granular especifica) mas e uma inconsistencia real de escopo multi-tenant.
- **Evidência**: $ctx = AccountContext::fromSession(); $tenantIds = $ctx->getAccessibleAccountIds(); // sem 'tarefas'
- **Correção**: Trocar para $ctx->getAccessibleAccountIds('tarefas') para alinhar com tasks.php/task_boards.php e respeitar a flag sync_tarefas das filiais.

### 20. processos.js usa fallback /api/users.php (1 conta, sem grouping) dentro de populateUserSelect
- **Módulo**: usuarios · **Tipo**: ponta_solta
- **Arquivo**: `public/assets/processos.js:1230`
- **Problema**: _loadUsuarios() faz fetch('/api/users.php') quando window._SYSTEM_USERS nao esta disponivel, e _usuariosList e passado para Yuris.populateUserSelect em _fillUserSelect (1264) e _fillUserSelectById (1283). Como /api/users.php so retorna usuarios de 1 conta e sem account_nome/account_tipo, nesse caminho de fallback os selects de responsavel ficam sem usuarios de filiais e sem agrupamento. Severidade baixa porque processos.php embute window._SYSTEM_USERS (linha 1381 via getAccessibleUsers), entao o fallback so dispara se o embed falhar; mesmo assim e uma ponta solta latente que vaza a deficiencia do endpoint.
- **Evidência**: processos.js:1229-1233 "const r = await fetch('/api/users.php',{credentials:'same-origin'}); _usuariosList = ((await r.json()).data||[]);" alimentando processos.js:1264 "Yuris.populateUserSelect(sel, _usuariosList, ...)"
- **Correção**: No fallback, usar um endpoint que retorne matriz+filiais com account_nome/account_tipo (ex.: task_users.php ou um users.php corrigido), ou corrigir /api/users.php conforme os achados acima.

### 21. Campo 'Senha atual' sempre mostra 'Nao registrada' — caminho morto (senha_plain hardcoded vazio)
- **Módulo**: usuarios · **Tipo**: ponta_solta
- **Arquivo**: `public/usuarios.php:1389`
- **Problema**: No modal de edicao, o campo 'Senha atual' (edit_senha_atual) e preenchido com u.senha_plain. Mas o endpoint /api/users.php SEMPRE devolve $row['senha_plain'] = '' (a coluna senha_texto foi removida na Fase 0/LGPD). Logo o campo nunca exibe nada util e a logica condicional (placeholder 'Nao registrada', borda verde/ambar) e codigo morto que sempre cai no ramo 'nao registrada'. UI confusa/legado nao limpo.
- **Evidência**: usuarios.php:1389 "senhaAtualEl.value = u.senha_plain || '';" + usuarios.php:1390 "senhaAtualEl.placeholder = u.senha_plain ? '' : 'Nao registrada — salve uma nova senha';" vs api/users.php:62 "$row['senha_plain'] = '';" (sempre vazio).
- **Correção**: Remover o campo 'Senha atual' e a logica de senha_plain do modal (ja nao existe senha em texto plano por LGPD), deixando apenas o campo 'Nova senha'.

### 22. Dual-write em webhook_logs marcado como 'removido na etapa 8' mas ainda ativo; leituras usam webhook_deliveries
- **Módulo**: webhooks · **Tipo**: ponta_solta
- **Arquivo**: `app/Services/WebhookDispatcher.php:478`
- **Problema**: tryDeliver() ainda grava em webhook_logs com o comentário 'Dual-write em webhook_logs (compat com painel atual; removido na etapa 8)'. Porém o painel já lê exclusivamente de webhook_deliveries (public/api/webhooks.php l.86-110 action=logs, e os COUNTs do GET single/list l.129-151). Resultado: webhook_logs é escrita morta (ninguém lê) e cresce indefinidamente, e o comentário contradiz o código (diz que foi removido na etapa 8 mas continua). Risco baixo, mas é ponta solta + crescimento de tabela sem consumo.
- **Evidência**: WebhookDispatcher.php:478 // Dual-write em webhook_logs (compat com painel atual; removido na etapa 8). WebhookDispatcher.php:480 $pdo->prepare("INSERT INTO webhook_logs (webhook_id, event_key, payload, response_status, response_body, duration_ms, success, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
- **Correção**: Remover o dual-write para webhook_logs (já que nada lê) ou, se ainda houver consumidor legado, atualizar o comentário e plugar a leitura. Confirmar e então dropar a tabela legada em migration.

### 23. $tenantIds = getAccessibleAccountIds('chat') calculado mas NUNCA usado em messages.php (intencao cross-conta abandonada)
- **Módulo**: whatsapp · **Tipo**: ponta_solta
- **Arquivo**: `public/api/whatsapp/messages.php:23`
- **Problema**: messages.php calcula $tenantIds = $ctx->getAccessibleAccountIds('chat') na linha 23, mas o restante do arquivo escopa tudo por instanceId resolvido da PROPRIA conta (findOrCreate($instName,'',$accountId)) e nunca referencia $tenantIds. Variavel morta. Indica que a feature de matriz ver conversas da filial foi iniciada e abandonada — coerente com o achado abaixo (media.php le cross-conta mas a lista/mensagens nao).
- **Evidência**: $tenantIds = $ctx->getAccessibleAccountIds('chat'); // linha 23 — grep confirma: unica ocorrencia no arquivo, nunca usada depois
- **Correção**: Remover a linha morta, OU (se a intencao e matriz ver chats das filiais) propagar $tenantIds pra resolver instancias acessiveis e escopar getChatList/findByJid por account_id IN (...).

### 24. media.php e contato_vinculos.php leem cross-conta (matriz+filiais), mas a lista de chats e mensagens nao — feature de compartilhamento de chat pela metade
- **Módulo**: whatsapp · **Tipo**: feature_orfa
- **Arquivo**: `public/api/whatsapp/media.php:35`
- **Problema**: media.php (linha 35) e contato_vinculos.php (linha 19) usam getAccessibleAccountIds('chat') pra servir midia/contato de QUALQUER conta acessivel (matriz le da filial). Porem chats.php (lista) e messages.php so mostram conversas da instancia da PROPRIA conta. Logo a capacidade cross-conta de media.php/contato_vinculos.php e inalcancavel pela UI: a matriz nunca ve a conversa da filial na lista, entao nunca chega a pedir a midia daquela conversa. Feature presente numa camada e ausente na outra (analogo ao caso clientes vs prospeccao).
- **Evidência**: media.php: $accessibleIds = $ctx->getAccessibleAccountIds('whatsapp'); ... WHERE m.id=? AND (m.account_id IN ($inClause) OR wi.account_id IN ($inClause)) vs chats.php: usa apenas $accountId/instanceId proprio
- **Correção**: Decidir o escopo do modulo: ou WhatsApp e estritamente por conta (entao simplificar media.php/contato_vinculos.php pra getAccountId e remover $tenantIds morto de messages.php), ou e cross-conta (entao a lista e as mensagens precisam agregar instancias das contas acessiveis). Hoje esta inconsistente.

### 25. import 'use App\Services\EvolutionApiService' incorreto e morto em contacts.php (classe e global)
- **Módulo**: whatsapp · **Tipo**: ponta_solta
- **Arquivo**: `public/api/whatsapp/contacts.php:13`
- **Problema**: contacts.php declara use App\Services\EvolutionApiService (linha 13), mas EvolutionApiService NAO tem namespace (e global). O proprio codigo reconhece isso no comentario da linha 71-72 e contorna usando new \EvolutionApiService($cfg) (linha 73). O 'use' fica morto/enganoso: qualquer 'new EvolutionApiService' sem barra neste arquivo resolveria pra FQCN inexistente. Risco latente em futura edicao.
- **Evidência**: use App\Services\EvolutionApiService; // linha 13 — porem a classe e global; linha 73 usa new \EvolutionApiService($cfg) com barra pra contornar
- **Correção**: Remover o use App\Services\EvolutionApiService de contacts.php (e de send.php/refresh_chat.php se presente) e padronizar new \EvolutionApiService, ou mover a classe pra um namespace de verdade.

### 26. deleteChat remove mensagens/chat por instanceId sem confirmar tenant explicitamente
- **Módulo**: whatsapp · **Tipo**: gap_matriz_filial
- **Arquivo**: `app/Models/WhatsAppMessage.php:823`
- **Problema**: deleteChat() faz DELETE FROM whatsapp_messages/whatsapp_chats WHERE instance_id = ? AND remote_jid = ?. O caller (chats.php acao 'delete') resolve instanceId via findOrCreate($instName,'',$accountId) e valida row.account_id === accountId no inicio, entao na pratica esta isolado. Mas a defesa depende 100% dessa checagem no topo do endpoint; o metodo de delete (destrutivo) nao tem o filtro de account_id que markDeleted() tem (markDeleted filtra por account_id IN). Inconsistencia de defesa em profundidade para uma operacao de perda de dados.
- **Evidência**: public function deleteChat(int $instanceId, string $remoteJid): bool { $this->db->prepare('DELETE FROM whatsapp_messages WHERE instance_id = ? AND remote_jid = ?')->execute([$instanceId,$remoteJid]); ... } // sem account_id, ao contrario de markDeleted()
- **Correção**: Adicionar AND account_id IN (...) no DELETE (ou validar instanceId pertence ao accountId dentro do metodo), espelhando markDeleted(), como defesa em profundidade contra regressao no guard do endpoint.

### 27. Tabela webhook_event_queue (migration 070) criada e nunca usada
- **Módulo**: x_features_orfas · **Tipo**: feature_orfa
- **Arquivo**: `database/migrations/070_create_webhook_event_queue.sql:10`
- **Problema**: A migration 070 cria webhook_event_queue dizendo 'WebhookDispatcher::fire() passara a INSERIR aqui ... o worker bin/webhook_worker.php consome esta fila'. Na pratica a implementacao final usou webhook_deliveries como fila: WebhookDispatcher::fire() insere em webhook_deliveries (enqueueDelivery, linha 374) e bin/webhook_worker.php consome 'SELECT * FROM webhook_deliveries' (linha 45). Grep por 'webhook_event_queue' em todo public/app/bin (excluindo migrations) = 0 ocorrencias. A tabela e morta.
- **Evidência**: webhook_worker.php:44-45: 'SELECT * FROM webhook_deliveries WHERE status IN (\'pending\',\'retrying\')'. WebhookDispatcher.php:374 insere em webhook_deliveries. grep -rn 'webhook_event_queue' --include=*.php public app bin = vazio.
- **Correção**: Remover a tabela webhook_event_queue (drop migration) ou documentar explicitamente que foi substituida por webhook_deliveries, pra evitar confusao de quem mexer no pipeline de webhooks no futuro.

### 28. Tabela webhook_events (migration 068) so e populada por seed, nunca lida pelo app
- **Módulo**: x_features_orfas · **Tipo**: feature_orfa
- **Arquivo**: `database/migrations/068_create_webhook_events.sql:8`
- **Problema**: webhook_events foi criada como 'espelho persistido pra UI/documentacao' do catalogo de eventos. Porem o catalogo continua sendo servido 100% ao vivo do PHP: public/webhooks.php:13 usa 'WebhookDispatcher::catalog()'. A unica coisa que toca webhook_events e o script database/seed_webhook_events.php (INSERT/SELECT). Nenhum endpoint ou tela LE dessa tabela. Ou seja, a feature de 'enriquecer/desabilitar eventos sem deploy' que a tabela prometia nunca foi plugada — a tabela so acumula dados que ninguem consome.
- **Evidência**: webhooks.php:13: '$catalog = WebhookDispatcher::catalog();'. grep 'webhook_events\b' (excluindo _queue/_deliveries e migrations) so aparece em database/seed_webhook_events.php (linhas 26,62). Nenhum SELECT da tabela no fluxo da aplicacao.
- **Correção**: Ou ligar a UI/dispatcher pra ler webhook_events (merge com o catalog() para permitir descricao/payload editavel e toggle status), ou remover a tabela + o seed se a decisao for manter o catalogo hardcoded.

### 29. push_monitors.assigned_account_id nunca usado e assigned_user_id e write-only (migration 076)
- **Módulo**: x_features_orfas · **Tipo**: feature_orfa
- **Arquivo**: `database/migrations/076_push_monitors_addon_cols.sql:31`
- **Problema**: A migration 076 adicionou assigned_user_id e assigned_account_id em push_monitors pra 'atribuicao direta a um user (advogado)' e 'alocacao a uma filial inteira'. Na pratica: assigned_account_id NAO tem nenhuma referencia em PHP/JS (0 ocorrencias). assigned_user_id e apenas GRAVADO num unico lugar (public/api/push/requests.php:463, no UPDATE de aprovacao de request) e NUNCA lido — o model PushMonitor nao menciona 'assigned' em nenhum SELECT/WHERE. Logo a atribuicao do monitor a um usuario/filial e capturada mas nenhuma tela/consulta a usa pra filtrar ou exibir 'meus monitores'. Feature de atribuicao meio implementada (a alocacao real parece acontecer via tabela monitor_quota_allocations, migration 073).
- **Evidência**: requests.php:461-463: 'UPDATE push_monitors SET origem_criacao=\'request_approved\', assigned_user_id=:uid, consome_cota=1 WHERE id=:id'. grep 'assigned_user_id' em app/Models/PushMonitor.php = 0; grep 'assigned_account_id' em todo public/app/bin (excl. migrations) = 0.
- **Correção**: Decidir: (a) usar assigned_user_id/assigned_account_id nos filtros de listagem de monitores ('atribuidos a mim/minha filial'), ou (b) remover as colunas se monitor_quota_allocations ja cobre a alocacao, pra nao deixar campo escrito que ninguem le.

### 30. Card de cliente colore conta tipo 'advogado' como filial (faixa roxa)
- **Módulo**: x_features_orfas · **Tipo**: gap_matriz_filial
- **Arquivo**: `public/clientes.php:941`
- **Problema**: No render do card de cliente, o label da faixa de origem mapeia corretamente os 3 tipos (MATRIZ/ADVOGADO/FILIAL, linhas 943-945), mas a CLASSE CSS de cor so distingue matriz vs resto: 'const stripCls = origemTipo === \'matriz\' ? \'is-matriz\' : \'is-filial\';'. Assim uma conta 'advogado' (solo) recebe a cor visual de filial (is-filial, roxo) embora o texto diga ADVOGADO. E uma inconsistencia cosmetica do tratamento matriz/filial/advogado (o tipo advogado nao tem cor propria).
- **Evidência**: clientes.php:941: 'const stripCls = origemTipo === \'matriz\' ? \'is-matriz\' : \'is-filial\';' enquanto :943-945 ja trata os 3 tipos no label ('origemTipo === \'advogado\' ? \'ADVOGADO\' : \'FILIAL\'').
- **Correção**: Introduzir classe is-advogado (cor propria) e mapear stripCls pelos 3 tipos, igual ao label, pra a cor refletir o tipo real da conta.

### 31. Painel Master rotula conta 'advogado' como [F] (Filial) no select de advogados
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\master.php:2385`
- **Problema**: No modal de advogado do Painel Master, o prefixo da option é 'a.tipo === 'matriz' ? 'M' : 'F'', que rotula qualquer conta não-matriz como [F] (Filial), incluindo contas tipo 'advogado'. Como o ENUM de accounts.tipo inclui 'advogado' (e existe a conta #72 Gabriel tipo advogado no banco), o select mostra [F] pra contas que são advogado solo. É ferramenta admin (impacto baixo), mas é o clássico hardcode que esquece o 3o tipo.
- **Evidência**: o.textContent = `[${a.tipo === 'matriz' ? 'M' : 'F'}] ${a.nome} (#${a.id})`;
- **Correção**: Mapear os 3 tipos: ({matriz:'M',filial:'F',advogado:'A'}[a.tipo] || '?').

### 32. Filtro de Origem em Clientes exclui contas 'advogado' (pseudo __filiais__ e optgroup só pegam tipo='filial')
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\clientes.php:482`
- **Problema**: O optgroup 'Filial específica' é montado com array_filter(..., fn($a) => $a['tipo'] === 'filial'), e a opção pseudo '__filiais__' filtra clientes por tipo !== 'matriz' apenas no JS, mas a lista de contas específicas omite advogados vinculados. Assim, clientes vindos de uma conta advogado vinculada não podem ser isolados por origem. Inconsistente com prospeccao.php:1166 e dashboard.php:416/processos.php:1059/tarefas.php:151, que usam 'if ($oa['tipo'] === 'matriz') continue;' (incluem advogado, embora sob o rótulo 'Filial específica').
- **Evidência**: $filiaisOnly = array_filter($origin_accounts, fn($a) => $a['tipo'] === 'filial'); ... <optgroup label="Filial específica"> <?php foreach ($filiaisOnly as $a): ?>
- **Correção**: Padronizar com os outros módulos (incluir advogado no optgroup) e idealmente renomear o rótulo/grupo pra cobrir filial+advogado, ou agrupar via tipo real. Também alinhar a semântica de __filiais__ com a presença de advogados.

### 33. Optgroup 'Filial específica' em Prospecção/Processos/Tarefas/Dashboard inclui advogado sob rótulo errado
- **Módulo**: x_matriz_filial · **Tipo**: gap_matriz_filial
- **Arquivo**: `C:\xampp\htdocs\sistema_vendas\public\prospeccao.php:1166`
- **Problema**: O loop usa 'if ($oa['tipo'] === 'matriz') continue;' dentro de <optgroup label="Filial específica">, então uma conta vinculada do tipo 'advogado' aparece listada como se fosse Filial. Mesma construção em processos.php:1059, tarefas.php:151 e dashboard.php:416. É só rótulo (a opção funciona por id), mas confunde o usuário do matriz quando há advogado vinculado. Note ainda que as opções pseudo '__filiais__'/'__matriz__' desses filtros recortam por tipo==='filial' (ex.: dashboard.php:49), deixando o advogado fora do recorte '__filiais__'.
- **Evidência**: <optgroup label="Filial específica"> <?php foreach ($origin_accounts as $oa): if ($oa['tipo'] === 'matriz') continue; ?> <option value="<?=htmlspecialchars((string)$oa['id'])?>"><?=htmlspecialchars($oa['nome'])?></option>
- **Correção**: Separar advogados num optgroup próprio (ou rótulo neutro como 'Conta específica') e revisar a semântica das opções __filiais__/__matriz__ pra contemplar advogado de forma consistente entre os 5 módulos.

### 34. Endpoint legal/accept.php (registro de aceite de termo) sem caller no frontend
- **Módulo**: x_pontas_soltas · **Tipo**: ponta_solta
- **Arquivo**: `public/api/legal/accept.php:1`
- **Problema**: accept.php registra aceite de termo legal (POST {legal_document_id, contexto, titular_email}) com anti-replay e CSRF, usando models LegalDocument/TermAcceptance. Nenhum JS chama esse endpoint: o login (login.php) usa GET /api/auth/check_terms.php pra DETECTAR se ja aceitou e submete o aceite pelo proprio POST do form de login (campo aceite_termos), nao via accept.php. Endpoint de gravacao de aceite ficou sem caller frontend.
- **Evidência**: accept.php:5-8 doc POST; grep 'legal/accept'/'accept.php' no frontend = 0 callers (so o proprio arquivo + models). login.php:190 chama check_terms.php (GET) e usa input hidden aceite_termos_servidor + checkbox aceite_termos no submit do form, sem fetch em accept.php.
- **Correção**: Confirmar se algum fluxo (signup, modal de re-aceite de nova versao de termo) deveria usar accept.php. Se o aceite e 100% feito no POST do login server-side, remover accept.php ou documenta-lo como endpoint de API externa. Risco de termos de versao nova nunca serem re-aceitos se a UI nao chama accept.php.

### 35. Endpoint legal/documents.php (documento legal vigente) sem caller conhecido
- **Módulo**: x_pontas_soltas · **Tipo**: ponta_solta
- **Arquivo**: `public/api/legal/documents.php:1`
- **Problema**: documents.php serve a versao vigente de termos/privacidade (GET ?tipo=..., ?hash=1, ?list=1), parte publica. Nenhum fetch no frontend o consome — as paginas legais (termos.php, privacidade.php, cookies.php, dpo.php) sao renderizadas server-side via includes/legal_page.php, sem buscar documents.php. Possivelmente previsto pra consumo dinamico (modal de termos, app externo) que ainda nao existe.
- **Evidência**: documents.php:5-11 doc GET publico; grep 'legal/documents'/'documents.php' no frontend = 0 callers. As paginas legais usam `require __DIR__ . '/includes/legal_page.php'` (ex.: lgpd.php:83, dpo.php:74).
- **Correção**: Se a intencao e centralizar o texto legal no banco (LegalDocument) e exibir versao+hash dinamicamente, ligar as paginas/modais a documents.php. Caso contrario, e endpoint orfao. Confianca baixa por ser explicitamente 'publico' e poder ter consumidor externo.

### 36. Entrada de config CI_API.users (Chat Interno) e morta
- **Módulo**: x_pontas_soltas · **Tipo**: ponta_solta
- **Arquivo**: `public/chat_interno.php:756`
- **Problema**: O mapa CI_API em chat_interno.php define `users: '/api/users.php'`, mas chat_interno.js nunca usa CI_API.users — o proprio comentario em chat_interno.js:373 diz que carrega modal_data (via conversas.php?action=modal_data) 'em vez de /api/users.php' justamente pra ter account_id/nome/tipo. CI_API.users e config residual sem efeito (nao quebra nada, mas confunde).
- **Evidência**: chat_interno.php:756 `users : '/api/users.php',`; grep 'CI_API.users' = 0 ocorrencias; chat_interno.js:373 comentario 'Carrega modal_data (em vez de /api/users.php)'.
- **Correção**: Remover a chave 'users' do mapa CI_API em chat_interno.php (limpeza), ja que o fluxo migrou pra modal_data. Cosmetico, sem impacto funcional.
