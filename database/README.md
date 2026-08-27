# database/ — schema, migrations e seeds

O banco é **MySQL 8.0 em produção** e **MariaDB (XAMPP) em desenvolvimento**.
Não são o mesmo motor: comportamento que depende de detalhe de versão precisa
ser testado nos dois.

## O que tem aqui

| Pasta / arquivo | O que é |
|---|---|
| `migrations/` | 124 arquivos, numerados de `001` a `110`. É o histórico de como o schema chegou ao que é hoje |
| `seeds/` | dados iniciais: `seed_demo.sql`, `seed_processos_mensais.sql`, e os catálogos do agente de IA (`ai_intake_catalog.php`, `ai_area_questions.php`, `ai_prompt_v2.php`, `ai_prompt_v3.php`) |
| `schema.sql` | retrato do schema completo |
| `db_schema_local.tsv` | dump das colunas do banco local, útil para conferir divergência |
| `seed_webhook_events.php` | popula o catálogo de eventos de webhook |
| `auditorias/` | `RELATORIO_DIVERGENCIAS.md` e `RELATORIO_VERIFICACAO.md`, comparações entre o que as migrations criam e o que o banco tem de fato |

## Migrations: as regras que não mudam

**Migration aplicada nunca é editada.** Corrigir uma migration antiga não muda
os bancos onde ela já rodou: o de produção continua como está e o do
desenvolvedor novo fica diferente. Correção é migration nova.

**O número é a ordem, e não se reaproveita.** Duas pessoas criando a mesma
numeração ao mesmo tempo é o conflito clássico. Confira o último número antes
de criar.

**Há dois formatos, `.sql` e `run_NNN.php`.** O `.php` existe para migration
que precisa de lógica (ler dado, decidir, transformar), o que SQL puro não
resolve. As mais recentes são php.

**Teste a migration antes de produção.** O DAVIQ usa um banco `migtest`
descartável para isso, e a receita vale aqui: rodar contra cópia, conferir,
depois produção. Nunca estrear migration no banco do cliente.

## Multi-tenant: a coluna que não pode faltar

Toda tabela que guarda dado de cliente tem **`account_id`**. Tabela nova sem
essa coluna é vazamento entre escritórios esperando acontecer, porque não há
como filtrar. Ver [`../docs/MULTITENANCY.md`](../docs/MULTITENANCY.md) e
[`../app/Core/README.md`](../app/Core/README.md).

## Divergência entre migrations e banco real

Os relatórios em `auditorias/` existem porque isso já aconteceu: colunas que os
models PHP usavam e que nenhuma migration criava. Se um `SELECT` reclamar de
coluna inexistente em produção mas funcionar em local (ou o contrário), comece
por lá.

Setup do zero: [`../docs/DATABASE_SETUP.md`](../docs/DATABASE_SETUP.md).
