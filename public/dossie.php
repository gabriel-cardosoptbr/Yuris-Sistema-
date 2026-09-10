<?php
/**
 * dossie.php — o relatório completo de UM cliente, prospecção ou processo.
 *
 * ?entidade=cliente|card|processo&id=N
 *
 * ---------------------------------------------------------------------------
 * POR QUE É UMA PÁGINA, E NÃO UM PDF GERADO NO SERVIDOR
 * ---------------------------------------------------------------------------
 * O projeto não usa Composer e não tem `vendor/`. Gerar PDF no servidor exigiria
 * embutir uma biblioteca inteira, e o resultado seria PIOR: layout rígido,
 * acentuação frágil, e um documento que ninguém consegue ajustar.
 *
 * A página impressa pelo navegador resolve o mesmo problema melhor. O botão
 * Imprimir abre a caixa do próprio navegador, onde "Salvar como PDF" é uma das
 * opções, e o arquivo sai com texto pesquisável, acento correto e quebra de
 * página decente. É a mesma coisa que o sistema do tribunal faz.
 *
 * Quem quiser o dado para cruzar em planilha usa o botão da direita, que baixa
 * CSV pelo /api/relatorios.php.
 *
 * ---------------------------------------------------------------------------
 * SEM SIDEBAR, DE PROPÓSITO
 * ---------------------------------------------------------------------------
 * É um documento, não uma tela do sistema: abre em aba própria, com o miolo
 * desenhado como folha. O menu lateral aqui só roubaria largura da folha e
 * apareceria como faixa cinza na impressão de quem esquecesse de desmarcar
 * "gráficos de fundo".
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;
use App\Relatorios\Dossie;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

$entidade = strtolower(trim((string) ($_GET['entidade'] ?? '')));
$id       = (int) ($_GET['id'] ?? 0);

/**
 * Página de recusa. Usada nos três casos (parâmetro inválido, sem permissão,
 * não encontrado) com textos diferentes, mas sem nunca dizer se o registro
 * existe em outra conta.
 */
function dossie_erro(string $titulo, string $texto): void
{
    http_response_code($titulo === 'Acesso negado' ? 403 : 404);
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($titulo) . ' — Yuris</title>';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#070F1C;color:#cbd5e1;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px;text-align:center}'
       . 'h1{margin:0 0 10px;font-size:22px;color:#e2e8f0}p{margin:0 0 18px;max-width:460px;line-height:1.6}'
       . 'a{color:#60a5fa}</style>';
    echo '<div><h1>' . htmlspecialchars($titulo) . '</h1><p>' . htmlspecialchars($texto) . '</p>';
    echo '<p><a href="javascript:window.close()">Fechar esta aba</a></p></div>';
    exit;
}

if ($id <= 0 || !isset(Dossie::MODULOS[$entidade])) {
    dossie_erro('Relatório não encontrado', 'O endereço não indica um registro válido. Volte à tela anterior e clique de novo no botão de relatório.');
}

$modulo = Dossie::MODULOS[$entidade];

// Gate de permissão de tela, igual ao das telas de origem. O gate de DADO vem
// logo depois, no escopo de contas passado ao Dossie: são dois, e nenhum
// substitui o outro.
$_isAdmin = strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin';
$_perms   = (array) ($_SESSION['user_permissions'] ?? []);
if (!$_isAdmin && !in_array('*', $_perms, true) && !in_array($modulo, $_perms, true)) {
    dossie_erro('Acesso negado', 'Você não tem permissão para ver este módulo. Peça ao administrador do escritório.');
}

$doc = Dossie::montar($entidade, $id, $ctx->getAccessibleAccountIds($modulo));
if ($doc === null) {
    dossie_erro('Relatório não encontrado', 'Este registro não existe, foi excluído, ou não pertence ao seu escritório.');
}

$rotuloEntidade = ['cliente' => 'Cliente', 'card' => 'Prospecção', 'processo' => 'Processo'][$entidade];
$csvUrl = '/api/relatorios.php?acao=dossie&formato=csv&entidade=' . urlencode($entidade) . '&id=' . $id;

/** Escapa para HTML. O dossiê imprime nome de cliente e texto de anotação: nada vai cru. */
function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($doc['titulo']) ?> — Relatório — Yuris</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/relatorios.css?v=<?= @filemtime(__DIR__ . '/assets/relatorios.css') ?: 1 ?>">
</head>
<body class="doc-body">

  <!-- A barra some na impressão: ver .no-print em relatorios.css -->
  <div class="doc-bar no-print">
    <div class="doc-bar-info">
      <span class="doc-bar-tag"><?= e($rotuloEntidade) ?></span>
      <strong><?= e($doc['titulo']) ?></strong>
    </div>
    <div class="doc-bar-acoes">
      <a class="btn-doc" href="<?= e($csvUrl) ?>">Baixar planilha</a>
      <button type="button" class="btn-doc btn-doc-primario" id="btnImprimir">Imprimir ou salvar em PDF</button>
    </div>
  </div>

  <main class="folha" role="main">

    <header class="folha-topo">
      <div>
        <p class="folha-conta"><?= e($doc['conta']) ?></p>
        <h1><?= e($doc['titulo']) ?></h1>
        <p class="folha-sub"><?= e($doc['subtitulo']) ?></p>
      </div>
      <div class="folha-meta">
        <span>Gerado em <?= e($doc['gerado_em']) ?></span>
        <span>Registro nº <?= (int) $doc['id'] ?></span>
      </div>
    </header>

    <?php if (!empty($doc['resumo'])): ?>
    <section class="resumo">
      <?php
        $rotulosResumo = [
            'documentos' => 'documentos', 'interacoes' => 'contatos', 'processos' => 'processos',
            'tarefas' => 'tarefas', 'prazos' => 'prazos', 'conversas' => 'conversas',
            'timeline' => 'movimentos',
        ];
        foreach ($doc['resumo'] as $chave => $qtd):
      ?>
        <div class="resumo-item">
          <strong><?= (int) $qtd ?></strong>
          <span><?= e($rotulosResumo[$chave] ?? $chave) ?></span>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="bloco">
      <h2>Identificação</h2>
      <?php if ($doc['identificacao'] === []): ?>
        <p class="vazio">Sem dados de cadastro preenchidos.</p>
      <?php else: ?>
        <dl class="pares">
          <?php foreach ($doc['identificacao'] as $p): ?>
            <div class="par">
              <dt><?= e($p['rotulo']) ?></dt>
              <dd><?= nl2br(e($p['valor'])) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>
    </section>

    <?php foreach ($doc['blocos'] as $b): ?>
    <section class="bloco">
      <h2>
        <?= e($b['titulo']) ?>
        <?php if ((int) ($b['total'] ?? 0) > 0 && ($b['tipo'] ?? '') !== 'pares'): ?>
          <span class="conta"><?= (int) $b['total'] ?></span>
        <?php endif; ?>
      </h2>

      <?php if ((int) ($b['total'] ?? 0) === 0): ?>
        <p class="vazio"><?= e($b['vazio'] ?? 'Sem registros.') ?></p>

      <?php elseif ($b['tipo'] === 'pares'): ?>
        <dl class="pares">
          <?php foreach ($b['itens'] as $p): ?>
            <div class="par"><dt><?= e($p['rotulo']) ?></dt><dd><?= nl2br(e($p['valor'])) ?></dd></div>
          <?php endforeach; ?>
        </dl>

      <?php elseif ($b['tipo'] === 'chips'): ?>
        <p class="chips">
          <?php foreach ($b['itens'] as $t): ?>
            <span class="chip"><?= e($t['nome']) ?></span>
          <?php endforeach; ?>
        </p>

      <?php elseif ($b['tipo'] === 'tabela'): ?>
        <div class="tabela-rolagem">
          <table>
            <thead>
              <tr><?php foreach ($b['colunas'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
              <?php foreach ($b['linhas'] as $l): ?>
                <tr><?php foreach ($l as $celula): ?><td><?= nl2br(e($celula)) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      <?php elseif ($b['tipo'] === 'timeline'): ?>
        <ol class="linha-tempo">
          <?php foreach ($b['itens'] as $ev): ?>
            <li class="evento">
              <div class="evento-quando"><?= e($ev['quando']) ?></div>
              <div class="evento-corpo">
                <p class="evento-acao">
                  <?= e($ev['acao']) ?>
                  <?php if (!empty($ev['campo'])): ?><span class="evento-campo"><?= e($ev['campo']) ?></span><?php endif; ?>
                  <?php if (!empty($ev['fase'])): ?><span class="evento-fase"><?= e($ev['fase']) ?></span><?php endif; ?>
                </p>
                <?php if (($ev['de'] ?? null) !== null || ($ev['para'] ?? null) !== null): ?>
                  <p class="evento-valores">
                    <?php if (($ev['de'] ?? null) !== null): ?><span class="de"><?= e($ev['de']) ?></span><?php endif; ?>
                    <?php if (($ev['de'] ?? null) !== null && ($ev['para'] ?? null) !== null): ?><span class="seta">&rarr;</span><?php endif; ?>
                    <?php if (($ev['para'] ?? null) !== null): ?><span class="para"><?= e($ev['para']) ?></span><?php endif; ?>
                  </p>
                <?php endif; ?>
                <p class="evento-quem"><?= e($ev['usuario']) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>

    <footer class="folha-rodape">
      Documento gerado pelo Yuris em <?= e($doc['gerado_em']) ?>.
      Reflete o que estava registrado no sistema neste momento.
    </footer>

  </main>

  <script>
    document.getElementById('btnImprimir').addEventListener('click', function () {
      window.print();
    });
  </script>
</body>
</html>
