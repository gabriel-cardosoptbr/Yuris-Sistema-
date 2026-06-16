<?php
/**
 * secao-05-recursos.php, #recursos
 * 6 módulos (copy preservada da v1), grid de cards com bullets.
 */
$modulos = [
  ['<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>', 'Operação', 'Planejamento, prospecção e tarefas com responsáveis e prazos.', ['Planejamento', 'Prospecção', 'Tarefas com kanban']],
  ['<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>', 'Jurídico', 'Processos com histórico, prazos, vínculos e auditoria de ações.', ['Processos e movimentações', 'Intimações e prazos', 'Histórico processual', 'Vínculos entre advogados']],
  ['<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>', 'Comunicação', 'Atendimento centralizado, mensagens com contexto e histórico ligado ao cliente.', ['WhatsApp', 'Chat interno', 'Atendimento', 'Histórico de conversas']],
  ['<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', 'Gestão', 'Usuários, advogados, matriz e filial com permissões por escopo.', ['Usuários e advogados', 'Matriz e filial', 'Permissões granulares', 'Equipe']],
  ['<path d="M18 16.98h-5.99c-1.1 0-1.95.68-2.48 1.61A4 4 0 0 1 2 17c0-2.22 1.8-4 4-4h4"/><path d="m13 10 3-3-3-3"/><path d="M7.07 7.07A8.35 8.35 0 0 1 16 6c1.55 0 3 .43 4.23 1.17"/>', 'Automações', 'Webhooks e integração com n8n, Make e Zapier para conectar o Yuris a fluxos externos.', ['Webhooks por evento', 'Integrações externas', 'Fluxos automatizados']],
  ['<path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/>', 'Segurança', 'Logs de auditoria, controle de acesso, 2FA e recursos voltados à LGPD.', ['LGPD · Privacy Center', 'Auditoria imutável', '2FA opcional', 'Isolamento multi-tenant']],
];
?>
<section id="recursos" class="lp2-section">
  <div class="lp2-container">
    <div class="lp2-section-head lp2-reveal">
      <span class="lp2-eyebrow">Módulos</span>
      <h2>Tudo o que o escritório jurídico precisa, em um só lugar.</h2>
      <p>Cada módulo foi pensado para uma frente da operação, e todos conversam entre si por padrão.</p>
    </div>

    <div class="lp2-grid lp2-grid-3 lp2-reveal">
      <?php foreach ($modulos as [$icon, $titulo, $desc, $bullets]): ?>
      <div class="lp2-card">
        <div class="lp2-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg></div>
        <h3><?= $titulo ?></h3>
        <p><?= $desc ?></p>
        <ul><?php foreach ($bullets as $b): ?><li><?= $b ?></li><?php endforeach; ?></ul>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="lp2-section-cta lp2-reveal">
      <a href="<?= wa('Olá Bruno, quero conhecer todos os módulos do Yuris!') ?>" target="_blank" rel="noopener" class="lp2-btn lp2-btn-primary lp2-btn-wa">
        <?= $waSvg ?>
        Conhecer todos os módulos
      </a>
    </div>
  </div>
</section>
