# Processos/ — processos judiciais e monitoramento de publicações

Telas: **Jurídico › Processos** (`public/processos.php`) e **Jurídico ›
Intimações** (`public/intimacoes.php`).

Duas coisas moram aqui, e são ligadas: o **processo** que o escritório
acompanha, e o **monitoramento** que fica varrendo os diários oficiais atrás de
publicação nova sobre aquele processo. O motor de coleta está em
[`Monitor/`](Monitor/).

O monitoramento é um **add-on de plano**, não é do pacote básico. Por isso três
das classes daqui tratam de cota e permissão, e não de processo.

## Arquivos

### O processo
| Classe | O que faz |
|---|---|
| `Processo.php` | o processo em si: listagem por conta, cadastro, dados da causa |
| `ProcessoAudit.php` | histórico processual garantido no servidor. É a linha do tempo do processo, e o registro não pode depender do front |

### Integração com as fontes
| Classe | O que faz |
|---|---|
| `AaspIntegration.php` | a integração AASP configurada por conta: credencial, última sincronização, estado |

### Monitoramento (add-on)
| Classe | O que faz |
|---|---|
| `PushMonitor.php` | um monitoramento cadastrado: o que vigiar (nome, OAB, número de processo) |
| `PushEvent.php` | uma publicação encontrada |
| `PushEventUserStatus.php` | se cada usuário já leu ou tratou aquela publicação |
| `PushProcessoLink.php` | liga a publicação encontrada ao processo cadastrado |
| `PushQueryLog.php` | log das consultas feitas às fontes |
| `PushTodayCache.php` | cache do dia, para não consultar a mesma coisa repetidas vezes |
| `MonitorQuota.php` | cálculo de cota do add-on: quantos monitoramentos o plano permite |
| `MonitorPermission.php` | quem pode ver e mexer em monitoramento dentro da conta |
| `MonitorAudit.php` | grava em `monitor_audit_log` |

## Regras

**Publicação chega por hash, não por comparação de texto.** O
`Monitor/PublicationHasher.php` gera um hash canônico por publicação, e é ele
que evita duplicata. Se você mudar como o hash é montado, o sistema volta a
enxergar publicações antigas como novas e o cliente recebe uma enxurrada de
avisos repetidos.

**Cota é verificada antes de criar, não depois.** `MonitorQuota` existe para
ser consultada na hora do cadastro. Criar primeiro e checar depois deixa o
cliente acima do plano.

**`ProcessoAudit` é server-side de propósito.** Não substitua por registro
vindo do front: o histórico processual é o que o escritório mostra para o
cliente dele.

## O que **não** está aqui

`LegalDocument` (versionamento de termos e políticas) parecia jurídico pelo
nome, mas é LGPD: vive em [`../Lgpd/`](../Lgpd/).
