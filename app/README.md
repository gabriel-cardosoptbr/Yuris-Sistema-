# app/ — o código de domínio do Yuris

Aqui mora toda a regra de negócio. Nada nesta pasta é acessível por URL: o
Apache serve `public/`, e as páginas e endpoints de lá é que carregam estas
classes.

## A regra desta pasta: uma pasta por assunto, não por tipo

Até 2026-08-27 o `app/` era dividido por **tipo técnico**: `Models/`,
`Helpers/`, `Services/`, `Controllers/`. O efeito prático era que um único
assunto ficava espalhado em três pastas. Quem fosse mexer em Tarefas precisava
abrir `Models/Task.php`, `Helpers/TaskAudit.php` e
`Services/RecurrenceCronService.php`, em diretórios diferentes, sem nada
indicando que os três andam juntos.

Hoje a divisão é por **assunto**, e cada pasta guarda o model, o service e o
helper daquele assunto lado a lado. O nome da pasta é o nome que o módulo tem
no menu do sistema, para que quem procura o código de uma tela procure pela
palavra que está na tela.

## Os domínios

| Pasta | Onde aparece no sistema | O que guarda |
|---|---|---|
| [`Core/`](Core/) | nenhum, é infraestrutura | conexão com o banco, contexto de tenant, criptografia, resposta JSON, .env, e-mail |
| [`Usuarios/`](Usuarios/) | Gestão › Usuários, tela de login | login, sessão, 2FA, convites, vínculos de advogado, times, consentimento |
| [`Master/`](Master/) | Painel Master, Gestão › Escritórios | conta (tenant), notificações, incidentes de segurança, auditoria do master |
| [`Prospeccao/`](Prospeccao/) | Operação › Prospecção | funil de vendas: cards, colunas do kanban, contatos |
| [`Clientes/`](Clientes/) | Operação › Clientes | base de clientes do escritório, origens, kanban operacional |
| [`Processos/`](Processos/) | Jurídico › Processos e Intimações | processos, histórico, AASP/DJEN e o motor de monitoramento (`Monitor/`) |
| [`Tarefas/`](Tarefas/) | Operação › Tarefas | tarefas, quadros, checklists, apontamento de horas, recorrência |
| [`Financas/`](Financas/) | Gestão › Finanças | plano de contas do DRE |
| [`WhatsAppAgente/`](WhatsAppAgente/) | Comunicação › WhatsApp, Automações › Agente | canal Evolution, webhook de entrada e o agente de IA (`AiIntake/`) |
| [`Webhooks/`](Webhooks/) | Automações › Webhooks | webhooks de **saída** do Yuris para sistemas do cliente |
| [`Billing/`](Billing/) | página Planos, e travas espalhadas pelo sistema | limites e módulos por plano, gateway de pagamento (`Gateway/`) |
| [`Lgpd/`](Lgpd/) | LGPD, DPO, Termos, Privacidade | solicitações do titular, anonimização, mascaramento de PII, documentos legais |

## Como o código é carregado (leia antes de mover qualquer arquivo)

**O projeto não tem autoloader.** Não existe Composer, nem
`spl_autoload_register`. Toda classe é carregada por um `require_once` com o
caminho escrito à mão:

```php
require_once __DIR__ . '/../../app/Processos/Processo.php';
use App\Processos\Processo;
```

Isso tem uma consequência direta: **mover um arquivo daqui quebra todo mundo
que o carrega**, e o erro só aparece em runtime, na hora em que a página roda.
Hoje são 1.108 linhas de `require` espalhadas por 192 arquivos.

Se precisar mover ou renomear algo em `app/`, **quatro** coisas têm que mudar
juntas. As duas últimas são as que passam despercebidas:

1. o caminho em todo `require_once` que aponta para o arquivo;
2. a declaração `namespace` dentro do arquivo, e todo `use App\...` que o importa;
3. os nomes de classe **escritos dentro de string**, que nenhuma busca por
   `use` encontra:
   ```php
   if (!class_exists('App\\Core\\RequestId')) { ... }
   method_exists('App\\WhatsAppAgente\\WhatsAppWebhookParser', 'extractQuotedWamid')
   ```
   Existem cerca de 50 desses. Quando erram, não quebram nada, só retornam
   `false` em silêncio e o comportamento muda sem aviso.
4. os **nomes curtos que dependiam do mesmo namespace**. Duas classes no mesmo
   namespace se enxergam sem `use`:
   ```php
   namespace App\Tarefas;
   $pdo = Database::getConnection();   // resolve App\Tarefas\Database
   ```
   Se `Database` sai para outro namespace e o `use` não é adicionado, isso passa
   a apontar para uma classe inexistente. **`php -l` não pega, carregar o arquivo
   não pega** (type hint só resolve na chamada), e a página só quebra quando
   aquela linha específica executa. Foi o que aconteceu em 27/08/2026: 28
   referências assim, e duas telas (Intimações e Escritórios) fatalavam.

Ao dividir um namespace, procure o que era irmão antes e virou estrangeiro
depois. Só uma varredura autenticada, que executa as páginas de verdade, prova
que não sobrou nenhum.

Depois de mexer, o mínimo a rodar está em [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md).

## Duas inconsistências conhecidas, de propósito

**Cinco classes de WhatsApp não declaram namespace** e vivem no namespace
global: `WhatsAppInstance`, `WhatsAppMessage`, `EvolutionApiService`,
`WhatsAppChannelAccessService` e `WhatsAppProvisioningService`. Elas se chamam
`\EvolutionApiService`, não `\App\WhatsAppAgente\EvolutionApiService`. Dar
namespace a elas é mudança de comportamento, com risco próprio, e ficou para um
passo separado. Já causou três bugs em produção (corrigidos no commit
`f7d5ca8`), então **ao chamar uma delas, confira se a classe é global**.

**`Contato` está em `Prospeccao/`** mas é usado também por Processos e pelo
handoff do agente de IA. Ficou onde nasceu; se um dia virar entidade
compartilhada de verdade, o lugar dela é `Core/`.

## Ao criar arquivo novo

Pergunte **de que assunto ele é**, não de que tipo ele é. Um service novo de
Tarefas vai em `Tarefas/`, não numa pasta `Services/`. Se o assunto ainda não
existe como pasta, criar uma pasta nova é aceitável, desde que ela ganhe o seu
`README.md` e entre na tabela acima.

Se o arquivo serve a três ou mais domínios sem pertencer a nenhum, ele é
infraestrutura e vai para `Core/`.
