<?php

namespace App\Crm;

use App\Core\Database;

/**
 * Anexo — documentos de cliente e de prospeccao (bloco A da Fase 2).
 *
 * ---------------------------------------------------------------------------
 * O QUE HAVIA ANTES
 * ---------------------------------------------------------------------------
 * Nada. `task_attachments` pertence a tarefa e `lgpd_request_attachments` a
 * peticao de titular. Nao existia lugar para o RG do cliente, o contrato
 * assinado ou a procuracao. O escritorio guardava fora do sistema.
 *
 * ---------------------------------------------------------------------------
 * O ARQUIVO ACOMPANHA A CONVERSAO SEM SER COPIADO
 * ---------------------------------------------------------------------------
 * `listar()` de um cliente le o escopo inteiro dele: os anexos DELE mais os das
 * prospeccoes que apontam para ele (Entidade::escopoLeitura). O contrato
 * anexado enquanto a pessoa era lead continua na ficha do cliente sem que a
 * conversao mova um byte no disco.
 *
 * Copiar seria pior de tres jeitos: dobra o espaco, cria duas verdades sobre o
 * mesmo documento, e uma pessoa que volta como prospeccao nova ligada ao MESMO
 * cliente exigiria copiar de novo. Lendo junto, ela entra sozinha.
 *
 * O campo `origem_titulo` na listagem diz de onde cada anexo veio, para a ficha
 * poder marcar "veio da prospeccao" em vez de misturar tudo sem rotulo.
 *
 * ---------------------------------------------------------------------------
 * SEGURANCA DO ARQUIVO
 * ---------------------------------------------------------------------------
 * Este servico NAO toca no filesystem: quem grava e apaga arquivo e o endpoint
 * /api/crm_anexos.php, que reusa integralmente as defesas ja auditadas do
 * /api/task_attachments.php (whitelist de MIME por finfo, prefixo aleatorio de
 * 16 bytes, Content-Disposition attachment, nosniff, guarda de path traversal)
 * e o .htaccess "Require all denied" que ja cobre public/uploads/ inteiro,
 * subpastas incluidas.
 *
 * A separacao e proposital: o servico cuida de dono, escopo e auditoria; o
 * endpoint cuida de bytes. Testar tenancy nao exige subir arquivo.
 *
 * ---------------------------------------------------------------------------
 * REMOCAO
 * ---------------------------------------------------------------------------
 * A LINHA fica (deleted_at), o ARQUIVO sai do disco. O nome do documento e
 * prova: "o contrato existiu e foi removido por fulano em tal dia" e uma
 * informacao que o escritorio precisa. Apagar a linha apagaria a pergunta junto
 * com a resposta.
 */
final class Anexo
{
    /** Espelha ALLOWED_MIME do /api/task_attachments.php. SVG fica de fora: pode conter <script>. */
    public const MIMES_PERMITIDOS = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain', 'text/csv',
        'application/zip', 'application/x-zip-compressed',
    ];

    /** 20 MB. Acima disso e arquivo de midia, que nao e o caso de uso aqui. */
    public const TAMANHO_MAXIMO = 20 * 1024 * 1024;

    /**
     * Anexos de uma entidade. Para cliente, inclui os das prospeccoes de origem.
     *
     * @param  int[] $accountIds contas que a sessao alcanca
     * @return array<int,array<string,mixed>>
     */
    public static function listar(string $entidade, int $entidadeId, array $accountIds): array
    {
        [$ents, $ids] = Entidade::escopoLeitura($entidade, $entidadeId, $accountIds);
        [$where, $params] = Entidade::whereEscopo($ents, $ids, 'a');

        $accountIds = Entidade::inteiros($accountIds);
        if ($accountIds === []) {
            return [];
        }
        $inAcc = implode(',', array_fill(0, count($accountIds), '?'));

        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            "SELECT a.id, a.entidade, a.entidade_id, a.file_name, a.mime_type, a.file_size,
                    a.descricao, a.uploaded_by, a.created_at,
                    u.nome AS enviado_por_nome,
                    c.cliente_nome AS origem_titulo
               FROM crm_anexos a
          LEFT JOIN users u ON u.id = a.uploaded_by
          LEFT JOIN cards c ON c.id = a.entidade_id AND a.entidade = 'card'
              WHERE $where
                AND a.deleted_at IS NULL
                AND a.account_id IN ($inAcc)
           ORDER BY a.created_at DESC, a.id DESC"
        );
        $st->execute(array_merge($params, $accountIds));

        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);

        // file_path NUNCA sai daqui. O front so recebe a URL do endpoint
        // autenticado; montar URL a partir do caminho foi exatamente o furo
        // que a correcao P0 do task_attachments fechou.
        foreach ($linhas as &$l) {
            $l['download_url'] = '/api/crm_anexos.php?action=download&id=' . (int) $l['id'];
            $l['fase']         = $l['entidade'] === Entidade::CARD ? 'prospeccao' : 'cliente';
        }
        unset($l);

        return $linhas;
    }

    /**
     * Grava a linha de um arquivo que o endpoint JA salvou no disco.
     *
     * @param array{entidade:string,id:int,account_id:int,titulo:string} $alvo
     * @return int id do anexo
     */
    public static function registrar(
        array $alvo,
        string $filePath,
        string $fileName,
        ?string $mime,
        ?int $tamanho,
        ?string $descricao,
        ?int $userId
    ): int {
        $pdo = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO crm_anexos
               (account_id, entidade, entidade_id, file_path, file_name, mime_type,
                file_size, descricao, uploaded_by, created_at)
             VALUES (:acc, :ent, :eid, :path, :nome, :mime, :tam, :desc, :uid, NOW())'
        )->execute([
            'acc'  => $alvo['account_id'],
            'ent'  => $alvo['entidade'],
            'eid'  => $alvo['id'],
            'path' => $filePath,
            'nome' => $fileName,
            'mime' => $mime,
            'tam'  => $tamanho,
            'desc' => $descricao,
            'uid'  => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        Auditoria::registrar($alvo, $userId, 'anexo_adicionado', 'documento', null, $fileName);

        return $id;
    }

    /**
     * Um anexo, conferindo conta. Devolve tambem `file_path`, porque quem chama
     * e o download e ele precisa do caminho.
     *
     * @param  int[] $accountIds
     * @return array<string,mixed>|null
     */
    public static function buscar(int $anexoId, array $accountIds): ?array
    {
        $accountIds = Entidade::inteiros($accountIds);
        if ($anexoId <= 0 || $accountIds === []) {
            return null;
        }
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            "SELECT * FROM crm_anexos
              WHERE id = ? AND deleted_at IS NULL AND account_id IN ($in)
              LIMIT 1"
        );
        $st->execute(array_merge([$anexoId], $accountIds));
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Marca o anexo como removido. Quem chama apaga o arquivo do disco DEPOIS,
     * so se este metodo devolver true.
     *
     * A ordem importa: se o arquivo saisse primeiro e o UPDATE falhasse, a
     * listagem mostraria um documento que nao existe mais.
     *
     * @param array{entidade:string,id:int,account_id:int,titulo:string} $alvo
     */
    public static function remover(int $anexoId, array $alvo, ?int $userId, string $fileName): bool
    {
        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            'UPDATE crm_anexos
                SET deleted_at = NOW(), deleted_by = :uid
              WHERE id = :id AND account_id = :acc AND deleted_at IS NULL'
        );
        $st->execute([
            'uid' => $userId,
            'id'  => $anexoId,
            'acc' => $alvo['account_id'],
        ]);

        // rowCount 0 = alguem removeu entre o buscar() e este UPDATE. Nao e erro,
        // mas quem chama NAO pode apagar o arquivo: a outra remocao ja cuidou disso.
        if ($st->rowCount() < 1) {
            return false;
        }

        Auditoria::registrar($alvo, $userId, 'anexo_removido', 'documento', $fileName, null);
        return true;
    }

    /** Quantos anexos a ficha desta entidade mostra. Usado pelos contadores da UI. */
    public static function contar(string $entidade, int $entidadeId, array $accountIds): int
    {
        [$ents, $ids] = Entidade::escopoLeitura($entidade, $entidadeId, $accountIds);
        [$where, $params] = Entidade::whereEscopo($ents, $ids, 'a');

        $accountIds = Entidade::inteiros($accountIds);
        if ($accountIds === []) {
            return 0;
        }
        $inAcc = implode(',', array_fill(0, count($accountIds), '?'));

        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            "SELECT COUNT(*) FROM crm_anexos a
              WHERE $where AND a.deleted_at IS NULL AND a.account_id IN ($inAcc)"
        );
        $st->execute(array_merge($params, $accountIds));
        return (int) $st->fetchColumn();
    }
}
