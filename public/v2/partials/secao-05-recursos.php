<?php
/**
 * secao-05-recursos.php, #recursos
 * 6 módulos (texto preservado) num mosaico de proporções diferentes: Operação e
 * Jurídico abrem grandes, Jurídico em azul por ser o coração do produto, e os
 * demais fecham em colunas. Cada card tem o seu peso; não é grade de iguais.
 */
$modulos = [
  ['<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>', 'Operação', 'Planejamento, prospecção, clientes e tarefas com responsáveis e prazos.', ['Planejamento', 'Prospecção e funil', 'Clientes com ficha completa', 'Documentos e etiquetas', 'Tarefas com kanban'], 'is-wide'],
  ['<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>', 'Jurídico', 'Processos com histórico, prazos, vínculos e auditoria de ações.', ['Processos e movimentações', 'Intimações e prazos', 'Histórico processual', 'Vínculos entre advogados'], 'ys-card--feature'],
  ['<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>', 'Comunicação', 'Atendimento centralizado, mensagens com contexto e histórico ligado ao cliente.', ['WhatsApp', 'Chat interno', 'Atendimento', 'Histórico de conversas'], ''],
  ['<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', 'Gestão', 'Usuários, advogados, matriz e filial com permissões por escopo.', ['Usuários e advogados', 'Matriz e filial', 'Permissões granulares', 'Equipe'], ''],
  ['<path d="M18 16.98h-5.99c-1.1 0-1.95.68-2.48 1.61A4 4 0 0 1 2 17c0-2.22 1.8-4 4-4h4"/><path d="m13 10 3-3-3-3"/><path d="M7.07 7.07A8.35 8.35 0 0 1 16 6c1.55 0 3 .43 4.23 1.17"/>', 'Automações', 'Webhooks e integração com n8n, Make e Zapier para conectar o Yuris a fluxos externos.', ['Webhooks por evento', 'Integrações externas', 'Fluxos automatizados'], ''],
  ['<path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/>', 'Segurança', 'Logs de auditoria, controle de acesso, 2FA e recursos voltados à LGPD.', ['LGPD · Privacy Center', 'Auditoria imutável', '2FA opcional', 'Isolamento multi-tenant'], 'ys-card--navy'],
];
?>
<section id="recursos" class="ys-section ys-bg-tint">
  <div class="ys-container">
    <?php lp2_head('Módulos', 'Tudo o que o escritório jurídico precisa, em um só lugar.', 'Cada módulo foi pensado para uma frente da operação, e todos conversam entre si por padrão.'); ?>

    <div class="ys-mosaic">
      <?php foreach ($modulos as $i => [$icon, $titulo, $desc, $bullets, $mod]): ?>
      <article class="ys-card is-hover ys-mod ys-mod-<?= $i + 1 ?> <?= $mod ?> ys-reveal">
        <div class="ys-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon ?></svg></div>
        <div class="ys-mod-body">
          <h3><?= $titulo ?></h3>
          <p><?= $desc ?></p>
        </div>
        <ul class="ys-mod-tags"><?php foreach ($bullets as $b): ?><li><?= $b ?></li><?php endforeach; ?></ul>
      </article>
      <?php endforeach; ?>
    </div>

    <div class="ys-section-cta ys-reveal"><?= lp2_wa_btn('Olá Bruno, quero conhecer todos os módulos do Yuris!', 'Conhecer todos os módulos') ?></div>
  </div>
</section>
