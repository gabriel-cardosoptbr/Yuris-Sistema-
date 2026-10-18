<?php
/**
 * Consulta pública de autenticidade de um contrato pelo código de verificação.
 * Mostra situação, identificadores, hashes e o selo do manifesto. NÃO mostra
 * nomes, CPF, contatos, o conteúdo do contrato nem as evidências pessoais.
 * O hash de um PDF é conferido no navegador (assets/verificar.js): o arquivo
 * não sai do computador de quem consulta.
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Contratos\Armazenamento;
use App\Contratos\Contrato;
use App\Contratos\Cripto;
use App\Contratos\Manifesto;
use App\Contratos\Tempo;
use App\Core\Database;
use App\Master\Marca;

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$bruto = (string) ($_GET['c'] ?? '');
$codigo = $bruto !== '' ? Cripto::normalizarCodigoVerificacao($bruto) : null;
$c = null;
$dados = null;
if ($codigo) {
    $pdo = Database::getConnection();
    $st = $pdo->prepare('SELECT c.*, a.nome AS conta_nome, a.configuracoes FROM crm_contratos c JOIN accounts a ON a.id = c.account_id WHERE c.codigo_verificacao = ?');
    $st->execute([$codigo]);
    $c = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    if ($c) {
        $acc = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $acc->execute([(int) $c['account_id']]);
        $marca = Marca::daConta($acc->fetch(\PDO::FETCH_ASSOC));
        $v = $pdo->prepare('SELECT uuid, numero, sha256, status FROM crm_contrato_versoes WHERE contrato_id = ? ORDER BY numero DESC');
        $v->execute([(int) $c['id']]);
        $versoes = $v->fetchAll(\PDO::FETCH_ASSOC);
        $as = $pdo->prepare('SELECT a.uuid, a.assinado_em, a.metodo, p.papel, v.numero FROM crm_contrato_assinaturas a
                               JOIN crm_contrato_participantes p ON p.id = a.participante_id JOIN crm_contrato_versoes v ON v.id = a.versao_id
                              WHERE a.contrato_id = ? ORDER BY a.assinado_em');
        $as->execute([(int) $c['id']]);
        $ar = $pdo->prepare("SELECT tipo, sha256, caminho FROM crm_contrato_arquivos WHERE contrato_id = ? AND tipo IN ('comprovante','conclusao','manifesto') ORDER BY id");
        $ar->execute([(int) $c['id']]);
        $arquivos = [];
        $selo = null;
        foreach ($ar->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            $arquivos[$a['tipo']] = $a['sha256'];
            if ($a['tipo'] === 'manifesto') {
                $m = json_decode((string) Armazenamento::ler((string) $a['caminho'], (string) $a['sha256']), true);
                $selo = is_array($m) ? Manifesto::conferir($m) + ['chave_id' => $m['selo']['chave_id'] ?? null] : ['valido' => false, 'motivo' => 'Manifesto ilegível.'];
            }
        }
        $dados = ['marca' => $marca, 'versoes' => $versoes, 'assinaturas' => $as->fetchAll(\PDO::FETCH_ASSOC), 'arquivos' => $arquivos, 'selo' => $selo];
    }
}
$chave = Cripto::chavePublica();
$hashes = [];
if ($dados) {
    foreach ($dados['versoes'] as $v) $hashes[$v['sha256']] = 'o PDF original da versão ' . (int) $v['numero'] . ($v['status'] === 'substituida' ? ' (substituída)' : '');
    $nomes = ['comprovante' => 'o comprovante de assinaturas', 'conclusao' => 'o PDF de conclusão (contrato + comprovante)'];
    foreach ($dados['arquivos'] as $t => $s) if (isset($nomes[$t])) $hashes[$s] = $nomes[$t];
}
$vjs = @filemtime(__DIR__ . '/assets/verificar.js') ?: 1;
$cor = $dados['marca']['paleta']['marca'] ?? '#015DFC';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Verificação de contrato</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/assinar.css?v=<?= @filemtime(__DIR__ . '/assets/assinar.css') ?: 1 ?>">
  <style>:root{--marca:<?= $h($cor) ?>}</style>
</head>
<body>
<main class="ass">
  <?php if ($dados && !empty($dados['marca']['logo_url'])): ?>
  <header class="ass-cab"><img class="ass-logo" src="<?= $h($dados['marca']['logo_url']) ?>" alt="<?= $h($dados['marca']['nome']) ?>"></header>
  <?php endif; ?>
  <div class="ass-titulo"><span class="ass-rot">Consulta de autenticidade</span><h1>Verificação de contrato</h1></div>

  <section class="ass-cartao">
    <form method="get" action="/verificar">
      <label for="c">Código de verificação</label>
      <input id="c" name="c" value="<?= $h($codigo ?? $bruto) ?>" placeholder="XXXX-XXXX-XXXX" maxlength="20" autocomplete="off" required>
      <button class="ass-btn" type="submit">Consultar</button>
    </form>
  </section>

  <?php if ($bruto !== '' && !$c): ?>
  <section class="ass-cartao"><p class="ass-msg">Nenhum contrato com este código. Confira as letras e os números.</p></section>
  <?php elseif ($c): ?>
  <section class="ass-cartao">
    <h2>Registro encontrado</h2>
    <div class="ass-dado"><span>Emitido por</span><b><?= $h($dados['marca']['nome'] ?? '') ?></b></div>
    <div class="ass-dado"><span>Situação</span><b><?= $h(Contrato::STATUS[$c['status']] ?? $c['status']) ?></b></div>
    <?php if ($c['concluido_em']): ?><div class="ass-dado"><span>Concluído em</span><b><?= $h(Tempo::local($c['concluido_em'], true)) ?> (Brasília, registrado pelo servidor)</b></div><?php endif; ?>
    <div class="ass-dado"><span>Código</span><b><?= $h($c['codigo_verificacao']) ?></b></div>
    <div class="ass-dado"><span>Identificador do contrato</span><b class="ass-hash"><?= $h($c['uuid']) ?></b></div>
    <?php foreach ($dados['versoes'] as $v): ?>
    <div class="ass-dado"><span>Versão <?= (int) $v['numero'] ?><?= $v['status'] === 'substituida' ? ' (substituída)' : '' ?></span><b class="ass-hash"><?= $h($v['uuid']) ?><br>SHA-256 <?= $h($v['sha256']) ?></b></div>
    <?php endforeach; ?>
    <?php foreach (['comprovante' => 'Comprovante', 'conclusao' => 'PDF de conclusão'] as $t => $rot): if (!empty($dados['arquivos'][$t])): ?>
    <div class="ass-dado"><span><?= $rot ?></span><b class="ass-hash">SHA-256 <?= $h($dados['arquivos'][$t]) ?></b></div>
    <?php endif; endforeach; ?>
  </section>

  <section class="ass-cartao">
    <h2>Assinaturas registradas</h2>
    <?php if (!$dados['assinaturas']): ?><p class="ass-nota">Nenhuma assinatura registrada ainda.</p><?php endif; ?>
    <?php foreach ($dados['assinaturas'] as $a): ?>
    <div class="ass-dado"><span><?= $h(Contrato::PAPEIS[$a['papel']] ?? $a['papel']) ?> · versão <?= (int) $a['numero'] ?></span>
      <b><?= $h(Tempo::local($a['assinado_em'], true)) ?><br><span class="ass-hash"><?= $h($a['uuid']) ?></span></b></div>
    <?php endforeach; ?>
    <p class="ass-nota">Método: assinatura eletrônica com confirmação por código de uso único enviado ao WhatsApp. Não é assinatura qualificada (ICP-Brasil).
      Nomes, documentos e contatos não aparecem nesta consulta.</p>
  </section>

  <section class="ass-cartao">
    <h2>Selo da plataforma</h2>
    <?php if ($dados['selo'] === null): ?>
      <p class="ass-nota">O manifesto de evidências é gerado quando o contrato é concluído.</p>
    <?php elseif ($dados['selo']['valido']): ?>
      <p class="ass-ok">Manifesto íntegro: o selo Ed25519 confere com a chave <?= $h($dados['selo']['chave_id']) ?>.</p>
    <?php else: ?>
      <p class="ass-aviso erro"><?= $h($dados['selo']['motivo']) ?></p>
    <?php endif; ?>
    <p class="ass-nota">O selo prova que o manifesto saiu desta plataforma e não mudou. Ele não substitui a manifestação de vontade de cada participante.
      <?php if ($chave): ?>Chave pública (<?= $h($chave['algoritmo']) ?>, <?= $h($chave['chave_id']) ?>): <span class="ass-hash"><?= $h($chave['chave_publica']) ?></span><?php endif; ?></p>
  </section>

  <section class="ass-cartao">
    <h2>Conferir um arquivo</h2>
    <p>Escolha o PDF que você recebeu. O hash é calculado no seu navegador; o arquivo não é enviado.</p>
    <input type="file" id="arquivo" accept="application/pdf">
    <div class="ass-aviso" id="resultado" hidden></div>
    <script type="application/json" id="hashes"><?= json_encode($hashes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
  </section>
  <?php endif; ?>
</main>
<footer class="ass-rodape">Horários registrados pelo relógio do servidor, sem carimbo do tempo de terceiro.</footer>
<script src="/assets/verificar.js?v=<?= $vjs ?>"></script>
</body>
</html>
