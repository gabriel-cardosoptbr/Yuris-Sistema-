# Master/ — a conta (tenant) e o Painel Master

Duas coisas moram aqui, e elas são vizinhas por um motivo: a **conta**, que é a
unidade de isolamento de todo o sistema, e o **Painel Master**, que é a tela do
super admin da Inovaize para administrar as contas.

Telas: Painel Master (`public/master.php`, `public/api/master/`) e
Gestão › Escritórios.

## Arquivos

| Classe | O que faz |
|---|---|
| `Account.php` | a conta, ou tenant. Toda tabela de dado de cliente tem `account_id` apontando para cá. Guarda também o tipo (matriz ou filial), o código de vínculo e os módulos habilitados (`features`) |
| `AccountBootstrapSeeder.php` | popula a primeira casca de uma conta nova: colunas de funil, quadro de tarefas, plano de contas. Sem isso o cliente entra num sistema vazio |
| `AccountNotification.php` | avisos que o Painel Master manda para as contas |
| `ResourceShare.php` | compartilhamento seletivo de card, processo ou contato entre contas vinculadas. O modelo é o "Share" do Notion / ACL do Drive: acesso é concedido item a item, nunca herdado |
| `SecurityIncident.php` | registro de incidente de segurança envolvendo dado pessoal. Exigência da LGPD, com prazo de notificação |
| `MasterAudit.php` | grava em `master_audit_log`. **Toda ação do super admin passa por aqui** |
| `AiSettings.php` | configuração global de IA da plataforma, em `app_settings`. Guarda a chave da OpenAI usada por todas as instâncias do agente: o super admin cadastra uma vez e os escritórios não precisam informar chave |

## Por que `AiSettings` está aqui e não em `WhatsAppAgente/`

Porque é configuração **da plataforma**, não da conta. A chave é da Inovaize e
vale para todos os tenants, então quem a administra é o Painel Master. A
configuração **por canal** do agente (prompt, modelo, se está ligado) fica em
`agent_configs` e é tratada em `../WhatsAppAgente/`.

## Regras

**Ação de super admin sem `MasterAudit` é ação sem rastro.** Se o Painel Master
ganhar um botão novo que altera dado de cliente, ele grava no log de auditoria,
sem exceção.

**Conta é a fronteira de isolamento.** Nenhuma query pode cruzar `account_id`
por conta própria. Quando o cruzamento é legítimo (matriz enxergando filial),
quem autoriza é o `../Core/AccountContext.php`, e o caminho é
`getAccessibleAccountIds()`, nunca uma query solta.

**`features` da conta liga e desliga módulo de verdade**, no front e no back,
com comportamento *fail-open* (na dúvida, libera). Ao criar módulo novo,
lembre-se de decidir se ele entra nessa lista.
