# database/ — schema, migrations e seeds

O banco é **MySQL 8.0 em produção** e **MariaDB (XAMPP) em desenvolvimento**.
Não são o mesmo motor: comportamento que depende de detalhe de versão precisa
ser testado nos dois.

## O que tem aqui

| Pasta / arquivo | O que é |
|---|---|
| `migrations/run_150.php` | **índices da listagem do funil** (Prospecção lenta, 16/10/2026): `whatsapp_chats.linked_card_id` (as quatro subconsultas por card de `Card::list` varriam a tabela de conversas inteira; a coluna de 496 leads da Fleetiflow levava 1,5 s no servidor), `cards.coluna_id` (o filtro por coluna da mesma listagem) e `crm_agendamentos.card_id` (`AgendaDoLead::proximas` busca por card sem conta, e o índice antigo começa por `account_id`). Só cria índice. Idempotente, `--dry-run` |
| `migrations/run_151.php` | **contratos e assinatura eletrônica** (edição CRM, 18/10/2026): `crm_contratos`, `crm_contrato_versoes`, `crm_contrato_arquivos`, `crm_contrato_participantes`, `crm_contrato_convites` (só o hash do token), `crm_contrato_sessoes`, `crm_contrato_desafios` (só o HMAC do código), `crm_contrato_assinaturas`, `crm_contrato_eventos` (encadeados por hash) e `crm_contrato_config`. Gatilhos `trg_ctr_*`: assinatura, evento e arquivo sem UPDATE/DELETE; versão fechada não troca de arquivo nem de hash. Para alterar essas tabelas depois, é preciso tirar e recriar os gatilhos (o próprio run_151 recria os que faltam). Idempotente, `--dry-run`. Ver `app/Contratos/README.md` |
| `migrations/run_155.php` | **setores do cliente são as colunas do quadro** (correção de 20/10/2026): cria `crm_cliente_setores_extras` (conta, cliente, coluna de `clientes_setores`; chave cliente + coluna) para o cliente que está em mais de uma coluna, e tira a `crm_cliente_setores` da 154 só se ela estiver vazia. Idempotente, `--dry-run`. Ver `App\Clientes\SetoresDoCliente` |
| `migrations/run_154.php` | **setores do cliente** (edição CRM, 20/10/2026): cria `crm_cliente_setores` (conta, cliente, setor de `teams`; chave cliente + setor, então um cliente pode ter vários) e preenche com o setor que os leads de origem já tinham (`cards.cliente_id` + `cards.team_id`). Só insere na tabela nova. **Substituída pela 155** (setor de cliente é coluna do quadro, não `teams`). Idempotente, `--dry-run`. Ver `App\Clientes\SetoresDoCliente` |
| `migrations/run_153.php` | **cobranças dos clientes** (edição CRM, 20/10/2026): `crm_cliente_valores.tipo` ganha `servico`, a tabela ganha `contrato_id` (nulo) e nasce `crm_cliente_cobrancas` (cliente, valor, contrato, tipo, descrição, competência, vencimento, valor, status aberta/paga/cancelada, pago em, valor pago, forma, observação, quem deu baixa; único em `(valor_id, competencia)`). Só acrescenta: valor de ENUM, coluna nula e tabela nova. Idempotente, `--dry-run`. Ver `App\Clientes\CobrancaDoCliente` |
| `migrations/run_152.php` | **valores do cliente** (o Financeiro da ficha do cliente, edição CRM, 20/10/2026): cria `crm_cliente_valores` (tipo mensal/anual/fixo/demanda, descrição, valor, dia de vencimento, início, fim, ativo, observação). Só cria a tabela. Idempotente, `--dry-run`. Ver `App\Clientes\ValoresDoCliente` |
| `migrations/run_149.php` | **estorno e pessoa no reembolso** (tela Finanças): `reembolsos.tipo` (`reembolso`, o padrão de tudo que já existia, ou `estorno`) e `reembolsos.favorecido_user_id` (o usuário da conta que recebe; NULL = pessoa de fora, só pelo nome), com índice por conta + pessoa. Regras em `app/Financas/README.md`. Idempotente, `--dry-run` |
| `migrations/run_148.php` | **foto diária das instâncias do Evolution** (aba Evolution): `evolution_snapshots`, uma linha por conta dona da aba + instância + dia (único), com a situação e os totais de mensagens, contatos e conversas. O Evolution só dá totais acumulados; a diferença entre fotos é o volume do dia. Regras em `app/Projetos/README.md`. Idempotente, `--dry-run` |
| `migrations/run_147.php` | **Projetos, segunda parte**: `projetos.parent_id` (subprojeto dentro de projeto-mãe, um nível), `projeto_contas` (quem usa o projeto: plano, situação, quanto paga, usuários, peso de consumo, custo direto, métricas da medição automática em JSON, `origem` manual ou sync) e `projeto_acessos` (rótulo, endereço, usuário e `senha_enc`, a senha cifrada com `App\Core\Crypto`). `account_id` nas duas tabelas novas; exclusão lógica. Exige a 146. Regras em `app/Projetos/README.md`. Idempotente, `--dry-run` |
| `migrations/run_146.php` | **Projetos** (aba da edição CRM): `projeto_servidores` (servidor e custo mensal, R$ ou US$), `projetos` (cliente, tipo, situação, endereço, stack, servidor + peso no rateio, usuários ativos) e `projeto_itens` (receita ou custo: categoria, fornecedor, valor unitário NULL = a preencher, quantidade, moeda, recorrência, vigência). `account_id` nas três; exclusão lógica. Totais, rateio e pendências não são gravados. Regras em `app/Projetos/README.md`. Idempotente, `--dry-run` |
| `migrations/run_145.php` | **Vitória nos números de treino só por comando** (edição CRM): `sdr_treino_estado`, uma linha por número de treino, ligado ou não. Desligada por padrão; "#ativar" liga e "#desativar" desliga, mandados do próprio número. Ver `SdrFleetiflow::comandoDeTreino`. Idempotente, `--dry-run` |
| `migrations/run_144.php` | **integração com a plataforma Fleetiflow** (edição CRM): `plataforma_conexoes` (endereço da API, chave e segredo dos eventos, cifrados), `plataforma_vinculos` (código da conta FLT ↔ lead, com o último resumo lido) e `plataforma_eventos` (eventos recebidos, únicos por id). Ver `app/Plataforma/README.md`. Idempotente, `--dry-run` |
| `migrations/run_143.php` | **orçamento no CRM** (edição Fleetiflow): `crm_servicos` e `crm_servico_componentes` (catálogo com as taxas: repasse imediato/mensal, cliente vê, custo), `crm_orcamentos` e `crm_orcamento_itens` (serviço ou débito do veículo). Ver `app/Orcamentos/README.md`. Idempotente, `--dry-run` |
| `migrations/run_142.php` | **quem mandou a mensagem e o que foi entregue ao agente** (edição CRM, 10/10/2026): cria `whatsapp_msg_autor` (`instance_id` + `wamid` únicos, `autor` `chat` ou `celular`, `user_id`) e `sdr_encaminhamentos` (cada entrega da mensagem do lead ao agente de pré-venda: origem `webhook` ou `manual`, `http_status`, `ok`, `user_id`). Collation de `whatsapp_messages`. Idempotente, com `--dry-run`. Ver `App\WhatsAppAgente\AutorDaMensagem` e `SituacaoConversa` |
| `migrations/run_141.php` | **agenda da próxima interação com o lead** (edição CRM): cria `crm_agendamentos` (conta, lead, tarefa, tipo, `lembrete_min`, `lembrete_enviado_para`, mensagem programada e o estado do envio). A data e a hora ficam na tarefa (`tasks.prazo`). Ver `app/Prospeccao/README.md`. Idempotente, `--dry-run` |
| `migrations/run_140.php` | **setor do lead e gestor do setor**: `cards.team_id` (o setor do lead, igual ao da conversa ligada; ver `app/WhatsAppAgente/README.md`) e `teams.gestor_user_id` (o usuário responsável pelo setor). Só cria as colunas e os índices. Idempotente, `--dry-run` |
| `migrations/run_139.php` | `reembolso_parcelas.pago_por_nome`: quem fez o pagamento de cada parcela (texto livre), gravado junto com a data ao marcar a parcela como paga. Exige a 138 |
| `migrations/run_138.php` | `reembolsos` e `reembolso_parcelas`: o reembolso (favorecido, descrição, data da despesa, valor total) e uma linha por parcela, com vencimento e `pago_em` (NULL = em aberto). `account_id` nas duas; parcela sai junto com o reembolso (`ON DELETE CASCADE`), mas o reembolso só é excluído logicamente. Regras em `app/Financas/README.md` |
| `migrations/run_137.php` | `whatsapp_instances.responsavel_user_id`, o vendedor dono do número: o lead que a automação cria por ele nasce com esse responsável (`SdrFleetiflow::donoDoNumero`). Preenchido por `scripts/manutencao/definir_dono_numero.php`. Idempotente, `--dry-run` |
| `migrations/` | numeradas a partir de `001`; a mais recente é `run_135.php` (`chat_mencoes.tipo` ganha `tarefa` e `conversa`, para o @ do Chat Interno da edição CRM); antes dela, `run_134.php` (`cards.tipo_lead` e `cards.temperatura`, o tipo e o termômetro escolhidos pelo consultor no card da edição CRM); antes dela, `run_133.php` (`account_marca_arquivos`, o logo e o ícone da marca de cada conta da edição CRM); antes dela, `run_132.php` (`whatsapp_identidades.perfil_comercial_em`, que registra a consulta do perfil comercial para não repeti-la a cada abertura do Chat); antes dela, `run_131.php` (`task_entregas`, a base do OTIF das tarefas, com o retroativo reconstruído do `task_history`). É o histórico de como o schema chegou ao que é hoje |
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

### A 111 é o exemplo de por que testar em banco descartável

A migration 111 (isola `contatos` por conta, bug B1) passou no banco de
desenvolvimento de primeira, porque aqui **nenhum** contato estava compartilhado
entre contas. Rodada num banco descartável com o cenário ruim montado de
propósito, quebrou na hora: ela tentava criar a cópia do contato **antes** de
trocar o UNIQUE global, e o índice recusava. O erro só apareceria em produção.

Molde que funcionou:

```bash
mysqldump -u root sistema_vendas > dump.sql
mysql -u root -e "CREATE DATABASE yuris_migtest"
mysql -u root yuris_migtest < dump.sql
# insira à mão o caso ruim que o dev não tem, e só então rode a migration
```

Lembre que **o `.env` vence a variável de ambiente** (ver `../config/README.md`):
`DB_NAME=yuris_migtest php ...` não basta.

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
