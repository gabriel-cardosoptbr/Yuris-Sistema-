# Integração AASP — Documentação técnica e comercial

> Referência completa do módulo de integração com a API de Intimações da AASP
> (Associação dos Advogados de São Paulo). Atualizada em **2026-05-25**.

---

## 1. Visão geral comercial

### O que é

O Yuris oferece **integração nativa com a API oficial de Intimações da AASP**
para advogados e escritórios que já possuem associação ativa. Ao configurar
sua chave AASP no Yuris (uma única vez, via admin do tenant), o sistema:

- **Sincroniza automaticamente** as intimações da chave AASP a cada 2h (configurável)
- **Permite busca por período** (até 30 dias por consulta)
- **Renderiza no mesmo feed** das publicações do DJEN nacional, lado a lado
- **Mantém status individual por usuário** (lida/favorita/com prazo/comentário)
- **Vincula a processos do CRM** e **gera tarefas/prazos** a partir da intimação

### O que NÃO é

- ❌ Não é parceria oficial nem produto da AASP — é uma integração técnica
  com a **API pública oficial** para associados habilitados
- ❌ Não substitui a assinatura AASP do associado — você ainda precisa ter
  conta ativa lá pra obter a chave
- ❌ Não copia marca, identidade visual ou layout proprietário da AASP

### Linguagem de marketing aprovada

✅ "Integração com API de Intimações AASP para associados habilitados"
✅ "Conecte sua chave AASP e receba intimações automaticamente"
✅ "Compatível com chave individual de associado e chave de empresa (sociedade)"

❌ Não usar: "Sistema oficial AASP" · "Parceria oficial AASP" · "Powered by AASP"
   · "Yuris by AASP" · qualquer reivindicação de endosso

---

## 2. Como obter chave AASP

1. Ser associado AASP em dia
2. Acessar https://intimacaoapi-cadastro.aasp.org.br/
3. Solicitar chave de API (gratuito para associados)
4. Receber 1 dos 2 tipos de chave:
   - **Associado**: 1 chave individual = 1 advogado
   - **Empresa**: 1 chave de escritório + lista de códigos AASP de associados vinculados

---

## 3. Arquitetura técnica

### Provider e endpoints

`app/Services/Push/AaspProvider.php` implementa `ProviderInterface` (mesmo
padrão do `DjenProvider`). Base URL configurável via `.env` `AASP_BASE_URL`,
default `https://intimacaoapi.aasp.org.br`.

**Endpoints da AASP usados:**

| Endpoint | Função |
|----------|--------|
| `GET /api/Associado/intimacao/json` | Intimações de 1 dia (chave Associado) |
| `GET /api/Associado/intimacao/GetJornaisComIntimacoes/json` | Dias com intimação nos últimos N dias |
| `GET /api/Empresa/intimacao/json` | Intimações de 1 dia (chave Empresa + código associado) |
| `GET /api/Empresa/intimacao/GetJornaisComIntimacoes/json` | Idem para Empresa |

Autenticação: query param `?chave=<credencial>`. Sem header Bearer.

### Schema mapeado (descoberto empiricamente)

Campos da AASP → schema normalizado do Yuris:

| AASP (raw) | Yuris (normalizado) |
|------------|---------------------|
| `jornal.nomeJornal` (ex: "DJENTJMG") | `tribunal` (ex: "TJMG") |
| `jornal.dataDisponibilizacao_Publicacao` | `data_disponibilizacao` |
| `numeroUnicoProcesso` | `numero_processo_mascara` |
| Dígitos de `numeroUnicoProcesso` | `numero_processo` |
| Regex `Órgão:` em `textoPublicacao` | `orgao` |
| `cabecalho` (sem `\r\n`) | `tipo_comunicacao` |
| `textoPublicacao` (limpo inline) | `conteudo` |
| `codigoRelacionamento` | `hash_externo` |
| `numeroPublicacao` | `numero_comunicacao` |
| Regex `Advogado(s)` + OAB | `advogados[]` (nome, oab, uf) |
| Regex `Parte(s):` | `destinatarios[]` (nome) |

### Tabelas

- `aasp_integrations` — credencial cifrada + estado da integração
- `aasp_credential_audit` — auditoria imutável de eventos (created/rotated/
  tested/revoked/sync_ok/sync_fail)
- Reuso: `push_today_cache`, `push_events`, `push_event_user_status`,
  `push_query_logs` (todos com `source_id='aasp'`)

### Segurança da credencial

- Cifrada via `app/Helpers/Crypto.php` (AES-256-GCM, versionado `v1:iv:ct:tag`)
- Chave mestre em `.env` `APP_ENCRYPTION_KEY` (32 bytes em base64/hex/raw)
- Mascarada na UI como `****XXXX` (4 últimos chars apenas)
- URL com chave **sempre mascarada** em logs (`?chave=***MASKED***`)
- Decifragem só em RAM durante a chamada HTTP (`unset` imediato depois)

### Multi-tenant

- `account_id NOT NULL` em ambas tabelas
- Models filtram `account_id` em todo SELECT/UPDATE/DELETE
- `getChavePlain($id, $accountId)` exige ambos — impossível IDOR cross-tenant
- `dueNow()` (uso cron) é cross-tenant intencionalmente

---

## 4. Endpoints internos do Yuris

| Método | Endpoint | Função | Auth |
|--------|----------|--------|------|
| GET | `/api/aasp/integrations.php` | Lista integrações do tenant | Sessão |
| GET | `/api/aasp/integrations.php?id=N` | 1 integração | Sessão |
| POST | `/api/aasp/integrations.php` | Cria integração | Owner/Admin |
| PATCH | `/api/aasp/integrations.php` | Update meta ou rotação de chave | Owner/Admin |
| DELETE | `/api/aasp/integrations.php` | Remove (apaga credencial cifrada) | Owner/Admin |
| POST | `/api/aasp/test.php` | Testa chave sem persistir | Owner/Admin |
| POST | `/api/aasp/sync.php` | Sincroniza 1 dia (default: hoje) | Sessão |
| POST | `/api/aasp/search.php` | Busca por período (fan-out com fallback) | Sessão |
| GET | `/api/push/tick.php?token=X` | Cron — chama AaspSyncRunner | Token |

---

## 5. Fluxo de uso

### Cadastro inicial (admin)

1. Vai em **Intimações → Integração AASP → "+ Conectar chave AASP"**
2. Preenche nome, escolhe Associado ou Empresa, cola a chave
3. Clica **"Testar conexão"** com data de teste (ex: última sexta)
4. Se OK, clica **"Salvar"** — integração nasce como `active` direto
5. Cron começa a sincronizar a cada 2h automaticamente

### Sincronização manual

- Botão **Sincronizar** no card da integração — puxa o dia de hoje (modo
  `diferencial=false` — traz tudo)

### Busca por período

- Define período no filtro lateral, clica **"Buscar publicações"**
- Sistema chama `GetJornaisComIntimacoes` (otimização: evita dias vazios)
- Se vier vazio, faz fallback consultando dia-a-dia (até 30 dias)
- Resultados aparecem **em tempo real, não persistem** (igual DJEN)
- Salva apenas se o user interagir (favoritar/lida/prazo/comentário)

### Cron automático

- Modo `diferencial=true` — AASP retorna só intimações que essa chave **ainda
  não consultou** (incremental nativo)
- Roda a cada 10min (via `tick.php`), processa integrações com `proxima_sync_em <= NOW()`
- Notificação interna pro `created_by` quando vier item novo

---

## 6. Limitações conhecidas

| Limitação | Origem | Mitigação no Yuris |
|-----------|--------|-------------------|
| 1 data por chamada | API AASP não aceita range | Fan-out (1 chamada por dia) com cap 30 dias |
| Modo Empresa exige `codigoPessoaAssociado` | API AASP retorna `400` sem | Validação local antes; suporta múltiplos códigos com fan-out |
| Chave aparece em URL (query) | API AASP usa query param | Mascaramento de URL em logs do Yuris (`?chave=***MASKED***`) |
| Sem rate limit declarado | AASP não documenta | Pausa defensiva de 1s entre chamadas (`AASP_RATE_LIMIT_MS`) |
| Chave longa (>256 chars) → HTTP 500 com body vazio | API AASP bug | Mensagem clara no Yuris: "chave mal-formada" |
| `GetJornaisComIntimacoes` pode subnotificar | API AASP comportamento | Fallback dia-a-dia obrigatório quando vier vazio |
| Sem endpoint de "marca lida" | API AASP só leitura | Status lida/não lida 100% no Yuris (`push_event_user_status`) |

---

## 7. Troubleshooting

### "Falhou: AASP: Chave de acesso incorreta"
A chave foi rejeitada. Confirme em https://intimacaoapi-cadastro.aasp.org.br/
que está ativa. Se for chave de Empresa, confirme que pelo menos 1
`codigoPessoaAssociado` está cadastrado.

### "AASP retornou 401 (não autorizado)"
Chave tem formato válido mas expirou ou foi revogada. Renove na AASP e
**Trocar chave** (ícone de chave no card).

### "AASP retornou 500 com body vazio"
Causa típica: chave malformada (espaço, aspas, prefixo extra). Cole **só
a chave**, sem texto adicional.

### "0 intimação(ões) · 0 de 7 dias com intimação"
A chave está OK mas a AASP não tem intimações registradas pra ela nesse
período. Verifique no portal AASP se o monitoramento está configurado
(quais OABs/processos serem monitorados).

### Cards aparecem vazios após sincronizar
Schema da AASP mudou. Abre modal **Trocar chave** + **Testar conexão** e
expande "Diagnóstico: campos raw" — mande print pro time técnico ajustar
o `normalize()`.

---

## 8. LGPD

### Operador registrado

AASP entrou em `data_processors` via migration 065:

- **Papel**: operador (AASP recebe OAB pra retornar intimações)
- **Base legal**: consentimento (admin do tenant configura voluntariamente)
- **Transferência internacional**: ❌ (servidor AASP em BR)
- **DPA status**: dispensado (sem contrato bilateral Yuris-AASP — cada
  tenant aceita termos da AASP individualmente)

### Anonymizer

`Anonymizer::pushAccountPurge($accountId)` apaga em cascata:
- `aasp_integrations` (incluindo `chave_encrypted`)
- `aasp_credential_audit`

`Anonymizer::pushUserStatus($userId)` zera comentários do user em
`push_event_user_status` (já existia, cobre AASP automaticamente porque
push_event_user_status é cross-source).

### Auditoria

- `aasp_credential_audit` registra eventos `created`/`rotated`/`tested`/
  `revoked`/`error`/`sync_ok`/`sync_fail` por integração — sem nunca
  expor a credencial em si
- `push_query_logs` registra cada chamada à AASP com URL mascarada
- `master_audit_log` registra tick do cron

---

## 9. Arquivos do módulo

```
database/migrations/
  064_aasp_integration.sql      — tabelas aasp_*
  065_aasp_data_processor.sql   — registro como operador LGPD

app/Helpers/
  Crypto.php                    — AES-256-GCM
  Anonymizer.php                — cobre aasp_* na purge

app/Models/
  AaspIntegration.php           — CRUD multi-tenant + audit

app/Services/Push/
  AaspProvider.php              — implementa ProviderInterface
  AaspSyncRunner.php            — cron processa integrações vencidas

public/api/aasp/
  integrations.php              — CRUD HTTP
  test.php                      — teste de chave (sem persistir)
  sync.php                      — sincroniza 1 dia
  search.php                    — busca por período (fan-out + fallback)

public/api/push/
  tick.php                      — cron principal (DJEN + AASP)

public/intimacoes.php           — UI (aba AASP + modal)
public/assets/intimacoes.js     — handlers JS
```

---

## 10. Configuração (.env)

```bash
# Cifra at-rest de credenciais (AASP, futuros)
APP_ENCRYPTION_KEY=base64:<32 bytes em base64>

# Base AASP (raro precisar mudar)
AASP_BASE_URL=https://intimacaoapi.aasp.org.br

# Pausa entre chamadas (defensivo anti-flood)
AASP_RATE_LIMIT_MS=1000

# Cap dias por busca manual (defensivo anti-timeout)
AASP_MAX_DAYS=30
```

Gerar `APP_ENCRYPTION_KEY` em produção:
```bash
openssl rand -base64 32
```
Cole prefixado com `base64:`.

---

## 11. Cron de produção (Windows)

```cmd
schtasks /Create /SC MINUTE /MO 10 /TN "Yuris-IntimaTick" ^
  /TR "curl -s http://localhost/sistema_vendas/public/api/push/tick.php?token=<SEU_CRON_TOKEN>"
```

Linux (cron):
```cron
*/10 * * * * curl -s "http://yuris.local/sistema_vendas/public/api/push/tick.php?token=$CRON_TOKEN" > /dev/null 2>&1
```

---

## 12. Validação empírica

Schema mapeado a partir de chave real (Bruno Carreira Ferreira, OAB SP-357838)
em **2026-05-24** com 3 tribunais distintos retornando estruturas similares:

- **TJMG** (4ª Câmara Cível Especializada)
- **TJSP** (Foro de Santo André - 2ª Vara Cível)
- **TST** (7ª Turma)

Todos os campos do `normalize()` confirmados em produção local.

---

## 13. Próximos passos sugeridos

- [ ] Configurar `schtasks` em produção
- [ ] Dashboard com métricas de uso (chamadas/dia, integrações ativas,
      erros por tenant) no Painel Master
- [ ] Webhook configurável: notificação externa quando vier intimação
      via integração AASP (Slack/Email/WhatsApp)
- [ ] Multi-integração na busca por período (atualmente usa só a 1ª
      ativa — adicionar dropdown se tenant tiver várias)
- [ ] Compactação automática de cache > 30 dias (já tem TTL diário,
      mas podemos retomar)
