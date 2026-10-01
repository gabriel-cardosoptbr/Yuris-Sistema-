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
| `auth/` | 1 | checagem de termos pendentes no login |
| `lgpd/` | 1 | solicitação do titular, aberta ao público |

`dashboard_comercial.php` alimenta o cockpit comercial da conta Fleetiflow
(`includes/dashboard_fleetiflow.php`) numa chamada só: KPIs do período e do
período anterior equivalente (mesma quantidade de dias, terminando na véspera),
distribuição do pipeline pelas flags `conta_funil`/`conta_oportunidade` das
colunas, meta do mês (`goals`), série do período por dia/semana/mês, evolução
(12 meses, 16 semanas, 30 dias), últimos 25 cards movimentados e a lista de
responsáveis. Aceita `start`, `end`, `responsavel` e `origin`; sem período,
últimos 30 dias. Só lê, e toda query filtra `account_id IN (contas acessíveis)`.

`_json_guard.php` começa com `_` de propósito: não é endpoint, é peça incluída
pelos outros.

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

`crm_termometro.php` lê e grava a regra do termômetro da edição CRM (`App\Prospeccao\Termometro`): `{etapas: {chave: nível}, quente_dias, morno_dias, frio_dias, resposta_esquenta}`; etapa que faltar vem do padrão, nível inválido dá 422. GET para todos da conta; POST só dono/admin, com CSRF; `{padrao: true}` volta ao padrão; conta jurídica recebe 403.

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
