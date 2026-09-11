<?php
namespace App\Master;

use App\Core\Database;

/**
 * Model: AccountNotification
 *
 * Central de notificações internas por conta.
 * Padrão: Linear notification inbox / Slack notification center.
 */
class AccountNotification
{
    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lista notificações de um usuário (inbox pessoal + notificações da conta).
     *
     * DUAS NATUREZAS NA MESMA CAIXA (migration 130):
     *
     *   dirigido   É pra você. Vem SEMPRE, e primeiro.
     *   movimento  Aconteceu no escritório. Vem depois, e some da lista de quem
     *              desligou a preferência `movimento`.
     *
     * A ordenação por natureza ANTES da data não é estética: com "tudo tem que
     * chegar", o movimento das últimas duas horas empurraria para baixo o aviso
     * de prazo que veio de manhã, que é justamente o que não pode sumir.
     */
    /** Teto de cada metade da caixa. Ver o porquê logo abaixo. */
    private const LIMITE_DIRIGIDO  = 30;
    private const LIMITE_MOVIMENTO = 20;

    public static function listForUser(int $userId, int $accountId, bool $apenasNaoLidas = false): array
    {
        // Quem desligou `movimento` simplesmente não recebe essa metade.
        $querMovimento = \App\Notificacoes\Aviso::querReceber($userId, 'movimento');

        /*
         * DUAS CONSULTAS, COM TETO PRÓPRIO, E NÃO UMA COM LIMIT 50.
         *
         * A primeira versão fazia uma consulta só, ordenando dirigido primeiro e
         * cortando em 50. Funcionou nos testes de mesa e QUEBROU no ambiente
         * real: numa conta com 95 avisos dirigidos parados, os 50 primeiros eram
         * todos dirigidos, e a movimentação NUNCA aparecia. Quem tivesse a caixa
         * cheia deixaria de ver o escritório se mexer, para sempre, sem nenhum
         * sinal de que faltava algo.
         *
         * Com um teto por natureza, cada metade tem lugar garantido. É por isso
         * que são duas consultas em vez de um ORDER BY esperto.
         */
        $pdo = Database::getConnection();

        $buscar = static function (string $natureza, int $limite) use ($pdo, $userId, $accountId, $apenasNaoLidas): array {
            $sql = 'SELECT * FROM account_notifications
                     WHERE account_id = :acc
                       AND (user_id = :uid OR user_id IS NULL)
                       AND natureza = :nat';
            if ($apenasNaoLidas) { $sql .= ' AND lida = 0'; }
            $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limite;

            $st = $pdo->prepare($sql);
            $st->execute(['acc' => $accountId, 'uid' => $userId, 'nat' => $natureza]);
            return $st->fetchAll();
        };

        $itens = $buscar(\App\Notificacoes\Aviso::DIRIGIDO, self::LIMITE_DIRIGIDO);

        if ($querMovimento) {
            // O dirigido vem ANTES na lista: com "tudo chega", a movimentação
            // das últimas horas empurraria para baixo o prazo que veio de manhã,
            // que é justamente o que não pode sumir de vista.
            $itens = array_merge($itens, $buscar(\App\Notificacoes\Aviso::MOVIMENTO, self::LIMITE_MOVIMENTO));
        }

        return $itens;
    }

    /**
     * O número vermelho do sino.
     *
     * Conta SÓ o que é dirigido a esta pessoa. Contar movimento faria o badge
     * marcar 200 todo dia, e um badge que sempre marca 200 não informa nada: a
     * pessoa para de olhar, e aí o prazo que vence amanhã some junto.
     *
     * Antes da migration 130 não havia essa distinção, e toda notificação era,
     * de fato, dirigida. Por isso a migration deixou as antigas como `dirigido`:
     * o comportamento delas não muda.
     */
    public static function countNaoLidas(int $userId, int $accountId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM account_notifications
             WHERE account_id = :acc AND user_id = :uid
               AND natureza = 'dirigido' AND lida = 0"
        );
        $stmt->execute(['acc' => $accountId, 'uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA
    // ─────────────────────────────────────────────────────────────────────────

    public static function criar(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO account_notifications
               (account_id, user_id, tipo, titulo, mensagem, payload, created_at)
             VALUES
               (:acc, :uid, :tipo, :titulo, :msg, :payload, NOW())'
        );
        $stmt->execute([
            'acc'     => $data['account_id'],
            'uid'     => $data['user_id']   ?? null,
            'tipo'    => $data['tipo'],
            'titulo'  => $data['titulo'],
            'msg'     => $data['mensagem']  ?? null,
            'payload' => isset($data['payload']) ? json_encode($data['payload']) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function marcarLida(int $id, int $userId, int $accountId): bool
    {
        // Escopo por tenant (Auditoria 2026-06-01, BAIXA #3): notificações de
        // conta (user_id IS NULL) poderiam ser marcadas por usuário de outro
        // tenant adivinhando o id. Exigir account_id fecha essa escrita cross-tenant.
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE account_notifications
             SET lida = 1, lida_em = NOW()
             WHERE id = :id AND account_id = :acc AND (user_id = :uid OR user_id IS NULL)'
        );
        return $stmt->execute(['id' => $id, 'acc' => $accountId, 'uid' => $userId]);
    }

    public static function marcarTodasLidas(int $userId, int $accountId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE account_notifications
             SET lida = 1, lida_em = NOW()
             WHERE account_id = :acc AND (user_id = :uid OR user_id IS NULL) AND lida = 0'
        );
        $stmt->execute(['acc' => $accountId, 'uid' => $userId]);
        return $stmt->rowCount();
    }
}
