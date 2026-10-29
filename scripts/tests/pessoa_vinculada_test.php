<?php
/**
 * pessoa_vinculada_test.php: a mesma pessoa em mais de uma conta
 * (App\Usuarios\PessoaVinculada, migration 169).
 *
 *   1. Sem banco: o formato da sessão emprestada abre igual ao do PHP.
 *   2. Com banco (contas locais 476 e 304): vínculo, lista de empresas, a sessão
 *      montada para a outra conta, a troca de empresa, quem não é a mesma pessoa
 *      não troca.
 *   3. Por HTTP (php -S nesta pasta): a chamada do WhatsApp para um número da
 *      outra conta roda como o usuário da pessoa lá, a sessão de verdade não muda,
 *      e quem não tem vínculo continua recusado.
 *
 * Desfaz tudo no fim. Uso: php scripts/tests/pessoa_vinculada_test.php
 */
if (PHP_SAPI !== 'cli') exit;
// Sessão do PHP no CLI só abre antes de qualquer saída: o resultado sai todo no fim.
ob_start();
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Usuarios\PessoaVinculada as P;
use App\Usuarios\SessaoEmprestada;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }

echo "== 1. Formato da sessão ==\n";
$dados = ['user_id' => 7, 'account_id' => 9, 'user_permissions' => ['chat', 'prospeccao'], 'csrf_token' => 'abc|def', 'nome' => "Kauê 'x'"];
$cod = SessaoEmprestada::codificar($dados);
session_id('zztesteformato' . getmypid());
@session_start();
$_SESSION = [];
session_decode((string) $cod);
ok('o PHP lê a sessão montada exatamente como foi montada', $_SESSION === $dados);
session_destroy();
ok('chave com "|" é recusada (não se empresta)', SessaoEmprestada::codificar(['a|b' => 1]) === null);

echo "\n== 2. Vínculo e troca (contas 476 e 304) ==\n";
$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$temTabela = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario_vinculos'")->fetchColumn();
$contas = $pdo->query('SELECT id FROM accounts WHERE id IN (476, 304) AND deleted_at IS NULL')->fetchAll(\PDO::FETCH_COLUMN);
if (!$temTabela || count($contas) < 2) {
    echo "  [pulado] precisa da migration 169 e das contas locais 476 e 304\n";
} else {
    $marca = 'zzteste' . getmypid();
    $novo = function (int $conta, string $nome, string $role) use ($pdo, $marca): int {
        $pdo->prepare('INSERT INTO users (account_id, nome, login, senha_hash, perfil, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), NOW())')
            ->execute([$conta, $nome, $marca . '_' . $nome . '@exemplo.test', password_hash('x', PASSWORD_DEFAULT), $role === 'admin' ? 'admin' : 'user', $role]);
        return (int) $pdo->lastInsertId();
    };
    $a = $novo(476, 'origem', 'user');      // vendedor na Inovaize local
    $b = $novo(304, 'gemeo', 'admin');      // a mesma pessoa na Fleetiflow local
    $c = $novo(476, 'outro', 'user');       // outra pessoa, sem vínculo
    $pdo->prepare('INSERT INTO user_permissions (user_id, page, account_id) VALUES (?, "chat", 476)')->execute([$a]);
    $servidor = null;
    try {
        ok('sem vínculo, a pessoa não tem outra empresa', P::contas($a) === []);
        P::vincular($a, $b);
        $lista = P::contas($a);
        ok('ligados, a lista traz as duas empresas', count($lista) === 2);
        ok('a empresa atual vem primeiro e marcada', $lista[0]['user_id'] === $a && $lista[0]['atual'] === true && $lista[1]['user_id'] === $b && $lista[1]['atual'] === false);
        ok('vista do outro lado, também são duas', count(P::contas($b)) === 2);
        ok('o usuário da pessoa na outra conta', P::usuarioNaConta($a, 304) === $b);
        ok('quem não é a mesma pessoa não tem usuário lá', P::usuarioNaConta($c, 304) === null);

        $s = P::sessaoNaConta($a, 304);
        ok('a sessão montada para a outra conta é a do usuário de lá', $s && (int) $s['user_id'] === $b && (int) $s['account_id'] === 304 && $s['user_role'] === 'admin');
        ok('com as permissões dele lá, não as da origem', $s && $s['user_permissions'] === ['*']);
        ok('não monta sessão para a própria conta', P::sessaoNaConta($a, 476) === null);

        // Troca de empresa (sessão de CLI).
        session_id('zztestetroca' . getmypid());
        @session_start();
        $_SESSION = ['user_id' => $a, 'account_id' => 476, 'csrf_token' => 'tok', 'ambiente' => 'digital'];
        ok('trocar para quem não é a mesma pessoa é recusado', P::trocar($a, $c)['ok'] === false);
        $r = @P::trocar($a, $b);
        ok('trocar para a outra empresa abre a sessão de lá', $r['ok'] && (int) $_SESSION['user_id'] === $b && (int) $_SESSION['account_id'] === 304);
        ok('a troca mantém o token do formulário e limpa o resto', ($_SESSION['csrf_token'] ?? '') === 'tok' && !isset($_SESSION['ambiente']));
        session_destroy();

        echo "\n== 3. Sessão emprestada pelo WhatsApp (HTTP) ==\n";
        $porta = 8190 + (getmypid() % 50);
        $pub = realpath(__DIR__ . '/../../public');
        $servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", '-t', $pub], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        usleep(900000);
        $sessao = function (int $uid, int $conta) {
            $id = 'zztesteemp' . $uid . 'x' . getmypid();
            session_id($id);
            @session_start();
            $_SESSION = ['user_id' => $uid, 'account_id' => $conta, 'user_role' => 'user', 'user_perfil' => 'user', 'user_permissions' => ['chat'], 'account_tipo' => 'matriz', 'csrf_token' => 'tok'];
            session_write_close();
            return $id;
        };
        $get = function (string $url, string $sid) use ($porta) {
            $ctx = stream_context_create(['http' => ['header' => 'Cookie: ' . session_name() . '=' . $sid . "\r\n", 'ignore_errors' => true, 'timeout' => 20]]);
            $corpo = @file_get_contents("http://127.0.0.1:$porta$url", false, $ctx);
            return json_decode((string) $corpo, true) ?: [];
        };
        $sidA = $sessao($a, 476);
        $arquivo = rtrim((string) (session_save_path() ?: sys_get_temp_dir()), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sidA;
        $antes = @file_get_contents($arquivo);
        $d = $get('/api/whatsapp/instances.php?action=numeros&empresa=304', $sidA);
        $ids = array_map(fn($n) => (int) $n['id'], $d['numeros'] ?? []);
        ok('os números da outra empresa vêm como o usuário de lá (o número 11 da 304)', in_array(11, $ids, true));
        $d = $get('/api/whatsapp/chats.php?channel_id=11&limit=5', $sidA);
        ok('a lista de conversas do número da outra empresa abre', isset($d['chats']));
        ok('a sessão de verdade não mudou', @file_get_contents($arquivo) === $antes);
        $d = $get('/api/whatsapp/instances.php?action=numeros', $sidA);
        $ids = array_map(fn($n) => (int) $n['id'], $d['numeros'] ?? []);
        ok('sem pedir outra empresa, continua na própria', !in_array(11, $ids, true));

        $sidC = $sessao($c, 476);
        $d = $get('/api/whatsapp/chats.php?channel_id=11&limit=5', $sidC);
        ok('quem não é a mesma pessoa continua sem acesso ao número da outra conta', !isset($d['chats']));
        $d = $get('/api/whatsapp/instances.php?action=numeros&empresa=304', $sidC);
        $ids = array_map(fn($n) => (int) $n['id'], $d['numeros'] ?? []);
        ok('e pedir a empresa dos outros não dá os números dela', !in_array(11, $ids, true));
        foreach ([$sidA, $sidC] as $sid) @unlink(rtrim((string) (session_save_path() ?: sys_get_temp_dir()), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    } catch (\Throwable $e) {
        $FALHAS[] = $e->getMessage(); echo '  [FALHA] ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
    } finally {
        if ($servidor) { proc_terminate($servidor); }
        $ids = implode(',', [$a, $b, $c]);
        $pdo->exec("DELETE FROM usuario_vinculos WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM user_permissions WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
ob_end_flush();
exit($FALHAS ? 1 : 0);
