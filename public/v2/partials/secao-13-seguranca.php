<?php
/**
 * secao-13-seguranca.php, #seguranca: seis garantias em células, só texto.
 * Reúne o que antes também aparecia em "Seis pilares".
 */
$itens = [
  ['Isolamento entre escritórios', 'Cada conta opera em escopo próprio, com dados separados por padrão no banco e na aplicação.'],
  ['Logs de auditoria', 'Ações sensíveis registradas com responsável, hora e contexto, base para qualquer verificação interna.'],
  ['2FA e controle de acesso', 'Autenticação em dois fatores opcional e permissões por perfil, módulo e escopo.'],
  ['Central de privacidade (LGPD)', 'Pedidos de acesso, retificação e exclusão tratados em um fluxo dedicado, com registro e prazo.'],
  ['Histórico imutável', 'O histórico dos processos é registrado de forma protegida contra alteração e exclusão.'],
  ['Em adequação contínua', 'Política de privacidade, termos, mapeamento e processos em evolução junto com a legislação.'],
];
?>
<section id="seguranca" class="ys-section">
  <div class="ys-container">
    <?php lp2_head('', 'Segurança não é detalhe. É parte da operação jurídica.', 'O Yuris adota uma estrutura voltada para controle, rastreabilidade e proteção de dados, com logs de auditoria, permissões por escopo e recursos preparados para apoiar a adequação à LGPD.'); ?>
    <div class="ys-ruled ys-reveal">
      <?php foreach ($itens as [$t, $d]): ?>
      <div class="ys-ruled-cell">
        <h3><?= $t ?></h3>
        <p><?= $d ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="ys-section-cta ys-reveal"><?= lp2_wa_btn('Olá Bruno, quero saber sobre segurança e LGPD no Yuris!', 'Falar sobre segurança e LGPD') ?></div>
  </div>
</section>
