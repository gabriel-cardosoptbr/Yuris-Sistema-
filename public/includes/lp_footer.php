<?php
/**
 * lp_footer.php — rodapé compartilhado das páginas públicas do Yuris.
 *
 * Mesmo visual do rodapé da landing (classes lp-footer do landing.css),
 * com a coluna "Soluções" linkando as páginas estratégicas de SEO.
 * A landing (index.php) mantém o rodapé próprio; este é usado pelas
 * páginas internas via seo_page.php.
 */
?>
<footer class="lp-footer">
  <div class="lp-container">
    <div class="lp-footer-grid">
      <div class="lp-footer-brand">
        <img src="/assets/img/logo-144.webp" alt="Yuris" width="36" height="36" loading="lazy" decoding="async">
        <p>Sistema Jurídico Inteligente. Controle, segurança e automação para a rotina de advogados e escritórios.</p>
      </div>
      <div class="lp-footer-col">
        <h5>Soluções</h5>
        <ul>
          <li><a href="/sistema-juridico/">Sistema Jurídico</a></li>
          <li><a href="/crm-juridico/">CRM Jurídico</a></li>
          <li><a href="/gestao-escritorio-advocacia/">Gestão de Escritório</a></li>
          <li><a href="/automacao-juridica/">Automação Jurídica</a></li>
          <li><a href="/controle-de-processos/">Controle de Processos</a></li>
          <li><a href="/prospeccao-juridica/">Prospecção Jurídica</a></li>
          <li><a href="/financeiro-juridico/">Financeiro Jurídico</a></li>
          <li><a href="/lgpd-escritorios-advocacia/">LGPD para Escritórios</a></li>
        </ul>
      </div>
      <div class="lp-footer-col">
        <h5>Produto</h5>
        <ul>
          <li><a href="/#recursos">Recursos</a></li>
          <li><a href="/planos.php">Planos</a></li>
          <li><a href="/demonstracao/">Demonstração</a></li>
          <li><a href="/blog/">Blog</a></li>
        </ul>
      </div>
      <div class="lp-footer-col">
        <h5>Empresa</h5>
        <ul>
          <li><a href="/sobre/">Sobre o Yuris</a></li>
          <li><a href="/#para-quem">Para quem é</a></li>
          <li><a href="/login.php">Entrar</a></li>
        </ul>
      </div>
      <div class="lp-footer-col">
        <h5>Legal</h5>
        <ul>
          <li><a href="/privacidade.php">Privacidade</a></li>
          <li><a href="/termos.php">Termos</a></li>
          <li><a href="/lgpd.php">LGPD</a></li>
          <li><a href="/cookies.php">Cookies</a></li>
          <li><a href="/dpo.php">Encarregado (DPO)</a></li>
        </ul>
      </div>
    </div>
    <div class="lp-footer-bottom">
      <span>&copy; <span data-year>2026</span> Yuris &middot; Sistema Jurídico Inteligente.</span>
      <span>O Yuris adota medidas técnicas e organizacionais voltadas à proteção de dados pessoais e segue em processo contínuo de adequação à LGPD.</span>
    </div>
  </div>
</footer>
