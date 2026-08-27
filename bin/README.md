# bin/ — processos de fundo

Diferente de [`../scripts/`](../scripts/), que alguém roda à mão, o que está
aqui é **chamado por agendamento**, sem ninguém olhando.

## Arquivos

| Arquivo | O que faz |
|---|---|
| `webhook_worker.php` | consome a fila `webhook_deliveries` e entrega os webhooks de saída |

## Como o worker funciona

Não é laço infinito: **roda um lote e sai**. Quem o traz de volta é o
agendador, a cada minuto.

- Linux: `* * * * * /usr/bin/php /var/www/sistema_vendas/bin/webhook_worker.php`
- Windows: Task Scheduler apontando para `C:\xampp\php\php.exe` com o caminho do
  script, repetindo a cada 1 minuto

Variáveis de ambiente: `WEBHOOK_WORKER_BATCH` (linhas por execução, padrão 50) e
`WEBHOOK_WORKER_LOG` (1 imprime resumo).

O trecho que evita entrega dupla: a linha é marcada como `retrying` **antes** do
POST. Se dois workers correrem juntos, o segundo não pega a mesma linha. Ao
mexer aqui, preserve essa ordem, ou o cliente recebe o mesmo evento duas vezes.

## Ao criar processo de fundo novo

O molde do `webhook_worker` é o certo, e vale copiar:

- **um lote e sai**, nunca laço infinito, que não sobrevive a deploy e vaza memória
- **trava contra execução concorrente**, porque o agendador atrasa e sobrepõe
- **idempotente**: rodar duas vezes na mesma janela não pode duplicar efeito
- **falha registrada**: ninguém está olhando o terminal quando quebra

Processo de fundo que falha em silêncio é o mais caro de descobrir: o sintoma
aparece dias depois, do lado do cliente.
