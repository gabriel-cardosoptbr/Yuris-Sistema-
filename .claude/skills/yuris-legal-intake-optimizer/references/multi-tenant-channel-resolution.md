# Resolução de canal e isolamento multi-tenant

Como o Yuris decide, **no backend**, qual canal/instância/credencial usar, respeitando
matriz, filial e grants. O front nunca prova autorização.

## Fonte de verdade (nomes reais)

- `app/Services/WhatsAppChannelAccessService.php` — camada única de autorização de canal
  (deny-by-default).
- `app/Helpers/AccountContext.php` — contexto de conta/usuário/tenant.
- Tabelas: `whatsapp_instances` (o canal; `id`, `account_id` dono, `instance_name`,
  `status`, `phone`), `whatsapp_settings` (credenciais por `account_id`:
  `evolution_base_url`, `evolution_api_key`, `evolution_instance`),
  `whatsapp_channel_accounts` (grants), `account_vinculos` (vínculo matriz↔filial).

## Nunca confie no front

Não aceite `account_id`, `instance_name`, `token`, `api_key` ou `channel_id` vindos do
cliente como prova de autorização. O `channel_id` pode ser **sugerido** pelo front, mas é
**revalidado** no backend contra os canais que a conta pode ver. Instância, token e
credenciais são **sempre** resolvidos no backend, a partir do **dono do canal**.

## `WhatsAppChannelAccessService::resolveForRequest()`

Assinatura real:

```php
resolveForRequest(\PDO $pdo, int $accountId, $requestedChannelId, string $perm): array
// $perm ∈ {view, send, sync, delete_messages, manage}
// retorna: { channel_id, owner_account_id, instance_name, instance_row, cfg,
//            access_type ('owner'|'shared'), is_owner, shared }
```

Ordem de prioridade da resolução:

1. **Explícito**: se `requestedChannelId > 0`, valida contra `viewableChannelIds()`. Se
   não for visível para a conta, nega (anti-tampering).
2. **Próprio**: `ownChannelId()` — canal onde a conta é dona (padrão quando nada é pedido).
3. **Compartilhado**: só com a flag `WHATSAPP_SHARED_CHANNELS_ENABLED=true`, tenta
   `firstSharedChannelId()` (filial herdando o canal da matriz).
4. **Bootstrap**: se a conta não tem nenhum vínculo, materializa o canal próprio a partir
   de `getSettings($accountId)` (compat legado).

Depois de resolver, chama `assert()` no canal para o `$perm` pedido (403 + exit se negado).
As credenciais retornadas em `cfg` são **sempre as do dono** (`owner_account_id`), nunca as
da conta requisitante.

## Matriz, filial e grants

- O vínculo vive em `account_vinculos` (`matriz_account_id`, `filial_account_id`,
  `status='active'`, além de flags de sync granular). É a base de hierarquia.
- O compartilhamento de canal vive em `whatsapp_channel_accounts`:
  `channel_id`, `account_id`, `access_type ('owner'|'shared')`,
  `can_view`/`can_send`/`can_sync`/`can_delete_messages`/`can_manage`, `granted_by`,
  `revoked_at`. O **dono** tem todas as permissões; o **shared** recebe permissões
  granulares e só vale em runtime com a flag ligada.
- `can_manage` (conectar/QR/logout/webhook/excluir instância) e, por decisão de produto,
  `can_delete_messages` **nunca** são concedidos a filial pela tela do tenant. Ficam com a
  matriz.
- A matriz controla o compartilhamento por filial em Escritórios → Vínculos
  (`public/api/whatsapp/share.php`). O super admin pode pelo Master
  (`public/api/master/whatsapp_channels.php`). Mesma tabela, mesmas regras.

## Regra para o agente

- **Filial sem grant ativo não acessa o canal da matriz**, mesmo conhecendo o `channel_id`.
- **Filial autorizada** usa o canal compartilhado pela mesma regra de runtime
  (`resolveForRequest` com a flag ligada). O agente não cria atalho próprio.
- O agente é **1 por canal** (UNIQUE `uk_agent_instance` em
  `agent_configs.whatsapp_instance_id`). Numa conversa do canal, há no máximo um agente
  automático. A associação de filial diz quem **vê** a conversa, não muda quem o bot
  **é**: o bot sempre responde como o **dono do canal** (o número conectado).
- Escopo de canais vinculáveis na tela do agente:
  `AccountContext::getAccessibleAccountIds()` ∪ `getPipelineAccountId()` (próprios +
  filiais + canal herdado da matriz).

## Checklist de isolamento (para revisão e testes)

- Toda query do agente é escopada por canal/conta resolvidos no backend.
- Nenhum endpoint do agente devolve `evolution_api_key`/`evolution_base_url`/`webhook`/QR.
- `channel_id` do front é sempre revalidado (`viewableChannelIds`).
- Negativas usam resposta genérica (não revelam se o canal existe). Ver
  `WhatsAppChannelAccessService::deny()`.
- Sem flag, compartilhamento não vale em runtime (filial cai no próprio canal ou em
  "sem canal").
