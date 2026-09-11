# App\Notificacoes

A central de avisos do Yuris. Entrou em 11/09/2026, a partir de um pedido
literal: *"essa notificação tem que chegar a absolutamente tudo, e sempre que eu
mencionar alguém, algum responsável, tem que chegar a mensagem para o
responsável também"*.

## O que existia antes

O sino já estava lá e funcionava. Só que **sete lugares no sistema inteiro**
criavam notificação: monitoramento DJEN e AASP, handoff do agente, vínculo de
conta, vínculo de advogado e compartilhamento de recurso.

Tudo o mais era silencioso:

- card mudou de etapa, cliente cadastrado, processo alterado, prazo criado,
  tarefa concluída: **zero aviso**
- os **quatro** lugares que guardam responsável não avisavam ninguém
- a **menção do chat interno existia desde a migration 037 e nunca avisou
  ninguém**: ficava gravada, e a pessoa só descobria por acaso

## A tensão que define o desenho

"Absolutamente tudo" num escritório ativo dá entre 100 e 300 avisos por dia.
Sino com 300 itens é sino ignorado, e aí o aviso que importava, o prazo que vence
amanhã, some junto com o resto.

Por isso **duas naturezas na mesma caixa**:

| | o que é | conta no badge? |
|---|---|---|
| `dirigido` | é pra você: virou responsável, foi mencionado, seu prazo chegou | **sim** |
| `movimento` | aconteceu no escritório | não |

Sem essa separação, atender ao pedido ao pé da letra destruiria a utilidade dele.

## Arquivos

```
Aviso.php       o ÚNICO caminho de escrita, com as quatro regras
Movimento.php   traduz linha de histórico em aviso legível
README.md       este arquivo
```

Fora daqui:

```
database/migrations/run_130.php          natureza, entidade, origem, url, dedupe + preferências
app/Master/AccountNotification.php       leitura do sino (duas metades, teto por natureza)
public/api/notificacao_preferencias.php  o que cada um quer receber
public/api/notificacao_prazos_tick.php   cron diário de prazo e tarefa vencendo
public/assets/notifications.js           render + navegação ao clicar
public/includes/sidebar.php              marca visual do movimento
public/configuracoes.php                 o bloco REAL de preferências
scripts/tests/notificacoes_test.php      58 asserts
```

## As quatro regras que seguram o ruído

**1. Ninguém é avisado do próprio ato.** A mais importante. Sem ela, cada pessoa
receberia um aviso a cada clique que ela mesma deu.

**2. Dedupe por janela.** Mover um card três vezes em dois minutos é UM
movimento. A janela é parametrizável: o aviso de prazo usa um dia, porque o mesmo
prazo deve avisar uma vez por dia enquanto continuar vencendo.

**3. Preferência por pessoa.** Quatro chaves: `movimento`, `responsavel`,
`mencao`, `prazo`. **Chave ausente significa LIGADO**: a tabela guarda o desvio
do padrão, não o padrão. Assim a entrega não depende de ninguém marcar caixinha.

**4. Falha em silêncio.** Notificação NUNCA derruba a operação que ela descreve.
Mesmo princípio de `Card::logEvento` e `Cliente::_logHistory`.

## Onde o movimento é capturado

Não em cada endpoint. O sistema já tem **quatro funções** por onde todo movimento
obrigatoriamente passa, porque é onde o histórico é gravado:

```
App\Prospeccao\Card::logEvento()      -> card_history
App\Clientes\Cliente::_logHistory()   -> clientes_history
App\Processos\ProcessoAudit::log()    -> processo_history
App\Tarefas\Task::history()           -> task_history
```

Pendurar o aviso nessas quatro cobre tudo, **inclusive o que for escrito
amanhã**: quem gravar histórico avisa, sem precisar lembrar.

## Onde o responsável é capturado

Quatro pontos, e em três deles a detecção **já existia** para o webhook:

| tela | como |
|---|---|
| `api/cards.php` | reusa o `eventKey === 'card.responsavel_changed'` que já era calculado |
| `api/processes.php` | idem, `processo.responsavel_changed` |
| `api/tasks.php` | reusa o `$changes` que já era montado para o histórico processual |
| `api/clientes.php` | **único que precisou comparar**, com o registro que o guard de tenant já havia carregado |

Reaproveitar é melhor que recalcular: duas detecções da mesma coisa divergem com
o tempo.

**Avisa os dois lados.** Quem entrou e quem saiu. O segundo importa: quem era
responsável por um prazo precisa saber que não é mais, senão segue contando com
um compromisso que já não é dele.

## Armadilhas que morderam aqui

**O `LIMIT 50` escondia o movimento para sempre.** A primeira versão fazia uma
consulta só, ordenando dirigido primeiro e cortando em 50. Funcionou na mesa e
quebrou no ambiente real: numa conta com 95 avisos dirigidos parados, os 50
primeiros eram todos dirigidos e a movimentação **nunca aparecia**, sem nenhum
sinal de que faltava algo. Agora são duas consultas com teto próprio (30 + 20).

**A janela de dedupe de 2 minutos não servia para o prazo diário.** O comentário
dizia "rodar duas vezes no mesmo dia não duplica", e era **falso**: a data estava
na chave, mas a consulta só olhava os últimos dois minutos. A janela virou
parâmetro.

**Concordância de gênero.** "Prospecção foi alterado" é o tipo de erro que faz a
advogada achar que o sistema é mal feito antes de ler o que o aviso diz. Os
verbos têm o marcador `{o}` e cada entidade declara o seu gênero.

**A limpeza do teste passava mentindo.** Ela apagava por `LIKE 'PREFIXO%'`, mas o
aviso de responsável tem título "Você é o responsável: PREFIXO Fulano", com o
prefixo **no meio**. Sobrava resíduo entre rodadas, e a asserção final "nada ficou
no banco" usava o mesmo LIKE errado, então dava PASS. Agora é `%PREFIXO%`.

## O que ficou de fora, e por quê

**Menção a processo, card ou cliente não gera aviso.** Só menção a **usuário**.
Mencionar um processo é uma referência, não um chamado: não existe pessoa do
outro lado, e avisar o responsável por ele transformaria toda citação em cobrança.

**O aviso não sai do sistema.** Sem e-mail e sem WhatsApp, por ora. `Aviso` é o
ponto único, então plugar um canal externo depois é acrescentar uma chamada
dentro de `gravar()`, e não caçar dezenas de lugares.

**A seção "Canais de notificação" e "Tipos de alerta" de `configuracoes.php`
continua decorativa.** Aqueles toggles só gravam no navegador e não mudam
comportamento nenhum; são anteriores a este módulo. O bloco **"O que eu recebo no
sino"**, no topo da mesma seção, é o único que grava no servidor.

## Rodar os testes

```
C:\xampp\php\php.exe scripts/tests/notificacoes_test.php
```

Escreve no banco. Não rode em produção.

## Cron

```
5 8 * * * docker exec yuris_app php /var/www/html/public/api/notificacao_prazos_tick.php
```
