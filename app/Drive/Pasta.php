<?php
namespace App\Drive;

use App\Core\Database;

/**
 * A pasta do Drive de cada cliente (20/10/2026). Guarda só o identificador da
 * pasta; os arquivos continuam no Drive.
 *
 * LISTAGEM E VERDADE. Cada pasta lida fica em `drive_itens` com a hora da
 * leitura (`drive_pastas_lidas`). A tela recebe sempre:
 *   atual = true   lido agora ou há pouco (até MAX_IDADE), com a hora;
 *   atual = false  o Google falhou: vem a última lista conhecida, a hora dela e
 *                  o erro, e a tela diz que pode estar desatualizada.
 * Lista velha nunca sai como se fosse de agora.
 *
 * SEGURANÇA. Subpasta só abre se foi vista dentro da árvore do próprio cliente
 * (está em `drive_itens` daquele cliente) ou é a raiz dele. Arquivo só vira
 * vínculo de cartão nas mesmas condições. Toda consulta filtra `account_id`.
 */
final class Pasta
{
    public const MAX_IDADE = 120;

    public static function daCliente(int $accountId, int $clienteId): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM drive_pastas_cliente WHERE cliente_id = ? AND account_id = ?');
        $st->execute([$clienteId, $accountId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Id da pasta a partir do link do Drive ou do próprio id. */
    public static function idDaReferencia(string $ref): ?string
    {
        $ref = trim($ref);
        if (preg_match('#/folders/([A-Za-z0-9_-]{10,})#', $ref, $m)) return $m[1];
        if (preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $ref, $m)) return $m[1];
        if (preg_match('#^[A-Za-z0-9_-]{10,}$#', $ref)) return $ref;
        return null;
    }

    private static function conferirCliente(int $accountId, int $clienteId): void
    {
        $st = Database::getConnection()->prepare('SELECT id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $st->execute([$clienteId, $accountId]);
        if (!$st->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado nesta conta.');
    }

    public static function vincular(int $accountId, int $userId, int $clienteId, string $referencia): array
    {
        self::conferirCliente($accountId, $clienteId);
        $id = self::idDaReferencia($referencia);
        if ($id === null) throw new \InvalidArgumentException('Cole o link da pasta do Drive ou escolha uma pasta da lista.');
        $f = Api::pedir(Conexao::acesso($accountId), 'GET', '/files/' . rawurlencode($id), ['fields' => 'id,name,mimeType,webViewLink,trashed', 'supportsAllDrives' => 'true']);
        if (($f['mimeType'] ?? '') !== Api::PASTA) throw new \InvalidArgumentException('Isso é um arquivo, não uma pasta. Escolha a pasta do cliente.');
        if (!empty($f['trashed'])) throw new \InvalidArgumentException('A pasta está na lixeira do Drive.');
        $pdo = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO drive_pastas_cliente (cliente_id, account_id, folder_id, folder_nome, folder_link, vinculado_por, vinculado_em, precisa_sync, ultima_sync, ultimo_erro, erro_em)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), 1, NULL, NULL, NULL)
             ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id), folder_nome = VALUES(folder_nome), folder_link = VALUES(folder_link),
               vinculado_por = VALUES(vinculado_por), vinculado_em = VALUES(vinculado_em), precisa_sync = 1, ultima_sync = NULL, ultimo_erro = NULL, erro_em = NULL'
        )->execute([$clienteId, $accountId, (string) $f['id'], mb_substr((string) $f['name'], 0, 255), $f['webViewLink'] ?? null, $userId ?: null]);
        // Pasta nova: o que se sabia da antiga não vale mais.
        $pdo->prepare('DELETE FROM drive_itens WHERE account_id = ? AND cliente_id = ?')->execute([$accountId, $clienteId]);
        $pdo->prepare('DELETE FROM drive_pastas_lidas WHERE account_id = ? AND cliente_id = ?')->execute([$accountId, $clienteId]);
        return self::daCliente($accountId, $clienteId);
    }

    public static function desvincular(int $accountId, int $clienteId): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM drive_pastas_cliente WHERE cliente_id = ? AND account_id = ?')->execute([$clienteId, $accountId]);
        $pdo->prepare('DELETE FROM drive_itens WHERE account_id = ? AND cliente_id = ?')->execute([$accountId, $clienteId]);
        $pdo->prepare('DELETE FROM drive_pastas_lidas WHERE account_id = ? AND cliente_id = ?')->execute([$accountId, $clienteId]);
    }

    /** Pastas da conta Google pelo nome, para escolher a do cliente. */
    public static function buscarPastas(int $accountId, string $termo): array
    {
        $termo = trim($termo);
        $q = "mimeType = '" . Api::PASTA . "' and trashed = false";
        if ($termo !== '') $q .= " and name contains '" . str_replace(['\\', "'"], ['\\\\', "\\'"], mb_substr($termo, 0, 80)) . "'";
        $r = Api::pedir(Conexao::acesso($accountId), 'GET', '/files', ['q' => $q, 'pageSize' => 25, 'orderBy' => 'modifiedTime desc',
            'fields' => 'files(id,name,webViewLink,modifiedTime)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']);
        return array_map(fn($f) => ['id' => $f['id'], 'nome' => $f['name'], 'link' => $f['webViewLink'] ?? null, 'modificado_em' => self::data($f['modifiedTime'] ?? null)], $r['files'] ?? []);
    }

    private static function data(?string $iso): ?string
    {
        if (!$iso) return null;
        try { return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s'); } catch (\Throwable $e) { return null; }
    }

    /** A subpasta pertence à árvore do cliente? (raiz ou vista dentro dela) */
    public static function pastaDoCliente(int $accountId, int $clienteId, string $folderId): bool
    {
        $p = self::daCliente($accountId, $clienteId);
        if (!$p) return false;
        if ($folderId === $p['folder_id']) return true;
        $st = Database::getConnection()->prepare('SELECT COUNT(*) FROM drive_itens WHERE account_id = ? AND cliente_id = ? AND file_id = ? AND mime = ?');
        $st->execute([$accountId, $clienteId, $folderId, Api::PASTA]);
        return (int) $st->fetchColumn() > 0;
    }

    /** Um arquivo visto dentro da árvore do cliente (para vincular a cartão). */
    public static function itemDoCliente(int $accountId, int $clienteId, string $fileId): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM drive_itens WHERE account_id = ? AND cliente_id = ? AND file_id = ?');
        $st->execute([$accountId, $clienteId, $fileId]);
        $i = $st->fetch(\PDO::FETCH_ASSOC);
        return $i ? self::formatar($i) : null;
    }

    private static function formatar(array $i): array
    {
        $pasta = $i['mime'] === Api::PASTA;
        return ['id' => $i['file_id'], 'nome' => $i['nome'], 'mime' => $i['mime'], 'pasta' => $pasta, 'tipo' => self::tipo($i['mime']),
                'modificado_em' => $i['modificado_em'], 'link' => $i['link'], 'tamanho' => $i['tamanho'] !== null ? (int) $i['tamanho'] : null];
    }

    public static function tipo(string $mime): string
    {
        $mapa = [Api::PASTA => 'Pasta', 'application/pdf' => 'PDF', 'application/vnd.google-apps.document' => 'Documento Google',
                 'application/vnd.google-apps.spreadsheet' => 'Planilha Google', 'application/vnd.google-apps.presentation' => 'Apresentação Google',
                 'application/zip' => 'ZIP'];
        if (isset($mapa[$mime])) return $mapa[$mime];
        if (str_starts_with($mime, 'image/')) return 'Imagem';
        if (str_starts_with($mime, 'video/')) return 'Vídeo';
        if (str_starts_with($mime, 'audio/')) return 'Áudio';
        if (str_contains($mime, 'word')) return 'Word';
        if (str_contains($mime, 'sheet') || str_contains($mime, 'excel')) return 'Planilha';
        if (str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint')) return 'Apresentação';
        return 'Arquivo';
    }

    /** Caminho da raiz até a pasta, pelo que se conhece da árvore. */
    private static function caminho(int $accountId, int $clienteId, array $raiz, string $folderId): array
    {
        $trilha = [];
        $atual = $folderId;
        $st = Database::getConnection()->prepare('SELECT file_id, parent_id, nome FROM drive_itens WHERE account_id = ? AND cliente_id = ? AND file_id = ?');
        for ($i = 0; $i < 15 && $atual !== $raiz['folder_id']; $i++) {
            $st->execute([$accountId, $clienteId, $atual]);
            $x = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$x) break;
            array_unshift($trilha, ['id' => $x['file_id'], 'nome' => $x['nome']]);
            $atual = $x['parent_id'];
        }
        array_unshift($trilha, ['id' => $raiz['folder_id'], 'nome' => $raiz['folder_nome']]);
        return $trilha;
    }

    /**
     * Lista uma pasta do cliente (raiz, sem $folderId). Usa a leitura recente se
     * houver; $forcar lê do Drive agora. Ver "LISTAGEM E VERDADE" no cabeçalho.
     */
    public static function listar(int $accountId, int $clienteId, ?string $folderId = null, bool $forcar = false): array
    {
        $raiz = self::daCliente($accountId, $clienteId);
        if (!$raiz) throw new \InvalidArgumentException('Este cliente ainda não tem pasta do Drive.');
        $folderId = $folderId ?: $raiz['folder_id'];
        if (!self::pastaDoCliente($accountId, $clienteId, $folderId)) throw new \InvalidArgumentException('Pasta fora da pasta do cliente.');
        $pdo = Database::getConnection();
        $lida = $pdo->prepare('SELECT lida_em, erro, erro_em FROM drive_pastas_lidas WHERE account_id = ? AND cliente_id = ? AND folder_id = ?');
        $lida->execute([$accountId, $clienteId, $folderId]);
        $l = $lida->fetch(\PDO::FETCH_ASSOC) ?: null;
        $recente = $l && $l['lida_em'] && (time() - strtotime($l['lida_em'])) <= self::MAX_IDADE && !$l['erro'];
        $precisa = $folderId === $raiz['folder_id'] && (int) $raiz['precisa_sync'] === 1;
        $erro = null;
        if ($forcar || !$recente || $precisa) {
            try {
                self::lerDoDrive($accountId, $clienteId, $folderId, $raiz);
                $lida->execute([$accountId, $clienteId, $folderId]);
                $l = $lida->fetch(\PDO::FETCH_ASSOC) ?: null;
            } catch (DriveErro $e) {
                $erro = $e->getMessage();
                $pdo->prepare('INSERT INTO drive_pastas_lidas (account_id, cliente_id, folder_id, lida_em, erro, erro_em) VALUES (?, ?, ?, NULL, ?, NOW())
                               ON DUPLICATE KEY UPDATE erro = VALUES(erro), erro_em = VALUES(erro_em)')->execute([$accountId, $clienteId, $folderId, mb_substr($erro, 0, 500)]);
                if ($folderId === $raiz['folder_id']) {
                    $pdo->prepare('UPDATE drive_pastas_cliente SET ultimo_erro = ?, erro_em = NOW() WHERE cliente_id = ? AND account_id = ?')->execute([mb_substr($erro, 0, 500), $clienteId, $accountId]);
                }
            }
        }
        $it = $pdo->prepare('SELECT * FROM drive_itens WHERE account_id = ? AND cliente_id = ? AND parent_id = ? ORDER BY mime = ? DESC, nome');
        $it->execute([$accountId, $clienteId, $folderId, Api::PASTA]);
        return [
            'pasta' => ['id' => $raiz['folder_id'], 'nome' => $raiz['folder_nome'], 'link' => $raiz['folder_link']],
            'atual_id' => $folderId,
            'caminho' => self::caminho($accountId, $clienteId, $raiz, $folderId),
            'itens' => array_map([self::class, 'formatar'], $it->fetchAll(\PDO::FETCH_ASSOC)),
            'sincronizado_em' => $l['lida_em'] ?? null,
            'atual' => $erro === null && !empty($l['lida_em']),
            'erro' => $erro,
        ];
    }

    /** Lê os filhos da pasta no Drive e troca o que se sabia dela. */
    public static function lerDoDrive(int $accountId, int $clienteId, string $folderId, ?array $raiz = null): int
    {
        $acesso = Conexao::acesso($accountId);
        $arquivos = [];
        $pagina = null;
        for ($i = 0; $i < 10; $i++) {
            $q = ['q' => "'" . str_replace("'", "\\'", $folderId) . "' in parents and trashed = false", 'pageSize' => 200, 'orderBy' => 'folder,name',
                  'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,webViewLink,size)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true'];
            if ($pagina) $q['pageToken'] = $pagina;
            $r = Api::pedir($acesso, 'GET', '/files', $q);
            foreach ($r['files'] ?? [] as $f) $arquivos[] = $f;
            $pagina = $r['nextPageToken'] ?? null;
            if (!$pagina) break;
        }
        $pdo = Database::getConnection();
        $agora = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $ids = array_map(fn($f) => (string) $f['id'], $arquivos);
            $sql = 'DELETE FROM drive_itens WHERE account_id = ? AND cliente_id = ? AND parent_id = ?';
            $args = [$accountId, $clienteId, $folderId];
            if ($ids) { $sql .= ' AND file_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($args, ...$ids); }
            $pdo->prepare($sql)->execute($args);
            $ins = $pdo->prepare('INSERT INTO drive_itens (account_id, cliente_id, file_id, parent_id, nome, mime, modificado_em, link, tamanho, sincronizado_em)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), nome = VALUES(nome), mime = VALUES(mime), modificado_em = VALUES(modificado_em),
                                    link = VALUES(link), tamanho = VALUES(tamanho), sincronizado_em = VALUES(sincronizado_em)');
            foreach ($arquivos as $f) {
                $ins->execute([$accountId, $clienteId, (string) $f['id'], $folderId, mb_substr((string) $f['name'], 0, 255), (string) $f['mimeType'],
                    self::data($f['modifiedTime'] ?? null), $f['webViewLink'] ?? null, isset($f['size']) ? (int) $f['size'] : null, $agora]);
            }
            $pdo->prepare('INSERT INTO drive_pastas_lidas (account_id, cliente_id, folder_id, lida_em, erro, erro_em) VALUES (?, ?, ?, ?, NULL, NULL)
                           ON DUPLICATE KEY UPDATE lida_em = VALUES(lida_em), erro = NULL, erro_em = NULL')->execute([$accountId, $clienteId, $folderId, $agora]);
            $raiz ??= self::daCliente($accountId, $clienteId);
            if ($raiz && $folderId === $raiz['folder_id']) {
                $pdo->prepare('UPDATE drive_pastas_cliente SET ultima_sync = ?, precisa_sync = 0, ultimo_erro = NULL, erro_em = NULL WHERE cliente_id = ? AND account_id = ?')
                    ->execute([$agora, $clienteId, $accountId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return count($arquivos);
    }

    /**
     * Envia um arquivo para a pasta (ou subpasta) do cliente e o coloca na lista.
     * @return array o item, como a listagem devolve
     */
    public static function enviar(int $accountId, int $clienteId, ?string $folderId, string $nome, string $bytes, string $mime): array
    {
        $raiz = self::daCliente($accountId, $clienteId);
        if (!$raiz) throw new \InvalidArgumentException('Este cliente ainda não tem pasta do Drive.');
        $folderId = $folderId ?: $raiz['folder_id'];
        if (!self::pastaDoCliente($accountId, $clienteId, $folderId)) throw new \InvalidArgumentException('Pasta fora da pasta do cliente.');
        $nome = trim(str_replace(["\r", "\n", '/', '\\'], ' ', $nome));
        if ($nome === '') throw new \InvalidArgumentException('Arquivo sem nome.');
        $f = Api::enviarArquivo(Conexao::acesso($accountId), ['name' => mb_substr($nome, 0, 250), 'parents' => [$folderId]], $bytes, $mime ?: 'application/octet-stream');
        Database::getConnection()->prepare(
            'INSERT INTO drive_itens (account_id, cliente_id, file_id, parent_id, nome, mime, modificado_em, link, tamanho, sincronizado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE nome = VALUES(nome), modificado_em = VALUES(modificado_em), link = VALUES(link), tamanho = VALUES(tamanho), sincronizado_em = NOW()'
        )->execute([$accountId, $clienteId, (string) $f['id'], $folderId, mb_substr((string) ($f['name'] ?? $nome), 0, 255), (string) ($f['mimeType'] ?? $mime),
            self::data($f['modifiedTime'] ?? null), $f['webViewLink'] ?? null, isset($f['size']) ? (int) $f['size'] : strlen($bytes)]);
        return self::itemDoCliente($accountId, $clienteId, (string) $f['id']);
    }

    /**
     * Quem da equipe tem acesso à pasta no Drive. Abrir um arquivo pelo link
     * exige que a pessoa tenha acesso no Drive também; aqui se vê quem não tem.
     *
     * @param array<int, array{id:int, nome:string, email:string}> $equipe quem vê o cliente no sistema
     */
    public static function acessos(int $accountId, int $clienteId, array $equipe): array
    {
        $raiz = self::daCliente($accountId, $clienteId);
        if (!$raiz) throw new \InvalidArgumentException('Este cliente ainda não tem pasta do Drive.');
        $r = Api::pedir(Conexao::acesso($accountId), 'GET', '/files/' . rawurlencode($raiz['folder_id']) . '/permissions',
            ['fields' => 'permissions(type,role,emailAddress,domain,displayName)', 'supportsAllDrives' => 'true']);
        $emails = []; $dominios = []; $qualquer = false;
        foreach ($r['permissions'] ?? [] as $p) {
            if (($p['type'] ?? '') === 'user' || ($p['type'] ?? '') === 'group') $emails[strtolower((string) ($p['emailAddress'] ?? ''))] = $p['role'] ?? '';
            if (($p['type'] ?? '') === 'domain') $dominios[strtolower((string) ($p['domain'] ?? ''))] = $p['role'] ?? '';
            if (($p['type'] ?? '') === 'anyone') $qualquer = true;
        }
        $com = []; $sem = [];
        foreach ($equipe as $u) {
            $e = strtolower(trim((string) $u['email']));
            $dom = substr(strrchr($e, '@') ?: '', 1);
            $papel = $emails[$e] ?? ($dominios[$dom] ?? ($qualquer ? 'reader' : null));
            if ($e !== '' && $papel !== null) $com[] = $u + ['papel' => $papel]; else $sem[] = $u;
        }
        return ['com_acesso' => $com, 'sem_acesso' => $sem, 'link_publico' => $qualquer];
    }

    /** Dá acesso de edição na pasta a pessoas da equipe (sem e-mail do Google). */
    public static function darAcesso(int $accountId, int $clienteId, array $emails): int
    {
        $raiz = self::daCliente($accountId, $clienteId);
        if (!$raiz) throw new \InvalidArgumentException('Este cliente ainda não tem pasta do Drive.');
        $acesso = Conexao::acesso($accountId);
        $n = 0;
        foreach (array_unique(array_filter(array_map(fn($e) => strtolower(trim((string) $e)), $emails))) as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
            Api::pedir($acesso, 'POST', '/files/' . rawurlencode($raiz['folder_id']) . '/permissions',
                ['sendNotificationEmail' => 'false', 'supportsAllDrives' => 'true'], ['type' => 'user', 'role' => 'writer', 'emailAddress' => $e]);
            $n++;
        }
        return $n;
    }
}
