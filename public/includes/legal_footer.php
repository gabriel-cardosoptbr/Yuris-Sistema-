<?php
/**
 * legal_footer.php — footer reutilizável com links LGPD.
 *
 * Include simples para inserir em qualquer página (planos.php, login.php, etc):
 *   <?php include __DIR__ . '/includes/legal_footer.php'; ?>
 *
 * Renderiza HTML inline com estilos próprios (sem dependência de CSS externo).
 */
?>
<footer class="legal-footer-global" style="
  margin-top: 48px;
  padding: 24px 22px;
  background: rgba(8, 12, 24, .65);
  border-top: 1px solid rgba(96, 165, 250, .12);
  color: #94a3b8;
  font-family: Inter, system-ui, sans-serif;
  font-size: 13px;
  line-height: 1.5;
">
  <div style="max-width: 1180px; margin: 0 auto; display: flex; gap: 16px 28px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
    <div>
      © <?= date('Y') ?> Yuris — Sistema jurídico SaaS &middot;
      <span style="opacity:.7">Em processo contínuo de adequação à LGPD</span>
    </div>
    <nav style="display: flex; gap: 18px; flex-wrap: wrap;">
      <a href="/sistema_vendas/public/privacidade.php" style="color: #7eb8f7; text-decoration: none;">Privacidade</a>
      <a href="/sistema_vendas/public/termos.php"      style="color: #7eb8f7; text-decoration: none;">Termos</a>
      <a href="/sistema_vendas/public/cookies.php"     style="color: #7eb8f7; text-decoration: none;">Cookies</a>
      <a href="/sistema_vendas/public/lgpd.php"        style="color: #7eb8f7; text-decoration: none;">LGPD &amp; Segurança</a>
      <a href="/sistema_vendas/public/dpo.php"         style="color: #7eb8f7; text-decoration: none;">Encarregado (DPO)</a>
      <a href="javascript:if(window.YurisCookies){YurisCookies.open()}else{location.reload()}"
         style="color: #94a3b8; text-decoration: none;">Gerenciar cookies</a>
    </nav>
  </div>
</footer>
