<?php
/**
 * Política de Cookies — modelo inicial (LGPD Etapa 5).
 * Revisão jurídica obrigatória antes de publicar.
 */
$LEGAL_PAGE = [
    'titulo'    => 'Política de Cookies',
    'descricao' => 'Quais cookies utilizamos e por quê.',
    'versao'    => '2026-05-23 (modelo inicial — pendente revisão jurídica)',
    'corpo_html' => <<<HTML
<h2>1. O que são cookies</h2>
<p>Cookies são pequenos arquivos armazenados no seu dispositivo pelo navegador.
Permitem que o site reconheça suas preferências, autentique você, e meça uso.</p>

<h2>2. Cookies que utilizamos</h2>

<h3>2.1 Essenciais (sempre ativos)</h3>
<p>Necessários para o sistema funcionar. Sem eles, o login e a segurança da
sessão ficam comprometidos. Não podem ser desativados.</p>
<ul>
  <li><strong>PHPSESSID</strong> — identifica sua sessão autenticada (HttpOnly, Secure em HTTPS, SameSite=Lax).</li>
  <li><strong>csrf_token</strong> — token anti-CSRF protegendo formulários e requisições.</li>
  <li><strong>yuris_cookie_consent</strong> (localStorage) — registra suas escolhas no banner de cookies.</li>
</ul>

<h3>2.2 Funcionais (opt-in)</h3>
<p>Melhoram a experiência mas não são essenciais.</p>
<ul>
  <li><strong>yuris_theme</strong> (localStorage) — preferência de tema claro/escuro.</li>
</ul>

<h3>2.3 Analíticos (opt-in)</h3>
<p>Carregamento de recursos externos que enviam seu IP a terceiros.</p>
<ul>
  <li><strong>Google Fonts</strong> — carrega fontes Inter/Poppins do Google. Envia IP e User-Agent.</li>
  <li><strong>jsDelivr / Cloudflare</strong> — carrega bibliotecas JS (Tailwind, Chart.js, SortableJS, qrcode-generator) de CDN. Envia IP.</li>
</ul>

<h3>2.4 Marketing</h3>
<p>Não utilizamos cookies de marketing/publicidade hoje. Reservado para o futuro.</p>

<h2>3. Como gerenciar</h2>
<p>Use o banner de cookies para escolher suas preferências. Você pode reabrir o
banner a qualquer momento clicando em <a href="javascript:if(window.YurisCookies){YurisCookies.open()}else{location.reload()}">Gerenciar cookies</a>.</p>
<p>Você também pode controlar cookies diretamente nas configurações do seu navegador. Note que desativar cookies essenciais impedirá o uso do sistema.</p>

<h2>4. Transferência internacional</h2>
<p>Os cookies analíticos (Google Fonts, jsDelivr) envolvem transferência de dados (IP, User-Agent) para servidores nos EUA. Avalie esse aspecto ao decidir aceitar ou recusar.</p>

<h2>5. Atualizações</h2>
<p>Esta Política pode mudar. Quando alteramos categorias ou cookies, o banner volta a aparecer pedindo novo consentimento.</p>

<h2>6. Mais informações</h2>
<p>Veja nossa <a href="/sistema_vendas/public/privacidade.php">Política de Privacidade</a> completa ou entre em contato com o <a href="/sistema_vendas/public/dpo.php">DPO</a>.</p>
HTML
];
require __DIR__ . '/includes/legal_page.php';
