<?php
namespace App\Drive;

use App\Core\Database;

/**
 * Atualização automática das pastas dos clientes (20/10/2026), rodada pelo
 * bin/drive_sync.php a cada poucos minutos.
 *
 * Usa a API de mudanças do Drive: guarda um marcador por conta
 * (`drive_contas.page_token`) e, a cada rodada, pergunta o que mudou desde ele.
 * Mudou um arquivo que está numa pasta conhecida de algum cliente (ou que
 * estava nela e saiu), aquela pasta é lida de novo. Pasta marcada
 * `precisa_sync` (recém-ligada, conta reconectada) também é lida. Assim a
 * lista da ficha acompanha o Drive sem ninguém clicar em "Atualizar".
 *
 * Erro do Google fica gravado (conta e pasta) e a tela mostra; a lista
 * anterior continua marcada com a hora em que foi lida.
 */
final class Sincronizacao
{
    /** @return array{contas:int, pastas:int, erros:int} */
    public static function rodar(int $limitePastas = 60): array
    {
        $pdo = Database::getConnection();
        $res = ['contas' => 0, 'pastas' => 0, 'erros' => 0];
        if (!Api::configurado()) return $res;
        foreach ($pdo->query('SELECT account_id FROM drive_contas')->fetchAll(\PDO::FETCH_COLUMN) as $acc) {
            $acc = (int) $acc;
            $res['contas']++;
            try {
                $pastas = self::pastasMudadas($acc);
                $st = $pdo->prepare('SELECT cliente_id, folder_id FROM drive_pastas_cliente WHERE account_id = ? AND precisa_sync = 1');
                $st->execute([$acc]);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $p) $pastas[(int) $p['cliente_id'] . '|' . $p['folder_id']] = [(int) $p['cliente_id'], $p['folder_id']];
                foreach (array_slice($pastas, 0, $limitePastas) as [$cli, $folder]) {
                    try {
                        Pasta::lerDoDrive($acc, $cli, $folder);
                        $res['pastas']++;
                    } catch (DriveErro $e) {
                        $res['erros']++;
                        $pdo->prepare('INSERT INTO drive_pastas_lidas (account_id, cliente_id, folder_id, lida_em, erro, erro_em) VALUES (?, ?, ?, NULL, ?, NOW())
                                       ON DUPLICATE KEY UPDATE erro = VALUES(erro), erro_em = VALUES(erro_em)')->execute([$acc, $cli, $folder, mb_substr($e->getMessage(), 0, 500)]);
                        if ($e->motivo === 'revogado') throw $e;
                    }
                }
                $pdo->prepare('UPDATE drive_contas SET ultima_sync = NOW(), ultimo_erro = NULL, erro_em = NULL WHERE account_id = ?')->execute([$acc]);
            } catch (DriveErro $e) {
                $res['erros']++;
                Conexao::registrarErro($acc, $e->getMessage());
            }
        }
        return $res;
    }

    /**
     * Pastas de clientes tocadas por mudanças desde o último marcador.
     * @return array<string, array{0:int, 1:string}> "cliente|pasta" => [cliente, pasta]
     */
    public static function pastasMudadas(int $accountId): array
    {
        $pdo = Database::getConnection();
        $acesso = Conexao::acesso($accountId);
        $conta = Conexao::linha($accountId);
        $token = $conta['page_token'] ?? null;
        if (!$token) {
            // Primeira rodada: marca o ponto de partida e lê todas as pastas raiz.
            $r = Api::pedir($acesso, 'GET', '/changes/startPageToken', ['supportsAllDrives' => 'true']);
            $pdo->prepare('UPDATE drive_contas SET page_token = ? WHERE account_id = ?')->execute([(string) ($r['startPageToken'] ?? ''), $accountId]);
            $pdo->prepare('UPDATE drive_pastas_cliente SET precisa_sync = 1 WHERE account_id = ?')->execute([$accountId]);
            return [];
        }
        $mudados = [];   // file_id => pais atuais
        for ($i = 0; $i < 20 && $token; $i++) {
            $r = Api::pedir($acesso, 'GET', '/changes', ['pageToken' => $token, 'pageSize' => 500, 'includeItemsFromAllDrives' => 'true', 'supportsAllDrives' => 'true',
                'fields' => 'nextPageToken,newStartPageToken,changes(fileId,removed,file(parents,trashed))']);
            foreach ($r['changes'] ?? [] as $c) $mudados[(string) $c['fileId']] = (array) ($c['file']['parents'] ?? []);
            if (!empty($r['newStartPageToken'])) {
                $pdo->prepare('UPDATE drive_contas SET page_token = ? WHERE account_id = ?')->execute([(string) $r['newStartPageToken'], $accountId]);
                break;
            }
            $token = $r['nextPageToken'] ?? null;
            if ($token) $pdo->prepare('UPDATE drive_contas SET page_token = ? WHERE account_id = ?')->execute([$token, $accountId]);
        }
        if (!$mudados) return [];

        // Pastas conhecidas: as raízes dos clientes e as subpastas já vistas.
        $conhecidas = [];
        $st = $pdo->prepare('SELECT cliente_id, folder_id FROM drive_pastas_cliente WHERE account_id = ?');
        $st->execute([$accountId]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $p) $conhecidas[$p['folder_id']][] = (int) $p['cliente_id'];
        $st = $pdo->prepare('SELECT cliente_id, file_id FROM drive_itens WHERE account_id = ? AND mime = ?');
        $st->execute([$accountId, Api::PASTA]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $p) $conhecidas[$p['file_id']][] = (int) $p['cliente_id'];

        $pastas = [];
        // Onde o arquivo estava (pela lista conhecida) e onde está agora (pais novos).
        $ids = array_keys($mudados);
        foreach (array_chunk($ids, 500) as $lote) {
            $st = $pdo->prepare('SELECT cliente_id, parent_id FROM drive_itens WHERE account_id = ? AND file_id IN (' . implode(',', array_fill(0, count($lote), '?')) . ')');
            $st->execute(array_merge([$accountId], $lote));
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $x) $pastas[(int) $x['cliente_id'] . '|' . $x['parent_id']] = [(int) $x['cliente_id'], $x['parent_id']];
        }
        foreach ($mudados as $pais) {
            foreach ($pais as $pai) {
                foreach ($conhecidas[$pai] ?? [] as $cli) $pastas[$cli . '|' . $pai] = [$cli, $pai];
            }
        }
        return $pastas;
    }
}
