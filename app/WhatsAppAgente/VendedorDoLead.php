<?php
namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * O vendedor do lead e o nome com que ele aparece nas mensagens (31/10/2026,
 * pedido do dono da Fleetiflow: "esse campo a gente sempre vai puxar do vendedor
 * [...] o nome tem que ser de acordo com o que está no sistema, no login de cadastro").
 *
 * O nome é o que o vendedor escolheu em "Nome nas mensagens" (users.nome_mensagens,
 * migration 173: "Kaue Souza", "Souza"...) ou, com o campo vazio, o primeiro nome do
 * cadastro (users.nome: "Kaue Souza" vira "Kaue"). Não há nome fixo em lugar nenhum:
 * mudou o campo, a mensagem muda junto (a próxima que sair). Usado por:
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
    public const NOME_MAX = 40;

    /** "Kaue Souza" -> "Kaue". */
    public static function primeiroNome(string $nome): string
    {
        $nome = trim($nome);
        return $nome === '' ? '' : (preg_split('/\s+/u', $nome)[0] ?? $nome);
    }

    /**
     * Como o vendedor assina: o que ele escolheu, ou o primeiro nome do cadastro.
     * @param string|null $escolhido users.nome_mensagens
     */
    public static function nomeNaMensagem(string $nomeCadastro, ?string $escolhido): string
    {
        $e = trim((string)$escolhido);
        return $e !== '' ? $e : self::primeiroNome($nomeCadastro);
    }

    /**
     * Valida o que o vendedor digitou. Vazio volta ao primeiro nome do cadastro.
     * Só letras (com acento), espaço, hífen, apóstrofo e ponto: sem número, link,
     * emoji nem símbolo, para a abertura nunca sair com lixo.
     * @return array{0:?string, 1:?string} [nome limpo ou null (= usar o cadastro), erro]
     */
    public static function sanearNome(string $texto): array
    {
        $t = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
        if ($t === '') return [null, null];
        if (mb_strlen($t) < 2) return [null, 'Use pelo menos 2 letras.'];
        if (mb_strlen($t) > self::NOME_MAX) return [null, 'Use no máximo ' . self::NOME_MAX . ' letras.'];
        if (!preg_match("/^\\p{L}[\\p{L}\\p{M} '.\\-]*$/u", $t)) return [null, 'Use só letras, espaço, hífen e apóstrofo.'];
        return [$t, null];
    }

    /** A coluna users.nome_mensagens existe (migration 173)? Uma consulta por requisição. */
    public static function temColuna(): bool
    {
        static $tem = null;
        if ($tem === null) {
            try {
                $tem = (int)Database::getConnection()->query(
                    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'nome_mensagens'"
                )->fetchColumn() > 0;
            } catch (\Throwable $e) { $tem = false; }
        }
        return $tem;
    }

    /** Trecho de SELECT com o nome escolhido (NULL antes da migration 173). */
    public static function colunaSql(string $alias = 'u'): string
    {
        return self::temColuna() ? "$alias.nome_mensagens" : 'NULL';
    }

    /**
     * Grava o nome que o vendedor quer nas mensagens. Vazio apaga (volta ao cadastro).
     * Vai para a auditoria da conta, como a Roleta.
     * @return string|null mensagem de erro, ou null se gravou
     */
    public static function salvarNome(int $accountId, int $userId, string $texto, int $porUserId): ?string
    {
        [$nome, $erro] = self::sanearNome($texto);
        if ($erro) return $erro;
        if (!self::temColuna()) return 'Recurso ainda não disponível (falta rodar a migration 173).';
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT nome, nome_mensagens FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL AND status = 'active' LIMIT 1");
        $st->execute([$userId, $accountId]);
        $antes = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$antes) return 'Usuário não encontrado.';
        $pdo->prepare('UPDATE users SET nome_mensagens = ?, updated_at = NOW() WHERE id = ? AND account_id = ?')->execute([$nome, $userId, $accountId]);
        \App\Master\Account::audit($accountId, 'sdr.vendedor.nome_mensagens', [
            'user_id' => $porUserId, 'entidade' => 'users', 'entidade_id' => $userId,
            'dados_antes' => ['nome_mensagens' => $antes['nome_mensagens']], 'detalhes' => ['nome_mensagens' => $nome],
        ]);
        return null;
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
            "SELECT c.id, u.nome, " . self::colunaSql() . " AS escolhido FROM cards c
               JOIN users u ON u.id = c.responsavel_user_id AND u.account_id = c.account_id AND u.deleted_at IS NULL AND u.status = 'active'
              WHERE c.account_id = ? AND c.id IN (" . implode(',', array_fill(0, count($cardIds), '?')) . ')'
        );
        $st->execute(array_merge([$accountId], $cardIds));
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $n = self::nomeNaMensagem((string)$r['nome'], $r['escolhido']);
            if ($n !== '') $out[(int)$r['id']] = $n;
        }
        return $out;
    }

    /** @return array{user_id:int, nome:string, vendedor:string}|null o usuário ativo da conta */
    public static function doUsuario(int $accountId, int $userId): ?array
    {
        if ($accountId <= 0 || $userId <= 0) return null;
        $st = Database::getConnection()->prepare("SELECT nome, " . self::colunaSql('users') . " AS escolhido FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL AND status = 'active' LIMIT 1");
        $st->execute([$userId, $accountId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['user_id' => $userId, 'nome' => (string)$r['nome'], 'vendedor' => self::nomeNaMensagem((string)$r['nome'], $r['escolhido'])];
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
