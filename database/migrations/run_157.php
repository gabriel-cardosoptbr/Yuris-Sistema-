<?php
/**
 * Migration 157: endereços de página já gravados no banco, sem ".php".
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Desde 20/10/2026 o sistema gera os endereços das páginas sem ".php"
 * (/tarefas?tarefa=26, não /tarefas.php?tarefa=26). O que já estava gravado
 * continuava com ".php" e abria por um redirecionamento a mais. Esta migration
 * acerta o que foi gravado antes:
 *
 *   account_notifications.url       /tarefas.php?tarefa=26   ->  /tarefas?tarefa=26
 *   chat_mencoes.url_destino        /sistema_vendas/public/processos.php?id=56
 *   pending_reviews.link_referencia     (caminho de desenvolvimento, que nem abria em
 *                                       produção)        ->  /processos?id=56
 *   projeto_acessos.url             https://yuris.com.br/login.php  ->  .../login
 *                                   (só endereços dos nossos domínios)
 *
 * Só troca o texto do endereço, e só de PÁGINA da raiz de public/ (a lista sai
 * de public/*.php). API (/api/...), index, login-fleetiflow, 404 e endereço de
 * fora (tribunais, por exemplo) ficam como estão.
 * IDEMPOTENTE: rodar de novo não acha mais nada.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_157.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_157.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 157' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$ficam = ['index', 'index-v1-legacy', 'login-fleetiflow', '404'];
$paginas = array_values(array_diff(array_map(fn($f) => basename($f, '.php'), glob(__DIR__ . '/../../public/*.php') ?: []), $ficam));
if (!$paginas) { echo "  ! não achei as páginas em public/: nada feito\n"; exit(1); }
usort($paginas, fn($a, $b) => strlen($b) <=> strlen($a));
$alt = implode('|', array_map(fn($p) => preg_quote($p, '#'), $paginas));
$nossos = '#^https?://(www\.)?(yuris\.com\.br|yuris\.inovaize\.com|crm\.[a-z0-9.-]+)(?=/)#i';

/** O endereço limpo, ou o mesmo se não for página nossa. */
$limpar = function (string $u, bool $prefixoDev, bool $soNossos) use ($alt, $nossos): string {
    $base = '';
    if (preg_match('#^https?://#i', $u)) {
        if (!$soNossos || !preg_match($nossos, $u, $m)) return $u;
        $base = $m[0];
        $u = substr($u, strlen($base));
    }
    if ($prefixoDev) $u = (string) preg_replace('#^/sistema_vendas/public(?=/)#', '', $u);
    return $base . (string) preg_replace('#^/(' . $alt . ')\.php(?=[?\#]|$)#i', '/$1', $u);
};

$existe = function (string $t) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (int) $st->fetchColumn() > 0;
};

// [tabela, coluna da chave, coluna do endereço, tira o prefixo de dev, aceita endereço absoluto dos nossos domínios]
$alvos = [
    ['account_notifications', 'id', 'url', false, false],
    ['chat_mencoes', 'id', 'url_destino', true, false],
    ['pending_reviews', 'id', 'link_referencia', true, false],
    ['projeto_acessos', 'id', 'url', false, true],
];
foreach ($alvos as [$t, $pk, $col, $dev, $abs]) {
    if (!$existe($t)) { echo "  = $t não existe aqui\n"; continue; }
    $sel = $pdo->query("SELECT `$pk` AS id, `$col` AS u FROM `$t` WHERE `$col` LIKE '%.php%'");
    $upd = $pdo->prepare("UPDATE `$t` SET `$col` = ? WHERE `$pk` = ?");
    $n = 0; $ex = [];
    foreach ($sel->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $novo = $limpar((string) $r['u'], $dev, $abs);
        if ($novo === $r['u']) continue;
        $n++;
        if (count($ex) < 2) $ex[] = $r['u'] . ' -> ' . $novo;
        if (!$dryRun) $upd->execute([$novo, $r['id']]);
    }
    echo "  " . ($n ? '+' : '=') . " $t.$col: $n endereço(s)" . ($ex ? "  (ex.: " . implode(' | ', $ex) . ")" : '') . "\n";
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
