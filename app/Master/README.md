# Master/ — a conta (tenant) e o Painel Master

Duas coisas moram aqui, e elas são vizinhas por um motivo: a **conta**, que é a
unidade de isolamento de todo o sistema, e o **Painel Master**, que é a tela do
super admin da Inovaize para administrar as contas.

Telas: Painel Master (`public/master.php`, `public/api/master/`) e
Gestão › Escritórios.

## Edição CRM: uma conta por marca

A edição CRM comercial (a da Fleetiflow) não é outro sistema: é a mesma conta
do Yuris com `configuracoes.produto = 'fleetiflow'`, que esconde e bloqueia o
jurídico. Para atender outra empresa no mesmo padrão (Inovaize, Autodoc...) se
cria **uma conta**, não um projeto novo:

1. Painel Master, botão **+ Conta CRM** (ou "+ Matriz" e Edição = CRM).
2. Preencher a marca: nome, cor, logo, ícone, e opcionalmente domínio e agente.
3. O `create_account.php` faz o mesmo que `scripts/create_fleetiflow_account.php`
   (funil comercial do `SdrFleetiflow::montarFunil`, sem o setor "Jurídico"), e
   grava a marca. A marca se edita depois pelo detalhe da conta, "Editar marca".

Trocar a edição de uma conta que já existe **não** é feito pelo Master: esconder
ou mostrar o jurídico de uma conta com dado dentro é decisão à parte.

**O agente de IA é por conta.** A Vitória (n8n do Fleetiflow, endereço no
`.env`) só atende a conta sem marca própria. Conta com marca usa o endereço
gravado na marca, ou fica sem agente. Sem essa separação, ligar o agente numa
conta nova mandaria os leads dela para o robô do Fleetiflow.

**Cor da marca legível (02/10/2026).** A cor principal aparece como texto em
fundo branco (links, abas, valores) e como fundo de botão com texto por cima.
`Marca::paleta()` escurece a cor escolhida até o contraste WCAG de 4,5:1 com o
branco quando ela é clara demais (dourado, amarelo, laranja): o dourado
`#D7A525` da Via Autodoc vira `#927019` nas telas. Os tons claros (`suave`,
`media`, `clara`) continuam saindo da cor original, e o texto sobre a cor
(`texto`) é o de maior contraste entre branco e grafite. A cor gravada na marca
não muda; o logo e o ícone guardam o tom vivo. A tela de login de domínio com
marca própria (`public/login-fleetiflow.php`) também tinge o fundo, a grade e
as manchas com a cor da marca; a da Fleetiflow segue com o azul de sempre.

**Domínio próprio** precisa de duas coisas: o domínio na marca (feito no
Master, faz a tela de login sair com a marca certa) e o DNS + certificado +
bloco do nginx no servidor, que continuam sendo configuração de infraestrutura.

## Arquivos

| Classe | O que faz |
|---|---|
| `Account.php` | a conta, ou tenant. Toda tabela de dado de cliente tem `account_id` apontando para cá. Guarda também o tipo (matriz ou filial) e o código de vínculo. O **produto** da conta (`getProduto()`: `'yuris'` padrão ou `'fleetiflow'`) e a disponibilidade do jurídico (`moduloJuridicoDisponivel()`) vivem em `configuracoes.produto` (JSON já existente na tabela, zero migration) — não confundir com plano/billing, que é `Billing/PlanFeature` |
| `Marca.php` | a **marca** de uma conta da edição CRM comercial: nome, subtítulo, cor (e a paleta derivada dela), logo, ícone, domínio próprio e o agente de IA de pré-venda. Mora em `configuracoes.marca` (JSON) e as imagens em `account_marca_arquivos` (migration 133), servidas por `/api/marca_arquivo.php?h=<sha1>`. Conta CRM **sem** marca gravada é a Fleetiflow original e recebe exatamente os valores que estavam escritos no código (`padraoFleetiflow()`). Ver a seção "Edição CRM: uma conta por marca" abaixo |
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
