<?php
namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * O vendedor do lead e o nome com que ele aparece nas mensagens (31/10/2026,
 * pedido do dono da Fleetiflow: "esse campo a gente sempre vai puxar do vendedor
 * [...] o nome tem que ser de acordo com o que está no sistema, no login de cadastro").
 *
 * O nome é SEMPRE o primeiro nome do cadastro do usuário (users.nome: "Kaue Souza"
 * vira "Kaue"). Não há outro lugar para editar: corrigiu o cadastro, a mensagem
 * muda junto. Usado por:
 *   - Roleta (a abertura do disparo sai com o nome do sorteado, {vendedor} no n8n);
 *   - App\FollowUp\Envio (a variável {vendedor} das cadências do Yuris);
 *   - SdrFleetiflow::encaminhar (a IA de pré-venda recebe o vendedor do lead e se
 *     apresenta com o nome dele).
 *
 * O vendedor do lead é o responsável do card (cards.responsavel_user_id); sem card,
 * o dono da conversa (whatsapp_chats.linked_user_id). Só usuário ativo da conta.
 */
final class VendedorDoLead
{
    /** "Kaue Souza" -> "Kaue". */
    public static function primeiroNome(string $nome): string
    {
        $nome = trim($nome);
        return $nome === '' ? '' : (preg_split('/\s+/u', $nome)[0] ?? $nome);
    }

    /**
     * Como a IA aparece para a EQUIPE quando fala em nome do vendedor ("Kaue (IA)"),
     * nos rótulos do Chat, do card e dos avisos. O "(IA)" é o que separa a IA dela
     * da pessoa de verdade ("Kaue pelo Chat").
     */
    public static function rotuloIA(string $primeiroNome): string
    {
        return trim($primeiroNome) . ' (IA)';
    }

    /**
     * Primeiro nome do vendedor de cada card, em lote (card sem vendedor fica de fora).
     * @param int[] $cardIds
     * @return array<int,string> card_id => primeiro nome
     */
    public static function doscards(int $accountId, array $cardIds): array
    {
        $cardIds = array_values(array_unique(array_filter(array_map('intval', $cardIds))));
        if ($accountId <= 0 || !$cardIds) return [];
        $st = Database::getConnection()->prepare(
            "SELECT c.id, u.nome FROM cards c
               JOIN users u ON u.id = c.responsavel_user_id AND u.account_id = c.account_id AND u.deleted_at IS NULL AND u.status = 'active'
              WHERE c.account_id = ? AND c.id IN (" . implode(',', array_fill(0, count($cardIds), '?')) . ')'
        );
        $st->execute(array_merge([$accountId], $cardIds));
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $n = self::primeiroNome((string)$r['nome']);
            if ($n !== '') $out[(int)$r['id']] = $n;
        }
        return $out;
    }

    /** @return array{user_id:int, nome:string, vendedor:string}|null o usuário ativo da conta */
    public static function doUsuario(int $accountId, int $userId): ?array
    {
        if ($accountId <= 0 || $userId <= 0) return null;
        $st = Database::getConnection()->prepare("SELECT nome FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL AND status = 'active' LIMIT 1");
        $st->execute([$userId, $accountId]);
        $nome = $st->fetchColumn();
        if ($nome === false) return null;
        return ['user_id' => $userId, 'nome' => (string)$nome, 'vendedor' => self::primeiroNome((string)$nome)];
    }

    /** O vendedor responsável pelo card, ou null. */
    public static function doCard(int $accountId, int $cardId): ?array
    {
        $st = Database::getConnection()->prepare('SELECT responsavel_user_id FROM cards WHERE id = ? AND account_id = ? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$cardId, $accountId]);
        $uid = (int)($st->fetchColumn() ?: 0);
        return $uid ? self::doUsuario($accountId, $uid) : null;
    }

    /**
     * O vendedor da conversa: o do card ligado a ela; a resposta do lead costuma
     * chegar num chat @lid sem card, e aí vale o card do mesmo telefone (o
     * $telefone que a Evolution manda no remoteJidAlt, ou whatsapp_identidades);
     * sem card, o dono da conversa.
     */
    public static function daConversa(int $accountId, int $instanceId, string $remoteJid, string $telefone = ''): ?array
    {
        if ($accountId <= 0 || $remoteJid === '') return null;
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT wc.linked_card_id, wc.linked_user_id FROM whatsapp_chats wc
                               JOIN whatsapp_instances wi ON wi.id = wc.instance_id AND wi.account_id = ?
                              WHERE wc.instance_id = ? AND wc.remote_jid = ? LIMIT 1');
        $st->execute([$accountId, $instanceId, $remoteJid]);
        $chat = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        if (!empty($chat['linked_card_id']) && ($v = self::doCard($accountId, (int)$chat['linked_card_id']))) return $v;

        $fone = preg_replace('/\D/', '', $telefone);
        if ($fone === '' && str_ends_with($remoteJid, '@s.whatsapp.net')) $fone = preg_replace('/\D/', '', explode('@', $remoteJid)[0]);
        if ($fone === '' && str_ends_with($remoteJid, '@lid')) {
            try {
                $st = $pdo->prepare('SELECT jid FROM whatsapp_identidades WHERE instance_id = ? AND lid = ? AND jid IS NOT NULL LIMIT 1');
                $st->execute([$instanceId, $remoteJid]);
                $fone = preg_replace('/\D/', '', explode('@', (string)($st->fetchColumn() ?: ''))[0]);
            } catch (\Throwable $e) { /* sem a tabela de identidades */ }
        }
        if (strlen($fone) >= 8) {
            $st = $pdo->prepare("SELECT id FROM cards WHERE account_id = ? AND deleted_at IS NULL
                                    AND RIGHT(REGEXP_REPLACE(COALESCE(telefone_whatsapp,''), '[^0-9]', ''), 8) = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$accountId, substr($fone, -8)]);
            $cardId = (int)($st->fetchColumn() ?: 0);
            if ($cardId && ($v = self::doCard($accountId, $cardId))) return $v;
        }
        return !empty($chat['linked_user_id']) ? self::doUsuario($accountId, (int)$chat['linked_user_id']) : null;
    }
}
