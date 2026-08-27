# Webhooks/ — os webhooks de saída do Yuris

Tela: **Automações › Webhooks** (`public/webhooks.php`, `public/api/webhooks.php`).

Aqui é o Yuris **avisando** sistemas do cliente que algo aconteceu: card mudou
de coluna, processo teve movimentação, tarefa foi concluída. O cliente cadastra
uma URL, escolhe os eventos, e passa a receber.

**Sentido único, e é fácil confundir:** este módulo só *envia*. O webhook que o
Yuris *recebe*, da Evolution API, é outro assunto e vive em
[`../WhatsAppAgente/`](../WhatsAppAgente/).

Documentação de contrato para o cliente:
[`../../docs/integracao/DOCUMENTACAO_WEBHOOKS_YURIS.md`](../../docs/integracao/DOCUMENTACAO_WEBHOOKS_YURIS.md).

## Arquivos

| Classe | O que faz |
|---|---|
| `WebhookDispatcher.php` | dispara um evento para todos os webhooks inscritos nele. 13 métodos |
| `WebhookPayloadBuilder.php` | monta o envelope v2 do payload |
| `WebhookRetryPolicy.php` | backoff exponencial: 60s, 300s, 1800s. O argumento é a tentativa que **acabou de falhar** |

Quem realmente entrega é o worker: [`../../bin/webhook_worker.php`](../../bin/webhook_worker.php).

## O envio é assíncrono, e tem que continuar sendo

O dispatcher **enfileira**, não entrega. Se a entrega fosse síncrona, um cliente
com endpoint lento ou fora do ar seguraria a requisição do usuário dentro do
Yuris. O worker é quem tenta, falha e reagenda.

## Regras

**URL cadastrada pelo cliente passa pelo `../Core/WebhookUrlValidator.php`.**
Sem isso, o cliente aponta o webhook para `localhost` ou para um IP interno e
transforma o Yuris em ferramenta de varredura da rede do servidor (SSRF). Essa
validação não é opcional.

**Payload é mascarado antes de sair.** Dado pessoal passa por
`../Lgpd/PayloadMasker.php`. O webhook sai do perímetro do Yuris, então o que
vai nele é o que o cliente pode receber.

**Versione o envelope.** Ele está na v2. Cliente já integrado depende do formato
atual: mudança de campo é v3, não edição da v2.

**Retry é limitado, e o fim é registrado.** Falha permanente precisa aparecer
para o cliente na tela, senão ele acha que recebeu.
