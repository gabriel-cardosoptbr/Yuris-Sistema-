<?php

namespace App\Crm;

use App\Core\Database;

/**
 * Tag — etiquetas e classificacoes de cliente e de prospeccao (bloco B).
 *
 * ---------------------------------------------------------------------------
 * TRES NOMES, UMA ESTRUTURA
 * ---------------------------------------------------------------------------
 * O pedido falava em "tags, labels e classificacoes". Sao a mesma coisa: um
 * rotulo curto de conjunto aberto que a pessoa aplica e tira. Construir tres
 * mecanismos daria tres telas de configuracao, tres tabelas de vinculo e a
 * pergunta eterna de qual usar. E um catalogo por conta, com cor.
 *
 * ---------------------------------------------------------------------------
 * TAG E OPINIAO, ENTAO ELA COPIA NA CONVERSAO
 * ---------------------------------------------------------------------------
 * Anexo e interacao sao lidos por escopo: o documento do lead aparece na ficha
 * do cliente sem copia. Tag NAO. Aqui ela e copiada uma vez, na conversao, e
 * depois vive solta.
 *
 * O motivo e concreto: "lead frio" e um juizo sobre a prospeccao. Se a ficha do
 * cliente herdasse a tag por leitura, o usuario nao conseguiria tirar ela do
 * cliente sem mexer no card, e isso e errado duas vezes. Primeiro porque depois
 * da conversao a classificacao e outra. Segundo porque editar o card para
 * arrumar o cliente e ação a distancia, do tipo que ninguem descobre sozinho.
 *
 * A copia acontece em App\Prospeccao\ConversaoCliente, uma vez, dentro da
 * transacao, e e registrada no historico do cliente.
 *
 * ---------------------------------------------------------------------------
 * SLUG, E POR QUE NAO E O NOME
 * ---------------------------------------------------------------------------
 * O vinculo aponta para o id, e o historico grava o NOME de quando o evento
 * aconteceu. O `slug` e a identidade estavel para o UNIQUE por conta: renomear
 * "Urgente" para "Prioridade alta" nao cria tag nova nem reescreve o passado.
 *
 * ---------------------------------------------------------------------------
 * MULTI-TENANT
 * ---------------------------------------------------------------------------
 * `crm_tag_vinculos.account_id` e denormalizado da tag, mas `aplicar()` confere
 * que tag.account_id == entidade.account_id ANTES de gravar. E a unica forma de
 * impedir que uma sessao matriz, que alcanca a filial, aplique tag da matriz num
 * card da filial: as duas contas sao acessiveis, e sem essa conferencia o SQL
 * aceitaria de bom grado.
 */
final class Tag
{
    /** Cores oferecidas na tela. Hex fixo para nao depender de tema. */
    public const CORES = [
        '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e',
        '#10b981', '#14b8a6', '#06b6d4', '#3b82f6', '#6366f1', '#8b5cf6',
        '#a855f7', '#ec4899', '#64748b',
    ];

    /* ===================================================================== */
    /* catalogo                                                              */
    /* ===================================================================== */

    /**
     * Catalogo de tags das contas dadas.
     *
     * Recebe a lista de contas acessiveis, e nao uma conta so, porque a sessao
     * matriz precisa ver e aplicar as tags das filiais que ela alcanca.
     *
     * @param int[] $accountIds
     */
    public static function catalogo(array $accountIds, bool $incluirInativas = false): array
    {
        $accountIds = Entidade::inteiros($accountIds);
        if ($accountIds === []) {
            return [];
        }
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $sql = "SELECT t.id, t.account_id, t.nome, t.slug, t.cor, t.ordem, t.ativo,
                       (SELECT COUNT(*) FROM crm_tag_vinculos v WHERE v.tag_id = t.id) AS usos
                  FROM crm_tags t
                 WHERE t.account_id IN ($in)";
        if (!$incluirInativas) {
            $sql .= ' AND t.ativo = 1';
        }
        $sql .= ' ORDER BY t.ordem, t.nome';

        $pdo = Database::getConnection();
        $st  = $pdo->prepare($sql);
        $st->execute($accountIds);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Cria a tag, ou devolve a que ja existe com o mesmo slug.
     *
     * Devolver a existente em vez de recusar e o que permite o "digite e crie"
     * da tela: quem digita "Urgente" numa ficha onde a tag ja existe quer
     * aplicar aquela tag, nao ver um erro de duplicidade.
     *
     * @return array<string,mixed> a tag (nova ou existente)
     */
    public static function criar(int $accountId, string $nome, ?string $cor, ?int $userId): array
    {
        $nome = trim($nome);
        if ($nome === '') {
            throw new \InvalidArgumentException('Nome da tag e obrigatorio');
        }
        $nome = mb_substr($nome, 0, 60);
        $slug = self::slugify($nome);
        $cor  = self::corValida($cor) ? $cor : self::corPorSlug($slug);

        $pdo = Database::getConnection();

        $st = $pdo->prepare('SELECT * FROM crm_tags WHERE account_id = ? AND slug = ? LIMIT 1');
        $st->execute([$accountId, $slug]);
        $ja = $st->fetch(\PDO::FETCH_ASSOC);
        if ($ja) {
            // Reativa em silencio: tag arquivada que volta a ser digitada e a
            // mesma tag, e criar uma segunda com slug_2 seria pior.
            if ((int) $ja['ativo'] === 0) {
                $pdo->prepare('UPDATE crm_tags SET ativo = 1, updated_at = NOW() WHERE id = ?')
                    ->execute([(int) $ja['id']]);
                $ja['ativo'] = 1;
            }
            return $ja;
        }

        $ordem = (int) $pdo->query(
            'SELECT COALESCE(MAX(ordem), 0) + 1 FROM crm_tags WHERE account_id = ' . (int) $accountId
        )->fetchColumn();

        $pdo->prepare(
            'INSERT INTO crm_tags (account_id, nome, slug, cor, ordem, ativo, created_by, created_at, updated_at)
             VALUES (:acc, :nome, :slug, :cor, :ordem, 1, :uid, NOW(), NOW())'
        )->execute([
            'acc'   => $accountId,
            'nome'  => $nome,
            'slug'  => $slug,
            'cor'   => $cor,
            'ordem' => $ordem,
            'uid'   => $userId,
        ]);

        return [
            'id'         => (int) $pdo->lastInsertId(),
            'account_id' => $accountId,
            'nome'       => $nome,
            'slug'       => $slug,
            'cor'        => $cor,
            'ordem'      => $ordem,
            'ativo'      => 1,
            'usos'       => 0,
        ];
    }

    /** Renomeia ou troca a cor. O slug NAO muda: e a identidade estavel. */
    public static function atualizar(int $tagId, array $accountIds, ?string $nome, ?string $cor): bool
    {
        $tag = self::buscar($tagId, $accountIds);
        if ($tag === null) {
            return false;
        }
        $sets   = [];
        $params = ['id' => $tagId];
        if ($nome !== null && trim($nome) !== '') {
            $sets[]         = 'nome = :nome';
            $params['nome'] = mb_substr(trim($nome), 0, 60);
        }
        if ($cor !== null && self::corValida($cor)) {
            $sets[]        = 'cor = :cor';
            $params['cor'] = $cor;
        }
        if ($sets === []) {
            return false;
        }
        $sets[] = 'updated_at = NOW()';
        $pdo    = Database::getConnection();
        return $pdo->prepare('UPDATE crm_tags SET ' . implode(', ', $sets) . ' WHERE id = :id')
                   ->execute($params);
    }

    /**
     * Arquiva a tag. Os vinculos FICAM.
     *
     * Apagar os vinculos junto reescreveria o passado: uma ficha marcada como
     * "Urgente" no ano passado continua tendo sido marcada. A tag arquivada sai
     * do seletor e continua aparecendo, apagada, onde ja estava.
     */
    public static function arquivar(int $tagId, array $accountIds): bool
    {
        if (self::buscar($tagId, $accountIds) === null) {
            return false;
        }
        return Database::getConnection()
            ->prepare('UPDATE crm_tags SET ativo = 0, updated_at = NOW() WHERE id = ?')
            ->execute([$tagId]);
    }

    /** @param int[] $accountIds */
    public static function buscar(int $tagId, array $accountIds): ?array
    {
        $accountIds = Entidade::inteiros($accountIds);
        if ($tagId <= 0 || $accountIds === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT * FROM crm_tags WHERE id = ? AND account_id IN ($in) LIMIT 1"
        );
        $st->execute(array_merge([$tagId], $accountIds));
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /* ===================================================================== */
    /* aplicacao                                                             */
    /* ===================================================================== */

    /**
     * Tags aplicadas a uma entidade. SEM escopo de leitura: tag nao e herdada.
     *
     * @param int[] $accountIds
     */
    public static function daEntidade(string $entidade, int $entidadeId, array $accountIds): array
    {
        $accountIds = Entidade::inteiros($accountIds);
        if ($accountIds === [] || $entidadeId <= 0 || !in_array($entidade, Entidade::TIPOS, true)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT t.id, t.nome, t.slug, t.cor, t.ativo, v.created_at AS aplicada_em
               FROM crm_tag_vinculos v
               JOIN crm_tags t ON t.id = v.tag_id
              WHERE v.entidade = ? AND v.entidade_id = ?
                AND v.account_id IN ($in)
           ORDER BY t.ordem, t.nome"
        );
        $st->execute(array_merge([$entidade, $entidadeId], $accountIds));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Aplica uma tag. Idempotente pelo UNIQUE (tag_id, entidade, entidade_id).
     *
     * @param array{entidade:string,id:int,account_id:int,titulo:string} $alvo
     * @return bool true = aplicou agora; false = ja estava, ou conta nao bate
     */
    public static function aplicar(array $alvo, int $tagId, array $accountIds, ?int $userId): bool
    {
        $tag = self::buscar($tagId, $accountIds);
        if ($tag === null) {
            return false;
        }

        /*
         * A conferencia que o SQL nao faz sozinho. Uma sessao matriz alcanca a
         * filial, entao `buscar()` acha a tag da matriz E o `resolver()` acha o
         * card da filial. As duas checagens passam, e o vinculo sairia cruzado.
         */
        if ((int) $tag['account_id'] !== (int) $alvo['account_id']) {
            return false;
        }

        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            'INSERT IGNORE INTO crm_tag_vinculos
               (tag_id, account_id, entidade, entidade_id, created_by, created_at)
             VALUES (:tag, :acc, :ent, :eid, :uid, NOW())'
        );
        $st->execute([
            'tag' => $tagId,
            'acc' => $alvo['account_id'],
            'ent' => $alvo['entidade'],
            'eid' => $alvo['id'],
            'uid' => $userId,
        ]);

        if ($st->rowCount() < 1) {
            return false; // ja estava aplicada: nao gera evento repetido
        }

        Auditoria::registrar($alvo, $userId, 'tag_aplicada', 'tag', null, (string) $tag['nome']);
        return true;
    }

    /**
     * Tira a tag da entidade.
     *
     * @param array{entidade:string,id:int,account_id:int,titulo:string} $alvo
     */
    public static function desaplicar(array $alvo, int $tagId, array $accountIds, ?int $userId): bool
    {
        $tag = self::buscar($tagId, $accountIds);
        if ($tag === null || (int) $tag['account_id'] !== (int) $alvo['account_id']) {
            return false;
        }

        $st = Database::getConnection()->prepare(
            'DELETE FROM crm_tag_vinculos
              WHERE tag_id = :tag AND entidade = :ent AND entidade_id = :eid AND account_id = :acc'
        );
        $st->execute([
            'tag' => $tagId,
            'ent' => $alvo['entidade'],
            'eid' => $alvo['id'],
            'acc' => $alvo['account_id'],
        ]);

        if ($st->rowCount() < 1) {
            return false;
        }

        Auditoria::registrar($alvo, $userId, 'tag_removida', 'tag', (string) $tag['nome'], null);
        return true;
    }

    /**
     * Copia as tags de uma prospeccao para o cliente. Chamado UMA VEZ, pela
     * conversao, dentro da transacao dela.
     *
     * Nao usa aplicar() de proposito: aqui nao ha sessao para validar contra, o
     * chamador ja garantiu que card e cliente sao da mesma conta, e um evento
     * de historico por tag poluiria a conversao. O resumo vai num evento so.
     *
     * @return string[] nomes das tags copiadas
     */
    public static function copiarCardParaCliente(int $cardId, int $clienteId, int $accountId, ?int $userId): array
    {
        $pdo = Database::getConnection();

        $st = $pdo->prepare(
            'SELECT v.tag_id, t.nome
               FROM crm_tag_vinculos v
               JOIN crm_tags t ON t.id = v.tag_id
              WHERE v.entidade = ? AND v.entidade_id = ? AND v.account_id = ?'
        );
        $st->execute([Entidade::CARD, $cardId, $accountId]);
        $tags = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($tags === []) {
            return [];
        }

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO crm_tag_vinculos
               (tag_id, account_id, entidade, entidade_id, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );

        $nomes = [];
        foreach ($tags as $t) {
            $ins->execute([(int) $t['tag_id'], $accountId, Entidade::CLIENTE, $clienteId, $userId]);
            if ($ins->rowCount() > 0) {
                $nomes[] = (string) $t['nome'];
            }
        }
        return $nomes;
    }

    /* ===================================================================== */
    /* helpers                                                               */
    /* ===================================================================== */

    /** Mesma implementacao de ClienteOrigem::slugify, para os slugs nao divergirem. */
    public static function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '_', $text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = preg_replace('~[^_\w]+~', '', $text ?: '');
        $text = trim((string) $text, '_');
        $text = preg_replace('~_+~', '_', $text);
        $text = strtolower($text);
        return $text === '' ? 'tag' : $text;
    }

    private static function corValida(?string $cor): bool
    {
        return is_string($cor) && preg_match('/^#[0-9a-fA-F]{6}$/', $cor) === 1;
    }

    /**
     * Cor estavel derivada do slug. A mesma tag sai da mesma cor em qualquer
     * conta, e ninguem precisa escolher cor para criar tag.
     */
    private static function corPorSlug(string $slug): string
    {
        $i = abs(crc32($slug)) % count(self::CORES);
        return self::CORES[$i];
    }
}
