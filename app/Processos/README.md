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
| `Processo.php` | o processo em si: listagem por conta, cadastro, dados da causa. `list()` com `from`/`to` (filtro de ano do calendário) devolve o processo cujo prazo OU início cai no período **e todo processo sem prazo**, em qualquer ano: sem isso ele não caía em ano nenhum e sumia da tela (23/10/2026) |
| `Processo::normalizarLinkTribunal` | o link do processo no tribunal (`processos.link_tribunal`, migration 174), alternativa manual à busca automática. Só http e https: `javascript:` num href executaria código. O sistema nunca acessa o link sozinho, ele só vira o botão "Abrir" da ficha |
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

## Tratamento das publicações (05/11/2026)

"Lida" é por usuário e não diz se o escritório fez algo. `PushEvent::registrarTratamento`
guarda o estado do escritório: `pendente`, `tratada`, `sem_providencia` ou `descartada`,
com autor, data e nota. Cada mudança acrescenta uma linha em `push_event_tratamentos`, que
nunca é reescrita. Descartar exige motivo, e nada é apagado. Criar tarefa ou prazo a partir
da publicação marca como tratada, sem pisar num tratamento já registrado.

**Tratar não calcula prazo.** Data de publicação, disponibilização, ciência e termo inicial
são coisas diferentes, e quem define é o profissional.

**O nome certo é publicação.** A tela chama "Publicações" porque o Diário traz mais que
intimação: em produção há "Lista de distribuição" junto de "Intimação". O tipo real vem de
`tipo_comunicacao`. Sem tipo informado, a tela diz "Publicação", nunca "Intimação". A AASP
continua "intimações" porque esse é o produto dela.

**Janela do DJEN.** O limite de 90 dias era nosso, e a mensagem o atribuía à DJEN. Em consulta
direta a API respondeu 200 com dados para o ano inteiro de 2024 e de 2025; 2021 veio vazio. A
busca manual agora aceita até 1 ano e pagina (até 6 páginas de 500). O `count` da API não é
total confiável: `truncado` sai da última página ter vindo cheia, e a tela avisa.

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
