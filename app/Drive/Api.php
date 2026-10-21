<?php
namespace App\Drive;

use App\Core\EnvLoader;

/**
 * Conversa HTTP com o Google (OAuth e Drive v3). Só aqui sai requisição para
 * fora; o resto da pasta chama `pedir`. O transporte é trocável (`$transporte`)
 * para o teste rodar sem rede e sem conta Google.
 *
 * Erro do Google vira DriveErro com mensagem em português e o código HTTP, para
 * a tela dizer o que houve (sem acesso, pasta apagada, conexão revogada) em vez
 * de mostrar lista velha como atual.
 */
final class Api
{
    public const ESCOPO = 'https://www.googleapis.com/auth/drive openid email';
    public const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN = 'https://oauth2.googleapis.com/token';
    public const DRIVE = 'https://www.googleapis.com/drive/v3';
    public const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';
    public const PASTA = 'application/vnd.google-apps.folder';

    /** @var null|callable(string $metodo, string $url, array $cabecalhos, ?string $corpo): array{0:int, 1:string, 2?:array} (o 3º: cabeçalhos da resposta, em minúsculas) */
    public static $transporte = null;

    public static function clientId(): string { return trim(EnvLoader::get('GOOGLE_DRIVE_CLIENT_ID', '')); }
    public static function clientSecret(): string { return trim(EnvLoader::get('GOOGLE_DRIVE_CLIENT_SECRET', '')); }
    /** Domínio permitido para a conta Google (ex.: inovaize.com). Vazio = qualquer conta. */
    public static function dominio(): string { return strtolower(trim(EnvLoader::get('GOOGLE_DRIVE_DOMINIO', ''))); }

    public static function configurado(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /** @return array{0:int, 1:string} */
    private static function enviar(string $metodo, string $url, array $cab, ?string $corpo): array
    {
        if (self::$transporte) return (self::$transporte)($metodo, $url, $cab, $corpo);
        $ch = curl_init($url);
        $linhas = [];
        foreach ($cab as $k => $v) $linhas[] = $k . ': ' . $v;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $linhas,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 40,
        ]);
        if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new DriveErro('Sem conexão com o Google (' . $erro . ').', 0);
        return [$status, (string) $resp];
    }

    /** Troca de token no OAuth (formulário). */
    public static function token(array $campos): array
    {
        [$st, $corpo] = self::enviar('POST', self::TOKEN, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($campos));
        $j = json_decode($corpo, true) ?: [];
        if ($st !== 200) {
            $cod = (string) ($j['error'] ?? '');
            if ($cod === 'invalid_grant') throw new DriveErro('A autorização do Google foi revogada ou expirou. Conecte a conta de novo.', $st, 'revogado');
            throw new DriveErro('O Google recusou a autorização (' . ($cod ?: 'HTTP ' . $st) . ').', $st);
        }
        return $j;
    }

    /**
     * Baixa bytes (a miniatura de um arquivo) com o token de acesso. Só endereço
     * do próprio Google; devolve null quando não há imagem.
     */
    public static function baixar(string $acesso, string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!preg_match('/(^|\.)(googleusercontent\.com|google\.com|googleapis\.com)$/', $host)) return null;
        [$st, $corpo] = self::enviar('GET', $url, ['Authorization' => 'Bearer ' . $acesso], null);
        return $st === 200 && $corpo !== '' ? $corpo : null;
    }

    /** Revoga a autorização no Google (ao desconectar). */
    public static function revogar(string $token): void
    {
        self::enviar('POST', 'https://oauth2.googleapis.com/revoke', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['token' => $token]));
    }

    /**
     * Chamada à API do Drive com o token de acesso. `$consulta` vira query string.
     * @return array resposta JSON
     */
    public static function pedir(string $acesso, string $metodo, string $caminho, array $consulta = [], ?array $json = null, ?string $base = null): array
    {
        $url = ($base ?? self::DRIVE) . $caminho . ($consulta ? '?' . http_build_query($consulta) : '');
        $cab = ['Authorization' => 'Bearer ' . $acesso, 'Accept' => 'application/json'];
        $corpo = null;
        if ($json !== null) { $cab['Content-Type'] = 'application/json; charset=utf-8'; $corpo = json_encode($json, JSON_UNESCAPED_UNICODE); }
        [$st, $resp] = self::enviar($metodo, $url, $cab, $corpo);
        return self::resposta($st, $resp);
    }

    /**
     * Abre uma sessão de envio "resumable" e devolve o endereço dela. O navegador
     * manda o arquivo direto para esse endereço (sem passar pelo servidor, então
     * sem o limite de envio do PHP); o token de acesso nunca sai do servidor. A
     * origem (o endereço do sistema) é o que libera o CORS da sessão no Google.
     */
    public static function abrirEnvio(string $acesso, array $meta, string $mime, int $tamanho, string $origem): string
    {
        $cab = ['Authorization' => 'Bearer ' . $acesso, 'Content-Type' => 'application/json; charset=UTF-8', 'X-Upload-Content-Type' => $mime ?: 'application/octet-stream', 'Origin' => $origem];
        if ($tamanho > 0) $cab['X-Upload-Content-Length'] = (string) $tamanho;
        $url = self::UPLOAD . '?' . http_build_query(['uploadType' => 'resumable', 'supportsAllDrives' => 'true', 'fields' => 'id,name,mimeType,modifiedTime,webViewLink,size,parents']);
        if (self::$transporte) {
            $r = (self::$transporte)('POST', $url, $cab, json_encode($meta, JSON_UNESCAPED_UNICODE));
            $local = (string) ($r[2]['location'] ?? '');
            if ($r[0] >= 300 || $local === '') self::resposta($r[0], $r[1] ?: '{}');
            return $local;
        }
        $ch = curl_init($url);
        $linhas = [];
        foreach ($cab as $k => $v) $linhas[] = $k . ': ' . $v;
        $local = '';
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $linhas, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => json_encode($meta, JSON_UNESCAPED_UNICODE),
            CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$local) { if (stripos($linha, 'location:') === 0) $local = trim(substr($linha, 9)); return strlen($linha); }]);
        $resp = curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) throw new DriveErro('Sem conexão com o Google.', 0);
        if ($st >= 300 || $local === '') self::resposta($st, (string) $resp ?: '{}');
        if (!preg_match('#^https://(www\.)?googleapis\.com/upload/#', $local)) throw new DriveErro('O Google devolveu um endereço de envio inesperado.', 502);
        return $local;
    }

    /**
     * Baixa um arquivo do Drive em pedaços, sem guardar tudo na memória. $aoComecar
     * roda uma vez, antes do primeiro pedaço, só se o Google respondeu 200 (é ali
     * que a tela manda os cabeçalhos do download). Erro do Google vira DriveErro.
     */
    public static function transmitir(string $acesso, string $url, callable $aoPedaco, ?callable $aoComecar = null): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!preg_match('/(^|\.)googleapis\.com$/', $host)) throw new DriveErro('Endereço de download inesperado.', 400);
        $cab = ['Authorization' => 'Bearer ' . $acesso];
        if (self::$transporte) {
            [$st, $corpo] = (self::$transporte)('GET', $url, $cab, null);
            if ($st !== 200) self::resposta($st, $corpo);
            if ($aoComecar) $aoComecar();
            $aoPedaco($corpo);
            return;
        }
        $status = 0; $comecou = false; $erro = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $acesso], CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 0, CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$status) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m)) $status = (int) $m[1]; return strlen($linha); },
            CURLOPT_WRITEFUNCTION => function ($c, $pedaco) use (&$status, &$comecou, &$erro, $aoPedaco, $aoComecar) {
                if ($status !== 200) { $erro .= $pedaco; return strlen($pedaco); }
                if (!$comecou) { $comecou = true; if ($aoComecar) $aoComecar(); }
                $aoPedaco($pedaco);
                return strlen($pedaco);
            }]);
        $ok = curl_exec($ch);
        $falha = curl_error($ch);
        curl_close($ch);
        if ($status !== 200) self::resposta($status ?: 502, $erro ?: '{}');
        if ($ok === false && !$comecou) throw new DriveErro('Sem conexão com o Google (' . $falha . ').', 0);
        if (!$comecou) { if ($aoComecar) $aoComecar(); }
    }

    /** Envio de arquivo (multipart: metadados + conteúdo). */
    public static function enviarArquivo(string $acesso, array $meta, string $bytes, string $mime): array
    {
        $limite = 'yuris' . bin2hex(random_bytes(8));
        $corpo = "--$limite\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . json_encode($meta, JSON_UNESCAPED_UNICODE) .
                 "\r\n--$limite\r\nContent-Type: $mime\r\n\r\n" . $bytes . "\r\n--$limite--";
        [$st, $resp] = self::enviar('POST', self::UPLOAD . '?' . http_build_query(['uploadType' => 'multipart', 'supportsAllDrives' => 'true',
            'fields' => 'id,name,mimeType,modifiedTime,webViewLink,size,parents']),
            ['Authorization' => 'Bearer ' . $acesso, 'Content-Type' => 'multipart/related; boundary=' . $limite], $corpo);
        return self::resposta($st, $resp);
    }

    private static function resposta(int $st, string $resp): array
    {
        $j = json_decode($resp, true);
        if ($st >= 200 && $st < 300) return is_array($j) ? $j : [];
        $msg = (string) ($j['error']['message'] ?? '');
        $motivo = (string) ($j['error']['errors'][0]['reason'] ?? '');
        if ($st === 401) throw new DriveErro('A conexão com o Google expirou. Conecte a conta de novo.', 401, 'revogado');
        if ($st === 403) throw new DriveErro('A conta Google conectada não tem acesso a este item do Drive' . ($motivo ? ' (' . $motivo . ')' : '') . '.', 403, 'sem_acesso');
        if ($st === 404) throw new DriveErro('O item não existe mais no Drive ou a conta conectada não o enxerga.', 404, 'nao_encontrado');
        if ($st === 429 || $st >= 500) throw new DriveErro('O Google está instável agora (HTTP ' . $st . '). Tente de novo em instantes.', $st, 'instavel');
        throw new DriveErro('O Drive recusou o pedido' . ($msg ? ': ' . $msg : ' (HTTP ' . $st . ')') . '.', $st);
    }
}
