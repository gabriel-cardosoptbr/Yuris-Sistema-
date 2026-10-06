# WhatsAppAgente/ — o canal WhatsApp e o agente de IA que roda sobre ele

Telas: **Comunicação › WhatsApp** (`public/chat.php`) e **Automações › Agente**
(`public/agente.php`).

## Por que WhatsApp e Agente estão na mesma pasta

No menu são dois itens. No código são uma coisa só, e isso é a premissa mais
importante deste módulo:

> **O agente é uma camada sobre a conexão de WhatsApp que já existe. Ele nunca
> cria uma segunda conexão.**

Para cada conta existe **uma única instância** da Evolution API, usada ao mesmo
tempo pelo chat humano e pelo agente. Um segundo QR Code, um segundo token, uma
segunda instância "do bot" ou um webhook paralelo quebram o módulo inteiro.

O agente guarda apenas uma **referência ao canal autorizado**
(`agent_configs.whatsapp_instance_id` → `whatsapp_instances.id`). As
credenciais continuam centralizadas em `whatsapp_settings`, por conta.

Separar as duas coisas em pastas diferentes deixaria essa relação invisível,
que é justamente como o erro acontece. Por isso ficam juntas.

O detalhe completo, com diagramas, está na skill de desenvolvimento em
`../../.claude/skills/yuris-legal-intake-optimizer/references/evolution-single-instance-architecture.md`.

## Arquivos

### O canal
| Classe | O que faz |
|---|---|
| `WhatsAppInstance.php` | a instância da Evolution ligada à conta (um **número**): status, dono, nome. `cfgDoCanal()`/`aplicarCanal()` montam a config da Evolution **de um número** (chave e nome da instância dele), para conta com vários números |
| `WhatsAppMessage.php` | mensagens do chat, gravadas por `wamid`. **Setor da conversa e setor do lead andam juntos** (`sincronizarSetorComCard()`, migration 140, 05/10/2026): `setTeam()` (o botão Setor do Chat) passa o setor, ou a falta dele, para o card ligado; `linkChat()` com card e sem setor faz um completar o outro, sem trocar setor já escolhido. É isso que cobre o robô marcar o setor no card antes de a conversa existir. Setor só atravessa dentro da mesma conta |
| `EvolutionApiService.php` | a camada de integração com a Evolution: `sendText`, `sendMedia`, `sendAudio`, webhook. **Todo envio passa por aqui**, 30 métodos |
| `WhatsAppProvisioningService.php` | provisionamento idempotente do canal ao conectar, inclusive geração do `webhook_token`. `adicionarNumero()` cria mais um número numa conta que já tem o primeiro (teto `MAX_NUMEROS_POR_CONTA`). `vincularExistente()` liga como primeiro número uma instância que **já existe** na Evolution (QR lido fora do sistema): é o `provision()` sem o `createInstance`, e recusa sem mexer em nada se a conta já tem número, se a chave ou o nome são de outra conta, ou se a instância já manda eventos para outro endereço (o webhook é um só por instância; sobrescrever cortaria quem recebe hoje). Simula por padrão; usado por `scripts/manutencao/vincular_whatsapp.php` |
| `WhatsAppChannelAccessService.php` | **camada única de autorização de canal.** Resolve se a sessão pode usar aquele canal, incluindo o caso filial usando canal da matriz |
| `WaLog.php` | log de uma linha em JSON, com chaves padronizadas, para todo o módulo |
| `PerfilComercial.php` | o nome de conta **comercial** do WhatsApp. Conta Business manda `pushName` vazio e a conversa aparecia só com o telefone; aqui o nome é **deduzido** do perfil comercial que a Evolution entrega (descrição "X é uma...", domínio do site) e gravado em `Identidade` com a origem `perfil_comercial`, que pesa **menos que o pushName**: qualquer nome real o substitui, e na dúvida fica null (a tela segue mostrando o telefone). Uma consulta à Evolution a cada 30 dias por contato, registrada em `whatsapp_identidades.perfil_comercial_em` (migration 132). Disparado pela lista do Chat (`contacts.php`, action `resolve_name`), nunca pelo webhook |

### A mídia
| Classe | O que faz |
|---|---|
| `MidiaCache.php` | as regras do que pode morar em `whatsapp_messages.media_base64`. Diz se o que está guardado é o **arquivo** ou só a **miniatura** (`ehMiniatura`), quais conteúdos podem ser guardados (`conteudoReconhecido`, `podeGuardar`), qual é o teto real do banco (`limiteDoBanco`), abre os envelopes de mensagem temporária e de visualização única (`desembrulhar`) e faz a segunda tentativa de download depois do 200 do webhook (`completar`). Tudo puro, menos `limiteDoBanco` e `completar` |

### O agente de pré-venda da edição CRM
| Classe | O que faz |
|---|---|
| `SdrFleetiflow.php` | o funil comercial automático (card em "Novos leads", etapas, pendentes) vale para **toda** conta da edição CRM. O encaminhamento ao agente de IA não: `urlDaConta()` devolve o endereço do `.env` (a Vitória) só para a conta sem marca própria, e o endereço gravado na marca para as outras, ou vazio (agente desligado, o botão de ligar recusa com aviso). `nomeAgenteDaConta()` dá o nome que aparece no Chat. **O encaminhamento leva o número** (`instance`, o nome da instância na Evolution, desde 05/10/2026): com dois números na conta, a Vitória responde pelo mesmo número em que o lead escreveu. **Os endpoints chamados pelo n8n da Vitória** (`api/whatsapp/sdr_etapa.php` e `sdr_transferencia.php`, token `FLEETIFLOW_SDR_WEBHOOK_TOKEN`; o `sdr_etapa` aceita `setor` desde 05/10/2026, que cria o setor pelo nome e marca card e conversa que ainda não têm setor) só agem na conta dona desse token, a Fleetiflow original: `contaDoTokenGlobal()` (desde 02/10/2026; antes aceitavam qualquer conta CRM, e com a Inovaize de número aberto o "card antes da conversa" passou a dar 404 e um telefone presente nas duas contas podia mexer no card da outra). **O lead da automação é do vendedor dono do número** (`donoDoNumero()`, `whatsapp_instances.responsavel_user_id`, migration 137, desde 02/10/2026): `garantirCard()` cria o card com esse responsável e dá o dono ao card antigo que ainda não tinha responsável; responsável já escolhido não muda. Sem número (robô avisou antes da conversa), vale o dono quando a conta tem um só. A descrição do lead deixou de citar o nome do produto. **Quem digitou a mensagem própria** sai de `origemEfetiva()`: o `source` da Evolution é "web" tanto para envio pela API quanto para o WhatsApp Web, então o que vale é o evento (na 2.3.7, `emitOwnEvents: false` faz a API disparar só `send.message`; `messages.upsert` com fromMe é alguém digitando). Mensagem de pessoa leva o card para "Em atendimento pelo especialista" e pausa a IA na conversa; envio do robô ou da Vitória não. O webhook guarda `$eventoOriginal` antes de converter `send.message` em `send_message`. **Card em atendimento ganha consultor** (`atribuirEspecialista`), só se ainda não tiver: quem respondeu pelo Chat do CRM, senão o responsável marcado na conversa, senão o **especialista padrão** da conta (`configuracoes.sdr.especialista_padrao`, escolhido na Prospecção por dono/admin), porque pelo celular ou WhatsApp Web não há como saber quem digitou. **Números de treino** (`NUMEROS_TREINO` + `FLEETIFLOW_SDR_NUMEROS_TREINO`, desde 06/10/2026, mesmo esquema do SDR Schumaher): a conversa nunca pausa nem vai para o especialista, e o `WhatsAppAgentBridge` encaminha para a Vitória mesmo com a conversa em "Com você" (e tira a pausa que tiver ficado). Antes disso o modo treino do n8n existia mas nunca recebia a mensagem: a conversa do testador ficava pausada e o CRM parava antes de encaminhar. A lista do n8n fica no nó "Configuração (editar aqui)" da Vitória; as duas têm de bater |

### O webhook de entrada
| Classe | O que faz |
|---|---|
| `WhatsAppWebhookAuth.php` | valida o segundo fator do webhook (header `X-Webhook-Token`), em modo compatível: enquanto a Evolution não manda, a entrega segue |
| `WhatsAppWebhookParser.php` | parsers **puros** do payload da Evolution. Sem efeito colateral, por isso é o mais fácil de testar |
| `WhatsAppWebhookEntitySync.php` | persistência das entidades do webhook: contatos, conversas, grupos, participantes |
| `WhatsAppAgentBridge.php` | o caminho do agente dentro do webhook: enfileira resposta, detecta envio humano, devolve o 200 rápido antes de processar |

### O agente
[`AiIntake/`](AiIntake/) — o motor de pré-atendimento jurídico.

## Todas as classes daqui têm namespace (desde 27/08/2026)

Cinco delas viviam no namespace global por herança: `WhatsAppInstance`,
`WhatsAppMessage`, `EvolutionApiService`, `WhatsAppChannelAccessService` e
`WhatsAppProvisioningService`. Chamavam-se `\EvolutionApiService`, e essa
exceção já tinha causado **três bugs em produção** (lembrete recorrente que
nunca enviava, reagir e apagar mensagem dando 500, corrigidos em `f7d5ca8`).

Hoje todas são `App\WhatsAppAgente\*`, como o resto do projeto. Se você
encontrar `\EvolutionApiService` em código antigo, script solto ou runbook
fora do repositório, está desatualizado.

Detalhe que mordeu na conversão: esses arquivos eram globais, então `PDO` neles
resolvia para a classe nativa. Com namespace, `PDO` passou a significar
`App\WhatsAppAgente\PDO`. Por isso `WhatsAppInstance` e `WhatsAppMessage`
ganharam `use PDO;`. Vale para qualquer classe nativa (`Exception`,
`DateTime`...) ao dar namespace a um arquivo que não tinha.

## O QR só aparece se o canal estiver provisionado

O QR vem da Evolution, e a Evolution só responde com as credenciais da conta em
`whatsapp_settings` (`evolution_base_url`, `evolution_api_key`,
`evolution_instance`). **Conta sem essas credenciais não tem como gerar QR.**

Isso mordeu em 04/09/2026: uma conta sem `whatsapp_settings` clicava em conectar
e não aparecia nada, sem mensagem. O `EvolutionApiService` caía nos defaults do
construtor (`http://localhost:8080` e chave vazia), a chamada morria, o QR
voltava string vazia e o endpoint respondia `{ok:true, qr:""}`. A tela recebia
**sucesso** e desenhava um vazio.

Hoje `public/api/whatsapp/instances.php?action=qr` responde:

| Situação | Resposta |
|---|---|
| sem `base_url` ou `api_key` | **409**, `code: canal_nao_configurado`, dizendo o que falta |
| Evolution respondeu sem QR, canal `open` | `ok:true` + `conectado:true` ("já está conectado") |
| Evolution respondeu sem QR, outro estado | **502**, `code: qr_vazio`, com o estado do canal |

**Quem provisiona é o Painel Master**, em Configurações de WhatsApp. Travado em
`../../scripts/tests/wa_invariants.php`.

### O nome da instância no bootstrap é único por conta

Quando uma conta ainda não tem `evolution_instance`, o
`WhatsAppChannelAccessService` cria um canal com nome de fallback. Esse fallback
**era o literal `yuris-crm`**, então toda conta não provisionada nascia com o
mesmo nome, e em 04/09/2026 duas contas diferentes tinham um canal `yuris-crm`.

Sem credencial isso é inofensivo (nenhuma fala com a Evolution), mas no dia em
que fossem configuradas contra a mesma Evolution passariam a dividir o **mesmo
WhatsApp**: vazamento entre escritórios, da mesma família do bug B1 dos
contatos. Hoje o fallback é `yuris-conta-{accountId}`.

## A mídia: arquivo, miniatura e prazo

`media_base64` guardava duas coisas sem distinção: o arquivo de verdade ou a
miniatura (`jpegThumbnail`) que o webhook e o sync gravam como quebra-galho
quando o download não termina a tempo. O `media.php` entregava o que estivesse
lá. Resultado, medido em 30/09/2026: documento baixado como "PDF" que era um
JPEG de 1 KB (não abria), vídeo que nunca tocava e foto borrada para sempre,
porque o arquivo inteiro nunca mais era buscado.

O caminho de hoje:

1. **Na chegada (webhook):** tenta o arquivo em 3s, antes do 200. Se não deu,
   grava a miniatura e agenda a **segunda tentativa** (`MidiaCache::completar`,
   15s de prazo), que roda depois do 200 e depois do agente. Só para mensagem
   NOVA e das últimas 24h: reenvio de histórico numa reconexão não agenda nada. No máximo 3 por
   requisição.
2. **Na abertura (`media.php`):** se o cache é a miniatura, ela sai do caminho e
   o arquivo é buscado na Evolution e gravado por cima. Se a busca falha, FOTO
   ainda mostra a miniatura (sem cache no navegador, para tentar de novo);
   documento, vídeo e áudio respondem 404, e a tela diz "indisponível" em vez
   de entregar arquivo com o nome certo e o conteúdo errado.
3. **O que pode ser guardado:** arquivo reconhecido pelos primeiros bytes
   (imagem, OGG, MP3, AAC, PDF, Office, MP4, WebM, ZIP...) ou texto declarado
   como texto, até o menor entre 15 MB de base64 (a coluna é `MEDIUMTEXT`) e o
   `max_allowed_packet` do servidor. Antes só imagem, OGG e MP3 até 4 MB.

**Mídia não pode derrubar a mensagem.** `WhatsAppMessage::save()` tira o
binário que não cabe antes de gravar, e se o banco recusar mesmo assim, grava a
mensagem sem ele. Antes o INSERT falhava e a mensagem inteira era pulada.

**O prazo é política, não defeito.** A retenção LGPD
(`public/api/lgpd_retention_tick.php`, migrations 051 e 108) apaga o
`raw_payload` aos **30 dias** e o `media_base64` aos **90 dias**. O texto da
conversa fica para sempre; foto, áudio e documento com mais de 90 dias mostram
"expirado". Mídia que não foi guardada nos primeiros 30 dias não tem mais como
ser buscada, porque a chave de decifrar estava no payload. Por isso guardar na
chegada importa: depois não há segunda chance. Mudar esses prazos é decisão de
produto e de LGPD, em `retention_policies`, não de código.

Travado em `../../scripts/tests/wa_midia_test.php`.

## Canal caído: o aviso nas telas da edição CRM

Em 30/09/2026 o canal da Fleetiflow caiu (Evolution, código 401: o aparelho
desconectou o WhatsApp) e ninguém percebeu por horas: nenhuma mensagem entrava,
o funil parou e o robô recebia 404 ao atualizar etapa. Desde então o menu
lateral (`public/includes/sidebar.php`) mostra no topo de toda tela da edição CRM,
menos o Chat (que tem o próprio aviso) e o Chat Interno (conversa da equipe, nada
a ver com o canal; lá o aviso ainda tomava a tela inteira por causa do layout em
colunas), um aviso quando o canal configurado da conta não está `open`, com a hora do
último evento recebido e, para dono/admin, o botão para reconectar no Chat.
Canal sem credencial não alarma. Reconectar é sempre ler o QR de novo.

`scripts/manutencao/especialista_retroativo.php` move para "Em atendimento pelo
especialista" os cards de conversas que uma pessoa já respondeu (mensagem própria
sem status `PENDING` no payload). Simulação por padrão; `--aplicar` move. Depois
de reconectar um canal que ficou fora, rode-o: o que a pessoa respondeu pelo
celular no período chega na sincronização e entra no critério.

## Regras que derrubam o módulo se ignoradas

**Uma instância da Evolution por NÚMERO; a conta pode ter vários números**
(desde 01/10/2026; antes era "uma instância por conta, sempre"). Como funciona:

- O **primeiro** número continua como sempre: nome e chave em `whatsapp_settings`
  (`evolution_instance`, `evolution_api_key`). Essa chave é a de **roteamento**:
  é por ela que o `webhook.php` identifica a conta.
- Cada número **adicional** guarda a própria chave em
  `whatsapp_instances.evolution_token` e tem o webhook com `?token=<chave da
  CONTA>`. O evento chega na conta certa, e o `instance` do payload diz o número.
  `findAccountByApiKey` também aceita a chave do número, para a Evolution que só
  manda o header.
- **Quem fala com a Evolution sobre um número usa a config DO NÚMERO**:
  `resolveForRequest` já devolve `cfg` assim; fora dele, `cfgDoCanal($instanceId)`.
  Nunca `getSettings($conta)['evolution_instance']` para responder ou baixar
  mídia: com dois números, sai pelo número errado.
- O número **padrão** (quem não manda `channel_id`) é o conectado mais novo
  (`ownChannelId`), não só o mais novo.
- O chat da edição Fleetiflow mostra uma aba por número (`public/assets/chat-numeros.js`)
  e o `chat.js` manda `channel_id` em toda chamada do pacote.

**O webhook responde 200 antes de processar.** `flushResponse()` vem antes de
`runAgentReply()`. Se inverter, a Evolution considera falha e reenvia.

**Idempotência por `wamid`.** O par `(instance_id, wamid)` é UNIQUE. Evento
repetido não pode virar mensagem repetida nem resposta repetida do bot.

**`fromMe` não aciona o agente.** Sem isso o bot responde a si mesmo, em laço.

**Mensagem manual do advogado pausa o bot** naquela conversa
(`whatsapp_chats.agent_paused`). O humano assume e o robô cala.
