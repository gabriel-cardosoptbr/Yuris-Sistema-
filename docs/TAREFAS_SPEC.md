# Especificação — Nova Aba "Tarefas" (Inovaize Yuris)

## Contexto

O sistema Inovaize Yuris já atende escritório de advocacia, com módulos de CRM (cards/pipeline), processos jurídicos, contatos, DRE financeiro, integração WhatsApp via Evolution API, chat interno e dashboard. Falta um módulo de **gestão de tarefas próprio**, no estilo kanban (Trello), que centralize o dia a dia do escritório: prazos, petições a redigir, ligações, follow-ups, conferência de publicações etc. Esta especificação descreve o que deve ser construído, em qual ordem, e como integrar com o que já existe.

A nova aba deve aparecer no menu lateral entre "Jurídico" e "Chat Interno", com o título **"Tarefas"** e ícone de checklist. A rota é `/public/tarefas.php`. Toda a base PHP segue o padrão atual do projeto (PDO via `Database.php`, autenticação por sessão, validação de CSRF, respostas JSON nos endpoints REST). Todo o frontend segue o design-system já presente em `public/assets/design-system.css` e `yuris-theme.css`.

## Conceito do módulo

A aba Tarefas é um kanban completo. O usuário pode ter dois tipos de quadro: **quadros pessoais**, que só ele vê, e **quadros compartilhados** do escritório, visíveis para os membros adicionados ao quadro. Cada quadro contém colunas customizáveis (criar, renomear, reordenar e excluir) e dentro de cada coluna ficam as tarefas, com drag-and-drop entre colunas e reordenação dentro da mesma coluna, exatamente como o usuário espera de uma ferramenta tipo Trello.

Cada tarefa carrega título, descrição rica (textarea com suporte a quebra de linha), prioridade (baixa, média, alta, urgente — com cores semânticas), prazo com data e hora, responsável (usuário do sistema), criador, status (ativa, concluída, arquivada), data de conclusão, ordem dentro da coluna e — se aplicável — referência à regra de recorrência que a gerou. Tarefas podem ser vinculadas a um ou mais elementos do sistema: contato (cliente), processo jurídico, card do CRM ou conta do DRE. Esse vínculo é polimórfico (uma tabela de links separada) e clicar no vínculo dentro da tarefa leva direto pro elemento vinculado. Cada tarefa tem ainda checklist interno (subtarefas marcáveis), comentários (com possibilidade de menção @usuario, espelhando o que já existe em `chat_interno`), anexos de arquivo, registro de tempo gasto (timer com início/fim ou entrada manual em minutos, opcionalmente com valor/hora pra cálculo de honorário) e lembretes que disparam por sistema, WhatsApp (via Evolution API) ou e-mail.

A interface deve oferecer três visões da mesma base de dados: **Kanban** (visão padrão), **Lista** (tabela ordenável e filtrável) e **Calendário** (mensal/semanal, mostrando tarefas no dia do prazo). O kanban tem filtros no topo: por responsável, prioridade, prazo (hoje / atrasadas / próximos 7 dias / todas), tag de vínculo (cliente X, processo Y) e busca textual. Ao clicar em uma tarefa, abre um painel lateral à direita (drawer) seguindo o mesmo padrão visual do card do CRM, com abas internas: **Geral** (campos principais), **Checklist**, **Vínculos**, **Comentários**, **Anexos**, **Tempo** e **Histórico**.

## Recorrência — como deve funcionar

A recorrência é o ponto mais sensível e deve seguir esta lógica exata. Quando o usuário cria uma tarefa e marca a opção "Recorrente", o formulário expande perguntando o tipo (diária, semanal, quinzenal, mensal, anual, ou customizada com intervalo arbitrário), os dias da semana aplicáveis (quando semanal), o dia do mês (quando mensal), a data de início e, opcionalmente, uma data de término. Essa configuração é gravada na tabela `task_recurrences` ligada à tarefa-modelo. A tarefa-modelo em si gera a **primeira instância** real na tabela `tasks` com o prazo da data de início.

O comportamento esperado é o seguinte: quando o usuário marca uma instância como concluída (move pra coluna marcada como "Concluído" ou clica no botão "Concluir"), o backend, na mesma transação da atualização, lê a regra de recorrência, calcula a próxima data prevista a partir da data do prazo da instância concluída e cria automaticamente uma nova instância na coluna "A fazer" (a primeira coluna do quadro ou a marcada como inicial), com o novo prazo. A nova instância recebe `origem_task_id` apontando pra anterior, formando uma cadeia de histórico. A instância concluída permanece visível no quadro (na coluna Concluído) e no histórico, mas se ela for arquivada manualmente, sai da visão padrão. Isso garante que o usuário sempre veja "a tarefa de hoje" sem perder o histórico do que foi feito.

Para o caso de o usuário esquecer de marcar a tarefa de ontem, há um cron/scheduled task diário que roda à meia-noite (rota interna `/public/api/tasks_recurrence_tick.php` protegida por token), varre todas as regras ativas em `task_recurrences` e, se a próxima data prevista já passou e não existe instância nascida pra ela, cria a instância marcada com flag visual "atrasada". Assim a recorrência nunca quebra mesmo se houver um dia esquecido.

A regra de cálculo da próxima data segue: para diária, soma `intervalo` dias (padrão 1); para semanal, busca o próximo dia da semana presente em `dias_semana`; para mensal, soma 1 mês mantendo `dia_mes` (com fallback pro último dia se o mês não tiver aquele dia, ex.: dia 31 em fevereiro); para anual, soma 1 ano; para custom, soma `intervalo` na unidade configurada.

## Modelo de dados

A migration deve ser criada como `database/migrations/013_create_tasks.sql` e contém as seguintes tabelas, todas com `engine=InnoDB`, `charset=utf8mb4` e timestamps `created_at`/`updated_at` onde aplicável:

```sql
CREATE TABLE task_boards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  descricao TEXT NULL,
  tipo ENUM('pessoal','compartilhado') NOT NULL DEFAULT 'pessoal',
  owner_id INT NOT NULL,
  cor VARCHAR(20) DEFAULT '#6366f1',
  ordem INT DEFAULT 0,
  ativo TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE task_board_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  board_id INT NOT NULL,
  user_id INT NOT NULL,
  papel ENUM('owner','editor','viewer') DEFAULT 'editor',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (board_id, user_id),
  FOREIGN KEY (board_id) REFERENCES task_boards(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE task_columns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  board_id INT NOT NULL,
  nome VARCHAR(100) NOT NULL,
  ordem INT DEFAULT 0,
  cor VARCHAR(20) DEFAULT '#94a3b8',
  is_coluna_inicial TINYINT(1) DEFAULT 0,
  is_coluna_concluido TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (board_id) REFERENCES task_boards(id) ON DELETE CASCADE
);

CREATE TABLE task_recurrences (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('diaria','semanal','quinzenal','mensal','anual','custom') NOT NULL,
  intervalo INT DEFAULT 1,
  dias_semana JSON NULL,
  dia_mes INT NULL,
  data_inicio DATE NOT NULL,
  data_fim DATE NULL,
  ativa TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  board_id INT NOT NULL,
  column_id INT NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  descricao TEXT NULL,
  prioridade ENUM('baixa','media','alta','urgente') DEFAULT 'media',
  prazo DATETIME NULL,
  prazo_tipo ENUM('legal','interno','administrativo') DEFAULT 'interno',
  responsavel_id INT NULL,
  criado_por_id INT NOT NULL,
  status ENUM('ativa','concluida','arquivada') DEFAULT 'ativa',
  concluida_em DATETIME NULL,
  ordem INT DEFAULT 0,
  recorrencia_id INT NULL,
  origem_task_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (board_id) REFERENCES task_boards(id) ON DELETE CASCADE,
  FOREIGN KEY (column_id) REFERENCES task_columns(id) ON DELETE RESTRICT,
  FOREIGN KEY (recorrencia_id) REFERENCES task_recurrences(id) ON DELETE SET NULL,
  FOREIGN KEY (responsavel_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (criado_por_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_board_column (board_id, column_id),
  INDEX idx_responsavel (responsavel_id),
  INDEX idx_prazo (prazo)
);

CREATE TABLE task_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  link_type ENUM('contato','processo','card','dre_account') NOT NULL,
  link_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  INDEX idx_task (task_id),
  INDEX idx_lookup (link_type, link_id)
);

CREATE TABLE task_checklist_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  descricao VARCHAR(500) NOT NULL,
  concluido TINYINT(1) DEFAULT 0,
  ordem INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE task_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  user_id INT NOT NULL,
  mensagem TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE task_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  user_id INT NULL,
  acao VARCHAR(80) NOT NULL,
  antes_json JSON NULL,
  depois_json JSON NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  INDEX idx_task_data (task_id, created_at)
);

CREATE TABLE task_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100),
  file_size INT,
  uploaded_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE task_time_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  user_id INT NOT NULL,
  inicio DATETIME NOT NULL,
  fim DATETIME NULL,
  duracao_minutos INT NULL,
  valor_hora DECIMAL(10,2) NULL,
  descricao VARCHAR(500) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE task_reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  user_id INT NOT NULL,
  lembrar_em DATETIME NOT NULL,
  canal ENUM('sistema','whatsapp','email') DEFAULT 'sistema',
  enviado TINYINT(1) DEFAULT 0,
  enviado_em DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_pendentes (enviado, lembrar_em)
);
```

A migration deve, no final, fazer um seed automático de quadros padrão para o usuário admin existente: "Meu Dia" (pessoal, colunas: A fazer, Em andamento, Concluído), "Prazos do Escritório" (compartilhado, colunas: Esta semana, Próximos 7 dias, Atrasados, Cumpridos), "Petições" (compartilhado, colunas: A redigir, Em revisão, Aguardando cliente, Protocolada) e "Atendimento" (compartilhado, colunas: Novo contato, Em contato, Aguardando retorno, Convertido). Sempre marcando a primeira coluna como `is_coluna_inicial = 1` e a última como `is_coluna_concluido = 1`.

## Models PHP

Criar dentro de `app/Tarefas/` seguindo o padrão dos models existentes (PDO, métodos estáticos ou de instância conforme o padrão atual do projeto, validação básica de input):

- `Task.php` — CRUD de tarefas, métodos `findByBoard($boardId, $filtros)`, `move($taskId, $columnId, $ordem)`, `complete($taskId, $userId)` (que dispara a geração da próxima instância recorrente), `archive($taskId)`, `withLinks($taskId)` (retorna a tarefa com todos os vínculos resolvidos).
- `TaskBoard.php` — CRUD de quadros, `findForUser($userId)` retornando quadros pessoais do usuário + quadros compartilhados onde ele é membro.
- `TaskColumn.php` — CRUD de colunas com método `reorder($boardId, $idsOrdenados)`.
- `TaskRecurrence.php` — CRUD da regra, método `calcularProximaData($dataAtual)` que aplica a lógica de cálculo descrita na seção de recorrência, e método `gerarProximaInstancia($taskConcluida)` que cria a nova `task` na coluna inicial do board.
- `TaskLink.php` — vincular/desvincular, `findByTask($taskId)` resolvendo o nome de cada elemento vinculado (chama `Contato`, `Processo`, `Card`, `DREAccount` conforme o tipo).
- `TaskChecklistItem.php`, `TaskComment.php`, `TaskHistory.php`, `TaskAttachment.php`, `TaskTimeEntry.php`, `TaskReminder.php` — cada um com seu CRUD básico.

## Endpoints API

Criar dentro de `public/api/` seguindo o padrão dos endpoints existentes (verificação de sessão, validação de CSRF em POST/PUT/DELETE, retorno JSON com `{ok: true/false, data, error}`):

- `tasks.php` — `GET ?board_id=&filtros` lista tarefas; `GET ?id=` retorna uma tarefa completa com vínculos, checklist, comentários, anexos e tempo; `POST` cria; `PUT` atualiza; `DELETE` arquiva (soft delete). Aceita ação especial `?action=move` para drag-and-drop e `?action=complete` para conclusão (que gatilha a recorrência).
- `task_boards.php` — CRUD de quadros + endpoint `?action=members` pra gerenciar membros.
- `task_columns.php` — CRUD + `?action=reorder`.
- `task_recurrences.php` — CRUD.
- `task_links.php` — `POST` vincula, `DELETE` desvincula, `GET ?task_id=` lista com nomes resolvidos.
- `task_checklist.php`, `task_comments.php`, `task_attachments.php`, `task_time_entries.php`, `task_reminders.php` — CRUD respectivo.
- `tasks_recurrence_tick.php` — endpoint interno chamado pelo cron, protegido por token configurado em `.env`/`config`. Varre regras ativas, garante criação das instâncias atrasadas e dispara lembretes pendentes (lê `task_reminders` com `enviado=0` e `lembrar_em <= NOW()`, dispara via canal configurado, marca como enviado). Para o canal `whatsapp`, usa o `EvolutionApiService.php` existente.

## Frontend

Criar `public/tarefas.php` (HTML estrutural + include do header/sidebar padrão) e `public/assets/tarefas.js` (lógica). Os estilos seguem o `design-system.css`/`yuris-theme.css` existentes; se precisar de estilos específicos do kanban, criar `public/assets/tarefas.css`.

A página tem três áreas. No topo, uma barra com seletor de quadros (dropdown ou abas horizontais com scroll), botão "+ Novo quadro", alternador de visão (Kanban / Lista / Calendário) e área de filtros (responsável, prioridade, prazo, busca textual). No corpo, a área principal renderiza a visão atual: no Kanban, colunas horizontais com scroll vertical interno em cada uma, drag-and-drop usando a mesma abordagem do `funnel.js`/`processos.js` (provavelmente HTML5 drag-and-drop nativo ou a biblioteca já usada); na Lista, tabela ordenável; no Calendário, grid mensal com células clicáveis.

Ao clicar em uma tarefa, abre um drawer lateral à direita, ocupando ~480px, com header (título editável inline, botões de concluir, arquivar e fechar) e abas internas. A aba Geral mostra descrição, prioridade, prazo (com seletor de tipo legal/interno/administrativo), responsável, recorrência (toggle que expande os campos de regra) e tags rápidas. A aba Checklist permite adicionar/marcar/remover itens. A aba Vínculos tem botões "+ Cliente", "+ Processo", "+ Card", "+ Conta DRE" que abrem busca/autocomplete e gravam o vínculo; vínculos existentes aparecem como chips clicáveis que navegam pro elemento. A aba Comentários é uma timeline com input no rodapé, suportando menção `@usuario`. A aba Anexos lista arquivos com upload por drag-and-drop. A aba Tempo mostra entradas registradas, botão "Iniciar timer" / "Parar timer" e total acumulado. A aba Histórico mostra a timeline de mudanças lida de `task_history`.

Criar tarefa pode ser inline (botão "+ Adicionar tarefa" no final de cada coluna, expande input simples só com título e ENTER cria) ou completa (botão "+ Nova tarefa" no topo abre o drawer já com formulário completo).

## Integrações com módulos existentes

A força do módulo está na integração. O **vínculo a processo** usa o `Processo.php` e a tabela `processos`; ao vincular, o select autocomplete busca por número CNJ ou nome do cliente. O **vínculo a cliente** usa `Contato.php`/`contatos`. O **vínculo a card** usa `Card.php`/`cards` permitindo amarrar tarefa a um lead específico do funil. O **vínculo a DRE** usa `DREAccount.php` para tarefas financeiras (ex.: "Conferir pagamento de honorário cliente X" amarra à conta correspondente).

Os **lembretes via WhatsApp** chamam o `EvolutionApiService.php` já implementado, enviando mensagem para o número do `responsavel_id` configurado em sua ficha de usuário. Os **comentários** podem ser estendidos no futuro pra notificar via chat interno (`chat_interno`) quando há menção. O **WebhookDispatcher.php** deve receber novos eventos: `task.created`, `task.updated`, `task.completed`, `task.due_soon`, `task.overdue` — assim integra com automações externas (Zapier, n8n).

Para o **dashboard** existente, adicionar widgets opcionais: "Tarefas de hoje", "Tarefas atrasadas" e "Tempo registrado na semana" — todos lendo de `tasks` e `task_time_entries`. Para o módulo **Jurídico**, adicionar dentro da tela de processo uma aba "Tarefas" que filtra `task_links` por processo, mostrando todas as tarefas amarradas àquele processo, com botão de criar nova já pré-vinculada.

## Boas práticas jurídicas embutidas

Três detalhes que diferenciam o módulo de um Trello genérico e o aproximam dos softwares jurídicos de referência (Clio, Projuris, Legalcloud, ADVBOX, Astrea). Primeiro, o campo `prazo_tipo` deve ser destacado visualmente no card: prazos do tipo `legal` aparecem com borda vermelha pulsante quando estão a menos de 48h do vencimento, prazos `administrativo` aparecem amarelos, e `interno` cinza neutro. Segundo, a prioridade segue uma paleta padrão: baixa em cinza `#94a3b8`, média em azul `#3b82f6`, alta em laranja `#f59e0b`, urgente em vermelho `#ef4444` — e essa cor aparece como faixa lateral no card. Terceiro, ao concluir uma tarefa que tem timer ativo ou tempo registrado, o sistema sugere automaticamente lançar esse tempo como honorário no DRE da conta vinculada ao cliente/processo (apenas sugere, não lança automaticamente).

## Ordem sugerida de implementação

Para o Claude Code construir em etapas testáveis, sugiro a seguinte sequência. Primeiro, criar a migration `013_create_tasks.sql` e rodar; conferir que as tabelas nascem corretas e o seed dos quadros padrão funciona. Depois, criar os Models PHP na ordem `TaskBoard`, `TaskColumn`, `Task`, `TaskRecurrence`, `TaskLink`, e os demais em sequência. Em seguida, implementar os endpoints API começando por `task_boards.php`, `task_columns.php` e `tasks.php` (com as ações básicas de listar, criar, mover, concluir) — neste ponto já dá pra testar via Postman. Depois construir o frontend mínimo: página `tarefas.php`, listagem de quadros, render do kanban com drag-and-drop e modal/drawer de criação e edição básicos. Em seguida, plugar checklist, vínculos, comentários e anexos. Depois implementar a lógica completa de recorrência (Model `TaskRecurrence`, endpoint `tasks_recurrence_tick.php`, gatilho no `complete()` do `Task`). Por fim, implementar time tracking, lembretes (sistema + WhatsApp), histórico, visões alternativas (Lista e Calendário) e integrações com Dashboard/Jurídico/Webhooks.

Cada etapa deve ser commitada separadamente pra facilitar revisão. Não é necessário criar testes automatizados nesta fase (o projeto não tem suíte de testes hoje), mas o Claude Code deve testar manualmente cada endpoint via cURL ou Postman antes de seguir, e cada interação de UI antes de marcar como pronta.

## Observações finais

Todos os textos de interface devem estar em português brasileiro. Datas devem ser formatadas em padrão BR (`dd/mm/aaaa HH:MM`). Permissões: usuário só pode editar/excluir tarefa se for o criador, o responsável, ou owner/editor do quadro compartilhado onde ela está; viewer só vê. Toda alteração relevante (mudança de coluna, conclusão, mudança de prazo, mudança de responsável) deve gerar entrada em `task_history`. Anexos devem ser armazenados em `public/uploads/tasks/{task_id}/` com nome sanitizado e validação de mime-type (proibir executáveis).
