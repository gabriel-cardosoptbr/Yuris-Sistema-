# Deploy do Assistente de Pré-Atendimento Jurídico — checklist pronto

Branch: `feature/ai-intake-agent` (commits `e018fe2`, `95c0d97` + vendor skills + este doc).
**NÃO executar sem aprovação explícita.** Não toca containers Evolution/n8n. Nenhuma segunda
instância da Evolution é criada.

Infra (memória): SSH `~/Downloads/docker-server.pem`, host
`ec2-56-126-106-120.sa-east-1.compute.amazonaws.com` (user `ubuntu`), repo do servidor
`/home/ubuntu/Yuris-Sistema-`, container `yuris_app`, remote
`github.com/gabriel-cardosoptbr/Yuris-Sistema-`.

---

## 0. Pré-deploy (local)
```bash
cd /c/xampp/htdocs/sistema_vendas
git status                       # working tree limpo, na branch feature/ai-intake-agent
git log --oneline -4             # confere os commits do agente
```

## 1. Publicar e mesclar em main
```bash
git push origin feature/ai-intake-agent
# Revisar e mesclar em main (PR ou local):
git checkout main && git pull --ff-only origin main
git merge --no-ff feature/ai-intake-agent
git push origin main
```

## 2. Atualizar o servidor
```bash
ssh -i ~/Downloads/docker-server.pem ubuntu@ec2-56-126-106-120.sa-east-1.compute.amazonaws.com \
  "git -C /home/ubuntu/Yuris-Sistema- pull --ff-only"
```

## 3. Migrations (idempotentes — seguras de reaplicar)
```bash
ssh -i ~/Downloads/docker-server.pem ubuntu@<host> \
  "docker exec -i yuris_app php /var/www/html/database/migrations/run_097.php"
ssh -i ~/Downloads/docker-server.pem ubuntu@<host> \
  "docker exec -i yuris_app php /var/www/html/database/migrations/run_098.php"
```
Esperado: 097 cria/garante `agent_configs` estendido + 7 tabelas `ai_*` + 35 áreas + prompt v1;
098 ativa o prompt v2. A saída do 098 deve terminar com `versao ativa agora: v2`.

## 4. Recarregar o PHP
```bash
ssh -i ~/Downloads/docker-server.pem ubuntu@<host> "docker exec yuris_app apache2ctl graceful"
```

## 5. Smoke pós-deploy (sem credencial)
```bash
curl -s -o /dev/null -w "%{http_code}\n" https://yuris.com.br/api/master/ai_prompt.php   # 302/403 sem sessao = ok (gate)
curl -s -o /dev/null -w "%{http_code}\n" https://yuris.com.br/api/master/ai_openai.php    # idem
curl -s -o /dev/null -w "%{http_code}\n" https://yuris.com.br/agente.php                  # 200/302
```
(Site no ar; endpoints Master devem barrar sem sessão master — nada de 500.)

## 6. Configurar (no Painel Master, super admin)
1. Master → aba **WhatsApp** → card **Security Key da OpenAI** → colar `sk-...` → **Validar** → **Salvar**.
2. (Opcional) revisar o **Prompt do Agente de IA** (logo abaixo) e salvar nova versão se quiser.

## 7. Ativar um agente (homologação)
Na conta de homologação (ex.: Silvana / Filial São Paulo), página **Agente de IA**:
escolher o canal **conectado** → marcar as **áreas atendidas** → comportamento/mensagens →
ligar **Ativo**. Mandar uma mensagem real de um WhatsApp de teste e conferir a resposta +
o card criado em Prospecção.

---

## Rollback
- **Desligar rápido (sem reverter código):**
  ```sql
  UPDATE agent_configs SET enabled = 0;   -- o webhook não dispara o agente sem enabled=1
  ```
- **Reverter código:** `git revert <commit>` dos commits do agente + `git pull` no servidor +
  `apache2ctl graceful`. As tabelas `ai_*` e as colunas novas de `agent_configs` são aditivas
  (podem permanecer inertes).
- **Voltar versão do prompt:** Painel Master → histórico de versões → **Ativar** a anterior.

## Notas
- Containers `evolution_api` e `n8n` não são tocados em nenhum passo.
- A chave da OpenAI fica cifrada (`APP_ENCRYPTION_KEY`); confirme que essa env existe no
  `yuris_app` antes do passo 6 (senão salvar a chave retorna 503).
