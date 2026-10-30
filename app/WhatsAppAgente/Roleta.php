<?php
namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * Roleta: divide entre os atendentes os leads que a prospecção automática abre
 * (pedido do dono da Fleetiflow, 30/10/2026: "cada disparo, um vai para um, outro
 * para outro... agora eu quero 70% Cauê e 30% a Vitória").
 *
 * A cada abertura que o n8n manda (robô da planilha e disparo das listas), o
 * sdr_etapa.php chama sortear(): o card do lead e a conversa passam a ser do
 * atendente sorteado. Quem participa e o percentual de cada um ficam em
 * whatsapp_settings (chave `sdr_roleta`, JSON), como AutomacaoSdr; cada sorteio
 * fica em sdr_roleta_sorteios (migration 170), que é o histórico, a contagem da
 * tela e a garantia de que o mesmo lead não é sorteado duas vezes.
 *
 * O sorteio é ponderado e equilibrado (escolher): só concorre quem ainda está
 * abaixo da parte dele na divisão atual, e entre esses vale o peso. Assim a conta
 * fica colada no percentual mesmo com poucos leads (com duas pessoas, nunca a
 * mais de um lead), e a ordem continua imprevisível. Salvar uma divisão nova recomeça a conta.
 *
 * O que a roleta NÃO faz:
 *   - não tira lead de quem já atende: só mexe em card na etapa "Novos leads" e
 *     cujo responsável é vazio ou o que a automação pôs sozinha (o dono do número,
 *     ou quem recebe as conversas novas no modo dono por conversa);
 *   - não sorteia de novo o mesmo lead: o segundo pedido devolve o primeiro sorteado;
 *   - sem configuração, desligada ou sem a migration: não faz nada, e o lead segue
 *     a regra de antes (SdrFleetiflow::donoDoNumero).
 */
final class Roleta
{
    public const CHAVE = 'sdr_roleta';

    /**
     * A configuração da conta. Sem registro: desligada e sem participantes.
     * @return array{ligada:bool, participantes:array<int,array{user_id:int, percentual:int}>, desde:?string}
     */
    public static function config(int $accountId): array
    {
        $vazia = ['ligada' => false, 'participantes' => [], 'desde' => null];
        if ($accountId <= 0) return $vazia;
        $st = Database::getConnection()->prepare('SELECT config_value FROM whatsapp_settings WHERE account_id = ? AND config_key = ? LIMIT 1');
        $st->execute([$accountId, self::CHAVE]);
        $j = json_decode((string)$st->fetchColumn(), true);
        if (!is_array($j)) return $vazia;
        $part = [];
        foreach ((array)($j['participantes'] ?? []) as $p) {
            $uid = (int)($p['user_id'] ?? 0);
            $pct = (int)($p['percentual'] ?? 0);
            if ($uid > 0 && $pct > 0) $part[] = ['user_id' => $uid, 'percentual' => $pct];
        }
        return ['ligada' => !empty($j['ligada']), 'participantes' => $part, 'desde' => $j['desde'] ?? null];
    }

    /**
     * Confere a divisão que a tela mandou. Cada participante tem de ser usuário
     * ativo da conta, sem repetir, com percentual inteiro de 1 a 100, e a soma tem
     * de dar 100. Devolve a mensagem do primeiro problema, ou null.
     * @param array<int,array{user_id:mixed, percentual:mixed}> $participantes
     */
    public static function validar(int $accountId, array $participantes): ?string
    {
        if (!$participantes) return 'Escolha pelo menos uma pessoa para a roleta.';
        $vistos = [];
        $soma = 0;
        $pdo = Database::getConnection();
        $ativo = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL AND status = 'active' LIMIT 1");
        foreach ($participantes as $p) {
            $uid = filter_var($p['user_id'] ?? null, FILTER_VALIDATE_INT);
            $pct = filter_var($p['percentual'] ?? null, FILTER_VALIDATE_INT);
            if (!$uid || $uid <= 0) return 'Pessoa inválida na roleta.';
            if (isset($vistos[$uid])) return 'A mesma pessoa aparece duas vezes.';
            $vistos[$uid] = true;
            if ($pct === false || $pct < 1 || $pct > 100) return 'Cada percentual tem de ser um número inteiro de 1 a 100.';
            $ativo->execute([$uid, $accountId]);
            if (!$ativo->fetchColumn()) return 'Só entra na roleta quem é usuário ativo da conta.';
            $soma += $pct;
        }
        if ($soma !== 100) return "Os percentuais somam {$soma}%. A soma tem de dar 100%.";
        return null;
    }

    /**
     * Grava a divisão (e se a roleta está ligada). Mudou quem participa ou algum
     * percentual: a contagem do equilíbrio recomeça (`desde` = agora). Só ligar ou
     * desligar não recomeça. Vai para a auditoria, como AutomacaoSdr::definir.
     * @return string|null mensagem do problema, ou null se gravou
     */
    public static function salvar(int $accountId, array $participantes, bool $ligada, ?int $userId = null): ?string
    {
        if ($accountId <= 0) return 'Conta inválida.';
        if ($erro = self::validar($accountId, $participantes)) return $erro;

        $novos = array_map(fn($p) => ['user_id' => (int)$p['user_id'], 'percentual' => (int)$p['percentual']], array_values($participantes));
        $atual = self::config($accountId);
        $pdo   = Database::getConnection();
        $chave = fn(array $ps) => implode(',', array_map(fn($p) => $p['user_id'] . ':' . $p['percentual'], $ps));
        // Relógio do banco, o mesmo de sdr_roleta_sorteios.created_at (UTC em produção).
        $desde = ($atual['desde'] && $chave($atual['participantes']) === $chave($novos)) ? $atual['desde'] : (string)$pdo->query('SELECT NOW()')->fetchColumn();
        $valor = json_encode(['ligada' => $ligada, 'participantes' => $novos, 'desde' => $desde], JSON_UNESCAPED_UNICODE);

        $st = $pdo->prepare('SELECT id FROM whatsapp_settings WHERE account_id = ? AND config_key = ? LIMIT 1');
        $st->execute([$accountId, self::CHAVE]);
        $id = $st->fetchColumn();
        if ($id) {
            $pdo->prepare('UPDATE whatsapp_settings SET config_value = ?, updated_at = NOW() WHERE id = ?')->execute([$valor, (int)$id]);
        } else {
            $pdo->prepare('INSERT INTO whatsapp_settings (account_id, config_key, config_value, updated_at) VALUES (?, ?, ?, NOW())')
                ->execute([$accountId, self::CHAVE, $valor]);
        }
        \App\Master\Account::audit($accountId, 'sdr.roleta.salva', [
            'user_id' => $userId, 'entidade' => 'whatsapp_settings', 'entidade_id' => null,
            'dados_antes' => $atual, 'detalhes' => ['ligada' => $ligada, 'participantes' => $novos, 'desde' => $desde],
        ]);
        return null;
    }

    /**
     * Quem recebe o próximo lead. Pura (o teste roda milhares de vezes).
     *
     * Concorre só quem, recebendo este lead, não passa da parte dele
     * (recebidos < percentual × total depois deste lead); entre esses, sorteio
     * pelo percentual. Se ninguém se encaixa (arredondamento), concorrem todos.
 * Com duas pessoas a conta nunca fica a mais de 1 lead da meta; com três ou
 * mais, a até 1,5 no meio do caminho. O total converge para o percentual.
     *
     * @param array<int,int> $percentuais user_id => percentual
     * @param array<int,int> $recebidos   user_id => leads já recebidos na divisão atual
     * @param callable|null  $rand        fn(int $max): int de 1 a $max (teste injeta)
     */
    public static function escolher(array $percentuais, array $recebidos, ?callable $rand = null): ?int
    {
        $percentuais = array_filter($percentuais, fn($p) => $p > 0);
        if (!$percentuais) return null;
        $rand ??= fn(int $max) => random_int(1, $max);
        $soma  = array_sum($percentuais);
        $total = 1;
        foreach ($percentuais as $uid => $_) $total += (int)($recebidos[$uid] ?? 0);

        $concorrem = [];
        foreach ($percentuais as $uid => $p) {
            if ((int)($recebidos[$uid] ?? 0) < $p / $soma * $total) $concorrem[$uid] = $p;
        }
        if (!$concorrem) $concorrem = $percentuais;

        $alvo = $rand(array_sum($concorrem));
        foreach ($concorrem as $uid => $p) {
            $alvo -= $p;
            if ($alvo <= 0) return (int)$uid;
        }
        return (int)array_key_last($concorrem);
    }

    /**
     * Sorteia o atendente do lead que acabou de receber a abertura e grava no card
     * (cards.responsavel_user_id) e na conversa (whatsapp_chats.linked_user_id).
     * A conversa que ainda não existe recebe quando o webhook a ligar ao card
     * (aoLigarConversa, chamado por SdrFleetiflow::garantirCard).
     *
     * @return array{user_id:int, nome:string, novo:bool}|array{pulou:string}
     */
    public static function sortear(int $accountId, int $cardId, ?int $instanceId = null, ?string $remoteJid = null, string $origem = 'disparo'): array
    {
        $cfg = self::config($accountId);
        if (!$cfg['ligada'] || !$cfg['participantes']) return ['pulou' => 'roleta desligada'];

        $pdo   = Database::getConnection();
        $trava = 'sdr_roleta_' . $accountId;
        $pdo->prepare('SELECT GET_LOCK(?, 5)')->execute([$trava]);
        try {
            $st = $pdo->prepare('SELECT id, account_id, coluna_id, responsavel_user_id FROM cards WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            $st->execute([$cardId]);
            $card = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$card || (int)$card['account_id'] !== $accountId) return ['pulou' => 'card não encontrado'];

            // Sorteado antes: devolve o mesmo e só reaplica na conversa.
            $st = $pdo->prepare('SELECT user_id FROM sdr_roleta_sorteios WHERE account_id = ? AND card_id = ? LIMIT 1');
            $st->execute([$accountId, $cardId]);
            $ja = (int)($st->fetchColumn() ?: 0);
            if ($ja) {
                self::aplicarNaConversa($pdo, $accountId, $ja, $instanceId, $remoteJid);
                return ['user_id' => $ja, 'nome' => self::nome($pdo, $ja), 'novo' => false];
            }

            $novo = SdrFleetiflow::colunasDaConta($pdo, $accountId)['novo'] ?? null;
            if ($novo === null || (int)$card['coluna_id'] !== (int)$novo) return ['pulou' => 'card fora de Novos leads'];
            $atual = $card['responsavel_user_id'] !== null ? (int)$card['responsavel_user_id'] : null;
            if ($atual !== null && !in_array($atual, self::donosAutomaticos($pdo, $accountId, $instanceId), true)) {
                return ['pulou' => 'card já tem responsável escolhido'];
            }

            $pesos = [];
            foreach ($cfg['participantes'] as $p) $pesos[$p['user_id']] = $p['percentual'];
            $uid = self::escolher($pesos, self::recebidos($pdo, $accountId, $cfg['desde']));
            if (!$uid) return ['pulou' => 'sem participante'];

            $pdo->prepare('INSERT INTO sdr_roleta_sorteios (account_id, card_id, user_id, percentual, origem, instance_id, remote_jid, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$accountId, $cardId, $uid, $pesos[$uid], mb_substr($origem, 0, 20), $instanceId, $remoteJid]);
            if ($atual !== $uid) {
                \App\Prospeccao\Card::update($cardId, ['responsavel_user_id' => $uid, '_usuario_id' => null]);
            }
            self::aplicarNaConversa($pdo, $accountId, $uid, $instanceId, $remoteJid);
            return ['user_id' => $uid, 'nome' => self::nome($pdo, $uid), 'novo' => true];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$trava]);
        }
    }

    /**
     * A conversa acabou de ser ligada ao card (garantirCard): se o lead foi
     * sorteado, ela passa a ser do sorteado. Quieta: sem a tabela, não faz nada.
     */
    public static function aoLigarConversa(int $accountId, int $cardId, int $instanceId, string $remoteJid): void
    {
        try {
            $pdo = Database::getConnection();
            $st = $pdo->prepare('SELECT user_id FROM sdr_roleta_sorteios WHERE account_id = ? AND card_id = ? LIMIT 1');
            $st->execute([$accountId, $cardId]);
            $uid = (int)($st->fetchColumn() ?: 0);
            if ($uid) self::aplicarNaConversa($pdo, $accountId, $uid, $instanceId, $remoteJid);
        } catch (\Throwable $e) {
            // sem a migration 170: a roleta não existe ainda
        }
    }

    /**
     * Quantos leads cada um recebeu: na divisão atual (desde que foi salva) e no
     * total, com o percentual de hoje ao lado, para conferir na tela.
     * @return array<int,array{user_id:int, nome:string, percentual:?int, atual:int, total:int, ultimo:?string}>
     */
    public static function contagem(int $accountId): array
    {
        $pdo = Database::getConnection();
        $cfg = self::config($accountId);
        $saida = [];
        foreach ($cfg['participantes'] as $p) {
            $saida[$p['user_id']] = ['user_id' => $p['user_id'], 'nome' => self::nome($pdo, $p['user_id']), 'percentual' => $p['percentual'], 'atual' => 0, 'total' => 0, 'ultimo' => null];
        }
        $st = $pdo->prepare(
            'SELECT s.user_id, u.nome, COUNT(*) AS total, SUM(s.created_at >= ?) AS atual, MAX(s.created_at) AS ultimo
               FROM sdr_roleta_sorteios s LEFT JOIN users u ON u.id = s.user_id
              WHERE s.account_id = ? GROUP BY s.user_id, u.nome'
        );
        $st->execute([$cfg['desde'] ?? '1970-01-01', $accountId]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['user_id'];
            $saida[$uid] ??= ['user_id' => $uid, 'nome' => (string)($r['nome'] ?? ('#' . $uid)), 'percentual' => null, 'atual' => 0, 'total' => 0, 'ultimo' => null];
            $saida[$uid]['atual']  = $cfg['desde'] ? (int)$r['atual'] : 0;
            $saida[$uid]['total']  = (int)$r['total'];
            $saida[$uid]['ultimo'] = $r['ultimo'];
        }
        return array_values($saida);
    }

    /** Os últimos sorteios, para a tela. @return array<int,array<string,mixed>> */
    public static function ultimos(int $accountId, int $limite = 20): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT s.card_id, s.user_id, s.percentual, s.origem, s.created_at, u.nome, c.titulo
               FROM sdr_roleta_sorteios s
          LEFT JOIN users u ON u.id = s.user_id
          LEFT JOIN cards c ON c.id = s.card_id AND c.account_id = s.account_id
              WHERE s.account_id = ? ORDER BY s.id DESC LIMIT ' . max(1, min(100, $limite))
        );
        $st->execute([$accountId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int,int> user_id => leads recebidos desde $desde */
    private static function recebidos(\PDO $pdo, int $accountId, ?string $desde): array
    {
        $st = $pdo->prepare('SELECT user_id, COUNT(*) FROM sdr_roleta_sorteios WHERE account_id = ? AND created_at >= ? GROUP BY user_id');
        $st->execute([$accountId, $desde ?? '1970-01-01']);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /**
     * Os responsáveis que a automação põe sozinha, e que por isso a roleta pode
     * trocar: o dono do número (com e sem a conversa) e, no modo dono por conversa,
     * quem recebe as conversas novas e as antigas. Nunca pela conversa: no modo
     * dono por conversa o "dono do número" dela é quem a pessoa escolheu.
     * @return int[]
     */
    private static function donosAutomaticos(\PDO $pdo, int $accountId, ?int $instanceId): array
    {
        $ids = [SdrFleetiflow::donoDoNumero($pdo, $accountId, $instanceId), SdrFleetiflow::donoDoNumero($pdo, $accountId, null)];
        if ($instanceId && ($m = DonoDaConversa::modo($pdo, $instanceId)) !== null) {
            $ids[] = $m['novas'];
            $ids[] = $m['antigas'];
        }
        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    /** A conversa passa a ser do sorteado, se está sem dono ou com o dono automático. */
    private static function aplicarNaConversa(\PDO $pdo, int $accountId, int $uid, ?int $instanceId, ?string $remoteJid): void
    {
        if (!$instanceId || !$remoteJid) return;
        $st = $pdo->prepare(
            'SELECT wc.id, wc.linked_user_id FROM whatsapp_chats wc
               JOIN whatsapp_instances wi ON wi.id = wc.instance_id AND wi.account_id = ?
              WHERE wc.instance_id = ? AND wc.remote_jid = ? LIMIT 1'
        );
        $st->execute([$accountId, $instanceId, $remoteJid]);
        $chat = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$chat) return;
        $atual = $chat['linked_user_id'] !== null ? (int)$chat['linked_user_id'] : null;
        if ($atual === $uid) return;
        if ($atual !== null && !in_array($atual, self::donosAutomaticos($pdo, $accountId, $instanceId), true)) return;
        $pdo->prepare('UPDATE whatsapp_chats SET linked_user_id = ? WHERE id = ?')->execute([$uid, (int)$chat['id']]);
    }

    private static function nome(\PDO $pdo, int $uid): string
    {
        $st = $pdo->prepare('SELECT nome FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        return (string)($st->fetchColumn() ?: ('#' . $uid));
    }
}
