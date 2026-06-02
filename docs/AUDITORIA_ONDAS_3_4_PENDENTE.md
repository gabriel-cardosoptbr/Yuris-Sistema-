# Handoff — Auditoria YURIS: Ondas 3 e 4 PENDENTES

> Continuação dos fixes da auditoria de 2026-06-01 (relatório completo:
> `docs/AUDITORIA_YURIS_2026-06-01.md`, 110 achados).
> Para retomar: diga "continuar Ondas 3+4 da auditoria".

## Já feito (em produção)
- **Onda 1** (commit `7789ba0`): segurança + LGPD + crítico Agente IA. DEPLOYED.
- **Onda 2** (commit `8a1eca8`): raiz matriz_id → account_vinculos no filtro Origem
  (dashboard.php, api/dashboard.php, processos.php, tarefas.php, master/advogados.php,
  master/search.php, WebhookPayloadBuilder.php). DEPLOYED.

## Convenções (lembrar)
- matriz↔filial via `account_vinculos` (accounts.matriz_id é SEMPRE NULL).
- tipos: matriz | filial | advogado. Nunca tratar "não-matriz" como filial.
- Deploy padrão: `git pull && docker exec yuris_app apache2ctl graceful` (sem rebuild).
- SSH: `C:\Users\11\Downloads\docker-server.pem` · ubuntu@ec2-56-126-106-120.sa-east-1.compute.amazonaws.com
- Cada deploy precisa de aprovação explícita do usuário.

---

## ONDA 3 — Completar feature cliente_id + pontes (raiz #2)

A migration 093 (processos.cliente_id) é write-only. Completar:

1. **Processo::list aceitar cliente_id** — `app/Models/Processo.php` ~linha 91:
   adicionar `if (isset($filters['cliente_id'])) { $sql .= ' AND p.cliente_id = :cliente_id'; $params['cliente_id'] = (int)$filters['cliente_id']; }`
   E em `public/api/processes.php` GET: ler `$_GET['cliente_id']` → filtro.

2. **clientes.php — bloco "Processos vinculados"** (espelhar prospeccao.php):
   no modal do cliente, GET `/api/processes.php?cliente_id=ID` e listar com link "Ver →".

3. **clientes.php — "Criar processo para este cliente"**: botão →
   `/processos.php?new_cliente_id=ID`; processos.js trata `new_cliente_id` chamando
   `_applySelection('cliente', ...)` (infra `_ensureVinculoData`/`_selecionarVinculo` já existe).

4. **clientes.php — tratar `?open=ID`** no init (espelhar prospeccao.php:3130):
   `URLSearchParams('open')` → `Clientes.openEditModal(id)` + history.replaceState.
   (Conserta o link "Ver ficha na aba Clientes" do vínculo de processo.)

5. **clientes em Tarefas (vínculo)** — task_link_search.php + task_links.php + tarefas.php:
   adicionar link_type 'cliente' (busca tabela clientes escopada por tenant);
   whitelist em task_links.php (~linha 47); resolveNome em TaskLink.php; botão na UI.
   OBS: já existe botão "contato" no backend de tarefas (tarefas.php:323) só faltando
   o <button data-type="contato"> na UI — avaliar reaproveitar.

6. **clientes em Chat (menções)** — `public/api/chat/mencoes.php`: adicionar 4º bloco
   buscando tabela clientes (tipo 'cliente', token '@[cli|id|nome]', url /clientes.php?open=).
   Estender enum chat_mencoes.tipo. (mencoes.php JÁ teve fix de tenant scope na Onda 1? NÃO —
   a Onda 1 mexeu em chat/mensagens.php, não mencoes.php. Ver achado chat_interno no relatório.)

7. **webhooks cliente.*** — `public/api/clientes.php`: após Cliente::create/update/archive,
   chamar WebhookDispatcher::fire($accountId, 'cliente.created'|'updated'|'deleted', ...).
   Os eventos já estão no catálogo, só não disparam.

## ONDA 4 — Advogado ≠ Filial (cor + filtros)

1. **CSS is-advogado** (cor própria, ex: verde/âmbar) — 3 arquivos de card strip:
   - `public/prospeccao.php` (~1849): mapear cls pelos 3 tipos + regra CSS .card-origin-strip.is-advogado (dark+light)
   - `public/clientes.php` (~941): stripCls 3 tipos + CSS .cli-card .origin-strip.is-advogado
   - `public/assets/tarefas.css`: .tk-card .origin-strip.is-advogado
   (O LABEL já foi corrigido pra ADVOGADO em commit 083a6e6; falta a COR.)

2. **Filtro Origem inclui advogado**:
   - prospeccao.php (~1928): `__filiais__` faz `tipo!=='filial' return false` → exclui advogado.
     Trocar semântica pra "não-matriz" OU adicionar `__advogados__`.
   - clientes.php (~482) e dashboard.php (~48): mesma classificação só matriz/filial.
   - optgroups "Filial específica" (prospeccao ~1166, etc): separar advogado num grupo próprio.

3. **Master selects rotulam advogado como [F]**:
   - master.php (~2385): `({matriz:'M',filial:'F',advogado:'A'}[a.tipo]||'?')`
   - master.php (~2548): viewAcc ícone/cor cobrir advogado (não binário isMatriz)
   - escritorios.php (~555): dropdown tipo incluir 'advogado'; (~1113) classe .badge-advogado

---

## Ondas NÃO selecionadas pelo usuário (backlog, no relatório)
- Onda 5 — bugs isolados (master openEditSub→openSubModal, TaskRecurrence unidade custom,
  whatsapp openGroupMembers, chat_interno URLs de menção, webhooks escopo+tabela, funil.php).
- Onda 6 — código morto / endpoints órfãos (task_attachments, task_reminders,
  account_notifications, consents, overview, etc — decidir plugar vs remover).
