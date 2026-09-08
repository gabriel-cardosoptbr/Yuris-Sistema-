<?php
/**
 * varredura_urls.php — captura o comportamento de TODA URL servida por public/,
 * com e sem sessao, para comparacao diferencial antes/depois de mudanca estrutural.
 *
 * Nasceu no D3 (camada de rota). A licao que o motivou: em 27/08/2026 a varredura
 * anonima deu 164/164 com o sistema quebrado, porque pagina interna redireciona pro
 * login ANTES de executar a linha que fatalava. Por isso aqui a varredura e
 * AUTENTICADA, com dois perfis (owner e member).
 *
 * Uso:
 *   php scripts/tests/varredura_urls.php --out=antes.json
 *   ... mudanca ...
 *   php scripts/tests/varredura_urls.php --out=depois.json
 *   php scripts/tests/varredura_urls.php --antes=antes.json --depois=depois.json
 *
 * Opcoes:
 *   --base=http://localhost:8090   raiz do servidor a varrer
 *   --out=ARQUIVO                  onde gravar a captura (JSON)
 *   --antes=A --depois=B           compara duas capturas e sai 1 se divergirem
 *   --owner=ID --member=ID         usuarios usados nas duas sessoes
 *
 * SEGURANCA: so faz GET, e NAO varre public/api/ por padrao. Endpoint de API com
 * sessao valida pode MUTAR dado (um /api/whatsapp/sync.php dispararia sincronizacao).
 * Pagina de sistema e leitura; API fica de fora a menos que --api seja passado.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    }
}

$base    = rtrim($opt['base'] ?? 'http://localhost:8090', '/');
$raiz    = dirname(__DIR__, 2);
$publico = $raiz . '/public';

/* ------------------------------------------------------------------ diff */
if (!empty($opt['antes']) || !empty($opt['diff'])) {
    // Separador proprio para cada arquivo: em caminho do Windows o ':' da letra
    // de unidade ("C:/...") arrebentava um --diff=A:B.
    $fa = $opt['antes']  ?? null;
    $fb = $opt['depois'] ?? null;
    if ($fa === null && !empty($opt['diff'])) {
        [$fa, $fb] = array_pad(explode(':', $opt['diff'], 2), 2, null);
    }
    foreach ([$fa, $fb] as $f) {
        if (!$f || !is_file($f)) {
            fwrite(STDERR, "captura nao encontrada: $f\n");
            exit(2);
        }
    }
    $a = json_decode(file_get_contents($fa), true);
    $b = json_decode(file_get_contents($fb), true);
    $chaves = array_unique(array_merge(array_keys($a['urls']), array_keys($b['urls'])));
    sort($chaves);
    $div = 0;
    foreach ($chaves as $k) {
        $x = $a['urls'][$k] ?? null;
        $y = $b['urls'][$k] ?? null;
        if ($x === null) { echo "SO EM B: $k\n"; $div++; continue; }
        if ($y === null) { echo "SO EM A: $k\n"; $div++; continue; }
        foreach (['status', 'location', 'hash'] as $campo) {
            if (($x[$campo] ?? null) !== ($y[$campo] ?? null)) {
                printf(
                    "DIFERE %-52s %-8s %s -> %s\n",
                    $k,
                    $campo,
                    var_export($x[$campo] ?? null, true),
                    var_export($y[$campo] ?? null, true)
                );
                $div++;
            }
        }
    }
    printf("\n%d URLs comparadas, %d divergencias\n", count($chaves), $div);
    exit($div === 0 ? 0 : 1);
}

/* ------------------------------------------------- inventario de URLs */
$pulaPasta = ['includes', 'uploads', 'assets', 'sistema_vendas', 'ai'];
$pulaArq   = ['index-v1-legacy.php'];

$urls = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($publico, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($publico)));
    $seg = explode('/', ltrim($rel, '/'));
    if (in_array($seg[0], $pulaPasta, true)) continue;
    if (in_array(basename($rel), $pulaArq, true)) continue;
    if ($seg[0] === 'api' && empty($opt['api'])) continue;
    if ($seg[0] === 'v2' && count($seg) > 1 && in_array($seg[1], ['partials', 'data'], true)) continue;

    $urls[] = $rel;                                   // /pagina.php
    if (basename($rel) === 'index.php') {
        // dirname() devolve "\" no Windows para "/index.php"; normaliza antes.
        $pasta = str_replace('\\', '/', dirname($rel));
        $urls[] = ($pasta === '/' ? '' : rtrim($pasta, '/')) . '/';  // /pasta/ (DirectoryIndex)
    } else {
        $urls[] = substr($rel, 0, -4);                // /pagina  (URL limpa)
    }
}
$urls[] = '/';
// URLs que NAO existem: provam qual e o comportamento de 404
$urls[] = '/pagina-que-nao-existe-d3';
$urls[] = '/pagina-que-nao-existe-d3.php';
$urls[] = '/pasta-que-nao-existe-d3/';
$urls = array_values(array_unique($urls));
sort($urls);

/* --------------------------------------------------- sessoes de teste */
$pdo = Database::getConnection();

/**
 * Cria uma sessao PHP identica a que o AuthController grava no login, sem
 * alterar dado nenhum (so SELECT). Devolve o PHPSESSID.
 */
function montaSessao(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare(
        'SELECT u.*, a.tipo AS acc_tipo, a.nome AS acc_nome, a.plano AS acc_plano
           FROM users u LEFT JOIN accounts a ON a.id = u.account_id
          WHERE u.id = ?'
    );
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }

    $ehAdmin = in_array($u['role'] ?? '', ['owner', 'admin'], true) || ($u['perfil'] ?? '') === 'admin';

    $sid = 'd3sweep' . bin2hex(random_bytes(9));
    session_id($sid);
    @session_start();
    $dados = [
        'user_id'          => (int) $u['id'],
        'user_nome'        => $u['nome'],
        'user_perfil'      => $u['perfil'],
        'user_role'        => $u['role'] ?? ($u['perfil'] === 'admin' ? 'owner' : 'user'),
        'account_id'       => (int) $u['account_id'],
        'account_tipo'     => $u['acc_tipo'] ?? 'matriz',
        'account_nome'     => $u['acc_nome'] ?? '',
        'account_plano'    => $u['acc_plano'] ?? 'basico',
        'user_permissions' => ['*'],
        'csrf_token'       => str_repeat('a', 32),
    ];
    if (!$ehAdmin) {
        $p = $pdo->prepare('SELECT page FROM user_permissions WHERE user_id = ?');
        $p->execute([$userId]);
        $dados['user_permissions'] = $p->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
    $_SESSION = $dados;
    $payload  = session_encode();
    session_write_close();
    return [$sid, $payload];
}

/**
 * Regrava o arquivo de sessao. Necessario porque a varredura passa por
 * /logout.php, que destroi a sessao: sem isto tudo que vem DEPOIS de "logout"
 * na ordem alfabetica seria varrido deslogado, e a varredura mentiria.
 */
function regravaSessao(string $sid, string $payload): void
{
    $dir = ini_get('session.save_path') ?: sys_get_temp_dir();
    if (str_contains($dir, ';')) {
        $dir = substr($dir, strrpos($dir, ';') + 1);
    }
    file_put_contents(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid, $payload);
}

$identidades = ['anon' => null];
$sessoes     = [];
$ownerId  = (int) ($opt['owner']  ?? 1);
$memberId = (int) ($opt['member'] ?? 3);
foreach (['owner' => $ownerId, 'member' => $memberId] as $rotulo => $uid) {
    $par = montaSessao($pdo, $uid);
    if ($par === null) {
        fwrite(STDERR, "usuario $uid nao existe; informe outro com --$rotulo=ID\n");
        exit(2);
    }
    [$sid, $payload]        = $par;
    $identidades[$rotulo]   = $sid;
    $sessoes[$rotulo]       = $payload;
}

/* ------------------------------------------- normalizacao para o hash */
/**
 * Tira do corpo tudo que muda a cada requisicao sem que o comportamento tenha
 * mudado: cache-busting por filemtime, token CSRF, id de requisicao, relogio.
 */
function normaliza(string $corpo): string
{
    $regras = [
        '/\?v=\d+/'                                       => '?v=X',
        '/\b[0-9a-f]{64}\b/i'                             => 'HASH64',
        '/\b[0-9a-f]{40}\b/i'                             => 'HASH40',
        '/\b[0-9a-f]{32}\b/i'                             => 'HASH32',
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => 'UUID',
        '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/'        => 'TS',
        '/\d{2}\/\d{2}\/\d{4}(,? \d{2}:\d{2}(:\d{2})?)?/' => 'DATA',
        '/\d{2}:\d{2}:\d{2}/'                             => 'HORA',
        '/h[\x{00e1}a] \d+ (segundo|minuto|hora|dia)s?/iu' => 'HA_X',
    ];
    return preg_replace(array_keys($regras), array_values($regras), $corpo);
}

/* -------------------------------------------------------- a varredura */
$saida = ['base' => $base, 'gerado_em' => gmdate('c'), 'urls' => []];
$total = 0;

foreach ($urls as $u) {
    foreach ($identidades as $rotulo => $sid) {
        if ($sid !== null) {
            regravaSessao($sid, $sessoes[$rotulo]);   // desfaz qualquer logout anterior
        }
        $ch = curl_init($base . $u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['User-Agent: yuris-varredura-d3'],
        ]);
        if ($sid !== null) {
            curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sid);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $saida['urls']["$rotulo $u"] = [
                'status' => 'ERRO', 'location' => curl_error($ch), 'bytes' => 0, 'hash' => '',
            ];
            curl_close($ch);
            $total++;
            continue;
        }
        $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $hdr = substr($resp, 0, $hs);
        $bd  = substr($resp, $hs);
        curl_close($ch);

        preg_match('/^Location:\s*(.+)$/mi', $hdr, $mloc);
        $saida['urls']["$rotulo $u"] = [
            'status'   => $st,
            'location' => isset($mloc[1]) ? trim($mloc[1]) : null,
            'bytes'    => strlen($bd),
            'hash'     => substr(sha1(normaliza($bd)), 0, 16),
        ];
        $total++;
    }
}

$destino = $opt['out'] ?? null;
if ($destino) {
    file_put_contents($destino, json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    printf(
        "%d URLs x %d identidades = %d requisicoes -> %s\n",
        count($urls),
        count($identidades),
        $total,
        $destino
    );
} else {
    echo json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}
