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
| `ValoresDoProcesso.php` | **valores do processo, resultado, carteira e projeção** (T3, 10/11/2026, migration 180): valor da causa, valor estimado e o resultado (tipo `acordo`, `sentenca`, `execucao`, `improcedente`, `outro`; valor; à vista ou parcelado; parcelas e primeiro vencimento). `salvar` grava e chama `sincronizar`, que põe o Financeiro de acordo pelo mecanismo do T2 (`App\Financas\Parcelamento`, `fin_parcelamentos` com `origem` = `processo_resultado` e `origem_id` = processo): um parcelamento só por processo, então editar nunca duplica; **parcela baixada nunca muda**, o restante (resultado menos o recebido) é dividido entre as pendentes, atualizadas no lugar; pendente que sobra, resultado tirado ou improcedente desativam (ativo = 0, nada é apagado). Os lançamentos ficam na conta dona do processo, com o cliente do processo. `carteira` (potencial, em aberto, projetado x obtido dos que têm resultado, improcedente = 0) e `projecaoMensal` (previsto pelas parcelas pendentes, recebido pela data da baixa, atrasado à parte), em centavos. Processo antigo, sem valores, não muda: `Processo::update` não conhece as colunas. API `public/api/processo_valores.php`; tela `public/assets/processo-valores.js` (ficha) e o painel de carteira em Finanças. Teste: `scripts/tests/processo_valores_test.php` |
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
| `PushEventUserStatus.php` | se cada usuário já leu ou tratou aquela publicação. `statusPorHash()` devolve id, lida, favorita e prazo do que já está salvo, pela chave `hash_conteudo`: é como a busca externa (`api/push/search.php`) mostra o que já foi lido (10/11/2026) |
| `PushProcessoLink.php` | liga a publicação encontrada ao processo cadastrado |
| `PushQueryLog.php` | log das consultas feitas às fontes |
| `PushTodayCache.php` | cache do dia, para não consultar a mesma coisa repetidas vezes. `semOsJaSalvos()` tira da lista o que já virou `push_events`, conferindo contra **toda** publicação salva da conta: conferir só contra a lista já filtrada fazia a publicação lida voltar em "Não lidas" como cópia do cache (10/11/2026) |
| `PublicacaoEstrutura.php` | a publicação em tópicos: cabeçalho, timbre, partes, advogados com OAB, seções do teor com parágrafos e assinatura, intimados e link do tribunal. Regra pura, sem banco. Também extrai advogados e partes do payload para o Diário (`api/push/list.php` delega) |
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

## A publicação em tópicos (07/11/2026)

"Tem que separar os OAB, o que é advogado, o que é corpo... separar todos os tópicos." O DJEN e a
AASP mandam o teor numa linha só. `PublicacaoEstrutura::estruturar($texto, $payload)` devolve:

- **cabecalho**: classe (o `nomeClasse` do DJEN, com a caixa consertada), documento, e o que vier em
  linhas ("Classe - Assunto:", "Relator:") ou no formato do TJSP ("Processo N - Classe - Assunto - partes - teor");
- **preambulo**: o timbre ("PODER JUDICIÁRIO ... 7ª VARA ... ATOrd N"), que vai miúdo;
- **partes**: pelo polo do DJEN (`destinatarios[].polo`), com o rótulo do texto quando existe (Reclamante,
  Requerido); sem payload, pelos rótulos em maiúsculas antes da primeira seção;
- **advogados**: os do DJEN, os da lista "ADV:" do fim e os citados com OAB ("NOME (OAB 123/SP)",
  "NOME - OAB/SP 123", "NOME OAB SP-123"), um por linha, sem repetir (a OAB identifica);
- **blocos**: cada seção em maiúsculas (Intimação, Conclusão, Despacho, Decisão, Sentença, Ato
  ordinatório...) com uma frase por parágrafo (abreviações como "V. Sa." e "v. Acórdão" não quebram;
  "Vistos" e itens numerados abrem linha) e a assinatura à parte: local e data, nome, cargo;
- **intimados** (o "Intimado(s) / Citado(s)" do fim) e **link** (só https, do payload).

**Em todas as telas desde 09/11/2026** ("arrume a identação de todas na plataforma"): além do pop-up da
home, a tela Publicações desenha cada cartão em tópicos. `PublicacaoEstrutura::doItem($it)` (conteúdo, ou o
resumo, e o payload) vai em `estrutura` nas três fontes da lista: `api/push/list.php`, `api/push/search.php`
e `api/aasp/search.php`, sempre antes de o payload ser apagado. Sem nenhum parágrafo, a tela usa o texto
corrido de antes. "Juiz de Direito: Fulano" abrindo o teor (o TJSP escreve assim depois das partes) vai
para o cabeçalho como **Juiz**.

Desde 08/11/2026 ("a indentação está horrível ainda", sobre um cumprimento de sentença do TJSP):
o formato do TJSP aceita `(processo principal N)` depois do número, que vira o campo "Processo
principal"; o " - - " entre as partes (campo vazio) some; "Nota de cartório:" e outros rótulos em
caixa normal no começo do teor viram o título da seção; o comunicado do tribunal no fim (migração para
o eproc, "solicita-se aos advogados") vira o bloco "Aviso do tribunal"; e o endereço que chega com
`&amp;` sem o `&` e o `;` (`id=2amppagina=2`) é consertado, só dentro de URL. No pop-up, endereço
https no texto vira link.

Usada pelo pop-up de publicação da home nova (`Inicio/Painel::publicacoes`). Teste:
`scripts/tests/publicacao_estrutura_test.php`, com textos nos formatos reais e nomes fictícios.

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

**Cada prazo e cada tarefa do processo tem o próprio log** (08/11/2026: "o log tem
que ficar dentro de cada um, dentro da tarefa se eu editar, dentro do prazo se eu
editar"). `ProcessoAudit::log()` aceita `$itemTipo` (`prazo` | `tarefa`) e `$itemId`
e grava `item_tipo` e `item_id` (migration 177); sem a migration, grava como antes.
`ProcessoAudit::doItem($pdo, $processoId, $tipo, $itemId)` devolve o log de um item:
os registros ligados a ele e, para os antigos, que não podem ganhar a ligação porque o
`processo_history` é **imutável** (migration 053, gatilhos que barram UPDATE e DELETE),
os registros de prazo (ou tarefa) daquele processo cujo nome mais longo citado é o do
item, sem outro item de mesmo nome. Quem chama já conferiu o acesso ao processo. As
APIs de prazo e de tarefa ligam todo log ao item, e uma edição de vários campos vira
**uma** entrada com cada "de → para" (antes, salvar a tarefa sem mexer no nome gravava
"Tarefa renomeada"). Teste: `scripts/tests/historico_item_test.php`.

## O que **não** está aqui

`LegalDocument` (versionamento de termos e políticas) parecia jurídico pelo
nome, mas é LGPD: vive em [`../Lgpd/`](../Lgpd/).
