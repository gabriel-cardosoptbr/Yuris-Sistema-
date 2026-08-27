# public/api/ — os endpoints REST

133 endpoints. Cada arquivo `.php` é um endpoint, e o caminho do arquivo é a
URL: `public/api/whatsapp/send.php` responde em `/api/whatsapp/send.php`.

**Mover um arquivo daqui muda a URL de uma chamada que o front já faz.** Vale a
mesma restrição de [`../README.md`](../README.md).

## Como está organizado

| Local | Qtd | O que é |
|---|---|---|
| raiz de `api/` | 46 | os endpoints dos módulos de Operação e Gestão: cards, clientes, tarefas, processos, usuários, times, DRE, metas, webhooks |
| `master/` | 42 | **tudo do Painel Master**: contas, filiais, planos, pagamentos, cotas, auditoria, LGPD, config de IA, canais de WhatsApp |
| `whatsapp/` | 22 | canal, chat, envio, mídia, e o `webhook.php` que recebe da Evolution |
| `push/` | 11 | monitoramento de publicações: monitores, cotas, permissões, busca, `tick.php` |
| `aasp/` | 4 | integração AASP: configurar, testar, buscar, sincronizar |
| `chat/` | 3 | chat interno entre usuários do escritório |
| `legal/` | 3 | documentos legais, aceite e consentimento |
| `auth/` | 1 | checagem de termos pendentes no login |
| `lgpd/` | 1 | solicitação do titular, aberta ao público |

`_json_guard.php` começa com `_` de propósito: não é endpoint, é peça incluída
pelos outros.

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
4. validação da entrada
5. a operação
6. resposta por `ApiResponse`, erro por `ErrorReporter`

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
