# bin/ — processos de fundo

Diferente de [`../scripts/`](../scripts/), que alguém roda à mão, o que está
aqui é **chamado por agendamento**, sem ninguém olhando.

## Arquivos

| Arquivo | O que faz |
|---|---|
| `webhook_worker.php` | consome a fila `webhook_deliveries` e entrega os webhooks de saída |
| `agenda_crm_worker.php` | agenda do lead da edição CRM (`App\Prospeccao\AgendaDoLead`): avisa no sino antes da hora e manda a mensagem programada pelo WhatsApp. A cada minuto: `* * * * * docker exec yuris_app php /var/www/html/bin/agenda_crm_worker.php`. Trava por `GET_LOCK`; a linha vira `enviando` antes do envio, então dois lotes juntos não mandam a mesma mensagem. `AGENDA_CRM_LOG=1` imprime o resumo |
| `cobrancas_clientes_worker.php` | cobranças dos clientes da edição CRM (`App\Clientes\CobrancaDoCliente`): gera as que faltam dos valores ativos (mensalidades e anuidades): as vencidas e a próxima a vencer, nunca além do fim do mês seguinte. Uma vez por dia basta, a geração é idempotente: `10 6 * * * docker exec -u www-data yuris_app php /var/www/html/bin/cobrancas_clientes_worker.php`. Trava por `GET_LOCK`. `COBRANCAS_LOG=1` imprime o resumo |
| `drive_sync.php` | Google Drive dos clientes (`App\Drive\Sincronizacao`): lê as mudanças do Drive desde o último marcador e relê só as pastas de cliente tocadas (e as recém-ligadas). A cada 5 minutos: `*/5 * * * * docker exec yuris_app php /var/www/html/bin/drive_sync.php`. Sem `GOOGLE_DRIVE_CLIENT_ID`, sai na hora. Trava por `GET_LOCK`. `DRIVE_LOG=1` imprime o resumo |
| `contratos_worker.php` | contratos da edição CRM (`App\Contratos`): expira os de prazo vencido e refaz a geração dos documentos finais que falhou (espera crescente, até 12 tentativas); apaga sessões da página de assinatura vencidas há 7 dias que nunca confirmaram o código. A cada minuto: `* * * * * docker exec yuris_app php /var/www/html/bin/contratos_worker.php`. Trava por `GET_LOCK`. `CONTRATOS_LOG=1` imprime o resumo |

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
