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

## Como o código é carregado

**Existe um autoloader**, em [`bootstrap.php`](bootstrap.php). Não é Composer:
são 20 linhas de `spl_autoload_register` que mapeiam namespace para pasta.

Quem precisa de uma classe carrega o bootstrap **uma vez** e usa:

```php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Processos\Processo;

$lista = Processo::list([...]);      // Processo.php carrega sozinho
```

```
App\Processos\Processo          ->  app/Processos/Processo.php
App\WhatsAppAgente\AiIntake\X   ->  app/WhatsAppAgente/AiIntake/X.php
```

**O namespace espelha a pasta, sem exceção.** Se você criar uma pasta nova,
o namespace tem que acompanhar, ou a classe não é encontrada.

### O que isso mudou, e por que importa

Até 27/08/2026 não havia autoloader: cada página carregava classe por classe,
com o caminho escrito à mão. Eram **1.077 linhas de `require_once` em 192
arquivos**, e mover um arquivo daqui quebrava todo mundo que o carregava, com o
erro aparecendo só em runtime.

Hoje, **mover um arquivo dentro de `app/` não exige caçar quem o carrega.**
Continua exigindo três coisas:

1. a declaração `namespace` do arquivo tem que continuar espelhando a pasta;
2. todo `use App\...` que o importa;
3. os nomes de classe **escritos dentro de string**, que nenhuma busca por
   `use` encontra:
   ```php
   if (!class_exists('App\\Core\\RequestId')) { ... }
   method_exists('App\\WhatsAppAgente\\WhatsAppWebhookParser', 'extractQuotedWamid')
   ```

E um cuidado que sobrevive ao autoloader: **nome curto que depende do mesmo
namespace**. Duas classes no mesmo namespace se enxergam sem `use`:

```php
namespace App\Tarefas;
$pdo = Database::getConnection();   // resolve App\Tarefas\Database
```

Se `Database` sai para outro namespace e o `use` não é adicionado, isso aponta
para uma classe inexistente. `php -l` não pega, e a página só quebra quando
aquela linha executa. Foi o que aconteceu em 27/08/2026: 28 referências assim,
com duas telas fatalando.

**Quem pega tudo isso é `../scripts/tests/class_refs_test.php`**, que confere
estaticamente se toda referência a classe do projeto resolve, inclusive em
caminho que nenhuma requisição executa. Rode-o ao mexer em namespace ou mover
arquivo.

### Carregar um arquivo de classe isolado não funciona mais

Antes, `require 'app/Billing/PlanFeature.php'` funcionava sozinho, porque o
próprio arquivo puxava suas dependências. Isso acabou: as dependências agora
vêm do autoloader. Em script, cron ou `php -r`, carregue **o bootstrap**:

```php
require '/var/www/yuris/app/bootstrap.php';
```

### O fuso horário mora no bootstrap

`date_default_timezone_set('UTC')` ficava no topo de `Core/Database.php` e valia
porque todo mundo carregava aquele arquivo de cara. Com autoloader, `Database.php`
só carrega no **primeiro uso** da classe, e um `date()` antes disso pegaria o
fuso do php.ini: timestamp errado, sem erro nenhum. Por isso o fuso passou para
o bootstrap, que roda primeiro. (Segue também em `Database.php`, como rede.)

Depois de mexer, o mínimo a rodar está em [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md).

## Uma inconsistência conhecida, de propósito

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
