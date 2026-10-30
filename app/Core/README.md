# Core/ — infraestrutura usada por todo o resto

Nada aqui é uma tela nem uma funcionalidade que o cliente vê. São as peças que
todos os outros domínios precisam para funcionar: conexão com o banco,
identificação do tenant, criptografia, formato de resposta, leitura de `.env`.

Se um arquivo serve a três ou mais domínios e não pertence a nenhum, o lugar
dele é aqui. Se serve a um só, ele pertence à pasta daquele domínio.

## Arquivos

| Classe | O que faz |
|---|---|
| `Database.php` | conexão PDO única com o MySQL. Também força `date()` a produzir timestamp em UTC, independente do php.ini ou do fuso do Windows |
| `AccountContext.php` | **o coração do multi-tenant.** `vendedorDaSessao()` (estática, 20/10/2026): o id do vendedor da edição CRM logado, ou null (dono, admin, conta jurídica, ou sem sessão de usuário, como webhook e workers); é a regra única de "o vendedor só vê o que é dele" para quem não tem o contexto na mão (`App\Crm\Entidade`, `WhatsAppChannelAccessService`, `App\Relatorios\Listagem`). `assertCanWriteMany()` (20/10/2026): `assertCanWrite` para uma lista, numa consulta só para os recursos das contas da sessão e um a um só para os compartilhados (o reorder do quadro manda centenas de cards). `vendedorCrm()` (02/10/2026): conta da edição CRM e usuário que não é dono nem admin; é a regra única do painel por vendedor (só os próprios valores) e do financeiro só de ADM, sempre false na edição jurídica. Diz de que conta é a sessão atual, quais contas ela alcança (matriz enxerga filiais), e qual conta usar ao gravar. Também resolve o **produto** da conta (`getProduto()`, `moduloJuridicoDisponivel()`) e bloqueia página/API jurídica para conta que não é `yuris` (`assertModuloJuridicoDisponivel[Page]()`) — ver `App\Master\Account::getProduto()`. 33 métodos |
| `VendedorEmFoco.php` | **o vendedor em foco da edição CRM** (30/10/2026): o dono ou admin escolhe um vendedor no menu e todas as telas mostram só o que é dele, até voltar para "Todos". A escolha fica na sessão (`$_SESSION['vendedor_em_foco']`), vale em todas as abas e some ao sair. **Não é permissão**: só estreita listagens; o que cada pessoa pode ver e fazer continua em `AccountContext::vendedorDaSessao()`. `escolhido()` é a escolha do admin, conferida (usuário ativo de conta que a sessão alcança); `id()` é o vendedor logado ou, para o admin, o escolhido; `podeEscolher()` é dono/admin de conta `fleetiflow`. Na edição jurídica tudo é null. Quem usa: Chat (conversas cujo lead é dele, `WhatsAppMessage::getChatList`), funil (`api/cards.php`), clientes, tarefas, painel comercial, orçamentos, Plataforma, relatórios (`App\Relatorios\Listagem`), desempenho e atendimento |
| `ZipEmFluxo.php` | .zip escrito à mão e em fluxo (21/10/2026; veio de `app/Drive`): o PHP de produção não tem a extensão zip e o disco é curto. Sem compressão, descritor de dados depois de cada arquivo, nomes em UTF-8, nome repetido ganha "(2)", até 4 GB. Usado pelo download do Drive (`App\Drive\Baixar`) e pela planilha |
| `PlanilhaXlsx.php` | planilha .xlsx escrita à mão sobre o `ZipEmFluxo` (21/10/2026): abas com cabeçalho em negrito, congelado e com filtro, larguras de coluna, título e link clicável (fórmula HYPERLINK). Conferida abrindo no Excel. Usada pelo relatório de entregas (`App\Digital\RelatorioEntregas`) |
| `IconeApp.php` | ícone do app instalado no celular (22/10/2026) feito do ícone da marca, sem GD nem Imagick (produção não tem): lê PNG de 8 bits, pinta sobre o fundo (a cor da borda, se o ícone é cheio; branco com 12% de margem, se é transparente, porque o iPhone pinta transparente de preto) e redimensiona para 180, 192 ou 512 px. `url()` diz o endereço do ícone de cada conta. Usado por `public/api/app_icone.php`, `app_manifest.php` e `sidebar.php` |
| `TenantGuard.php` | valida se a sessão pode tocar um recurso específico. Use junto com o `AccountContext`, nunca no lugar dele |
| `ApiResponse.php` | resposta JSON padronizada dos endpoints. Todo endpoint em `public/api/` deve responder por aqui, para o formato não variar de tela para tela |
| `ErrorReporter.php` | tratamento padronizado de exceção nos endpoints. Registra o detalhe no log e devolve mensagem genérica ao cliente, exigência da LGPD |
| `RequestId.php` | ID de 12 hex por request HTTP, para amarrar as linhas de log de uma mesma chamada |
| `Crypto.php` | AES-256-GCM para cifrar credencial em repouso (chave da Evolution, token de gateway). Nunca guarde credencial em texto puro |
| `EnvLoader.php` | parser de `.env` sem dependência externa |
| `ProductHost.php` | domínio próprio do Fleetiflow CRM: decide pelo `Host` da requisição (não pela conta) qual produto um VISITANTE SEM SESSÃO encontra em `/` e `/login.php`. Lista de domínios em `FLEETIFLOW_DOMAINS` no `.env`, **mais** os domínios gravados na marca de uma conta CRM pelo Painel Master (`contaDoHost()`, `marcaDoHost()`): nesse caso a tela de login sai com a marca daquela conta. Não confundir com `Account::getProduto()` — este é sobre a porta de entrada, aquele é sobre o que a conta já logada vê |
| `Url.php` | monta URL respeitando o base path da aplicação |
| `Router.php` | **a camada de rota de `public/`.** Resolve URL para o arquivo que responde por ela: primeiro a tabela declarada em `config/rotas.php`, depois sondagem em `public/`. Devolve o caminho, não inclui; quem inclui é `public/index.php`, porque página PHP de topo de pilha espera escopo global |
| `Timeline.php` | **a linha do tempo única de uma pessoa.** Junta `card_history` e `clientes_history`, que têm formatos diferentes, e faz a timeline do cliente incluir o que aconteceu enquanto ele ainda era prospecção. Lê junto em vez de copiar |
| `UserOptions.php` | renderiza `<select>` de usuários, usado por várias telas |
| `WebhookUrlValidator.php` | proteção contra SSRF nas URLs de webhook que o cliente cadastra: bloqueia IP interno, localhost e afins |
| `Mailer.php` | fila de e-mail transacional. `send()` **enfileira** em `emails_outbox`, não envia na hora. Quem envia é o worker |

## O que quase sempre dá errado aqui

**Consultar o banco sem filtrar por conta.** Toda query que lê dado de cliente
precisa do `account_id` vindo do `AccountContext`. Uma query sem esse filtro
vaza dado de um escritório para outro, e é o pior tipo de bug que este sistema
pode ter. A auditoria de isolamento de 2026-07-28 achou exatamente isso.

**Responder erro com a mensagem da exceção.** A mensagem crua pode conter nome,
CPF ou trecho de query. Use o `ErrorReporter`.
