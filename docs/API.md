Endpoints principais (public/api/)

Observação: Muitos endpoints retornam/aceitam JSON e usam sessão/CSRF para autenticação de usuário.

Endpoints relevantes:
- api/cards.php — CRUD de cards (cards do funil).
- api/columns.php — CRUD e ordenação de colunas do pipeline.
- api/card_checklist.php — Gerenciamento de checklist por card.
- api/dashboard.php — Dados do dashboard (métricas e gráficos).
- api/dashboard_settings.php — Salva preferências de período do dashboard (session).
- api/dre_accounts.php — CRUD de contas DRE.
- api/dre_codes.php — CRUD de códigos DRE.
- api/goals.php — Metas do usuário (se aplicável).
- api/users.php — CRUD de usuários (apenas para admins).

Agente (novo)
- api/agent_settings.php — GET/POST para carregar e salvar as configurações do agente de IA.
  - Persistência atual: sessão de usuário ($_SESSION['agent_settings']).
  - GET: retorna objeto com {name, enabled, whatsapp_number, provider, api_key, prompt}.
  - POST: aceita JSON com os mesmos campos e grava na sessão.

Sugestão: Persistir em banco de dados (tabela settings) para configurações permanentes por tenant/empresa.
