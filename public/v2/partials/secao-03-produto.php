<?php /* secao-03-produto.php, #produto (abas "O Yuris por dentro" — telas interativas) */
require_once __DIR__ . '/_cenas.php';
$telas = [
  ['dashboard', '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>', 'Dashboard', 'Visão geral da operação: processos ativos, prazos da semana, intimações novas e produtividade, tudo num relance.'],
  ['processos', '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/>', 'Processos', 'Lista filtrável com número, cliente, responsável, status e prazo. Cada linha leva ao histórico completo.'],
  ['tarefas', '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>', 'Tarefas', 'Kanban por etapa, a fazer, em andamento e concluído, com responsáveis e prazos claros.'],
  ['intimacoes', '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>', 'Intimações', 'Publicações recebidas com status, vínculo ao processo e ação rápida para virar prazo ou tarefa.'],
  ['comunicacao', '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>', 'Comunicação', 'Conversas de WhatsApp e chat interno conectadas ao cliente e ao processo, com histórico preservado.'],
  ['automacao', '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>', 'Automação', 'Webhooks por evento conectam o Yuris a CRMs, ERPs, n8n, Make e outros sistemas do escritório.'],
  ['matriz', '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>', 'Matriz e filiais', 'Compartilhe processos entre matriz e filiais com acesso por escopo, cada unidade vê só o que é seu.'],
];
?>
<section id="produto" class="lp2-section">
  <div class="lp2-container">
    <?php lp2_head('Visão do produto', 'O Yuris por dentro', 'Navegue entre as principais telas para ver como o sistema organiza a rotina jurídica.'); ?>
    <div class="lp2-reveal" data-tabs="mockups">
      <div class="lp2-tab-list" role="tablist">
        <?php foreach ($telas as $i => [$id, $ic, $nome, $desc]): ?>
        <button type="button" class="lp2-tab<?= $i === 0 ? ' active' : '' ?>" data-target="<?= $id ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><?= $ic ?></svg><?= $nome ?>
        </button>
        <?php endforeach; ?>
      </div>
      <?php foreach ($telas as $i => [$id, $ic, $nome, $desc]): ?>
      <div class="lp2-tab-pane<?= $i === 0 ? ' active' : '' ?>" data-pane="<?= $id ?>">
        <div class="lp2-screen">
          <div class="lp2-screen-text">
            <div class="lp2-feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><?= $ic ?></svg></div>
            <h3><?= $nome ?></h3><p><?= $desc ?></p>
          </div>
          <div class="lp2-screen-demo"><?= lp2_scene('tab-' . $id) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="lp2-section-cta lp2-reveal"><?= lp2_wa_btn('Olá Bruno, quero ver o Yuris rodando!', 'Quero ver o sistema rodando') ?></div>
  </div>
</section>
