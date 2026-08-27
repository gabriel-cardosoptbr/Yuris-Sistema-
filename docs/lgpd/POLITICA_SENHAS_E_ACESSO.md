# Política de Senhas e Controle de Acesso — Yuris

**Versão:** 1.0 — 2026-05-23
**Mantenedor:** DPO + Equipe Técnica
**Aplicação:** todos os usuários (colaboradores, administradores, super_admins) e prestadores que acessem a infraestrutura ou aplicação Yuris.

---

## 1. Princípios

- **Menor privilégio:** cada usuário recebe **apenas** os acessos estritamente necessários para sua função.
- **Need-to-know:** acessos a dados pessoais são justificados pela finalidade do papel.
- **Segregação de funções:** sempre que possível, separar quem aprova de quem executa (especialmente em pagamentos e mudanças de schema).
- **Rastreabilidade:** toda ação relevante é logada em `master_audit_log` ou `account_audit_log`.
- **Revisão periódica:** acessos são revisados trimestralmente.

## 2. Composição da senha

### 2.1 Requisitos mínimos
- **Comprimento:** mínimo 10 caracteres.
- **Composição:** 3 das 4 categorias abaixo:
  - Maiúsculas (A-Z)
  - Minúsculas (a-z)
  - Dígitos (0-9)
  - Símbolos especiais (`!@#$%^&*()_+-=[]{}|;:,.<>?`)
- **Vetadas:** palavras comuns (dicionário), padrões de teclado (qwerty, 1234), senhas previamente vazadas (idealmente checadas contra HaveIBeenPwned ou similar).
- **Únicas:** **proibido reutilizar** a mesma senha entre sistemas (Yuris vs. e-mail pessoal vs. outros SaaS).

### 2.2 Recomendado
- Uso de **gerenciador de senhas** (Bitwarden, 1Password, Keeper).
- Frases passphrase (4+ palavras aleatórias) atendem critério de força.

### 2.3 Armazenamento
- Senhas **NUNCA** são armazenadas em texto claro.
- Hashing **bcrypt** com cost mínimo 10 (PHP `password_hash` padrão).
- Hash + salt em `users.senha`.
- Senha em texto claro nunca aparece em logs, e-mails, mensagens, snapshots.

### 2.4 Recuperação
- Reset por token único de uso único, válido por 30 minutos.
- Notificação imediata ao e-mail registrado quando senha é alterada.
- Bloqueio temporário se houver 3+ resets em 24h (suspeita de fraude).

## 3. Autenticação multifator (MFA)

### 3.1 Obrigatório
- **super_admin** (Painel Master): MFA obrigatório (TOTP via Google Authenticator/Authy ou similar). Implementado em `TotpHelper`.
- Sem MFA, o acesso ao Painel Master é bloqueado.

### 3.2 Recomendado (fortemente)
- **Admin de tenant** (`role=owner|admin`): MFA recomendado — banner pede habilitação.
- **Usuários comuns:** MFA disponível, sem obrigatoriedade ainda.

### 3.3 Reset de MFA
- Apenas via canal verificado (DPO + comprovação de identidade).
- Tentativa de reset gera log em `master_audit_log` com severidade alta.

## 4. Sessões

- Sessão expira após **8 horas de inatividade** (configurável).
- Sessão fica **encerrada à força** (`AccountContext::assertAccountActive()`) se:
  - A conta do usuário for suspensa;
  - O usuário for desativado;
  - Houver mudança em permissões críticas (ex.: revogação de role).
- Token de sessão regenerado no login (mitigação session fixation).
- Cookie de sessão com flags `HttpOnly`, `Secure` (em produção), `SameSite=Lax`.

## 5. Tentativas falhas e bloqueio

- Registradas em `login_attempts` (IP, e-mail tentado, timestamp, sucesso).
- Após **5 falhas** em 15 minutos, o IP fica bloqueado por **30 minutos** (rate limiting).
- Múltiplos IPs falhando contra a mesma conta dispara alerta interno (suspeita de credential stuffing).
- Brute-force massivo contra `/master_login.php` aciona aba de Incidentes (severidade alta).

## 6. Papéis e níveis de acesso

### 6.1 Hierarquia
| Papel | Escopo | Acessos típicos |
|-------|--------|------------------|
| **super_admin** | Toda a plataforma (cross-tenant) | Painel Master: contas, planos, faturas, incidentes, operadores, LGPD, auditoria geral. **MFA obrigatório.** |
| **owner** | 1 tenant (matriz) + filiais vinculadas | Tudo do seu tenant + administração de filiais + faturas (somente leitura). |
| **admin** | 1 tenant | Administração do tenant: criar usuários, configurar módulos, ver finanças. |
| **gestor** | 1 tenant | Acesso operacional + relatórios; não cria usuários. |
| **usuario** | 1 tenant | Operação diária: cards, processos, tarefas, conversas. |
| **advogado_associado** | Vinculado a 1+ tenants por `resource_shares` | Acessa apenas processos/cards onde foi convidado. |

### 6.2 Provisão
- Criação de usuário **somente** por owner/admin do tenant ou super_admin (via Painel Master).
- E-mail e telefone obrigatórios.
- Senha inicial gerada pelo sistema (única) ou definida pelo usuário em primeiro acesso via link único.

### 6.3 Revisão
- Trimestralmente: cada gestor revisa a lista de usuários ativos do seu tenant e desativa os que não estão mais com função relacionada.
- Anualmente: o DPO faz amostragem cruzada (RH × lista de usuários ativos).

## 7. Acesso a banco de dados

- **DBA / acesso direto** (CLI, GUI tipo DBeaver) — exclusivamente para equipe técnica autorizada por escrito.
- Credenciais de banco em `.env`, nunca commitadas (`.gitignore`).
- Conexões da aplicação usam usuário com permissões mínimas (sem `DROP`, `GRANT`, `ALTER USER`).
- DBA em produção: acesso justificado, registrado, com revisão por outro membro da equipe.

## 8. Acesso a logs e auditoria

- Logs de aplicação (`error_log`) acessíveis apenas pela equipe técnica.
- Logs de auditoria (master_audit_log, etc.) acessíveis pelo DPO + super_admin via Painel Master.
- Tabelas de audit são **imutáveis no nível do banco** (triggers SIGNAL 45000 — migration 053).

## 9. Tokens e chaves

- **CRON_TOKEN** — secret no `.env`, único, ≥ 32 chars aleatórios. Sem fallback hardcoded.
- **CSRF_TOKEN** — regenerado por sessão; obrigatório em POST/PATCH/DELETE.
- **MFA_ENCRYPTION_KEY** — chave AES-256 para cifrar 2FA secrets e api_keys em repouso. **NUNCA versionada.**
- **Chaves de operadores** (Evolution API, LLM) — cifradas em repouso (`TotpHelper::encryptSecret`).
- **Tokens de webhook** — secretos próprios por integração, rotacionáveis.

### 9.1 Rotação obrigatória
- Em caso de incidente: rotação imediata.
- Anual: revisão de todas as chaves; rotação de pelo menos as classificadas como críticas.
- Rotação registrada em `master_audit_log`.

## 10. Acesso remoto / VPN

- Painel Master deve idealmente ser acessado de IPs allowlisted (a configurar em produção via Apache).
- VPN recomendada para equipe técnica acessando ambientes produtivos.
- Acesso via redes públicas (cafés, aeroportos) **proibido** para operações administrativas sensíveis.

## 11. Princípios de codificação segura (para a equipe técnica)

- **Sempre prepared statements** (PDO), nunca concatenação de variáveis em SQL.
- **Sempre validar input** com allowlists, não denylists.
- **Sempre usar `htmlspecialchars` ou helpers de escape** ao exibir input do usuário em HTML.
- **Nunca commitar segredos** — `.env` está no `.gitignore`; pre-commit hook recomendado para detectar.
- **CSRF token** em todos os POST/PATCH/DELETE.
- **Filtrar `account_id`** em toda query que envolva dado de tenant (isolamento multi-tenant).

## 12. Saída do colaborador (offboarding)

Detalhado em `PROCEDIMENTO_ONBOARDING_OFFBOARDING.md`. Resumindo:
- **Mesmo dia** do desligamento: desativar `users.ativo = 0`, encerrar sessões ativas.
- **Em até 24h**: revogar acessos a banco, repositórios, e-mail, ferramentas.
- **Em até 48h**: rotação de credenciais compartilhadas (se aplicável).

## 13. Conformidade

- Esta política atende aos requisitos de **segurança** (LGPD Art. 46) e **boas práticas** (Art. 50).
- Auditorias internas verificam aderência trimestralmente.

## 14. Revisão

Anual ou após mudanças significativas em controles de acesso. Próxima revisão prevista: **2027-05-23**.
