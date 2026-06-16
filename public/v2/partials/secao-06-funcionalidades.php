<?php /* secao-06-funcionalidades.php, #funcionalidades (vitrine) */
$itens = [
  ['v-processos', '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/>', 'Processos', 'Organize processos com histórico, responsáveis, vínculos e visão clara de andamento.'],
  ['v-intimacoes', '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>', 'Intimações', 'Centralize intimações, origem da consulta, OAB vinculada e associação ao processo.'],
  ['v-tarefas', '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>', 'Tarefas e prazos', 'Transforme prazos em tarefas rastreáveis, com responsáveis, status e alertas.'],
  ['v-prospeccao', '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>', 'Prospecção', 'Acompanhe oportunidades, leads e clientes em um funil visual para a operação jurídica.'],
  ['v-comunicacao', '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>', 'Comunicação', 'Centralize conversas e mantenha o histórico conectado ao cliente ou processo.'],
  ['v-automacoes', '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>', 'Automações', 'Dispare ações automáticas para n8n, Make, Zapier ou robôs próprios por meio de webhooks.'],
  ['v-lgpd', '<path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/>', 'LGPD e auditoria', 'Registre ações críticas, consentimentos, solicitações e logs para uma operação mais segura.'],
  ['v-matriz', '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>', 'Matriz e filial', 'Separe unidades, filiais, advogados e permissões sem perder a visão gerencial.'],
];
?>
<section id="funcionalidades" class="lp2-section">
  <div class="lp2-container">
    <?php lp2_head('Funcionalidades', 'Cada peça da rotina, no lugar certo.', 'Clique numa funcionalidade para ver como ela aparece dentro do sistema.'); ?>
    <div class="lp2-vitrine lp2-reveal" data-tabs="vitrine">
      <div class="lp2-vitrine-list">
        <?php foreach ($itens as $i => [$id, $ic, $nome, $desc]): ?>
        <button type="button" class="lp2-vit-item<?= $i === 0 ? ' active' : '' ?>" data-target="<?= $id ?>">
          <span class="lp2-vit-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $ic ?></svg><?= $nome ?></span>
          <p><?= $desc ?></p>
        </button>
        <?php endforeach; ?>
      </div>
      <div class="lp2-vitrine-mockup">
        <?php foreach ($itens as $i => [$id, $ic, $nome, $desc]): ?>
        <div class="lp2-vit-pane<?= $i === 0 ? ' active' : '' ?>" data-pane="<?= $id ?>">
          <div class="lp2-feature-pane">
            <?= lp2_ill($id) ?>
            <h3><?= $nome ?></h3><p><?= $desc ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="lp2-section-cta lp2-reveal"><?= lp2_wa_btn('Olá Bruno, quero ver as funcionalidades do Yuris funcionando!', 'Quero testar as funcionalidades') ?></div>
  </div>
</section>
