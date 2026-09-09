<?php

namespace App\Core;

/**
 * Timeline — a linha do tempo unica de uma pessoa dentro do sistema.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA QUE ELA RESOLVE
 * ---------------------------------------------------------------------------
 * O historico do Yuris mora em tabelas separadas, com formatos diferentes:
 *
 *   card_history      campo_alterado / valor_anterior / valor_novo  (colunas)
 *   clientes_history  antes_json / depois_json                      (JSON)
 *
 * Quando uma prospeccao vira cliente, a pessoa e a mesma, mas o rastro dela
 * fica dos dois lados. Se a tela do cliente lesse so `clientes_history`, a
 * timeline comecaria do zero no dia da conversao, e tudo que aconteceu enquanto
 * ela era prospeccao sumiria da vista.
 *
 * ---------------------------------------------------------------------------
 * A ESCOLHA: LER JUNTO, NAO COPIAR
 * ---------------------------------------------------------------------------
 * Na conversao NAO se copia uma linha de historico sequer. O vinculo
 * `cards.cliente_id` e o que costura os dois lados, e a timeline do cliente
 * consulta:
 *
 *     clientes_history do cliente
 *   + card_history de TODAS as prospeccoes que apontam para ele
 *
 * Copiar centenas de linhas na conversao criaria duas verdades para o mesmo
 * evento, e a segunda envelheceria sozinha. Ler junto tem outra vantagem: uma
 * pessoa que volta como prospeccao nova, vinculada ao MESMO cliente, entra na
 * timeline dele automaticamente, sem migracao de dado nenhuma.
 *
 * ---------------------------------------------------------------------------
 * MULTI-TENANT
 * ---------------------------------------------------------------------------
 * `clientes_history` tem account_id proprio. `card_history` NAO tem: o dono e o
 * card. Por isso toda leitura de card_history aqui passa por JOIN em `cards`
 * filtrando account_id. Nunca consulte card_history sem esse JOIN.
 */
final class Timeline
{
    /** Categorias usadas pelos filtros da UI. */
    public const CATEGORIAS = ['cadastro', 'comercial', 'processos', 'whatsapp', 'documentos', 'tarefas', 'sistema'];

    /**
     * Timeline de uma prospeccao. So os eventos dela.
     *
     * @param int[] $accountIds contas que a sessao alcanca
     */
    public static function paraCard(int $cardId, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $cardId <= 0) {
            return [];
        }
        $eventos = self::eventosDeCards([$cardId], $accountIds);
        return self::ordena($eventos);
    }

    /**
     * Timeline de um cliente: os eventos dele MAIS os de toda prospeccao que
     * aponta para ele. E isto que faz o historico nao recomecar na conversao.
     *
     * @param int[] $accountIds contas que a sessao alcanca
     */
    public static function paraCliente(int $clienteId, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $clienteId <= 0) {
            return [];
        }

        $eventos = self::eventosDeCliente($clienteId, $accountIds);

        $cardIds = self::cardsDoCliente($clienteId, $accountIds);
        if ($cardIds !== []) {
            $eventos = array_merge($eventos, self::eventosDeCards($cardIds, $accountIds));
        }

        return self::ordena($eventos);
    }

    /**
     * Ids das prospeccoes que apontam para este cliente, dentro das contas
     * acessiveis. E a consulta que costura a timeline.
     *
     * @return int[]
     */
    public static function cardsDoCliente(int $clienteId, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $clienteId <= 0) {
            return [];
        }
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT id FROM cards
              WHERE cliente_id = ?
                AND account_id IN ($in)"
        );
        $st->execute(array_merge([$clienteId], $accountIds));
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /* ===================================================================== */
    /* leitores por tabela                                                    */
    /* ===================================================================== */

    /**
     * card_history das prospeccoes dadas. O JOIN em `cards` NAO e conveniencia:
     * card_history nao tem account_id, e sem ele a consulta atravessaria contas.
     */
    private static function eventosDeCards(array $cardIds, array $accountIds): array
    {
        $cardIds = self::inteiros($cardIds);
        if ($cardIds === []) {
            return [];
        }
        $pdo    = Database::getConnection();
        $inCard = implode(',', array_fill(0, count($cardIds), '?'));
        $inAcc  = implode(',', array_fill(0, count($accountIds), '?'));

        $st = $pdo->prepare(
            "SELECT ch.id, ch.card_id, ch.created_at, ch.acao, ch.campo_alterado,
                    ch.valor_anterior, ch.valor_novo, ch.de_coluna_id, ch.para_coluna_id,
                    u.nome AS user_nome, u.login AS user_login,
                    cde.nome AS coluna_de, cpara.nome AS coluna_para
               FROM card_history ch
               JOIN cards c            ON c.id  = ch.card_id
          LEFT JOIN users u            ON u.id  = ch.usuario_id
          LEFT JOIN pipeline_columns cde   ON cde.id   = ch.de_coluna_id
          LEFT JOIN pipeline_columns cpara ON cpara.id = ch.para_coluna_id
              WHERE ch.card_id IN ($inCard)
                AND c.account_id IN ($inAcc)"
        );
        $st->execute(array_merge($cardIds, $accountIds));

        $saida = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $de   = $r['valor_anterior'];
            $para = $r['valor_novo'];
            // 'moved' guarda a coluna nos campos proprios, nao em valor_*.
            if ($r['acao'] === 'moved') {
                $de   = $r['coluna_de']   ?? $de;
                $para = $r['coluna_para'] ?? $para;
            }
            $saida[] = self::evento(
                (string) $r['created_at'],
                $r['user_nome'] ?: ($r['user_login'] ?: null),
                (string) ($r['acao'] ?? ''),
                $r['campo_alterado'] ?: null,
                $de,
                $para,
                'card',
                (int) $r['card_id'],
                'prospeccao'
            );
        }
        return $saida;
    }

    /** clientes_history do cliente. Tem account_id proprio, entao filtra direto. */
    private static function eventosDeCliente(int $clienteId, array $accountIds): array
    {
        $pdo   = Database::getConnection();
        $inAcc = implode(',', array_fill(0, count($accountIds), '?'));

        $st = $pdo->prepare(
            "SELECT ch.id, ch.cliente_id, ch.created_at, ch.acao,
                    ch.antes_json, ch.depois_json,
                    u.nome AS user_nome, u.login AS user_login
               FROM clientes_history ch
          LEFT JOIN users u ON u.id = ch.user_id
              WHERE ch.cliente_id = ?
                AND ch.account_id IN ($inAcc)"
        );
        $st->execute(array_merge([$clienteId], $accountIds));

        $saida = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $antes  = self::json($r['antes_json']);
            $depois = self::json($r['depois_json']);

            // 'updated' guarda SO os campos que mudaram nos dois lados. Vira um
            // evento por campo, para a timeline dizer o que mudou, e nao apenas
            // "registro atualizado".
            if (($r['acao'] ?? '') === 'updated' && is_array($depois) && $depois !== []) {
                foreach ($depois as $campo => $novo) {
                    if (!is_scalar($novo) && $novo !== null) {
                        continue;
                    }
                    $saida[] = self::evento(
                        (string) $r['created_at'],
                        $r['user_nome'] ?: ($r['user_login'] ?: null),
                        'updated',
                        (string) $campo,
                        is_array($antes) ? ($antes[$campo] ?? null) : null,
                        $novo,
                        'cliente',
                        (int) $r['cliente_id'],
                        'cliente'
                    );
                }
                continue;
            }

            $saida[] = self::evento(
                (string) $r['created_at'],
                $r['user_nome'] ?: ($r['user_login'] ?: null),
                (string) ($r['acao'] ?? ''),
                null,
                null,
                null,
                'cliente',
                (int) $r['cliente_id'],
                'cliente'
            );
        }
        return $saida;
    }

    /* ===================================================================== */
    /* normalizacao                                                           */
    /* ===================================================================== */

    private static function evento(
        string $quando,
        ?string $usuario,
        string $acao,
        ?string $campo,
        $de,
        $para,
        string $origemTipo,
        int $origemId,
        string $fase
    ): array {
        return [
            'quando'    => $quando,
            'usuario'   => $usuario,          // null = acao do sistema
            'acao'      => $acao,             // crua: o front traduz com Yuris.translateAuditAcao
            'categoria' => self::categoria($acao, $campo),
            'campo'     => $campo,
            'de'        => self::texto($de),
            'para'      => self::texto($para),
            'origem'    => ['tipo' => $origemTipo, 'id' => $origemId],
            'fase'      => $fase,             // 'prospeccao' ou 'cliente': a UI marca de onde veio
        ];
    }

    /** Categoria do evento, usada pelos filtros da timeline. */
    private static function categoria(string $acao, ?string $campo): string
    {
        $a = strtolower($acao);
        if (str_contains($a, 'whatsapp') || str_contains($a, 'chat') || str_contains($a, 'conversa')) {
            return 'whatsapp';
        }
        if (str_contains($a, 'processo')) {
            return 'processos';
        }
        if (str_contains($a, 'document') || str_contains($a, 'anexo') || str_contains($a, 'arquivo')) {
            return 'documentos';
        }
        if (str_contains($a, 'task') || str_contains($a, 'tarefa') || str_contains($a, 'checklist')) {
            return 'tarefas';
        }
        if (in_array($a, ['moved', 'reorder', 'reordered', 'stage_changed', 'status_changed', 'convertido_cliente', 'vinculado_cliente'], true)) {
            return 'comercial';
        }
        if (in_array($a, ['created', 'updated', 'archived', 'restored', 'deleted', 'merged'], true) || $campo !== null) {
            return 'cadastro';
        }
        return 'sistema';
    }

    /** Valor legivel. JSON de reorder vira texto curto em vez de chaves cruas. */
    private static function texto($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_scalar($v)) {
            $s = (string) $v;
            $decodificado = json_decode($s, true);
            if (is_array($decodificado)) {
                $partes = [];
                foreach ($decodificado as $k => $x) {
                    if (is_scalar($x) || $x === null) {
                        $partes[] = $k . ': ' . ($x ?? '');
                    }
                }
                return $partes ? implode(', ', $partes) : null;
            }
            return $s;
        }
        return null;
    }

    private static function json($v): ?array
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : null;
    }

    /** Mais recente primeiro, com desempate estavel. */
    private static function ordena(array $eventos): array
    {
        usort($eventos, static function (array $a, array $b): int {
            $cmp = strcmp((string) $b['quando'], (string) $a['quando']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) ($a['campo'] ?? ''), (string) ($b['campo'] ?? ''));
        });
        return $eventos;
    }

    /** @return int[] */
    private static function inteiros(array $v): array
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
