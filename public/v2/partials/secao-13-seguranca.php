<?php /* secao-13-seguranca.php, #seguranca (grid 6 cards) */
$itens = [
  ['<path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/>', 'Isolamento multi-tenant', 'Cada conta opera em escopo próprio, com dados protegidos por padrão a nível de banco e aplicação.'],
  ['<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>', 'Logs de auditoria', 'Ações sensíveis registradas com responsável, hora e contexto, base para qualquer verificação interna.'],
  ['<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', '2FA e controle de acesso', 'Autenticação em dois fatores opcional e permissões granulares por perfil, módulo e escopo.'],
  ['<path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/><polyline points="9 12 11 14 15 10"/>', 'Privacy Center (LGPD)', 'Solicitações de acesso, retificação e exclusão tratadas em um fluxo dedicado, com registro e prazo.'],
  ['<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><line x1="10" y1="13" x2="14" y2="13"/>', 'Histórico imutável', 'O histórico de processos é registrado de forma protegida contra alteração e exclusão.'],
  ['<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>', 'Em adequação contínua', 'Política de privacidade, termos, mapeamento e processos em evolução constante junto da legislação.'],
];
?>
<section id="seguranca" class="lp2-section">
  <div class="lp2-container">
    <?php lp2_head('Segurança', 'Segurança não é detalhe. É parte da operação jurídica.', 'O Yuris adota uma estrutura voltada para controle, rastreabilidade e proteção de dados, com logs de auditoria, permissões por escopo e recursos preparados para apoiar a adequação à LGPD.'); ?>
    <div class="lp2-grid lp2-grid-3 lp2-reveal">
      <?php foreach ($itens as [$ic, $t, $d]): ?>
      <div class="lp2-card">
        <div class="lp2-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $ic ?></svg></div>
        <h3><?= $t ?></h3><p><?= $d ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="lp2-section-cta lp2-reveal"><?= lp2_wa_btn('Olá Bruno, quero saber sobre segurança e LGPD no Yuris!', 'Falar sobre segurança e LGPD') ?></div>
  </div>
</section>
