# Arquitetura do Yuris

Monolito PHP 8.2, sem framework, sem Composer, sem npm e **sem etapa de
build**. O que está no repositório é literalmente o que roda no servidor: o
deploy em produção é um `git pull` dentro do container.

Isso é uma escolha, não um atraso, e tem consequências que valem entender antes
de propor mudança estrutural.

## O caminho de uma requisição

```
navegador
   |
Apache (DocumentRoot = public/)
   |
public/<pagina>.php            página renderizada no servidor
   |  fetch/ajax
public/api/<endpoint>.php      resposta JSON
   |  require_once
app/<Dominio>/<Classe>.php     regra de negócio
   |  PDO
MySQL
```

Não existe roteador. **O caminho do arquivo em `public/` é a URL.**

Existe autoloader (`app/bootstrap.php`), então um ponto de entrada faz **um só**
require e usa as classes direto:

```php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Processos\Processo;
```

O autoloader mapeia namespace para pasta (`App\Processos\Processo` →
`app/Processos/Processo.php`). Até 27/08/2026 não havia autoloader: eram 1.077
`require_once` com caminho escrito à mão, em 192 arquivos.

## Onde fica cada coisa

| Pasta | Papel | README |
|---|---|---|
| `app/` | regra de negócio, organizada **por assunto** | [`../app/README.md`](../app/README.md) |
| `public/` | tudo que responde por URL: páginas, API, assets | [`../public/README.md`](../public/README.md) |
| `database/` | schema, 124 migrations, seeds | [`../database/README.md`](../database/README.md) |
| `bin/` | processos de fundo, chamados por cron | [`../bin/README.md`](../bin/README.md) |
| `scripts/` | utilitários de linha de comando e as suites de teste | [`../scripts/README.md`](../scripts/README.md) |
| `config/` | configuração, lida do `.env` | [`../config/README.md`](../config/README.md) |
| `storage/` | gerado em runtime, fora do git | |

A organização de `app/` por domínio e a regra de manter os READMEs em dia estão
em [`../CLAUDE.md`](../CLAUDE.md).

## Multi-tenant, a decisão que sustenta o resto

Banco único, schema único, **`account_id` em toda tabela de dado de cliente**.
É o padrão de Clio, HubSpot e Pipedrive.

Quem resolve de que conta é a sessão é `App\Core\AccountContext`, e é ele também
que sabe quando uma matriz pode enxergar a filial
(`getAccessibleAccountIds()`). Nenhuma query cruza `account_id` por conta
própria.

Hierarquia de um nível só: Matriz → Filial. Acesso de fora (advogado associado)
é concedido **item a item**, via `ResourceShare`, nunca por herança.

Detalhe em [`MULTITENANCY.md`](MULTITENANCY.md).

## Sessão e autenticação

Sessão PHP, com `$_SESSION['user_id']`, endurecida no
`App\Usuarios\AuthController` (regeneração de id, expiração). Tokens CSRF nas
operações sensíveis. 2FA por TOTP implementado à mão, sem biblioteca.

O **Painel Master** tem login e 2FA próprios, separados do login dos
escritórios. Não é um papel do login comum.

## Assíncrono

Três coisas não acontecem na requisição do usuário, e é de propósito:

- **e-mail**: `Mailer::send()` enfileira em `emails_outbox`
- **webhook de saída**: o dispatcher enfileira, quem entrega é
  `bin/webhook_worker.php`, chamado por cron a cada minuto
- **resposta do agente de IA**: o webhook devolve 200 antes de processar
  (`flushResponse()` antes de `runAgentReply()`), senão a Evolution reenvia

Todo processo de fundo roda **um lote e sai**, com trava contra execução
concorrente, e precisa ser idempotente: o agendador atrasa e sobrepõe.

## Degradação combinada

As integrações externas são opcionais e cada uma degrada sozinha: sem Evolution
o WhatsApp fica indisponível e o resto funciona; sem gateway configurado o
`NullGateway` responde; sem chave de IA o agente não atende. Isso permite rodar
o sistema inteiro em desenvolvimento sem nenhuma credencial real, e é também
por isso que **em dev algumas coisas passam sem realmente acontecer**: ao testar
cobrança ou agente, confirme qual adapter está ativo.

## O mínimo a rodar antes de dar algo por pronto

Com o MySQL de pé:

```bash
for f in $(find app public bin scripts config database -name "*.php"); do php -l "$f"; done
```

```bash
for t in scripts/tests/*.php; do php "$t"; done
```

Baseline conhecido em **27/08/2026**:

| Suite | Esperado |
|---|---|
| `class_refs_test` | 3411 referências + 323 requires, todos resolvem |
| `wa_webhook_parser_test` | 42 PASS · 0 FAIL |
| `wa_webhook_token_test` | 21 PASS · 0 FAIL |
| `wa_invariants` | 39 PASS · 0 FAIL |
| `plan_gate_e2e_test` | 25 ok · 0 falha |
| `plan_feature_test` | 79 ok · 0 falha |

**Tudo verde é o esperado; qualquer falha é regressão.** Até 27/08/2026 o
`plan_feature` fechava em `66 ok · 12 falha`, tratadas como dívida herdada. Eram
as asserções de preço da página pública, que deixaram de valer quando o produto
tirou os valores de `planos.php`. O teste estava velho, não o código.

Ao mexer em `app/`, verifique também que nenhum `require` ficou apontando para o
vazio e que todo `use App\...` resolve.

### As duas formas que o lint não pega

1. **Nome de classe dentro de string**
   (`class_exists('App\\Core\\RequestId')`), cerca de 50 no repositório. Quando
   erram, retornam `false` em silêncio.
2. **Nome curto que dependia do mesmo namespace.** Duas classes no mesmo
   namespace se enxergam sem `use`. Ao dividir um namespace, o nome curto passa
   a apontar para uma classe inexistente, e **nem `php -l` nem carregar o
   arquivo percebem**: type hint só resolve na chamada.

**A defesa principal contra as duas é o `scripts/tests/class_refs_test.php`**,
que confere estaticamente se todo nome de classe do projeto resolve, inclusive
em caminho de código que nenhuma requisição executa. Ele foi validado contra o
commit quebrado: acusa as 131 referências, e fica limpo depois da correção.

Ainda assim, **mudança estrutural em `app/` também pede varredura autenticada**,
porque o teste estático não pega erro de lógica. Sem sessão, as páginas internas
redirecionam para o login antes de executar a linha que quebra: o teste passa e
dá falsa segurança. Em 27/08/2026 a varredura anônima deu 164/164 e havia 28
referências quebradas, com duas telas fatalando.

Para criar a sessão sem alterar dado, replique o que o `AuthController` grava
(só `SELECT`), escreva o arquivo de sessão e mande o `PHPSESSID` como cookie.
Teste com pelo menos dois perfis: um `owner`/super admin e um `member`, porque
o caminho de permissão é diferente.

## Limitações conhecidas, assumidas

- **O autoloader depende do namespace espelhar a pasta.** Pasta nova com
  namespace divergente faz a classe sumir, sem erro de sintaxe. É o preço de
  não usar Composer, e o `class_refs_test` é quem cobre isso
- **Carregar um arquivo de classe isolado não funciona**: as dependências vêm
  do autoloader, então script, cron e `php -r` carregam `app/bootstrap.php`
- **`public/` não é agrupável por domínio** sem uma camada de rota que preserve
  os endereços atuais
- **Cobertura de teste concentrada**: das seis suites de `scripts/tests/`, o
  `class_refs_test` cobre o projeto inteiro, mas as outras cinco cobrem só
  plano e WhatsApp; o resto é verificado à mão

## Próximos passos, se um dia valer a pena

Em ordem de custo/benefício, do mais barato ao mais caro:

1. ~~**Autoloader**~~ — **feito em 27/08/2026** (`app/bootstrap.php`)
2. ~~**Namespace nas cinco classes globais**~~ — **feito em 27/08/2026**
3. **Camada de rota em `public/`**, preservando os endereços atuais. Só então
   faz sentido agrupar `public/` por domínio
4. **Ampliar os testes** para os módulos sem cobertura

O que **não** está no caminho: trocar de linguagem ou adotar SPA. O sistema é
renderizado no servidor, sem build, e isso é o que permite o deploy ser um
`git pull`.
