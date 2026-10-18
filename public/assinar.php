<?php
/**
 * Página externa de assinatura de contrato (edição CRM). Sem login do CRM.
 * O link chega como /assinar#t=<token>: o fragmento não vai ao servidor (não cai
 * em log nem em Referer); assets/assinar.js troca o token por uma sessão curta
 * e apaga o fragmento. Regra em app/Contratos/Assinatura.php.
 */
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; worker-src 'self' blob: https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; "
    . "frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$v = @filemtime(__DIR__ . '/assets/assinar.js') ?: 1;
$c = @filemtime(__DIR__ . '/assets/assinar.css') ?: 1;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="referrer" content="no-referrer">
  <meta name="robots" content="noindex,nofollow">
  <title>Assinatura de contrato</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/assinar.css?v=<?= $c ?>">
</head>
<body>
  <main class="ass" id="ass" aria-live="polite">
    <div class="ass-carregando"><span class="ass-giro" aria-hidden="true"></span>Abrindo o contrato…</div>
  </main>
  <footer class="ass-rodape">
    Assinatura eletrônica com confirmação por código enviado ao WhatsApp. Seus dados são usados só para registrar esta assinatura e
    comprovar o contrato. <a href="/privacidade.php" target="_blank" rel="noopener noreferrer">Política de Privacidade</a>
  </footer>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script src="/assets/assinar.js?v=<?= $v ?>"></script>
</body>
</html>
