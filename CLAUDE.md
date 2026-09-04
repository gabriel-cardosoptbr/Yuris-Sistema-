# Yuris — instruções do projeto

Sistema de gestão para escritórios de advocacia. **Está em produção, com
escritórios reais usando.** Multi-tenant: cada escritório é uma conta, e o
isolamento entre contas é a garantia mais importante do sistema.

## A pilha, em uma tela

- **PHP 8.2**, monolito. Sem Composer, sem npm, **sem etapa de build**
- **MySQL 8.0** em produção, **MariaDB (XAMPP)** em desenvolvimento
- Apache com `DocumentRoot` em `public/`
- Deploy em produção: `git pull` dentro do container Docker no EC2. O que está
  no repositório é o que roda
- Desenvolvimento local: `http://localhost:8090`

**Existe autoloader** desde 27/08/2026: [`app/bootstrap.php`](app/bootstrap.php),
20 linhas de `spl_autoload_register` que mapeiam namespace para pasta. Todo ponto
de entrada carrega o bootstrap e nada mais:

```php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Processos\Processo;
```

Antes disso eram 1.077 `require_once` com caminho à mão, em 192 arquivos.
Detalhe em [`app/README.md`](app/README.md).

---

## REGRA PERMANENTE: a estrutura de pastas e os READMEs

Esta é a regra que governa o repositório, definida em **27/08/2026**. Ela vale
para toda sessão futura, de pessoa ou de IA.

### 1. A organização de pastas é para ser mantida

`app/` é organizado **por assunto**, não por tipo técnico. Uma pasta por
domínio, e o nome da pasta é o nome do módulo no menu do sistema.

```
app/Core  Usuarios  Master  Prospeccao  Clientes  Processos  Tarefas
    Financas  WhatsAppAgente  Webhooks  Billing  Lgpd
```

**Não recrie `Models/`, `Helpers/`, `Services/` ou `Controllers/`.** Era assim
antes, e o efeito era que um assunto ficava espalhado em três pastas.

Arquivo novo vai na pasta do **assunto** dele. Se serve a três ou mais domínios
sem pertencer a nenhum, é infraestrutura e vai para `Core/`. Se o assunto ainda
não existe, criar pasta nova é aceitável, desde que ela ganhe seu `README.md` e
entre na tabela de [`app/README.md`](app/README.md).

### 2. Toda pasta tem `README.md`, e ele é atualizado junto com o código

Cada pasta tem um `README.md` que explica o que ela abriga, o que cada arquivo
faz, e as regras que valem ali.

**Ao mexer no código de uma pasta, o `README.md` dela é atualizado na mesma
mudança.** Arquivo novo, arquivo removido, arquivo que mudou de propósito,
regra nova: tudo isso entra no README. Uma mudança só está pronta quando o
README da pasta descreve o que passou a ser verdade.

READMEs existentes:

```
README.md · CLAUDE.md (este arquivo)
app/README.md
   Core/  Usuarios/  Master/  Prospeccao/  Clientes/  Processos/
   Processos/Monitor/  Tarefas/  Financas/  WhatsAppAgente/
   WhatsAppAgente/AiIntake/  Webhooks/  Billing/  Billing/Gateway/  Lgpd/
public/README.md · public/api/README.md
database/README.md · scripts/README.md · bin/README.md · config/README.md
docs/README.md
```

### 3. `public/` não é reorganizado sem uma camada de rota

O caminho do arquivo em `public/` **é a URL**. Mover `public/processos.php`
muda o endereço `/processos.php` e quebra link salvo, link em e-mail já
enviado, e webhook cadastrado. Agrupar `public/` por domínio exige antes uma
camada que preserve os endereços atuais, e isso é decisão de arquitetura, não
de pasta.

### 4. Material que não é código do sistema fica fora do repositório

Deck comercial, apresentação, PDF, backup antigo: nada disso mora aqui. O que
foi retirado em 27/08/2026 está em `~/Documents/Claude/Projects/yuris-material/`.

---

## O que verificar antes de dar qualquer mudança por pronta

Com o MySQL de pé:

```bash
for f in $(find app public bin scripts config database -name "*.php"); do php -l "$f"; done
```

```bash
for t in scripts/tests/*.php; do php "$t"; done
```

Baseline em 27/08/2026: `class_refs` 3411 referências + 323 requires, todos
resolvem ·
`wa_webhook_parser` 42/0 · `wa_webhook_token` 21/0 · `wa_invariants` 39/0 ·
`plan_gate_e2e` 25 ok/0 · `plan_feature` 79 ok/0 · `dominios` 51 ok/0 · `djen_filtros` 8 ok/0.

**Tudo verde é o esperado.** As 12 falhas antigas do `plan_feature` eram o teste
exigindo os preços que saíram da página pública; foram corrigidas, e o bloco
hoje confere a decisão nova (nenhum valor visível, JSON-LD sem `offers`).

Ao mexer em `app/`, confira também que nenhum `require` ficou apontando para o
vazio e que todo `use App\...` resolve. E lembre das duas formas que **nenhuma
dessas checagens pega**:

- nomes de classe **dentro de string** (`class_exists('App\\Core\\RequestId')`),
  cerca de 50: quando erram, retornam `false` em silêncio;
- **nomes curtos que dependiam do mesmo namespace**: duas classes no mesmo
  namespace se enxergam sem `use`, então mover uma delas quebra a outra sem que
  `php -l` ou carregar o arquivo percebam (type hint só resolve na chamada).

**Quem pega as duas é o `scripts/tests/class_refs_test.php`**, que confere
estaticamente se todo nome de classe do projeto resolve, inclusive em caminho de
código que nenhuma requisição executa. Rode-o sempre que mexer em namespace,
mover arquivo ou renomear classe.

**Mudança estrutural em `app/` também pede varredura autenticada**, com sessão
de verdade, porque o teste estático não pega erro de lógica. Sem sessão, as
páginas internas redirecionam para o login antes de executar a linha que quebra,
e o teste passa dando falsa segurança. Foi o que aconteceu em 27/08/2026.

## Riscos deste projeto, em ordem de gravidade

1. **Vazar dado entre escritórios.** Toda query de dado de cliente filtra por
   `account_id`, vindo de `AccountContext`. Nunca consulte antes de resolver a
   sessão e a conta. A auditoria de 28/07/2026 achou exatamente isso.
2. **Quebrar o canal de WhatsApp.** Existe **uma única instância** da Evolution
   por conta, usada ao mesmo tempo pelo chat humano e pelo agente. Segunda
   instância, segundo QR ou webhook paralelo derrubam o módulo. Ver
   [`app/WhatsAppAgente/README.md`](app/WhatsAppAgente/README.md).
3. **Namespace que deixa de espelhar a pasta.** É o que o autoloader usa para
   achar o arquivo. Pasta nova exige namespace igual, ou a classe some.
4. **Editar migration já aplicada.** Não muda os bancos onde já rodou. Correção
   é migration nova.
5. **Prazo de LGPD.** Solicitação de titular e incidente têm prazo legal. Ver
   [`app/Lgpd/README.md`](app/Lgpd/README.md).

## Convenções

- **Português** em código, comentário, commit e documentação
- **Sem travessão (`—`)** em texto: use vírgula ou dois-pontos
- Deploy e feature nova viram nota no cofre Obsidian **"YURIS - Segundo
  Cérebro"**, autossuficiente para recriar. Faz parte do "pronto"
- Sem diálogo nativo do navegador (`alert`, `confirm`, `prompt`): use modal ou
  toast na identidade visual do sistema
