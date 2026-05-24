# Política de Backup e Recuperação — Yuris

**Versão:** 1.0 — 2026-05-23
**Mantenedor:** Equipe Técnica + DPO (supervisão)
**Aplicação:** dados produtivos da Yuris (banco MariaDB, arquivos em `storage/`, configurações de servidor).

---

## 1. Propósito

Garantir a **disponibilidade** e **integridade** dos dados em caso de incidente (falha de hardware, ransomware, erro humano, desastre natural), em conformidade com o princípio da **prevenção** (LGPD Art. 6 VIII) e o controle de segurança (Art. 46).

## 2. Objetivos quantitativos

| Métrica | Meta |
|---------|------|
| **RPO** (Recovery Point Objective — perda máxima aceitável) | **24 horas** |
| **RTO** (Recovery Time Objective — tempo máximo de restauração) | **4 horas** |
| **Taxa de sucesso de teste de restore** | **≥ 99%** |
| **Retenção de backups** | conforme tabela abaixo |

> Valores acima podem ser endurecidos por plano (ex.: Enterprise → RPO 1h, RTO 30min) — refletir no contrato com o cliente.

## 3. O que é coberto

| Ativo | Frequência | Retenção | Localização |
|-------|------------|----------|-------------|
| **Banco MariaDB** (`sistema_vendas`) | Diária (full, off-hours) + binlog contínuo | 30 dias dailies + 12 semanais + 12 mensais | Servidor primário + 1 cópia off-site cifrada |
| **`storage/uploads/`** (anexos de cards/processos) | Diária (incremental) | 30 dias | Mesmo padrão |
| **`storage/whatsapp_media/`** | Diária (incremental) | 30 dias | Mesmo padrão |
| **`storage/lgpd_exports/`** | Diária (incremental) | 30 dias (já criptografados pelo Anonymizer) | Mesmo padrão |
| **Configurações** (Apache, MariaDB, .env modelo sem secrets) | Sob demanda + após mudança | 12 meses | Repositório separado de infraestrutura |
| **Repositório de código** (git) | Push contínuo para origin | Indefinido (immutable git) | GitHub/GitLab/auto-hospedado |

## 4. Tipos de backup

### 4.1 Full (completo)
- Frequência: **semanal** (domingo madrugada).
- Tamanho: snapshot integral do banco + storage.
- Retenção: 12 semanas (3 meses).

### 4.2 Incremental
- Frequência: **diária** (entre 02:00 e 04:00, fuso da Yuris).
- Tamanho: apenas o que mudou desde o último full/incremental.
- Retenção: 30 dias.

### 4.3 Binlog (point-in-time recovery)
- MariaDB com **binary logging** ativado (`log_bin`).
- Permite restaurar até **5 minutos antes** de um incidente conhecido.
- Retenção: 14 dias.

### 4.4 Mensal de longo prazo
- Primeiro full de cada mês: arquivado por 12 meses.
- Usado para auditoria histórica e recuperação de dados antigos não-críticos.

## 5. Cifragem

- **Backups em trânsito:** TLS 1.2+ ao copiar para destino off-site.
- **Backups em repouso:** AES-256 (chave separada do banco — sob custódia do DPO + responsável técnico).
- **Chave de backup NUNCA junto** com os próprios backups.
- Sob hipótese alguma um backup é trafegado ou armazenado em texto claro fora da infra primária.

## 6. Localização (3-2-1 rule)

A Yuris adota a regra **3-2-1**:
- **3 cópias** dos dados (1 produção + 2 backups).
- **2 mídias** diferentes (servidor primário + storage off-site — ex.: S3, Backblaze, NAS dedicado).
- **1 cópia off-site** geograficamente separada (em outra região, idealmente outro estado).

## 7. Validação e testes

- **Mensal:** teste de restore parcial em ambiente de staging (sample de 1 tenant aleatório).
- **Trimestral:** teste de restore completo do banco (executar `mysqldump` + restaurar em VM isolada + smoke test funcional).
- **Anual:** simulação de desastre — restaurar tudo em infra paralela em até **RTO 4h**.
- Resultados registrados em planilha mantida pela equipe técnica + revisada pelo DPO.

## 8. Procedimento de restauração

### 8.1 Em caso de incidente acidental (erro humano, ex.: DROP TABLE)

1. **Isolar:** desabilitar acesso à aplicação (Apache em manutenção).
2. **Identificar o ponto** de restauração:
   - Backup full mais recente (até 24h atrás);
   - Aplicar binlog até o último ponto válido (até 5min antes do incidente).
3. **Restaurar em staging primeiro**, validar integridade, validar com DPO e gestor afetado.
4. **Promover staging para produção** (cutover controlado).
5. **Documentar incidente** em `security_incidents` mesmo que não tenha sido malicioso.

### 8.2 Em caso de ransomware

1. **Desconectar** os hosts afetados da rede.
2. **NÃO pagar resgate** — política firme.
3. Acionar `PROCEDIMENTO_INCIDENTES.md` com severidade **crítica**.
4. Restaurar a partir do **último backup limpo** (verificar com checksums — backups da última semana podem estar comprometidos).
5. Investigar vetor de invasão e mitigar antes de religar.
6. Comunicar ANPD + titulares conforme Art. 48.

### 8.3 Em caso de desastre físico (incêndio, alagamento)

1. Ativar plano de continuidade — restaurar em infra cloud secundária (provisória).
2. Restaurar a partir do off-site cifrado mais recente.
3. Cliente recebe SLA de **4 horas** de retomada (RTO).

## 9. Responsabilidades

| Papel | Responsabilidade |
|-------|-------------------|
| **Equipe técnica (designado)** | Operar rotinas, monitorar sucesso/falha, executar testes mensais. |
| **DPO** | Validar conformidade da política; revisar relatórios trimestrais; aprovar mudanças. |
| **Diretoria** | Aprovar contrato com provedor off-site; aprovar orçamento. |
| **Operador off-site** (se terceirizado) | Cumprir DPA assinado, fornecer evidências de cifragem e isolamento. |

## 10. Monitoramento

- Alarme automatizado se:
  - Backup diário falhar 1 vez → alerta para equipe técnica;
  - Backup falhar 2 dias consecutivos → escalonar para DPO + diretoria;
  - Verificação de checksum detectar corrupção → suspender uso do backup, recriar imediatamente.

## 11. Eliminação de backups antigos

- **Backups expirados** (fora da retenção) são apagados em ciclo mensal.
- Mídia off-site descomissionada é destruída fisicamente (degausse + trituração) ou criptograficamente apagada com 3+ passes.
- Procedimento registrado em log próprio.

## 12. Considerações LGPD

- **Direito à eliminação** (Art. 18 VI): quando titular solicita eliminação, os dados são apagados do produtivo imediatamente, mas podem permanecer nos backups até a expiração natural (≤ 12 meses) — esta limitação é informada na resposta ao titular.
- **Anonimização** (Etapa 7): aplicada ao produtivo. Backups antigos sobreescritos pela rotação eventualmente "esquecem" os dados originais.
- **Acesso a backups:** restrito ao mínimo absoluto, com log de cada acesso.

## 13. Conformidade

- Atende LGPD Art. 46 (segurança), Art. 47 (boas práticas/governança), Art. 50 (programa de governança em privacidade).
- Alinhado a controles ISO 27001 (A.12.3 — Backup).

## 14. Revisão

Anual ou após incidente que envolva backup. Próxima revisão prevista: **2027-05-23**.
