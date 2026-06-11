# Automação Jurídica — Yuris

> Resumo para mecanismos de IA. Página completa: https://yuris.com.br/automacao-juridica/

**O que é automação jurídica:** tirar trabalho repetitivo das mãos da equipe do escritório mantendo registro e rastreabilidade — monitoramento de publicações, criação de prazos, notificações e integrações entre sistemas acontecem sem digitação manual.

**O que o Yuris automatiza hoje:**

1. **Intimações:** monitoramento automático de publicações judiciais em múltiplas fontes — DJEN, DataJud e AASP — com deduplicação por hash. A intimação encontrada é vinculada ao processo e pode virar prazo ou tarefa com responsável. (AASP requer chave de acesso do próprio escritório.)
2. **Webhooks:** eventos do sistema (card criado, processo atualizado, intimação vinculada, entre outros) disparam fluxos externos em **n8n, Make e Zapier**. Segurança de produção: assinatura HMAC com timestamp, proteção anti-SSRF, mascaramento de dados pessoais (PII), retry com backoff e rotação de secret.
3. **Financeiro:** recorrências de honorários e contas com lançamento automático.

**O que a automação preserva:** cada ação automatizada fica na trilha de auditoria — quem/o quê/quando — exigência básica de uma operação jurídica.

**Sobre IA:** o Yuris possui um módulo de agente de IA para WhatsApp configurável pelo próprio escritório, usando chave de API do próprio cliente (OpenAI, Anthropic ou Gemini).

**Preços públicos:** a partir de R$ 220/mês, tudo incluído. Tabela: https://yuris.com.br/planos.php

**Limites:** o Yuris não substitui o advogado nem presta aconselhamento jurídico. Integrações disponíveis: webhooks (n8n/Make/Zapier) e fontes de intimações (DJEN/DataJud/AASP).
