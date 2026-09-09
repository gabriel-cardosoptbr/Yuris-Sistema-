<?php

namespace App\Crm;

use App\Core\Database;

/**
 * Interacao — contatos registrados e notas internas (bloco D da Fase 2).
 *
 * ---------------------------------------------------------------------------
 * O QUE HAVIA ANTES
 * ---------------------------------------------------------------------------
 * A coluna `observacoes text`, nos dois cadastros. Texto solto, sem autor, sem
 * data, sem historico: quem escreveu por cima apagava o que estava la e ninguem
 * ficava sabendo. Uma ligacao de meia hora com o cliente sumia junto.
 *
 * ---------------------------------------------------------------------------
 * UMA TABELA PARA INTERACAO E PARA NOTA
 * ---------------------------------------------------------------------------
 * Nota interna E uma interacao sem contraparte: `tipo = 'nota'`. Duas tabelas
 * obrigariam a ficha a fazer dois SELECTs e um merge por data em PHP, com dois
 * formatos de linha alimentando a mesma timeline, para uma diferenca que cabe
 * numa coluna.
 *
 * ---------------------------------------------------------------------------
 * `ocorrido_em` NAO E `created_at`
 * ---------------------------------------------------------------------------
 * A ligacao de ontem as 15h e registrada hoje as 9h. `ocorrido_em` e quando o
 * fato foi, `created_at` e quando alguem digitou. A timeline ordena pelo
 * primeiro, senao o registro atrasado apareceria fora de lugar e a leitura
 * cronologica do relacionamento ficaria errada.
 *
 * O segundo nao e enfeite: e ele que responde "isso foi anotado depois?".
 *
 * ---------------------------------------------------------------------------
 * A INTERACAO APARECE UMA VEZ SO NA TIMELINE
 * ---------------------------------------------------------------------------
 * Criar interacao NAO grava evento de auditoria, de proposito. A propria linha
 * de `crm_interacoes` (com created_by e created_at) e a prova da criacao, e a
 * Timeline le a tabela direto. Gravar tambem um `interacao_registrada` no
 * historico faria o mesmo fato aparecer duas vezes na mesma tela, que e
 * exatamente o que o pedido proibiu.
 *
 * Editar e remover, sim, sao auditados: ali a linha atual nao conta mais a
 * historia toda, porque ela mudou.
 *
 * ---------------------------------------------------------------------------
 * ACOMPANHA A CONVERSAO SEM COPIA
 * ---------------------------------------------------------------------------
 * Interacao e FATO, como anexo e como historico: `listar()` de um cliente le o
 * escopo inteiro dele (ele mais as prospeccoes que apontam para ele). A ligacao
 * feita enquanto a pessoa era lead continua na ficha do cliente sem que a
 * conversao copie uma linha.
 */
final class Interacao
{
    public const TIPOS = ['ligacao', 'reuniao', 'email', 'whatsapp', 'presencial', 'nota', 'outro'];

    public const DIRECOES = ['entrada', 'saida', 'interna'];

    /** Rotulos para a UI e para o texto do historico. */
    public const ROTULOS = [
        'ligacao'    => 'Ligação',
        'reuniao'    => 'Reunião',
        'email'      => 'E-mail',
        'whatsapp'   => 'WhatsApp',
        'presencial' => 'Atendimento presencial',
        'nota'       => 'Nota interna',
        'outro'      => 'Outro',
    ];

    /**
     * Interacoes de uma entidade. Para cliente, inclui as das prospeccoes de origem.
     *
     * @param int[] $accountIds
     */
    public static function listar(string $entidade, int $entidadeId, array $accountIds, int $limite = 200): array
    {
        [$ents, $ids]     = Entidade::escopoLeitura($entidade, $entidadeId, $accountIds);
        [$where, $params] = Entidade::whereEscopo($ents, $ids, 'i');

        $accountIds = Entidade::inteiros($accountIds);
        if ($accountIds === []) {
            return [];
        }
        $inAcc  = implode(',', array_fill(0, count($accountIds), '?'));
        $limite = max(1, min(500, $limite));

        $st = Database::getConnection()->prepare(
            "SELECT i.id, i.entidade, i.entidade_id, i.tipo, i.direcao, i.assunto, i.conteudo,
                    i.ocorrido_em, i.duracao_min, i.created_by, i.created_at, i.updated_at,
                    u.nome AS registrado_por_nome,
                    c.cliente_nome AS origem_titulo
               FROM crm_interacoes i
          LEFT JOIN users u ON u.id = i.created_by
          LEFT JOIN cards c ON c.id = i.entidade_id AND i.entidade = 'card'
              WHERE $where
                AND i.deleted_at IS NULL
                AND i.account_id IN ($inAcc)
           ORDER BY i.ocorrido_em DESC, i.id DESC
              LIMIT $limite"
        );
        $st->execute(array_merge($params, $accountIds));

        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($linhas as &$l) {
            $l['tipo_rotulo'] = self::ROTULOS[$l['tipo']] ?? $l['tipo'];
            $l['fase']        = $l['entidade'] === Entidade::CARD ? 'prospeccao' : 'cliente';
            // "anotado depois": o front marca isso para nao confundir o leitor.
            $l['retroativo']  = $l['created_at'] !== null
                && strtotime((string) $l['created_at']) - strtotime((string) $l['ocorrido_em']) > 3600;
        }
        unset($l);
        return $linhas;
    }

    /**
     * Registra uma interacao.
     *
     * @param array{entidade:string,id:int,account_id:int,titulo:string} $alvo
     * @param array<string,mixed> $dados tipo, direcao, assunto, conteudo, ocorrido_em, duracao_min
     * @return int id da interacao
     * @throws \InvalidArgumentException
     */
    public static function registrar(array $alvo, array $dados, ?int $userId): int
    {
        $tipo = (string) ($dados['tipo'] ?? 'nota');
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de interacao invalido');
        }

        $conteudo = trim((string) ($dados['conteudo'] ?? ''));
        $assunto  = trim((string) ($dados['assunto'] ?? ''));
        if ($conteudo === '' && $assunto === '') {
            throw new \InvalidArgumentException('Escreva o assunto ou o conteudo');
        }

        // Nota interna nao tem direcao: ninguem ligou para ninguem.
        $direcao = $dados['direcao'] ?? null;
        if ($tipo === 'nota') {
            $direcao = null;
        } elseif (!in_array($direcao, self::DIRECOES, true)) {
            $direcao = null;
        }

        $ocorrido = self::momento($dados['ocorrido_em'] ?? null);

        $duracao = isset($dados['duracao_min']) && $dados['duracao_min'] !== ''
            ? max(0, min(100000, (int) $dados['duracao_min']))
            : null;

        $pdo = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO crm_interacoes
               (account_id, entidade, entidade_id, tipo, direcao, assunto, conteudo,
                ocorrido_em, duracao_min, created_by, created_at, updated_at)
             VALUES (:acc, :ent, :eid, :tipo, :dir, :ass, :cont, :ocor, :dur, :uid, NOW(), NOW())'
        )->execute([
            'acc'  => $alvo['account_id'],
            'ent'  => $alvo['entidade'],
            'eid'  => $alvo['id'],
            'tipo' => $tipo,
            'dir'  => $direcao,
            'ass'  => $assunto === '' ? null : mb_substr($assunto, 0, 180),
            'cont' => $conteudo === '' ? null : mb_substr($conteudo, 0, 20000),
            'ocor' => $ocorrido,
            'dur'  => $duracao,
            'uid'  => $userId,
        ]);

        // Sem Auditoria::registrar aqui, e de proposito. Ver o cabecalho.
        return (int) $pdo->lastInsertId();
    }

    /**
     * Edita uma interacao. Registra no historico O QUE mudou, porque a linha
     * atual passa a nao contar mais a versao anterior.
     *
     * @param int[] $accountIds
     */
    public static function atualizar(int $id, array $accountIds, array $dados, ?int $userId): bool
    {
        $atual = self::buscar($id, $accountIds);
        if ($atual === null) {
            return false;
        }
        $alvo = Entidade::resolver((string) $atual['entidade'], (int) $atual['entidade_id'], $accountIds);
        if ($alvo === null) {
            return false;
        }

        $sets   = [];
        $params = ['id' => $id];
        $mudou  = [];

        if (array_key_exists('assunto', $dados)) {
            $novo = trim((string) $dados['assunto']);
            $novo = $novo === '' ? null : mb_substr($novo, 0, 180);
            if ($novo !== $atual['assunto']) {
                $sets[]        = 'assunto = :ass';
                $params['ass'] = $novo;
                $mudou['assunto'] = [$atual['assunto'], $novo];
            }
        }
        if (array_key_exists('conteudo', $dados)) {
            $novo = trim((string) $dados['conteudo']);
            $novo = $novo === '' ? null : mb_substr($novo, 0, 20000);
            if ($novo !== $atual['conteudo']) {
                $sets[]         = 'conteudo = :cont';
                $params['cont'] = $novo;
                $mudou['conteudo'] = ['(texto anterior)', '(texto novo)'];
            }
        }
        if (!empty($dados['ocorrido_em'])) {
            $novo = self::momento($dados['ocorrido_em']);
            if ($novo !== $atual['ocorrido_em']) {
                $sets[]         = 'ocorrido_em = :ocor';
                $params['ocor'] = $novo;
                $mudou['ocorrido_em'] = [$atual['ocorrido_em'], $novo];
            }
        }
        if (array_key_exists('duracao_min', $dados)) {
            $novo = $dados['duracao_min'] === '' || $dados['duracao_min'] === null
                ? null
                : max(0, min(100000, (int) $dados['duracao_min']));
            if ((string) $novo !== (string) $atual['duracao_min']) {
                $sets[]        = 'duracao_min = :dur';
                $params['dur'] = $novo;
                $mudou['duracao_min'] = [$atual['duracao_min'], $novo];
            }
        }

        if ($sets === []) {
            return false;
        }
        $sets[] = 'updated_at = NOW()';

        $ok = Database::getConnection()
            ->prepare('UPDATE crm_interacoes SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);

        if ($ok) {
            $rotulo = self::ROTULOS[$atual['tipo']] ?? $atual['tipo'];
            foreach ($mudou as $campo => [$de, $para]) {
                Auditoria::registrar(
                    $alvo,
                    $userId,
                    'interacao_editada',
                    $rotulo . ': ' . $campo,
                    $de === null ? null : (string) $de,
                    $para === null ? null : (string) $para
                );
            }
        }
        return (bool) $ok;
    }

    /**
     * Remove a interacao. Soft delete: a linha fica, o registro sai das listas.
     *
     * @param int[] $accountIds
     */
    public static function remover(int $id, array $accountIds, ?int $userId): bool
    {
        $atual = self::buscar($id, $accountIds);
        if ($atual === null) {
            return false;
        }
        $alvo = Entidade::resolver((string) $atual['entidade'], (int) $atual['entidade_id'], $accountIds);
        if ($alvo === null) {
            return false;
        }

        $st = Database::getConnection()->prepare(
            'UPDATE crm_interacoes
                SET deleted_at = NOW(), deleted_by = :uid
              WHERE id = :id AND deleted_at IS NULL'
        );
        $st->execute(['uid' => $userId, 'id' => $id]);
        if ($st->rowCount() < 1) {
            return false;
        }

        $rotulo = self::ROTULOS[$atual['tipo']] ?? $atual['tipo'];
        $titulo = (string) ($atual['assunto'] ?? '');
        Auditoria::registrar(
            $alvo,
            $userId,
            'interacao_removida',
            $rotulo,
            $titulo !== '' ? $titulo : mb_substr((string) ($atual['conteudo'] ?? ''), 0, 60),
            null
        );
        return true;
    }

    /** @param int[] $accountIds */
    public static function buscar(int $id, array $accountIds): ?array
    {
        $accountIds = Entidade::inteiros($accountIds);
        if ($id <= 0 || $accountIds === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT * FROM crm_interacoes
              WHERE id = ? AND deleted_at IS NULL AND account_id IN ($in)
              LIMIT 1"
        );
        $st->execute(array_merge([$id], $accountIds));
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Quantas interacoes a ficha desta entidade mostra. */
    public static function contar(string $entidade, int $entidadeId, array $accountIds): int
    {
        [$ents, $ids]     = Entidade::escopoLeitura($entidade, $entidadeId, $accountIds);
        [$where, $params] = Entidade::whereEscopo($ents, $ids, 'i');

        $accountIds = Entidade::inteiros($accountIds);
        if ($accountIds === []) {
            return 0;
        }
        $inAcc = implode(',', array_fill(0, count($accountIds), '?'));

        $st = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM crm_interacoes i
              WHERE $where AND i.deleted_at IS NULL AND i.account_id IN ($inAcc)"
        );
        $st->execute(array_merge($params, $accountIds));
        return (int) $st->fetchColumn();
    }

    /**
     * Aceita 'Y-m-d H:i:s', 'Y-m-d\TH:i' (o que o input datetime-local manda),
     * 'Y-m-d' e 'd/m/Y H:i'. Vazio ou invalido vira agora.
     *
     * Vira agora em vez de recusar porque a data e conveniencia: quem nao mexeu
     * no campo quer registrar o que acabou de acontecer, e barrar o registro por
     * causa do formato perderia o conteudo que a pessoa escreveu.
     */
    private static function momento($v): string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return date('Y-m-d H:i:s');
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i', 'd/m/Y H:i', 'Y-m-d', 'd/m/Y'] as $f) {
            $d = \DateTime::createFromFormat($f, $s);
            if ($d !== false) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        $ts = strtotime($s);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }
}
