<?php
declare(strict_types=1);

namespace App\Notificacoes;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Push: o aviso que chega no celular (e no computador) com o sistema fechado
 * (22/10/2026).
 *
 * É o Web Push dos navegadores: o aparelho que aceitou receber avisos dá um
 * endereço do serviço de push dele (Google no Android e no Chrome, Apple no
 * iPhone com o app instalado, Mozilla no Firefox) e duas chaves públicas. Para
 * avisar, o servidor manda a mensagem CIFRADA para esse endereço; só o aparelho
 * consegue ler. O `public/sw.js` recebe e mostra a notificação.
 *
 * Sem biblioteca (o projeto não usa Composer): as duas contas do protocolo
 * estão aqui, com o openssl do PHP:
 *   · RFC 8291 (cifra aes128gcm): ECDH P-256 com a chave do aparelho, HKDF
 *     com o segredo de autenticação dele, AES-128-GCM;
 *   · RFC 8292 (VAPID): um JWT ES256 assinado com a chave do sistema, que
 *     prova ao serviço de push quem está mandando.
 * O par VAPID é gerado sozinho no primeiro uso e guardado em `chaves_sistema`,
 * com a parte privada cifrada (App\Core\Crypto).
 *
 * QUANDO SAI
 * Aviso::gravar() chama enfileirar() para os tipos de tempo real; a fila é
 * enviada no fim da requisição (register_shutdown_function), em paralelo
 * (curl_multi), com prazo de 5 segundos. Falha nunca derruba quem avisou.
 *
 * SEGURANÇA
 *   · endereço só de serviço de push conhecido (lista SERVICOS): sem isso, o
 *     navegador poderia mandar o servidor chamar qualquer URL (SSRF);
 *   · a inscrição é de UM usuário de UMA conta; aparelho que troca de usuário
 *     passa a ser do último que ativou; sair (logout) apaga a inscrição dele;
 *   · aparelho que o serviço diz não existir mais (404/410) é apagado.
 */
final class Push
{
    /** Serviços de push aceitos: host exato ou terminação. */
    private const SERVICOS = [
        'fcm.googleapis.com', 'android.googleapis.com',
        '.push.services.mozilla.com', 'push.services.mozilla.com',
        'web.push.apple.com', '.push.apple.com',
        '.notify.windows.com',
    ];

    private const ASSUNTO = 'https://yuris.com.br';

    /** @var list<array{conta:int,usuario:int,msg:array}> */
    private static array $fila = [];
    private static bool $agendado = false;

    /* ===================================================================== */
    /* inscrição                                                              */
    /* ===================================================================== */

    /** Chave pública VAPID (base64url), que o navegador precisa para se inscrever. */
    public static function chavePublica(): ?string
    {
        $k = self::chaves();
        return $k ? self::b64u($k['publica']) : null;
    }

    /** Guarda (ou atualiza) o aparelho de um usuário. */
    public static function inscrever(int $accountId, int $userId, string $endpoint, string $p256dh, string $auth): bool
    {
        if ($accountId <= 0 || $userId <= 0 || !self::endpointValido($endpoint)) return false;
        $pub = self::deB64u($p256dh);
        $sec = self::deB64u($auth);
        if (strlen($pub) !== 65 || $pub[0] !== "\x04" || strlen($sec) !== 16) return false;

        $hash = hash('sha256', $endpoint);
        $ua = mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255) ?: null;
        Database::getConnection()->prepare(
            'INSERT INTO push_inscricoes (account_id, user_id, endpoint, endpoint_hash, p256dh, auth, user_agent, criado_em, falhas)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0)
             ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), user_id = VALUES(user_id), p256dh = VALUES(p256dh),
                                     auth = VALUES(auth), user_agent = VALUES(user_agent), falhas = 0'
        )->execute([$accountId, $userId, $endpoint, $hash, self::b64u($pub), self::b64u($sec), $ua]);
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['push_endpoint_hash'] = $hash;
        return true;
    }

    /** O aparelho desligou os avisos (só a inscrição do próprio usuário). */
    public static function remover(int $userId, string $endpoint): void
    {
        Database::getConnection()->prepare('DELETE FROM push_inscricoes WHERE endpoint_hash = ? AND user_id = ?')
            ->execute([hash('sha256', $endpoint), $userId]);
    }

    /** Logout: o aparelho de quem saiu para de receber avisos com nome de cliente. */
    public static function removerDaSessao(): void
    {
        $hash = (string) ($_SESSION['push_endpoint_hash'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) return;
        try {
            Database::getConnection()->prepare('DELETE FROM push_inscricoes WHERE endpoint_hash = ?')->execute([$hash]);
        } catch (\Throwable $e) {
            error_log('[Push] remover na saída: ' . $e->getMessage());
        }
    }

    /** Quantos aparelhos a pessoa tem recebendo avisos. */
    public static function aparelhos(int $userId, int $accountId): int
    {
        try {
            $st = Database::getConnection()->prepare('SELECT COUNT(*) FROM push_inscricoes WHERE user_id = ? AND account_id = ?');
            $st->execute([$userId, $accountId]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* ===================================================================== */
    /* envio                                                                  */
    /* ===================================================================== */

    /**
     * Põe um aviso na fila desta requisição. `msg`: id, titulo, corpo, url, tipo.
     * Sai no fim da requisição, depois da resposta montada.
     */
    public static function enfileirar(int $accountId, int $userId, array $msg): void
    {
        if ($accountId <= 0 || $userId <= 0) return;
        self::$fila[] = ['conta' => $accountId, 'usuario' => $userId, 'msg' => $msg];
        if (!self::$agendado) {
            self::$agendado = true;
            register_shutdown_function([self::class, 'enviarFila']);
        }
    }

    /** Envia o que está na fila. @return int quantos aparelhos aceitaram */
    public static function enviarFila(): int
    {
        $fila = self::$fila;
        self::$fila = [];
        if (!$fila || !function_exists('curl_multi_init')) return 0;
        try {
            $k = self::chaves();
            if (!$k) return 0;
            $pdo = Database::getConnection();
            $st  = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM push_inscricoes WHERE user_id = ? AND account_id = ?');

            $pedidos = [];
            $porUsuario = [];
            foreach ($fila as $item) {
                // Teto por pessoa numa requisição: uma rajada vira no máximo 5 avisos.
                $chave = $item['conta'] . ':' . $item['usuario'];
                if (($porUsuario[$chave] = ($porUsuario[$chave] ?? 0) + 1) > 5) continue;
                $st->execute([$item['usuario'], $item['conta']]);
                $corpo = json_encode(self::mensagem($item['msg']) + ['icone' => self::icone($pdo, $item['conta'])],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $ins) {
                    if (!self::endpointValido((string) $ins['endpoint'])) continue;
                    $pedidos[] = ['ins' => $ins, 'corpo' => $corpo];
                }
            }
            return self::despachar($pdo, $k, $pedidos);
        } catch (\Throwable $e) {
            error_log('[Push] ' . $e->getMessage());
            return 0;
        }
    }

    /** @param list<array{ins:array, corpo:string}> $pedidos */
    private static function despachar(\PDO $pdo, array $k, array $pedidos): int
    {
        if (!$pedidos) return 0;
        $multi = curl_multi_init();
        $alcas = [];
        $jwts  = [];
        foreach ($pedidos as $i => $p) {
            $url = (string) $p['ins']['endpoint'];
            $aud = (string) parse_url($url, PHP_URL_SCHEME) . '://' . (string) parse_url($url, PHP_URL_HOST);
            $jwts[$aud] ??= self::jwt($aud, $k['privada']);
            try {
                $cifrado = self::cifrar($p['corpo'], self::deB64u((string) $p['ins']['p256dh']), self::deB64u((string) $p['ins']['auth']));
            } catch (\Throwable $e) {
                error_log('[Push] cifra: ' . $e->getMessage());
                continue;
            }
            $h = curl_init($url);
            curl_setopt_array($h, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $cifrado,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: vapid t=' . $jwts[$aud] . ', k=' . self::b64u($k['publica']),
                    'Content-Encoding: aes128gcm',
                    'Content-Type: application/octet-stream',
                    'TTL: 86400',
                    'Urgency: high',
                ],
            ]);
            curl_multi_add_handle($multi, $h);
            $alcas[$i] = $h;
        }
        do {
            $status = curl_multi_exec($multi, $ativos);
            if ($ativos) curl_multi_select($multi, 1.0);
        } while ($ativos && $status === CURLM_OK);

        $ok = 0;
        $apaga = $pdo->prepare('DELETE FROM push_inscricoes WHERE id = ?');
        $certo = $pdo->prepare('UPDATE push_inscricoes SET ultimo_envio_em = NOW(), falhas = 0 WHERE id = ?');
        $falha = $pdo->prepare('UPDATE push_inscricoes SET falhas = falhas + 1 WHERE id = ?');
        foreach ($alcas as $i => $h) {
            $codigo = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
            $id = (int) $pedidos[$i]['ins']['id'];
            if ($codigo >= 200 && $codigo < 300) { $certo->execute([$id]); $ok++; }
            elseif ($codigo === 404 || $codigo === 410) { $apaga->execute([$id]); }
            else {
                $falha->execute([$id]);
                error_log('[Push] serviço respondeu ' . $codigo . ' ' . mb_substr((string) curl_multi_getcontent($h), 0, 200));
            }
            curl_multi_remove_handle($multi, $h);
            curl_close($h);
        }
        curl_multi_close($multi);
        $pdo->exec('DELETE FROM push_inscricoes WHERE falhas >= 20');
        return $ok;
    }

    /** O ícone do app da conta (marca própria, Fleetiflow ou Yuris), para a notificação. */
    private static function icone(\PDO $pdo, int $accountId): string
    {
        static $cache = [];
        if (isset($cache[$accountId])) return $cache[$accountId];
        try {
            $st = $pdo->prepare('SELECT * FROM accounts WHERE id = ? LIMIT 1');
            $st->execute([$accountId]);
            $conta = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
            $produto = \App\Master\Account::getProduto($conta);
            $marca = $produto === 'fleetiflow' ? \App\Master\Marca::daConta($conta) : null;
            return $cache[$accountId] = \App\Core\IconeApp::url($produto, $marca, 192);
        } catch (\Throwable $e) {
            return $cache[$accountId] = '/assets/app-fleetiflow-192.png?v=1';
        }
    }

    /** O que vai dentro da notificação (cabe nos 4 KB do protocolo). */
    private static function mensagem(array $m): array
    {
        $url = (string) ($m['url'] ?? '/dashboard');
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) $url = '/dashboard';
        return [
            'id'     => (int) ($m['id'] ?? 0),
            'tipo'   => mb_substr((string) ($m['tipo'] ?? 'aviso'), 0, 40),
            'titulo' => mb_substr((string) ($m['titulo'] ?? 'Aviso'), 0, 120),
            'corpo'  => mb_substr((string) ($m['corpo'] ?? ''), 0, 400),
            'url'    => mb_substr($url, 0, 255),
        ];
    }

    /* ===================================================================== */
    /* RFC 8291: cifra aes128gcm                                              */
    /* ===================================================================== */

    /**
     * Cifra o corpo para um aparelho. Público para o teste conferir com a
     * decifração do lado do aparelho.
     */
    public static function cifrar(string $corpo, string $chaveAparelho, string $segredo): string
    {
        $efemera = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC] + self::cfgOpenssl());
        if ($efemera === false) throw new \RuntimeException('openssl_pkey_new falhou');
        $nossa = self::pontoPublico($efemera);
        $compartilhado = openssl_pkey_derive(openssl_pkey_get_public(self::pemPublica($chaveAparelho)), $efemera, 32);
        if ($compartilhado === false) throw new \RuntimeException('ECDH falhou');

        $sal = random_bytes(16);
        [$cek, $nonce] = self::derivar($compartilhado, $segredo, $chaveAparelho, $nossa, $sal);
        $tag = '';
        $cifrado = openssl_encrypt($corpo . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cifrado === false) throw new \RuntimeException('AES-GCM falhou');
        return $sal . pack('N', 4096) . chr(65) . $nossa . $cifrado . $tag;
    }

    /** @return array{0:string,1:string} chave de conteúdo (16) e nonce (12) */
    public static function derivar(string $compartilhado, string $segredo, string $chaveAparelho, string $chaveServidor, string $sal): array
    {
        $prkChave = hash_hmac('sha256', $compartilhado, $segredo, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0" . $chaveAparelho . $chaveServidor . "\x01", $prkChave, true);
        $prk = hash_hmac('sha256', $ikm, $sal, true);
        return [
            substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16),
            substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12),
        ];
    }

    /* ===================================================================== */
    /* RFC 8292: VAPID                                                        */
    /* ===================================================================== */

    private static function jwt(string $aud, string $pemPrivada): string
    {
        $cab = self::b64u((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $dad = self::b64u((string) json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => self::ASSUNTO], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($cab . '.' . $dad, $der, $pemPrivada, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('assinatura VAPID falhou');
        }
        return $cab . '.' . $dad . '.' . self::b64u(self::derParaBruta($der));
    }

    /** @return array{publica:string, privada:string}|null publica crua (65 bytes) e PEM privada */
    private static function chaves(): ?array
    {
        static $cache = null;
        if ($cache !== null) return $cache ?: null;
        try {
            $pdo = Database::getConnection();
            $ler = static function () use ($pdo): ?array {
                $st = $pdo->prepare("SELECT valor FROM chaves_sistema WHERE nome = 'vapid' LIMIT 1");
                $st->execute();
                $v = json_decode((string) $st->fetchColumn(), true);
                if (!is_array($v) || empty($v['publica']) || empty($v['privada'])) return null;
                return ['publica' => self::deB64u((string) $v['publica']), 'privada' => Crypto::decrypt((string) $v['privada'])];
            };
            $k = $ler();
            if ($k === null) {
                $par = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC] + self::cfgOpenssl());
                if ($par === false || !openssl_pkey_export($par, $pem, null, self::cfgOpenssl())) {
                    throw new \RuntimeException('não gerou o par VAPID');
                }
                $valor = json_encode(['publica' => self::b64u(self::pontoPublico($par)), 'privada' => Crypto::encrypt($pem)]);
                // INSERT IGNORE: duas requisições gerando ao mesmo tempo ficam com a mesma chave.
                $pdo->prepare("INSERT IGNORE INTO chaves_sistema (nome, valor, criado_em) VALUES ('vapid', ?, NOW())")->execute([$valor]);
                $k = $ler();
            }
            $cache = $k ?? false;
            return $k;
        } catch (\Throwable $e) {
            error_log('[Push] chaves: ' . $e->getMessage());
            $cache = false;
            return null;
        }
    }

    /* ===================================================================== */
    /* utilidades                                                             */
    /* ===================================================================== */

    public static function endpointValido(string $url): bool
    {
        if (strlen($url) > 2000 || !str_starts_with($url, 'https://')) return false;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || parse_url($url, PHP_URL_PORT) !== null) return false;
        foreach (self::SERVICOS as $s) {
            if ($s[0] === '.' ? str_ends_with($host, $s) : $host === $s) return true;
        }
        return false;
    }

    /** Ponto público EC não comprimido: 0x04 || X || Y. @param \OpenSSLAsymmetricKey $chave */
    private static function pontoPublico($chave): string
    {
        $d = openssl_pkey_get_details($chave);
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** Ponto P-256 cru (65 bytes) em PEM SubjectPublicKeyInfo. */
    public static function pemPublica(string $ponto): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $ponto;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Assinatura ECDSA em DER para r||s de 64 bytes (formato do JWT). */
    private static function derParaBruta(string $der): string
    {
        $pos = 2;
        if ((ord($der[1]) & 0x80) !== 0) $pos += ord($der[1]) & 0x7f;
        $partes = [];
        for ($i = 0; $i < 2; $i++) {
            $tam = ord($der[$pos + 1]);
            $num = ltrim(substr($der, $pos + 2, $tam), "\0");
            $partes[] = str_pad($num, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $tam;
        }
        return $partes[0] . $partes[1];
    }

    /** No Windows (XAMPP) o openssl precisa achar o openssl.cnf para gerar chave. */
    private static function cfgOpenssl(): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || getenv('OPENSSL_CONF')) return [];
        foreach (['C:/xampp/apache/conf/openssl.cnf', 'C:/xampp/php/extras/openssl/openssl.cnf'] as $c) {
            if (is_file($c)) return ['config' => $c];
        }
        return [];
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function deB64u(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
