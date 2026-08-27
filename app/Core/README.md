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
| `AccountContext.php` | **o coração do multi-tenant.** Diz de que conta é a sessão atual, quais contas ela alcança (matriz enxerga filiais), e qual conta usar ao gravar. 28 métodos |
| `TenantGuard.php` | valida se a sessão pode tocar um recurso específico. Use junto com o `AccountContext`, nunca no lugar dele |
| `ApiResponse.php` | resposta JSON padronizada dos endpoints. Todo endpoint em `public/api/` deve responder por aqui, para o formato não variar de tela para tela |
| `ErrorReporter.php` | tratamento padronizado de exceção nos endpoints. Registra o detalhe no log e devolve mensagem genérica ao cliente, exigência da LGPD |
| `RequestId.php` | ID de 12 hex por request HTTP, para amarrar as linhas de log de uma mesma chamada |
| `Crypto.php` | AES-256-GCM para cifrar credencial em repouso (chave da Evolution, token de gateway). Nunca guarde credencial em texto puro |
| `EnvLoader.php` | parser de `.env` sem dependência externa |
| `Url.php` | monta URL respeitando o base path da aplicação |
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
