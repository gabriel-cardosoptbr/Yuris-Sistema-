# public/api/ — os endpoints REST

137 endpoints. Cada arquivo `.php` é um endpoint, e o caminho do arquivo é a
URL: `public/api/whatsapp/send.php` responde em `/api/whatsapp/send.php`.

**Mover um arquivo daqui muda a URL de uma chamada que o front já faz.** Vale a
mesma restrição de [`../README.md`](../README.md).

## Como está organizado

| Local | Qtd | O que é |
|---|---|---|
| raiz de `api/` | 50 | os endpoints dos módulos de Operação e Gestão: cards, clientes, tarefas, processos, usuários, times, DRE, metas, webhooks, mais `prospeccao_conversao.php` ("Tornar cliente") e `timeline.php` (a linha do tempo única) e `cliente_vinculos.php` (conversa e tarefa vindas da prospecção) |
| `master/` | 42 | **tudo do Painel Master**: contas, filiais, planos, pagamentos, cotas, auditoria, LGPD, config de IA, canais de WhatsApp |
| `whatsapp/` | 22 | canal, chat, envio, mídia, e o `webhook.php` que recebe da Evolution |
| `push/` | 11 | monitoramento de publicações: monitores, cotas, permissões, busca, `tick.php`. Bloqueado (403) para conta `produto=fleetiflow`, exceto `tick.php`, que é cron sem conta única (ver abaixo) |
| `aasp/` | 4 | integração AASP: configurar, testar, buscar, sincronizar. Bloqueado (403) para conta `produto=fleetiflow` |
| `chat/` | 3 | chat interno entre usuários do escritório. `mencoes.php` é a busca do @: na edição jurídica, usuários, processos, cards e clientes; na edição CRM (conta sem módulo jurídico) um ramo próprio busca pessoas, leads (com etapa e consultor), clientes (setor e WhatsApp), tarefas (só de quadro que a pessoa enxerga, a regra de `TaskBoard::acesso`) e conversas de WhatsApp (do canal que a conta pode ver, por `resolveRequestedChannel` + `check`, que não encerram a requisição nem criam canal), com no máximo 4 por tipo em "Todos" e atalho por prefixo (`@lead fiat`, `@cliente`, `@tarefa`, `@zap`, `@pessoa`). `mensagens.php` grava a menção só depois de conferir que a referência é da conta, e a tarefa só se a pessoa vê o quadro |
| `legal/` | 3 | documentos legais, aceite e consentimento |
| `auth/` | 4 | checagem de termos pendentes no login (`check_terms.php`), sessão viva da tela aberta (`sessao_viva.php`), o token do formulário de login da sessão de agora (`csrf.php`, 28/10/2026: a tela pede logo antes de enviar, ver `assets/login-token.js`) e a troca de empresa de quem trabalha em mais de uma conta (`trocar_empresa.php`, 29/10/2026: GET lista as empresas, POST `{user_id}` com `X-CSRF-Token` troca a sessão, ver `App\Usuarios\PessoaVinculada`) |
| `lgpd/` | 1 | solicitação do titular, aberta ao público |

**Painel por vendedor (02/10/2026).** Na edição CRM, quem não é dono nem admin
(`AccountContext::vendedorCrm()`) recebe de `dashboard_comercial.php` só os próprios
cards (`responsavel` forçado para ele no servidor; `?responsavel=` de outro é
ignorado), só a própria meta e só ele na lista de responsáveis. `relatorios.php`
faz o mesmo na fonte `prospeccoes` e no dossiê de card (404 para card alheio).
`dre_accounts.php`, `dre_codes.php` e `taxes.php` respondem 403 para o vendedor:
financeiro é só de ADM nessa edição. Na edição CRM, o GET de `dre_accounts.php` devolve
também `recebido_clientes` (cobranças de clientes com baixa, pela data do pagamento, no
mesmo período ou mês do filtro) e soma esse valor em `combined.receita` (20/10/2026). Nessa edição `closed_total` não soma o
card cujo cliente tem valores cadastrados (exceto "por demanda"), para não dobrar a receita.
`crm_anexos.php` leva `contratos` (os documentos da aba Contratos ligados à ficha) na
listagem da edição CRM, e `crm_contratos.php` aceita `criar_de_anexo` (`{anexo_id}`: o PDF
anexado na ficha vira contrato do mesmo cliente ou lead). A edição jurídica não muda. Também em
`dashboard_comercial.php`: as consultas de metas e usuários usam `$tpAcc`, só os
parâmetros das contas; com `:resp` sobrando o PDO derrubava o painel (HY093), e
escolher uma pessoa em "Toda a equipe" quebrava a tela.

`whatsapp/chats.php` aceita `?coluna_id=N` (etapa do lead ligado à conversa, a coluna do card) e
`?coluna_id=0` (conversa sem lead), junto de `team_id` e `user_id`; é o filtro "Todas as etapas" do Chat da
edição CRM (07/10/2026). Sem o parâmetro, a lista é a de sempre.

**Dono por conversa (22/10/2026).** No número com o modo ligado (`App\WhatsAppAgente\DonoDaConversa`), o
vendedor só recebe na lista, nas não lidas e nos pendentes as conversas dele e as sem dono, e todo endpoint de
`whatsapp/` que recebe uma conversa responde 404 para a de outro vendedor. `chats.php` devolve
`dono_conversa: {eu, admin, equipe}` (null fora do modo) e cada conversa traz `dono_nome`; `POST chats.php`
com `action: 'dono'` assume (sem `user_id`) ou passa para `user_id`. `agent_takeover.php` ao pausar o agente
também passa a conversa para quem clicou. `send.php` grava `sent_by_user_id` em cada mensagem enviada (quem digitou; métricas de atendimento, migration 165).

`whatsapp/situacao.php` (sessão, edição CRM) é a linha "Aguardando resposta" do Chat: `GET ?jid=` devolve
`{disponivel, situacao}` (motivo, desde quando, quem pausou, agente ligado no número da conversa; canal com
`view`), e `POST {action:'mandar_agente', remote_jid, _csrf}` entrega de novo ao agente as mensagens do lead
que esperam resposta (canal com `send`; 409 com o motivo quando não pode). Regra em
`App\WhatsAppAgente\SituacaoConversa`. Na mesma edição, `whatsapp/messages.php` acrescenta `autor`,
`autor_rotulo` e `autor_deduzido` às mensagens nossas (`App\WhatsAppAgente\AutorDaMensagem`).

`followup.php` é a tela Operação › Follow-up (`App\FollowUp`, 23/10/2026). GET devolve as cadências, as
etapas do funil, a chave geral, a fila dos próximos envios (até 150, com data e hora, na ordem de saída, com
`ordem` na fila do número, `na_frente` e a faixa `sai_de`/`sai_ate` de `Envio::estimar`) e os últimos envios.
POST JSON com `X-CSRF-Token`, só dono/admin: `salvar` (cria ou edita; erro de validação volta 422 com a
mensagem), `ligar`, `excluir`, `ordenar` e `chave` (a chave geral, a mesma do botão do Chat). O vendedor da
edição CRM recebe 403. Na edição CRM, a listagem de `cards.php` traz em cada card `followup` (cadência, passo,
total, `previsto` em ISO 8601, `na_fila`, `desde`, `ultima_saida`, e, quando sai em até 12 h, `ordem`,
`na_frente`, `sai_de` e `sai_ate`; ou `estado: sem_mensagem`) e `robo`
(`{nome}` quando a conversa está com o robô de pré-venda), ou `null`; falha nessa leitura não derruba a lista.

`whatsapp/automacao_toggle.php` (sessão) e `whatsapp/sdr_automacao.php` (n8n, cabeçalho `X-Fleetiflow-Token`)
são os botões "Disparo" e "Follow-up" do Chat: o primeiro lê (`GET`, com `disponivel` e `pode_alterar`) e
grava (`POST {qual, ligado, _csrf}`, só owner/admin, só na conta da prospecção); o segundo é o que o robô de
disparo e a cadência de follow-up perguntam antes de cada mensagem (`{disparo, followup}`). Regra em
`App\WhatsAppAgente\AutomacaoSdr`.

`whatsapp/sdr_roleta.php` (n8n, cabeçalho `X-Fleetiflow-Token`) é a pergunta que o robô da planilha e o disparo das
listas fazem ANTES de montar a abertura: POST `{telefone, card_id?, tipo, origem}` devolve `{ok, atendente}` com
`user_id`, `atendente` (nome completo), `nome` (como aparece na mensagem, "Cauê") e `quem` (como se apresenta, "o
Cauê"), ou `atendente: null` com o `motivo` (roleta desligada, ou `tipo` diferente de `abertura`). Pedir de novo
para o mesmo lead devolve o mesmo atendente (`Roleta::reservar`).

`whatsapp/roleta.php` (sessão, só owner/admin, inclusive para ler) é a tela Operação › Roleta: GET devolve a
divisão (`ligada`, `participantes` `[{user_id, percentual}]`, `desde`), a equipe ativa, a `contagem` por pessoa
(`atual` desde a divisão salva e `total`) e os `ultimos` sorteios; `pronta: false` quando a migration 170 não
rodou. POST `{ligada, participantes, _csrf}` grava (soma diferente de 100, pessoa repetida ou de fora volta 422
com a mensagem). O sorteio em si é pedido pelo n8n no `whatsapp/sdr_etapa.php` com `roleta: true`, só na
abertura. Regra em `App\WhatsAppAgente\Roleta`.

`whatsapp/card_whatsapp.php?card_id=ID` devolve tudo do WhatsApp de um lead para a ficha do card da edição CRM:
etapa, setor, quem atende (agente de IA ligado ou pausado, pessoa, responsável), os números nossos ligados à
conversa (nome, telefone, status, dono) e as 300 mensagens mais recentes em texto. Só lê o banco (não chama a
Evolution), então responde com o número desconectado. Exige que o card seja de uma conta acessível pelo
usuário (senão 404) e sessão (senão 401).

`reembolsos.php` é a API dos reembolsos e estornos da tela Finanças (edição CRM): `GET`
devolve a lista com parcelas, situação e o resumo, o saldo por pessoa (`pessoas`), os
usuários da conta (`usuarios`, para o "Para quem") e os tipos; criar e atualizar aceitam
`tipo` (reembolso|estorno) e `favorecido_user_id`, que precisa ser usuário da conta da
sessão (senão 422) e dá o nome; `POST {acao}` cria, atualiza, exclui, marca
parcela como paga (`pagar`), desfaz (`desfazer`) ou quita tudo (`quitar`). CSRF no
cabeçalho; `pagar` e `quitar` levam `pago_em` e `pago_por_nome` (quem pagou). Vendedor
recebe 403, conta sem o módulo ligado 404; leitura e escrita pela lista de contas
acessíveis de `financas`. Regras em `app/Financas/README.md`.

`evolution.php` é a API da aba Evolution (edição CRM): `GET` lê o Evolution na hora (só
leitura, configuração global do Painel Master), grava a foto do dia de cada instância e
devolve instâncias (sem token), resumo, por projeto, histórico diário e a lista de
projetos (com mãe) para o vínculo; se o Evolution não responde, devolve `erro` e o
histórico. `POST {acao}`: `vincular` (instância → projeto da conta; 0 desliga) e
`custo_salvar` (custo mensal; vazio apaga), ambos auditados (`evolution.*`). CSRF no
cabeçalho, `Cache-Control: no-store`. Vendedor recebe 403, conta sem a pasta Projetos 404.
Regras em `app/Projetos/README.md`.

`projetos.php` é a API da pasta Projetos (edição CRM): `GET` devolve o painel inteiro
(projetos com itens, contas, acessos sem senha, números, pendências, evolução e, na mãe,
o agregado com os subprojetos; servidores com o rateio; resumo; opções dos selects;
cotação do dólar). `POST {acao}`: `projeto_salvar` (aceita `parent_id`),
`projeto_excluir`, `item_salvar`, `item_excluir`, `servidor_salvar`, `servidor_excluir`,
`conta_salvar`, `conta_excluir`, `acesso_salvar`, `acesso_excluir`, `cotacao_salvar`;
toda escrita devolve o painel atualizado. `acesso_revelar` devolve só a senha decifrada e
grava `projeto.acesso_revelado` no histórico; a resposta sai com `Cache-Control: no-store`.
O histórico de `acesso_salvo` nunca leva a senha, só se ela foi trocada ou apagada. CSRF no
cabeçalho. Vendedor recebe 403, conta sem o módulo ligado 404; leitura e escrita pela
lista de contas acessíveis de `financas`. Regras em `app/Projetos/README.md`.

`tema_escuro.php?arquivo=ff-ficha|chat-numeros` devolve a cópia escura de uma folha da
edição CRM, gerada na hora por `App\Master\TemaEscuroCrm` (lista fechada de arquivos,
nada de caminho vindo da URL; cache com ETag pela data do arquivo e do conversor).

`dashboard_comercial.php` alimenta o cockpit comercial da conta Fleetiflow
(`includes/dashboard_fleetiflow.php`) numa chamada só. Desde 20/10/2026, **venda** é o
negócio fechado no funil **e** o valor cadastrado no cliente (mensalidade, anuidade,
serviço, taxa; "por demanda" não), na data em que começa; mesmo cliente e mesma data é
uma venda só. Receita fechada, vendas, conversão, ticket médio, a série, a evolução e a
meta do mês saem dessa fonte única (`$VENDAS`), e o card fechado cujo cliente tem valores
sai para não contar duas vezes. **Desde 23/10/2026 a mensalidade entra na receita em
todo mês em que está ativa** (anuidade no aniversário), não só no mês em que começou:
"não tá somando todas as mensalidades" (`CobrancaDoCliente::sqlReceitaDosValores`). Só a
primeira vez conta como venda nova; o ticket médio usa só o valor das vendas novas. O resto: KPIs do período e do
período anterior equivalente (mesma quantidade de dias, terminando na véspera),
distribuição do pipeline pelas flags `conta_funil`/`conta_oportunidade` das
colunas, meta do mês (`goals`), série do período por dia/semana/mês, evolução
(12 meses, 16 semanas, 30 dias), últimos 25 cards movimentados e a lista de
responsáveis. Aceita `start`, `end`, `responsavel` e `origin`; sem período,
últimos 30 dias. Só lê, e toda query filtra `account_id IN (contas acessíveis)`.

`_json_guard.php` começa com `_` de propósito: não é endpoint, é peça incluída
pelos outros.

`whatsapp/sync.php` (o botão Sincronizar, e `php public/api/whatsapp/sync.php <canal>` na
linha de comando) aprende o par `@lid` ↔ telefone de cada mensagem (`key.remoteJidAlt`,
`Identidade::registrar`) antes de montar as conversas, como o webhook já fazia. Sem isso, o
contato que respondeu pelo `@lid` virava uma conversa separada da que mandamos para o
telefone (02/10/2026, 26 de 54 conversas no primeiro sync da Inovaize).

`whatsapp/contacts.php` tem, além de `fetch_pic`, a action `resolve_name`: para
conversa 1:1 sem nome, deduz o nome da conta comercial e grava na identidade
(ver `PerfilComercial` em [`../../app/WhatsAppAgente/README.md`](../../app/WhatsAppAgente/README.md)).
Só aceita `jid` de conversa que já existe no canal resolvido, para não virar
consulta de perfil de número arbitrário.

`whatsapp/media.php` entrega foto, áudio, vídeo e documento de uma mensagem.
O que está guardado pode ser só a miniatura: nesse caso ele busca o arquivo de
verdade na Evolution e grava por cima, e se não conseguir responde **404**
para documento, vídeo e áudio (foto ainda mostra a miniatura). Nunca entregue
o conteúdo da coluna sem passar por `MidiaCache::ehMiniatura`. O
`whatsapp/webhook.php` faz uma segunda tentativa de download depois do 200,
para mídia nova que não baixou nos 3s. Detalhe em
[`../../app/WhatsAppAgente/README.md`](../../app/WhatsAppAgente/README.md).

`marca_arquivo.php?h=<sha1>` entrega o logo ou o ícone da marca de uma conta.
É **sem sessão** de propósito (o logo aparece na tela de login); o endereço é o
hash do conteúdo, não o id da conta, e só sai PNG/JPEG/WebP com `nosniff` e CSP
fechada. `master/marca.php` lê e grava a marca (só super admin, CSRF,
auditoria); `master/create_account.php` aceita `edicao: 'crm'` com a marca.

`avisos_push.php` é o aparelho que recebe avisos (Web Push, 22/10/2026): GET dá a
chave pública VAPID; POST `inscrever` (endereço e chaves do navegador, só de
serviço de push conhecido), `remover` e `testar`. Sempre a própria pessoa.
`avisos_destinatarios.php` é quem recebe o aviso de lead novo (só dono e admin;
usuário de outra conta é descartado). `account_notifications.php?count=1&desde=N`
devolve também `ultimo` e os avisos de tempo real novos (`novos`), que o sino usa
para o "bling"; `desde=-1` é a primeira pergunta (só o ponto de partida). Ver
[`../../app/Notificacoes/README.md`](../../app/Notificacoes/README.md).

`whatsapp/messages.php` marca como lido o aviso de mensagem nova da conversa aberta
(`Aviso::marcarConversaLida`, 23/10/2026): quem está vendo a conversa já viu.

`app_manifest.php` é o manifesto do app instalável (PWA, 22/10/2026): nome, cor
e ícones da marca da conta logada; sem sessão, a marca do domínio
(`ProductHost`), e no domínio do Yuris, o Yuris. Não tem dado de cliente.
`app_icone.php?h=<sha1>&l=<180|192|512>` é o ícone do app feito do ícone da
marca (`App\Core\IconeApp`: quadrado, opaco, no tamanho pedido), com a mesma
regra do `marca_arquivo.php` (sem sessão, endereço pelo hash, cache de um ano);
o convertido fica em `sys_get_temp_dir()/yuris_icone_app`, e formato que o
conversor não lê redireciona para o original.

`crm_termometro.php` lê e grava a regra do termômetro da edição CRM (`App\Prospeccao\Termometro`): `{etapas: {chave: nível}, quente_dias, morno_dias, frio_dias, resposta_esquenta}`; etapa que faltar vem do padrão, nível inválido dá 422. GET para todos da conta; POST só dono/admin, com CSRF; `{padrao: true}` volta ao padrão; conta jurídica recebe 403.

`plataforma.php` é a integração com a plataforma Fleetiflow (`app/Plataforma/`), só na edição CRM. GET `?conexao=1` (a ligação, sem segredo), `?card_id=` (vínculo do lead, resumo da conta e eventos; sem vínculo, sugestões), `&financeiro=1` (mensalidades, recargas, extrato), `?buscar=`, `?planos=1`, `?carteira=1`. POST com CSRF: `conectar`/`desconectar` (dono/admin), `vincular`, `desvincular`, e `modulo`, `plano`, `responder` (admin ou responsável pelo lead, conexão com escopo comercial). Vendedor só nos próprios leads. `plataforma_eventos.php` é máquina a máquina, sem sessão: `?c=<conta>` diz qual segredo conferir e a assinatura HMAC (`X-Fleetiflow-Assinatura`, timestamp de até 10 min) autentica; idempotente pelo id do evento.

`cliente_valores.php` também responde `?quadro=1` (`{clientes, setores}`: o card de cada cliente, `App\Clientes\CardDoCliente`, e as colunas do quadro) e `POST {acao:'setores', cliente_id, setores:[...]}` (as colunas a mais; a principal não muda aqui); o GET do cliente traz `setores` (`{principal, extras}`) e `setores_conta` (as colunas), e há `cobranca_reabrir`. `cliente_valores.php` é o Financeiro da ficha do cliente (`App\Clientes\ValoresDoCliente` e `CobrancaDoCliente`): GET `?cliente_id=` as linhas, o resumo, as cobranças com os totais e os contratos do cliente (para o seletor), mais `tipos`, `formas` e `pode_baixar`; POST com CSRF `salvar`, `remover`, `cobranca_pagar`, `cobranca_desfazer` e `cobranca_cancelar` (estas três confirmam que a cobrança é do cliente e da conta, e vendedor da edição CRM recebe 403); quem abre Clientes; cliente de outra conta volta 400.

`vendedor_em_foco.php` é o seletor "Vendedor" do menu da edição CRM (30/10/2026, `App\Core\VendedorEmFoco`): GET `{pode_escolher, atual, vendedores}`, POST `{vendedor_id}` (null = todos) com `X-CSRF-Token`, só dono/admin. As listagens que leem a escolha: `cards.php` (o funil), `clientes.php`, `tasks.php` (sem `responsavel_id` na URL), `dashboard_comercial.php` (sem `responsavel`), `crm_orcamentos.php` (`vendedor_id` do orçamento), `plataforma.php` (carteira), `relatorios.php` (via `Listagem`) e `whatsapp/chats.php` (lead da conversa; sem lead, o dono dela). Permissão (o 404 do card de outro, o lead criado ser de quem cria) não muda: segue o vendedor logado.

`crm_lead_quadro.php` é o lead em mais de uma etapa e em mais de um setor (edição CRM, `App\Prospeccao\QuadroDoLead`): GET `?quadro=1` (por lead: etapas e setores a mais, orçamentos, contratos, último contato; mais as etapas e os setores da conta), GET `?card_id=` (etapas e setores do lead), POST com CSRF `etapas` e `setores`. Quem abre Prospecção; lead de outra conta volta 400.

`recebimentos_clientes.php` é o painel "Recebimentos de clientes" da tela Finanças (edição CRM): GET `?filtro=abertas|atrasadas|pagas` os números do mês e a lista; POST com CSRF `pagar`, `desfazer`, `cancelar`. Financeiro é de ADM: vendedor da edição CRM recebe 403, conta Yuris 404.

`crm_contratos.php` também aceita `?lista=1&sem_cliente=1` e a ação `vincular_cliente` (`{id, cliente_id}`, 0 desliga, só quando há lead; só quem gerencia o contrato). Desde 20/10/2026: vendedor da edição CRM recebe 403 em tudo (contrato é só da administração); `criar` e `atualizar` exigem `card_id` ou `cliente_id` e aceitam `vendedor_id` (sem ele, o responsável do lead ou do cliente); a ação `vincular_crm` (`{id, vendedor_id, cliente_id, card_id}`) troca vendedor e card em qualquer situação; lista e ficha trazem `vendedor_id` e `vendedor`. `cliente_valores.php` não manda contratos ao vendedor e, quando ele salva uma linha, mantém o contrato que ela já tinha.

`crm_contratos.php` são os contratos da edição CRM (`app/Contratos/`), lado da equipe: GET `?central=1`, `?lista=1` (filtros `status`, `busca`, `cliente_id`, `card_id`), `?id=` a ficha (CPF e telefone mascarados), `?apoio=1`, `?buscar=` (vazio traz todos os clientes e leads da conta, em ordem alfabética; com texto, pelo nome, CPF/CNPJ ou telefone; `tipo=cliente|lead`, `etapa=<coluna do funil>` e `com_nome=1` filtram; a resposta traz as etapas do funil), `?baixar=<uuid>` um arquivo (hash conferido na leitura; 409 se não bate) e `?evidencias=` o pacote JSON (CPF completo só para quem gerencia). POST com CSRF: `criar`, `atualizar`, `anexar` (multipart), `participante_salvar` (`id` é o contrato, `participante_id` o participante), `participante_remover`, `enviar`, `novo_link`, e só para quem gerencia `liberar`, `cancelar`, `reprocessar`, `config_salvar`. `assinatura.php` é a API da página externa `/assinar`, sem login do CRM: só o cookie `ctr_s` de um convite, POST com `X-CSRF-Token` da sessão (`abrir`, `enviar_codigo`, `confirmar`, `identificar`, `assinar`, `recusar`) e GET `?estado=1` e `?documento=1` (este só depois do código). GET nunca assina nem consome convite.

`crm_orcamentos.php` é o orçamento do CRM (`app/Orcamentos/`), só na edição CRM (conta jurídica recebe 403). `?cliente_ref=N` devolve o cliente para começar um orçamento pela ficha dele (aba Propostas). GET lista (sem `status`, os ativos: rejeitado fica de fora), `?card_id=` os do lead, `?id=` a ficha com `para_o_cliente` e `interno` (mais o texto do WhatsApp), `?apoio=1` catálogo, débitos e equipe, `?servicos=1` o catálogo com as taxas, `?buscar=` leads e clientes, `?lead=` um lead. POST com CSRF: `criar`, `atualizar`, `aprovar`, `rejeitar`, `reabrir`, `converter`; e `servico_salvar`, `taxa_adicionar`, `taxa_ajustar`, `taxa_remover`, só dono/admin. Validação volta 422 com a mensagem para a tela.

**Ambientes da Inovaize** (20/10/2026, só a conta com o módulo): `ambientes.php` dá os números das Visões Gerais (`?visao=empresa|software|digital|comercial_areas`, cada uma conferindo `Ambiente::podeEntrar`) e grava a área do serviço do lead (`lead_area`); `digital.php` é a operação de Digital e a ficha do cliente de cada ambiente (`?software_quadro=N`, `acao:'demanda_nova'`, `'demanda_mover'` e `?relatorio_software=N`: o quadro do projeto de Software; `?propostas=N`, `acao:'proposta_mover'` e `?relatorio_propostas=N`: as propostas do Comercial, só com a tela Orçamentos; `?producao=N`: o quadro de entregas da aba Produção; `acao:'entrega_nova'`; `?relatorio=N&de=&ate=`: o relatório de entregas em .xlsx; `?ficha=N`: perfil, postagem, Software, projetos ligados, negociações; `?quadro_clientes=1`: o miolo do card do quadro; `acao:'perfil_salvar'`, `'entrega_link'`, `'projeto_ligar'`; área do cliente marcada na ficha, `acao:'areas'`; entregas e responsáveis do quadro, a aba Entrega do cartão, a operação do cliente, entregas do mês, frequência, campanhas, materiais); `drive.php` é o Google Drive do cliente (conexão, pasta, navegar no Drive para escolher a pasta em `?navegar=`, baixar um arquivo em `?baixar=ID` ou vários em `?zip=1` (em fluxo; erro vira uma página simples, porque é um link aberto pela pessoa), o link e o compartilhamento da pasta (`?compartilhamento=1`, `acao:'link_publico'` só administrador), envio direto do navegador ao Google (`acao:'envio_abrir'` e `'envio_registrar'`), nova pasta (`acao:'nova_pasta'`) e as seções do Drive da ficha (`?secoes=1`, `acao:'secao_criar'`, `'secao_renomear'`, `'secao_remover'`, `'secao_ordem'`; nunca o vendedor), miniatura de cada arquivo em `?cliente=N&miniatura=ID`, listagem com a hora da leitura, busca de pasta, acessos da equipe, envio de arquivo que registra o recebimento do material, arquivo de cartão) e `drive_oauth.php` conecta a conta Google (só administrador, `state` na sessão, volta só para endereço interno). `task_boards.php` filtra por `?area=` e `?cliente=` e aceita `area`, `cliente_id` e `modelo`; `clientes.php` aceita `?area=software|digital`; `cliente_valores.php` grava a área da linha; `projetos.php` grava a área do projeto.

`cards.php`: a lista de uma etapa vai comprimida (gzip, `ob_gzhandler`) quando o navegador aceita; o servidor só comprime HTML, e uma etapa de 749 leads passava de 800 KB em JSON. A compressão é só nesta resposta, de propósito: ligar para todo JSON no servidor seguraria o webhook do WhatsApp, que responde e continua trabalhando. **Clientes do vendedor** (20/10/2026): `clientes.php`, `cliente_valores.php` (ficha e `?quadro=1`), `timeline.php`, `cliente_vinculos.php` e a busca de `crm_contratos.php` mostram ao vendedor da edição CRM só os clientes de que ele é responsável; cliente sem responsável vendedor fica só para dono e admin; o cliente que o vendedor cadastra nasce dele. No Chat, ele só alcança o número de que é dono (`WhatsAppChannelAccessService`). Vendedor da edição CRM (`vendedorCrm()`, 20/10/2026) vê e mexe só nos leads de que é responsável: a lista força `responsavel_user_id` = ele; abrir, editar, arrastar ou excluir lead de outro dá 404 (o mesmo de "não existe"); o lead que ele cria nasce dele. Dono e admin seguem vendo tudo. O `reorder` (arrastar) confere a escrita em lote (`AccountContext::assertCanWriteMany`) e grava só o que mudou (`Card::bulkUpdateOrders`).

`crm_agenda.php` é a agenda da próxima interação com o lead (`App\Prospeccao\AgendaDoLead`), só na edição CRM (conta jurídica recebe 403). GET `?card_id=` devolve os agendamentos do lead, os quadros em que a pessoa pode criar tarefa, a equipe e se há WhatsApp para a mensagem programada (só o número, o destino é resolvido no servidor); GET `?hoje=1` devolve a agenda do dia da pessoa logada. POST com CSRF: `{acao:'criar', card_id, tipo, quando, ...}` (exige escrita no card), `cancelar`, `concluir` e `reenviar` com `{id}`. Erro de validação volta 422 com a mensagem para a tela. Na edição CRM, a listagem de `cards.php` traz em cada card `proxima_interacao` (tipo, hora, título, responsável, atrasada, mensagem programada) ou `null`; falha nessa leitura não derruba a lista.

`crm_especialista.php` lê e grava o especialista padrão da edição CRM (quem vira consultor do card quando a conversa é respondida pelo celular ou WhatsApp Web). GET para todos da conta; POST só dono/admin, com CSRF; conta jurídica recebe 403.

`otif.php` devolve o OTIF das tarefas (JSON, ou planilha com `formato=csv`),
com a mesma regra de visibilidade da tela `desempenho.php`: dono/admin vê a
equipe, os demais só o próprio, e `colaborador` é ignorado para quem não é
admin. Só GET.

## Os três endpoints que não são chamados por tela

`tasks_recurrence_tick.php`, `lgpd_retention_tick.php`,
`whatsapp_health_tick.php` e `push/tick.php` são disparados por **agendamento**,
não por usuário. Se um deles parar, o sintoma aparece longe: tarefa recorrente
que não nasce, lembrete que não chega, publicação que não é buscada. Ao
investigar "sumiu sozinho", confira o cron antes do código.

## O molde de um endpoint

Todo endpoint segue a mesma ordem, e sair dela é onde os bugs aparecem:

1. `require_once` das classes de [`../../app/`](../../app/) que vai usar
2. `session_start()` e checagem de sessão
3. **contexto de conta** (`AccountContext`), antes de qualquer query
4. se o endpoint é jurídico (Processos, Intimações, `aasp/`, `push/`, `juridico_metrics.php`, `advogado_vinculos.php`): `$ctx->assertModuloJuridicoDisponivel()` logo em seguida — bloqueia 403 para conta `produto=fleetiflow`. Ver [`../../app/Core/README.md`](../../app/Core/README.md)
5. validação da entrada
6. a operação
7. resposta por `ApiResponse`, erro por `ErrorReporter`

## Regras

**Nenhuma query antes do `AccountContext`.** A auditoria de isolamento de
28/07/2026 achou exatamente o oposto disso, um endpoint consultando pedido antes
de saber de quem era a sessão. É o pior bug possível aqui, porque não quebra
nada, só entrega dado do escritório errado.

**Webhook que vem de fora valida assinatura ou token.** Vale para
`whatsapp/webhook.php` (header `X-Webhook-Token`) e para qualquer webhook de
gateway. A URL é pública; qualquer um a chama.

**Erro não devolve a mensagem da exceção.** Ela pode conter nome, CPF ou trecho
de query. Use `ErrorReporter`.

**Endpoint novo do Master grava auditoria.** Ver
[`../../app/Master/README.md`](../../app/Master/README.md).
