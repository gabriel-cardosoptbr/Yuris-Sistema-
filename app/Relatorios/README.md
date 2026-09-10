# App\Relatorios

O módulo de relatórios do Yuris. Entrou em 10/09/2026, e até ele o sistema **não
gerava documento nenhum**: nem PDF, nem planilha, nem impressão. O que existia
era gráfico na tela e um botão no Dashboard que tirava uma foto PNG dela.

## As duas coisas, que são diferentes

| | o que é | onde |
|---|---|---|
| **Dossiê** | tudo sobre UMA pessoa ou UM processo, com o histórico inteiro | botão "Relatório completo" dentro da ficha → `public/dossie.php` |
| **Listagem** | MUITOS registros, poucas colunas: a carteira | menu Gestão → Relatórios → `public/relatorios.php` |

Não foram unificados de propósito. Unificar faria a listagem carregar campos que
não usa e o dossiê perder profundidade.

## Arquivos

```
Dossie.php     monta o dossiê de cliente | card | processo
Listagem.php   monta a listagem de clientes | prospeccoes | processos
Planilha.php   transforma qualquer um dos dois em CSV que o Excel abre
```

Fora daqui:

```
public/dossie.php                 a folha, desenhada para virar papel
public/relatorios.php             a tela de listagens
public/api/relatorios.php         JSON e download de CSV
public/assets/relatorios.css      o CSS de tela E de impressão
scripts/tests/relatorios_test.php 106 asserts
```

## Por que não existe PDF gerado no servidor

O projeto não usa Composer e não tem `vendor/`. Gerar PDF no servidor exigiria
embutir uma biblioteca inteira, e o resultado seria **pior**: layout rígido,
acentuação frágil, e um documento que ninguém consegue ajustar.

A página impressa pelo navegador resolve melhor. "Salvar como PDF" é uma opção da
própria caixa de impressão, e o arquivo sai com **texto pesquisável**, acento
correto e quebra de página decente.

Por isso `relatorios.css` tem uma regra que parece contradizer o resto do
sistema: **o documento é claro, sempre**, inclusive na tela. O Yuris é escuro,
mas um documento escuro impresso ou gasta um cartucho, ou (o que acontece na
prática, porque o navegador desmarca "gráficos de fundo" por padrão) sai em
branco. O que a pessoa vê é o que sai na impressora, e isso vale mais que a
coerência de tema.

## O dossiê tem UM formato para as três entidades

`Dossie::montar()` sempre devolve a mesma estrutura, e quem renderiza não sabe
qual entidade é:

```php
['entidade', 'id', 'titulo', 'subtitulo', 'conta', 'gerado_em',
 'identificacao' => [ ['rotulo','valor'] ],
 'blocos'        => [ ['chave','titulo','tipo','total','vazio', ...] ],
 'resumo'        => ['documentos'=>N, 'timeline'=>N, ...]]
```

Quatro tipos de bloco: `pares`, `tabela`, `chips` e `timeline`. **Acrescentar um
bloco novo a qualquer entidade não exige tocar na tela nem no CSV.**

## O que ele NÃO faz, e por quê

**Não inventa consulta.** O dado consolidado já existia e estava maduro. Reusa
`App\Core\Timeline`, `App\Clientes\VinculosCliente` e os quatro blocos da Fase 2
(`App\Crm\Anexo/Tag/CampoPersonalizado/Interacao`). Consulta nova existe só onde
não havia nada: processo, checklist do card e vínculos do card.

**Não confia no id que recebe.** `montar()` resolve a entidade dentro das contas
acessíveis e devolve `null` para os três casos: não existe, foi apagado, não é
seu. Os três iguais de propósito, porque distinguir já seria vazamento.

## O gate é por FONTE, nunca pela tela

Cada entidade tem o seu módulo, e o escopo de contas é pedido COM ele:

```
cliente   → clientes
card      → prospeccao
processo  → processos
```

Quem pode abrir Relatórios mas não enxerga Processos recebe **lista vazia** de
processos e **404** no dossiê de processo. Não existe caminho em que "ter acesso
a relatórios" amplie o que a pessoa já podia ver.

A permissão `relatorios` existe (está em `$_validPages` e na tela de Usuários),
mas **não é exigida** para ver o menu: a sidebar aceita qualquer um dos três
módulos, via `perm_any`. Se ela fosse obrigatória, nasceria desmarcada para todo
mundo e o escritório inteiro concluiria que o módulo não foi entregue.

## Armadilhas que já morderam aqui

**O `LIMIT 50` do processo.** A tela lia `processo_history` com limite fixo, o
que servia para mostrar o começo. Um relatório que promete "histórico completo"
não pode cortar em 50 e não avisar. `Timeline::paraProcesso()` não tem LIMIT.

**Nenhuma das três tabelas do processo tem `account_id`.** Quem tem a conta é o
processo. Toda leitura passa por JOIN em `processos`. Mesmo problema de
`card_history`.

**As tabelas de histórico são IMUTÁVEIS.** `card_history`, `clientes_history` e
`processo_history` têm trigger que recusa UPDATE e DELETE (migration 053, LGPD
Art. 37). O teste não tenta limpá-las: ele prova que a linha órfã não é
alcançável, porque toda leitura passa pelo JOIN na tabela dona.

**CSV injection.** Uma célula que começa com `=`, `+`, `-`, `@` ou TAB é
FÓRMULA no Excel. Como o conteúdo vem do que as pessoas digitaram no CRM, um
nome como `=cmd|...` viraria execução na máquina de quem abre. `Planilha`
prefixa com apóstrofo.

**BOM e ponto e vírgula.** Sem BOM o Excel do Windows lê o arquivo como ANSI e
"Prospecção" vira "ProspecÃ§Ã£o". Com vírgula como separador, o Excel em
português joga tudo numa coluna só, e a advogada conclui, com razão, que o
relatório veio quebrado.

**Data partida ao meio.** Numa coluna espremida o navegador quebrava
`20/01/2026` em `20/01/20` e `26`. Numa folha de processo isso não é feio, é
errado: o leitor vê duas datas. A tabela ganhou largura mínima, e `Listagem`
declara em `COLUNAS_LIVRES` quais colunas podem quebrar.

**A etiqueta de fase.** Na timeline de um cliente ela é o que diz "isto
aconteceu quando ele ainda era prospecção", e vale ouro. Na de um processo, todo
evento tem a mesma fase, e ela vira ruído repetido linha a linha. Só aparece
quando há mais de uma.

## Rodar os testes

```
C:\xampp\php\php.exe scripts/tests/relatorios_test.php
```

Escreve no banco. Não rode em produção.
