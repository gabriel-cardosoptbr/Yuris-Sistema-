# Fase 2 — Compartilhamento de canal WhatsApp (matriz → filial)

**Pacote de entrega (Parte F).** Branch `feature/whatsapp-shared-channels`.
**Status: NÃO deployado. Feature flag `WHATSAPP_SHARED_CHANNELS_ENABLED=false`.**
Aguardando autorização expressa para merge / ativar flag / deploy.

Data: 2026-06-19.

---

## 1. Resumo executivo

A filial passa a poder usar o WhatsApp da matriz por **autorização explícita de
canal**, e não por relaxar o isolamento multi-tenant. Nenhum acesso é inferido de
hierarquia em tempo de requisição: cada acesso exige um registro explícito em
`whatsapp_channel_accounts`, e tudo passa por uma **camada única de autorização**
(`WhatsAppChannelAccessService`), com **negação por padrão**.

O comportamento só muda quando a flag `WHATSAPP_SHARED_CHANNELS_ENABLED` estiver
ligada. Com a flag desligada (estado atual), o isolamento é idêntico ao de antes:
cada conta só enxerga o próprio canal.

---

## 2. Modelo de segurança

- **Camada única** `WhatsAppChannelAccessService`. Todos os endpoints de dados
  passam por ela. Não há 14 implementações de checagem — há uma.
- **Deny-by-default.** Sem registro ativo em `whatsapp_channel_accounts` → sem acesso.
- **`channel_id` = `whatsapp_instances.id`.** Nunca se confia em
  `instance_name`/`account_id`/`channel_id` vindos do front sem validar contra um
  grant ativo.
- **Instância e credenciais resolvidas no backend, sempre do DONO do canal.**
- **`access_type`:** `owner` (todas as permissões) | `shared` (granular).
- **Permissões:** `can_view`, `can_send`, `can_sync`, `can_delete_messages`, `can_manage`.
- **`manage` é exclusivo do dono.** Conexão / QR / logout / restart / exclusão /
  webhook / credenciais nunca são concedidos a uma conta compartilhada — nem que a
  coluna esteja marcada (o código força).
- **`getPipelineAccountId()` não é usado em runtime.** Hierarquia só é validada na
  CONCESSÃO do vínculo (em `create_filial` e no endpoint Master).
- **Resolução de canal:** sem `channel_id` do request → o backend resolve o canal
  próprio; filial com herança ativada (e flag ligada) resolve o canal compartilhado;
  acesso só com grant ativo.

### Modos de WhatsApp da filial (no `+Filial`)

- **`matriz` (herdar, padrão):** registro `shared` apontando para o canal da matriz
  (view+send+sync; sem delete/manage). Só vale com a flag ligada.
- **`propria`:** provisiona canal próprio (owner). Sem acesso ao da matriz.
- **`depois`:** nada (pendente).

---

## 3. Migration / schema

`database/migrations/096_whatsapp_channel_accounts.sql` + `run_096.php` (idempotente):

Tabela `whatsapp_channel_accounts`:
`id`, `channel_id` (FK → `whatsapp_instances.id` ON DELETE CASCADE),
`account_id` (FK → `accounts.id` ON DELETE CASCADE),
`access_type` ENUM('owner','shared'),
`can_view`, `can_send`, `can_sync`, `can_delete_messages`, `can_manage` (TINYINT default 0),
`granted_by`, `created_at`, `revoked_at` (NULL = ativo).
UNIQUE `(channel_id, account_id)`. Índices por account/channel/ativo.
Backfill: toda instância existente recebe a linha de DONO (owner, full perms).

> Em produção, rodar antes de ativar a flag:
> `docker exec -i yuris_app php /var/www/html/database/migrations/run_096.php`

---

## 4. Arquivos alterados (21 arquivos; +1034 / -212)

```
.env.example                                        (flag)
app/Services/WhatsAppChannelAccessService.php       (camada única — novo)
app/Services/WhatsAppProvisioningService.php        (grant de dono ao provisionar)
database/migrations/096_whatsapp_channel_accounts.sql + run_096.php  (novos)
public/api/master/create_filial.php                 (whatsapp_mode)
public/api/master/whatsapp_channels.php             (grant/revoke — novo, Parte D)
public/api/whatsapp/instances.php                   (view/manage)
public/api/whatsapp/send.php                        (send + sanitização de erro)
public/api/whatsapp/media_upload.php                (send + channel_id na resposta)
public/api/whatsapp/messages.php                    (view)
public/api/whatsapp/chats.php                        (view; delete = delete_messages)
public/api/whatsapp/media.php                        (view; cfg do dono; debug owner-only)
public/api/whatsapp/sync.php                         (sync)
public/api/whatsapp/refresh_chat.php                 (sync)
public/api/whatsapp/discover.php                     (sync)
public/api/whatsapp/reaction.php                     (send)
public/api/whatsapp/message_action.php               (delete_messages; escopo requisitante)
public/api/whatsapp/contacts.php                     (view; fetch_pic escopado)
public/api/whatsapp/group_members.php                (view)
public/api/whatsapp/contato_vinculos.php             (gate view)
```

Commits: `e364670` (fundação) · `f62555a` (B-backend) · `b959f63` (delete_messages) ·
`27e1096` (C) · `7429b13` (D) · `99f90a1` (E).

---

## 5. Matriz de autorização por endpoint

| Endpoint | Método / ação | Permissão exigida | Observações |
|---|---|---|---|
| `instances.php` | GET status / list / default | `view` | papel owner/admin ainda exigido p/ list/default |
| `instances.php` | connect / qr / restart / logout / set_webhook / create | `manage` | exclusivo do dono |
| `send.php` | POST | `send` | erro da Evolution sanitizado |
| `media_upload.php` | POST | `send` | retorna `channel_id` resolvido |
| `reaction.php` | POST | `send` | account_id da reação = dono |
| `message_action.php` | POST delete | `delete_messages` | exclusão escopada à **conta requisitante** |
| `messages.php` | GET | `view` | |
| `chats.php` | GET + mark/archive/pin/link/set_team | `view` | |
| `chats.php` | POST delete (conversa) | `delete_messages` | mais estrito que view |
| `media.php` | GET | `view` | cfg do dono; 404 anti-enum; `debug` só dono |
| `contacts.php` | GET + fetch_pic + edit | `view` | fetch_pic escopado por instance_id |
| `group_members.php` | GET | `view` | |
| `contato_vinculos.php` | GET | `view` (gate) | resolução de contato no CRM próprio |
| `sync.php` / `refresh_chat.php` / `discover.php` | — | `sync` | dados gravados sob o dono |
| `config.php` | POST | super_admin | infra própria da conta (inalterado) |
| `whatsapp_channels.php` (Master) | grant / revoke | super_admin + master_mode + CSRF | hierarquia validada na concessão |
| `webhook.php` | inbound | N/A | identifica tenant pela apikey da instância (dono) |
| `agent_instances` / `agent_takeover` | — | escopo próprio (pré-existente) | fora da camada de canal — ver riscos residuais |

---

## 6. Testes e resultados (local)

| Bateria | Resultado |
|---|---|
| **17 cenários de segurança do spec** (camada de autorização) | **20/20** |
| Resolução de canal (Parte C: owner / shared / flag / bootstrap / anti-tamper) | 20/20 |
| Grant/revoke + gate de hierarquia (Parte D) | 10/10 |
| Revoke idempotente + proteção do dono | 4/4 |
| Exclusão de mensagem (dono apaga; filial não apaga a da matriz) | 4/4 |
| `php -l` em todos os arquivos tocados | OK |

Pontos provados pela bateria dos 17: deny-by-default; dono libera tudo; flag OFF →
filial herdeira não vê o canal da matriz; flag ON → libera só view/send/sync;
`delete_messages` desligado por padrão p/ filial; `manage` exclusivo do dono mesmo
com `can_manage=1` forçado no banco; credenciais/instância sempre do dono;
anti-tampering por `channel_id`; sem cross-tenant nas listagens; `grant()` força
`can_manage=0` p/ shared; revoke nunca toca o dono; grant revogado nega em runtime;
bootstrap dá canal próprio (nunca alheio).

### Revisão adversarial (multi-agente)
- Parte D (endpoint Master): 13 achados → corrigidos os reais (revoke rowCount +
  só shared; dono soft-deleted; viewer bloqueado; granted_by removido).
- Parte E (14 endpoints de dados): 19 achados, 3 confirmados reais → corrigidos
  (message_action escopo; contacts fetch_pic escopo; send.php sanitização) +
  hardening (instances list whitelist; media debug owner-only; media_upload channel_id).

---

## 7. Riscos residuais (aceitos / a decidir)

1. **Super admin gere todas as contas.** Não há escopo por conta no Painel Master
   (vale para todos os endpoints master, não só este). O nível `viewer` foi
   bloqueado nas mutações de canal, mas isso não é uma política system-wide.
2. **Autorização de runtime é baseada no GRANT, não no vínculo vivo.** Se um vínculo
   matriz↔filial for suspenso, o acesso compartilhado persiste até ser **revogado**
   no Painel Master. (Decisão de design: o grant é a fonte de verdade em runtime.)
3. **`can_delete_messages` para conta compartilhada é efetivamente inerte.** Como
   toda mensagem do canal grava `account_id` = dono da instância, uma filial não
   casa o escopo e não apaga nada (seguro). Exclusão granular por sub-conta exigiria
   rastrear o autor (mudança de schema) — fora do escopo desta fase.
4. **`agent_instances` / `agent_takeover`** seguem o escopo pré-existente
   (`getPipelineAccountId`), são somente-leitura/operacionais e sanitizados; não
   foram migrados para a camada de canal.
5. **`chats.php` ação `link`** valida card/usuário pelo escopo CRM M→F pré-existente
   (auditado na LGPD), ortogonal ao compartilhamento de canal.
6. **TOCTOU na concessão** (vínculo suspenso entre validar e gravar): ação manual de
   admin, recuperável por revoke. Não mitigado com transação/lock.

---

## 8. Rollout (quando autorizado)

1. `git -C /home/ubuntu/Yuris-Sistema- pull --ff-only` (após merge na main).
2. Rodar a migration 096 em produção (idempotente).
3. **Manter a flag desligada** e validar que tudo segue como antes (isolamento).
4. Conceder um compartilhamento de teste (conta de homologação) via Painel Master.
5. Ligar `WHATSAPP_SHARED_CHANNELS_ENABLED=true` e validar a herança.
6. `docker exec yuris_app apache2ctl graceful`.

Rollback: desligar a flag (volta ao isolamento por canal próprio) sem reverter código.

---

## 9. Falta (não bloqueia a revisão)

- **Parte B (frontend):** 3 radios `whatsapp_mode` (herdar matriz [padrão] / própria
  / configurar depois) no modal `+Filial` do `master.php` (o backend já honra).
  Opcional: aba "Compartilhamento de canais" no Master consumindo
  `whatsapp_channels.php` (grant/revoke + lista).

---

## 10. Pendência

**Nenhum deploy foi feito. A flag está desligada.** Aguardando autorização expressa
para: (a) merge na main, (b) rodar a migration em produção, (c) ligar a flag.
