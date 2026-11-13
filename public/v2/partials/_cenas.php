<?php
/**
 * _cenas.php: as ilustrações de produto do site público.
 *
 * REGRA (13/11/2026): o site NUNCA mostra a tela real de dentro do Yuris. Nada de
 * retrato, recorte ou gravação do sistema. O que aparece aqui são ILUSTRAÇÕES feitas
 * para o site, na linguagem visual do site, com dados fictícios: elas explicam o que
 * cada recurso faz, não como a tela é.
 *
 * E não podem ter "cara de IA" (mesma data): sem barra de janela, sem bolinhas, sem
 * linhas de enfeite no lugar do texto, sem avatar de iniciais, sem fundo pontilhado,
 * sem diagrama de caixinhas. Cada cena tem conteúdo de verdade, legível, e conta UMA
 * ideia: o processo guarda a história, a intimação vira prazo, a conversa vira cliente,
 * cada um vê o seu, os números saem do sistema.
 *
 * Movimento: as peças de cada cena entram em sequência quando a cena aparece (--i dá a
 * ordem; quem anima é o site-motion.css). Toca uma vez, não fica repetindo.
 *
 *   lp2_scene('intimacao')    devolve a figura pronta, com legenda
 *   nomes antigos (juridico, operacao, gestao, dashboards...) continuam valendo
 */

if (!function_exists('lp2_scene')) {
    function lp2_scene(string $slug): string
    {
        $mapa = [
            'juridico' => 'processo', 'tab-processos' => 'processo',
            'intimacoes' => 'intimacao', 'tab-intimacoes' => 'intimacao', 'automacoes' => 'intimacao', 'tab-automacao' => 'intimacao',
            'operacao' => 'funil', 'comunicacao' => 'funil', 'cliente' => 'funil', 'tab-comunicacao' => 'funil', 'tab-clientes' => 'funil', 'tab-tarefas' => 'funil',
            'gestao' => 'estrutura', 'vinculos' => 'estrutura', 'tab-matriz' => 'estrutura',
            'dashboards' => 'indicadores', 'tab-dashboard' => 'indicadores', 'showcase' => 'indicadores',
        ];
        $cena = $mapa[$slug] ?? $slug;
        $rotulos = [
            'processo'    => 'Ilustração: a ficha de um processo com o histórico de ações, cada uma com data e autor, e os honorários ligados a ele',
            'intimacao'   => 'Ilustração: uma publicação do tribunal vinculada ao processo e convertida em prazo com responsável',
            'funil'       => 'Ilustração: uma conversa de WhatsApp que entra no funil comercial e avança de novo contato a contrato',
            'estrutura'   => 'Ilustração: o que a matriz, cada filial e o advogado associado enxergam dentro do sistema',
            'indicadores' => 'Ilustração: indicadores do escritório, com processos ativos, prazos da semana, receita do mês e evolução dos honorários',
        ];
        if (!isset($rotulos[$cena])) return '';

        ob_start(); ?>
<figure class="ys-cena ys-cena-<?= $cena ?> ys-reveal" role="img" aria-label="<?= htmlspecialchars($rotulos[$cena], ENT_QUOTES, 'UTF-8') ?>">
  <div class="ys-cena-palco" aria-hidden="true">
<?php switch ($cena):

  // ─────────────── O processo guarda a história ───────────────
  case 'processo': ?>
    <div class="ys-c-card ys-c-ficha" style="--i:0">
      <p class="ys-c-sobre">Processo</p>
      <p class="ys-c-num">1028473-22.2026.8.26.0100</p>
      <dl class="ys-c-dados">
        <div><dt>Cliente</dt><dd>Clínica São Lucas Ltda</dd></div>
        <div><dt>Vara</dt><dd>12ª Vara Cível, TJSP</dd></div>
        <div><dt>Responsável</dt><dd>Dra. Helena Marques</dd></div>
      </dl>
      <p class="ys-c-sobre ys-c-sep">Histórico</p>
      <ol class="ys-c-hist">
        <li style="--i:2"><time>12/03</time><span>Processo cadastrado</span><em>Helena</em></li>
        <li style="--i:3"><time>14/03</time><span>Contestação juntada</span><em>Rafael</em></li>
        <li style="--i:4"><time>02/04</time><span>Intimação recebida, prazo de 15 dias</span><em>Automático</em></li>
        <li style="--i:5" class="is-novo"><time>03/04</time><span>Tarefa criada: manifestação</span><em>Mariana</em></li>
      </ol>
    </div>
    <div class="ys-c-card ys-c-nota" style="--i:6">
      <p class="ys-c-sobre">Honorários do processo</p>
      <p class="ys-c-valor">R$ 18.000,00</p>
      <div class="ys-c-barra"><i style="--p:50%"></i></div>
      <p class="ys-c-leg">3 de 6 parcelas recebidas</p>
    </div>
  <?php break;

  // ─────────────── A intimação vira prazo ───────────────
  case 'intimacao': ?>
    <div class="ys-c-card ys-c-pub" style="--i:0">
      <p class="ys-c-sobre">Publicação de hoje</p>
      <p class="ys-c-tit">TJSP · Foro Regional de Santo Amaro</p>
      <p class="ys-c-texto">“Designada audiência de conciliação para o dia 25/11, às 10h30, por videoconferência. Intimem-se as partes por seus procuradores.”</p>
      <p class="ys-c-vinc" style="--i:2">Vinculada ao processo <b>1004521-10.2026.8.26.0002</b></p>
    </div>
    <div class="ys-c-seta" style="--i:3"><svg viewBox="0 0 24 60" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v52"/><path d="M5 47l7 8 7-8"/></svg></div>
    <div class="ys-c-card ys-c-prazo" style="--i:4">
      <p class="ys-c-sobre">Prazo criado</p>
      <p class="ys-c-tit">Audiência de conciliação</p>
      <dl class="ys-c-dados">
        <div><dt>Quando</dt><dd>25/11, 10h30</dd></div>
        <div><dt>Responsável</dt><dd>Dr. Rafael Costa</dd></div>
      </dl>
      <p class="ys-c-selo" style="--i:6">Na agenda, com aviso 2 dias antes</p>
    </div>
  <?php break;

  // ─────────────── A conversa vira cliente ───────────────
  case 'funil': ?>
    <div class="ys-c-card ys-c-chat" style="--i:0">
      <p class="ys-c-sobre">WhatsApp do escritório</p>
      <p class="ys-c-balao" style="--i:1">Bom dia! Vocês atendem causas trabalhistas?</p>
      <p class="ys-c-balao is-nosso" style="--i:2">Atendemos, sim. A Dra. Helena fala com você ainda hoje.</p>
      <p class="ys-c-vinc" style="--i:3">Conversa ligada ao contato <b>Marcos Teixeira</b></p>
    </div>
    <div class="ys-c-etapas">
      <div class="ys-c-etapa" style="--i:4"><p class="ys-c-sobre">Novo contato</p>
        <div class="ys-c-lead is-novo"><b>Marcos Teixeira</b><span>Veio pelo WhatsApp</span></div></div>
      <div class="ys-c-etapa" style="--i:5"><p class="ys-c-sobre">Proposta</p>
        <div class="ys-c-lead"><b>Padaria Dois Irmãos</b><span>Indicação · R$ 6.500</span></div></div>
      <div class="ys-c-etapa" style="--i:6"><p class="ys-c-sobre">Contrato</p>
        <div class="ys-c-lead is-ok"><b>Renata Carvalho</b><span>Virou cliente, com o histórico</span></div></div>
    </div>
  <?php break;

  // ─────────────── Cada um vê o seu ───────────────
  case 'estrutura':
    $linhas = [
      ['Matriz',             'Todos',        'Completo',     'Toda a equipe'],
      ['Filial São Paulo',   'Da filial',    'Da filial',    'Equipe da filial'],
      ['Filial Campinas',    'Da filial',    'Da filial',    'Equipe da filial'],
      ['Advogado associado', 'Só os dele',   'Sem acesso',   'Sem acesso'],
    ]; ?>
    <div class="ys-c-card ys-c-quadro" style="--i:0">
      <p class="ys-c-sobre">Quem vê o quê</p>
      <div class="ys-c-grade">
        <span></span><span class="ys-c-col">Processos</span><span class="ys-c-col">Financeiro</span><span class="ys-c-col">Equipe</span>
        <?php foreach ($linhas as $n => [$quem, $a, $b, $c]): ?>
        <b style="--i:<?= $n + 1 ?>"><?= $quem ?></b>
        <?php foreach ([$a, $b, $c] as $v): ?>
        <span class="ys-c-cel<?= $v === 'Sem acesso' ? ' is-nao' : ($n === 0 ? ' is-tudo' : '') ?>" style="--i:<?= $n + 1 ?>"><?= $v ?></span>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <p class="ys-c-vinc" style="--i:6">Cada ação fica registrada com <b>quem fez, de onde e quando</b></p>
    </div>
  <?php break;

  // ─────────────── Os números saem do sistema ───────────────
  case 'indicadores':
    $meses = [['Jun', 52], ['Jul', 61], ['Ago', 58], ['Set', 74], ['Out', 83], ['Nov', 100]]; ?>
    <div class="ys-c-kpis">
      <div class="ys-c-card ys-c-kpi" style="--i:0"><p class="ys-c-sobre">Processos ativos</p><p class="ys-c-n"><span data-conta="153">153</span></p></div>
      <div class="ys-c-card ys-c-kpi" style="--i:1"><p class="ys-c-sobre">Prazos na semana</p><p class="ys-c-n"><span data-conta="9">9</span></p></div>
      <div class="ys-c-card ys-c-kpi is-destaque" style="--i:2"><p class="ys-c-sobre">Receita do mês</p><p class="ys-c-n">R$ <span data-conta="128" data-sufixo=".660">128.660</span></p></div>
    </div>
    <div class="ys-c-card ys-c-graf" style="--i:3">
      <p class="ys-c-sobre">Honorários recebidos, últimos 6 meses</p>
      <div class="ys-c-barras">
        <?php foreach ($meses as $n => [$m, $v]): ?>
        <div class="ys-c-col-b<?= $n === 5 ? ' is-atual' : '' ?>"><i style="--h:<?= $v ?>%;--i:<?= $n + 4 ?>"></i><span><?= $m ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php break;

endswitch; ?>
  </div>
  <figcaption>Ilustração com dados fictícios.</figcaption>
</figure>
<?php
        return ob_get_clean();
    }
}
