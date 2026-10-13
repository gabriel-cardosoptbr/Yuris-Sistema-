<?php
namespace App\Plataforma;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Conexao: a ligação desta conta do CRM com a plataforma Fleetiflow, e o
 * único jeito de falar com ela.
 *
 * ---------------------------------------------------------------------------
 * COMO LIGA
 * ---------------------------------------------------------------------------
 * O Master da plataforma gera um CÓDIGO DE CONEXÃO (`FFC1.<base64url>`), que
 * leva o endereço da API e a chave `ffc_`. Quem administra o CRM cola esse
 * código uma vez (`conectar`). O CRM confere a chave, diz à plataforma para
 * onde mandar os eventos (`PUT /conexao`) e recebe o segredo que os assina.
 * Chave e segredo ficam cifrados (App\Core\Crypto, APP_ENCRYPTION_KEY).
 *
 * Tudo passa pela API v1 da plataforma: o CRM nunca lê o banco dela. Trocar
 * de CRM é implementar este mesmo contrato (docs/integracao-comercial.md, no
 * repositório da plataforma).
 *
 * A chave nunca é logada, nunca volta para a tela e nunca vai em URL.
 */
final class Conexao
{
    private const PREFIXO_CODIGO = 'FFC1.';
    private const TIMEOUT = 12;

    /** Lê o código de conexão. null quando não é um código válido. */
    public static function abrirCodigo(string $codigo): ?array
    {
        $codigo = trim($codigo);
        if (strncmp($codigo, self::PREFIXO_CODIGO, strlen(self::PREFIXO_CODIGO)) !== 0) return null;
        $b64 = strtr(substr($codigo, strlen(self::PREFIXO_CODIGO)), '-_', '+/');
        $json = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        $dados = $json === false ? null : json_decode($json, true);
        if (!is_array($dados) || !is_string($dados['u'] ?? null) || !is_string($dados['k'] ?? null)) return null;
        if (!preg_match('#^https?://#i', $dados['u']) || strncmp($dados['k'], 'ffc_', 4) !== 0) return null;
        return ['api_url' => rtrim($dados['u'], '/'), 'chave' => $dados['k']];
    }

    /** A conexão da conta, sem segredo nenhum. null quando não está ligada. */
    public static function situacao(int $accountId): ?array
    {
        $st = Database::getConnection()->prepare(
            'SELECT api_url, nome_conexao, escopos, crm_url, conectado_em, ultimo_evento_em, conectado_por
               FROM plataforma_conexoes WHERE account_id = ? LIMIT 1'
        );
        $st->execute([$accountId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['escopos'] = array_values(array_filter(explode(',', (string) $row['escopos'])));
        $row['pode_mudar'] = in_array('comercial', $row['escopos'], true);
        return $row;
    }

    public static function ligada(int $accountId): bool
    {
        return self::situacao($accountId) !== null;
    }

    /**
     * Liga a conta à plataforma com o código de conexão.
     *
     * @param string $crmUrl origem pública deste CRM (https://crm...), de onde
     *                       sai o endereço que recebe os eventos
     * @throws \InvalidArgumentException com a mensagem para a tela
     */
    public static function conectar(int $accountId, int $userId, string $codigo, string $crmUrl): array
    {
        $aberto = self::abrirCodigo($codigo);
        if ($aberto === null) {
            throw new \InvalidArgumentException('Este não é um código de conexão da plataforma. Ele começa com FFC1.');
        }
        $crmUrl = rtrim($crmUrl, '/');
        $urlEventos = $crmUrl . '/api/plataforma_eventos.php?c=' . $accountId;

        // Primeiro confere a chave: código velho ou revogado para aqui.
        $quem = self::requisitar($aberto['api_url'], $aberto['chave'], 'GET', '/conexao');
        if ($quem['status'] === 401) {
            throw new \InvalidArgumentException('A plataforma recusou este código. Ele pode ter sido revogado: peça um novo no Painel Master.');
        }
        if ($quem['status'] !== 200) {
            throw new \InvalidArgumentException('A plataforma não respondeu como esperado (HTTP ' . $quem['status'] . '). Tente de novo.');
        }

        $reg = self::requisitar($aberto['api_url'], $aberto['chave'], 'PUT', '/conexao', [
            'urlDosEventos' => $urlEventos,
            'sistema'       => 'Yuris CRM',
        ]);
        $segredo = (string) ($reg['corpo']['segredoDosEventos'] ?? '');
        if ($reg['status'] !== 200 || $segredo === '') {
            throw new \InvalidArgumentException('A plataforma não aceitou o endereço dos eventos: ' . self::mensagem($reg, 'HTTP ' . $reg['status']));
        }

        $escopos = implode(',', array_filter((array) ($reg['corpo']['escopos'] ?? []), 'is_string'));
        $pdo = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO plataforma_conexoes
               (account_id, api_url, chave_cifrada, segredo_eventos_cifrado, nome_conexao, escopos, crm_url, conectado_por, conectado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE api_url = VALUES(api_url), chave_cifrada = VALUES(chave_cifrada),
               segredo_eventos_cifrado = VALUES(segredo_eventos_cifrado), nome_conexao = VALUES(nome_conexao),
               escopos = VALUES(escopos), crm_url = VALUES(crm_url), conectado_por = VALUES(conectado_por),
               conectado_em = VALUES(conectado_em)'
        )->execute([
            $accountId,
            $aberto['api_url'],
            Crypto::encrypt($aberto['chave']),
            Crypto::encrypt($segredo),
            mb_substr((string) ($reg['corpo']['nome'] ?? ''), 0, 120) ?: null,
            $escopos,
            $crmUrl,
            $userId ?: null,
        ]);

        return self::situacao($accountId);
    }

    public static function desconectar(int $accountId): void
    {
        Database::getConnection()->prepare('DELETE FROM plataforma_conexoes WHERE account_id = ?')->execute([$accountId]);
    }

    /** O segredo que assina os eventos desta conta. null sem conexão. */
    public static function segredoDosEventos(int $accountId): ?string
    {
        $st = Database::getConnection()->prepare('SELECT segredo_eventos_cifrado FROM plataforma_conexoes WHERE account_id = ? LIMIT 1');
        $st->execute([$accountId]);
        $cifrado = $st->fetchColumn();
        return $cifrado ? Crypto::decrypt((string) $cifrado) : null;
    }

    public static function marcarEvento(int $accountId): void
    {
        Database::getConnection()->prepare('UPDATE plataforma_conexoes SET ultimo_evento_em = UTC_TIMESTAMP() WHERE account_id = ?')->execute([$accountId]);
    }

    /**
     * Chama a API da plataforma em nome da conta.
     *
     * @return array{status:int, corpo:array}
     * @throws \RuntimeException sem conexão ou sem rede (mensagem para a tela)
     */
    public static function api(int $accountId, string $metodo, string $rota, ?array $corpo = null): array
    {
        $st = Database::getConnection()->prepare('SELECT api_url, chave_cifrada FROM plataforma_conexoes WHERE account_id = ? LIMIT 1');
        $st->execute([$accountId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('O CRM ainda não está ligado à plataforma.');
        try {
            $chave = Crypto::decrypt((string) $row['chave_cifrada']);
        } catch (\Throwable $e) {
            error_log('[plataforma] chave da conexão não abre: ' . $e->getMessage());
            throw new \RuntimeException('A chave da conexão com a plataforma não abre. Ligue de novo com um código novo.');
        }
        return self::requisitar((string) $row['api_url'], $chave, $metodo, $rota, $corpo);
    }

    /** @return array{status:int, corpo:array} */
    private static function requisitar(string $base, string $chave, string $metodo, string $rota, ?array $corpo = null): array
    {
        $ch = curl_init($base . $rota);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $chave];
        if ($corpo !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
        $resposta = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $falha = curl_error($ch);
        curl_close($ch);
        if ($resposta === false || $status === 0) {
            error_log('[plataforma] ' . $metodo . ' ' . $rota . ' sem resposta: ' . $falha);
            throw new \RuntimeException('A plataforma não respondeu. Tente de novo em instantes.');
        }
        $json = json_decode((string) $resposta, true);
        return ['status' => $status, 'corpo' => is_array($json) ? $json : []];
    }

    /** Mensagem de erro da plataforma, já pronta para a tela. */
    public static function mensagem(array $resposta, string $padrao): string
    {
        // A plataforma responde erro como {"success":false,"error":{"code","message"}}.
        $m = $resposta['corpo']['error']['message'] ?? ($resposta['corpo']['message'] ?? null);
        if (is_array($m)) $m = implode(' ', array_filter($m, 'is_string'));
        return is_string($m) && $m !== '' ? $m : $padrao;
    }
}
