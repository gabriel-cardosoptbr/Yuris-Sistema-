<?php

namespace App\Crm;

use App\Core\Database;
use App\Core\Timeline;

/**
 * Entidade — o portao unico de tenancy da Fase 2.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA QUE ELE RESOLVE
 * ---------------------------------------------------------------------------
 * As seis tabelas novas guardam `entidade ENUM('cliente','card')` + `entidade_id`.
 * Vinculo polimorfico e pratico para ler os dois lados de uma vez, mas tem um
 * risco conhecido: o banco nao consegue garantir que o `entidade_id` existe nem
 * que ele e da conta certa. Nao existe FK possivel para uma coluna que aponta
 * para duas tabelas.
 *
 * A resposta e centralizar: NENHUM servico da Fase 2 toca em `clientes` ou
 * `cards` para descobrir dono. Todos passam por `resolver()`. Um lugar so para
 * errar, um lugar so para testar, um lugar so para consertar.
 *
 * ---------------------------------------------------------------------------
 * O CONTRATO
 * ---------------------------------------------------------------------------
 * `resolver()` devolve null quando a entidade nao existe, esta apagada, ou nao
 * pertence a nenhuma conta acessivel pela sessao. As tres situacoes viram o
 * mesmo null de proposito: quem chama nao deve distinguir "nao existe" de "nao
 * e seu", porque a diferenca ja e um vazamento (diz que o id existe em outra
 * conta). A API traduz o null em 404 sempre.
 *
 * Quando resolve, devolve o `account_id` REAL da entidade. E esse valor que vai
 * para a linha nova, nunca o account_id da sessao: uma sessao matriz alcanca a
 * filial, e um anexo posto num card da filial pertence a filial, nao a matriz.
 * Gravar o da sessao mudaria o dono do dado em silencio.
 */
final class Entidade
{
    public const CLIENTE = 'cliente';
    public const CARD    = 'card';

    /** Os dois valores que o ENUM aceita. Qualquer outro e recusado antes do SQL. */
    public const TIPOS = [self::CLIENTE, self::CARD];

    /**
     * Descobre a conta dona de uma entidade, conferindo acesso.
     *
     * @param  string $entidade   'cliente' ou 'card'
     * @param  int[]  $accountIds contas que a sessao alcanca
     * @return array{entidade:string,id:int,account_id:int,titulo:string}|null
     *         null = nao existe, esta apagada, ou nao e de conta acessivel
     */
    public static function resolver(string $entidade, int $entidadeId, array $accountIds): ?array
    {
        $accountIds = self::inteiros($accountIds);
        if ($entidadeId <= 0 || $accountIds === [] || !in_array($entidade, self::TIPOS, true)) {
            return null;
        }

        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));

        // Os nomes de coluna divergem entre as duas tabelas (nome / cliente_nome),
        // por isso o SELECT e montado por tipo em vez de um SQL so.
        $sql = $entidade === self::CLIENTE
            ? "SELECT id, account_id, nome AS titulo
                 FROM clientes
                WHERE id = ? AND deleted_at IS NULL AND account_id IN ($in)
                LIMIT 1"
            : "SELECT id, account_id, cliente_nome AS titulo
                 FROM cards
                WHERE id = ? AND deleted_at IS NULL AND account_id IN ($in)
                LIMIT 1";

        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$entidadeId], $accountIds));
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }

        return [
            'entidade'   => $entidade,
            'id'         => (int) $r['id'],
            'account_id' => (int) $r['account_id'],
            'titulo'     => (string) ($r['titulo'] ?? ''),
        ];
    }

    /**
     * O escopo de LEITURA de uma entidade: quais pares (entidade, id) compoem a
     * ficha dela.
     *
     * Para uma prospeccao, e ela mesma.
     *
     * Para um cliente, e ele MAIS toda prospeccao que aponta para ele. E isto
     * que faz o documento anexado enquanto a pessoa era lead continuar visivel
     * depois da conversao, sem que a conversao copie arquivo nenhum. Mesmo
     * principio do App\Core\Timeline e do App\Clientes\VinculosCliente.
     *
     * Vale para FATO (anexo, interacao). Tag e campo personalizado NAO usam
     * isto: sao opiniao, sao copiados uma vez na conversao e depois vivem
     * soltos. Ver o cabecalho da migration 127.
     *
     * @param  int[] $accountIds
     * @return array{0:array<int,string>,1:array<int,int>}  [entidades[], ids[]] alinhados por indice
     */
    public static function escopoLeitura(string $entidade, int $entidadeId, array $accountIds): array
    {
        if ($entidade === self::CARD) {
            return [[self::CARD], [$entidadeId]];
        }

        $entidades = [self::CLIENTE];
        $ids       = [$entidadeId];

        foreach (Timeline::cardsDoCliente($entidadeId, $accountIds) as $cardId) {
            $entidades[] = self::CARD;
            $ids[]       = (int) $cardId;
        }

        return [$entidades, $ids];
    }

    /**
     * Monta o pedaco de WHERE que aplica um escopo de leitura.
     *
     * Vira "(entidade = ? AND entidade_id = ?) OR (entidade = ? AND entidade_id = ?)".
     * O par junto e o que importa: um "entidade IN (...) AND entidade_id IN (...)"
     * casaria cliente 7 com card 7, que sao pessoas diferentes.
     *
     * @return array{0:string,1:array<int,mixed>}  [sql, params]
     */
    public static function whereEscopo(array $entidades, array $ids, string $alias = ''): array
    {
        $p      = $alias === '' ? '' : $alias . '.';
        $partes = [];
        $params = [];
        foreach ($entidades as $i => $ent) {
            $partes[]  = "({$p}entidade = ? AND {$p}entidade_id = ?)";
            $params[]  = $ent;
            $params[]  = (int) $ids[$i];
        }
        if ($partes === []) {
            // Escopo vazio nao pode virar WHERE vazio: seria "sem filtro".
            return ['1 = 0', []];
        }
        return ['(' . implode(' OR ', $partes) . ')', $params];
    }

    /** @param int[] $v @return int[] */
    public static function inteiros(array $v): array
    {
        $out = [];
        foreach ($v as $x) {
            $i = (int) $x;
            if ($i > 0) {
                $out[$i] = $i;
            }
        }
        return array_values($out);
    }
}
