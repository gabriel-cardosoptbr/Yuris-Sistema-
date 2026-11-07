# Inicio/ — a home do escritório e a interface nova (prévia)

Pasta criada em **07/11/2026**. Guarda a home nova do Yuris, a que abre quando a
pessoa entra, e a chave que decide quem a vê.

**Está em prévia.** Depois da paleta de 06/11/2026 (que subiu para todos e foi
reprovada), mudança de aparência só vai para todos depois que o dono do produto
aprova. Hoje só vê quem abriu uma tela com `?novo=1`.

## O pedido

"Queria algo no Yuris que remetesse a um escritório, que abrisse e se sentisse no
escritório." E, com as referências de tela: "a home muito mais voltada à
advocacia do que a métricas; o advogado tem que saber o que está acontecendo:
agenda, associados, processos. As métricas podem ficar dentro de Gestão." E o
menu "aprofundado, com a seleção em relevo", como na referência do campo de
seleção em pílula.

## Arquivos

| Arquivo | O que faz |
|---|---|
| `InterfaceNova.php` | a chave da prévia. `ligada($_GET, $_COOKIE, $contaDeMarca)` decide sem efeito colateral; `aplicar()` grava o cookie `yuris_interface_nova` (um ano) quando a URL traz `?novo=1`, e apaga com `?novo=0`; `scriptCookie()` faz o mesmo por JavaScript, para o menu, que entra com os cabeçalhos já enviados. Conta de marca (edição CRM) nunca liga: o desenho dela é outro. É cookie, e não `localStorage` como a paleta, porque o PHP precisa saber antes de desenhar: a home nova é outra página |
| `Painel.php` | o que a home mostra, lido de onde já está, sem copiar: agenda e prazos de `App\Tarefas\Central` (as três tabelas, com a visibilidade de quadros e processos); publicações pendentes de `push_events`; a equipe de `users` + `CargaDaEquipe` + os prazos de processo cujo responsável é o nome da pessoa; processos por última movimentação com a última publicação de cada um; quem espera resposta no WhatsApp pela mesma regra de `MetricasAtendimento::relatorio` (a última mensagem é do contato); e os números do rodapé. As regras puras (saudação, tipo do item, rótulo de vencimento, frase do resumo, situação do processo) ficam separadas das consultas |

## Onde entra

- [`public/dashboard.php`](../../public/dashboard.php) chama `InterfaceNova::aplicar()`
  antes de qualquer saída e, depois de calcular tenant e DRE, entrega
  [`public/includes/dashboard_inicio.php`](../../public/includes/dashboard_inicio.php)
  quando a conta é jurídica, a chave está ligada e não veio `?gestao=1`. Com
  `?gestao=1` o painel de métricas de sempre aparece, acendendo **Gestão ›
  Indicadores** no menu.
- [`public/includes/sidebar.php`](../../public/includes/sidebar.php) lê a mesma
  chave: troca "Visão Geral" por "Início", põe "Indicadores" como primeiro item
  de Gestão e carrega `assets/interface-nova.css`, que veste o menu que já existe
  (painel em relevo, itens em pílula, a página atual numa pílula azul que brilha).
  O HTML do menu não muda.
- `assets/inicio.css` é a home: dois temas pelas variáveis `--ini-*`, a faixa de
  boas-vindas com as peças de madeira e a luz azul por trás feitas em CSS (madeira
  como moldura, nunca atrás de texto), cards com relevo, campos com sombra interna.

## Regras que valem aqui

- **Toda consulta filtra por conta.** `Painel` recebe os `$tenantIds` do dashboard e
  o escopo da Central (`user_id`, `is_admin`, `contas_tarefas`, `contas_processos`),
  e nunca consulta sem eles. O teste confere que nada de outra conta aparece.
- **Nada de reticências escondendo dado.** Texto quebra linha; a folha não usa
  `text-overflow`. O teste trava.
- **Azul escuro, nunca roxo.** As variáveis estão no topo das duas folhas; é ali
  que se mexe.
- **Quando aprovar**, ligar para todos é apagar a condição da chave em
  `dashboard.php` e `sidebar.php`, não reescrever nada. Antes disso, nada aqui
  aparece para quem não pediu.

Teste: `scripts/tests/interface_nova_test.php`.
