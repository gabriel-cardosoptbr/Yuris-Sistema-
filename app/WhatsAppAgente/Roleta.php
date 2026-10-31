<?php
namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * Roleta: divide entre os atendentes os leads que a prospecção automática abre
 * (pedido do dono da Fleetiflow, 30/10/2026: "cada disparo, um vai para um, outro
 * para outro... agora eu quero 70% Cauê e 30% a Vitória").
 *
 * O sorteio acontece ANTES do envio (desde 30/10/2026, pedido do dono: "as
 * primeiras mensagens do Cauê têm que vir com o nome Cauê"): o n8n pergunta em
 * api/whatsapp/sdr_roleta.php quem é o atendente da vez (reservar) e monta a
 * abertura com o nome dele. No robô da planilha o card só nasce depois do envio,
 * então o sorteio fica guardado pelo telefone e, quando o sdr_etapa.php avisa a
 * abertura (sortear), é ligado ao card: o card e a conversa passam a ser do
 * sorteado. Sem a pergunta antes (fluxo antigo), sortear() sorteia na hora.
 *
 * O nome que vai na mensagem é sempre o primeiro nome do cadastro do vendedor
 * (VendedorDoLead), nunca um campo próprio da roleta (31/10/2026, pedido do dono).
 *
 * O VENDEDOR DA VEZ é sorteado de antemão e fica guardado (`sdr_roleta_vez`): o
 * admin vê na Roleta, na Prospecção e no Follow-up quem recebe o próximo lead. Cada
 * abertura nova consome o da vez e já sorteia o seguinte (daVez, reservar).
 *
 * Quem participa e o percentual ficam em whatsapp_settings (chave `sdr_roleta`,
 * JSON), como AutomacaoSdr; cada sorteio fica em sdr_roleta_sorteios (migrations
 * 170 e 171), que é o histórico, a contagem da tela e a garantia de que o mesmo
 * lead (card ou telefone) não é sorteado duas vezes.
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
    /** O vendedor da vez, já sorteado (user_id), em whatsapp_settings. */
    public const CHAVE_VEZ = 'sdr_roleta_vez';

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
        // Só quem participa e o percentual recomeçam a conta (e o sorteio do
        // vendedor da vez); a ordem em que a tela manda as pessoas, não.
        $chave = function (array $ps): string {
            $v = array_map(fn($p) => $p['user_id'] . ':' . $p['percentual'], $ps);
            sort($v);
            return implode(',', $v);
        };
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
        // Divisão nova: o vendedor da vez é sorteado de novo, já na regra nova.
        if ($desde !== $atual['desde']) self::gravarVez($pdo, $accountId, null);
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
            $card = self::card($pdo, $accountId, $cardId);
            if (!$card) return ['pulou' => 'card não encontrado'];

            // Sorteado antes (pelo card, ou pelo telefone antes de o card nascer):
            // devolve o mesmo, liga ao card e aplica no card e na conversa.
            $ja = self::jaSorteado($pdo, $accountId, $card);
            if ($ja) {
                self::aplicar($pdo, $accountId, $card, $ja, $instanceId, $remoteJid);
                return ['user_id' => $ja, 'nome' => self::nome($pdo, $ja), 'novo' => false];
            }

            if (!self::podeTrocar($pdo, $accountId, $card, $instanceId)) {
                return ['pulou' => (int)$card['coluna_id'] !== (int)(SdrFleetiflow::colunasDaConta($pdo, $accountId)['novo'] ?? 0)
                    ? 'card fora de Novos leads' : 'card já tem responsável escolhido'];
            }
            $atual = $card['responsavel_user_id'] !== null ? (int)$card['responsavel_user_id'] : null;

            $pesos = self::pesos($cfg);
            $uid = self::vezAtual($pdo, $accountId, $cfg);
            if (!$uid) return ['pulou' => 'sem participante'];

            self::gravar($pdo, $accountId, $cardId, self::digitos($card['telefone_whatsapp'] ?? ''), $uid, $pesos[$uid], $origem, $instanceId, $remoteJid);
            if ($atual !== $uid) {
                \App\Prospeccao\Card::update($cardId, ['responsavel_user_id' => $uid, '_usuario_id' => null]);
            }
            self::aplicarNaConversa($pdo, $accountId, $uid, $instanceId, $remoteJid);
            self::proximaVez($pdo, $accountId, $cfg);
            return ['user_id' => $uid, 'nome' => self::nome($pdo, $uid), 'novo' => true];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$trava]);
        }
    }

    /**
     * O atendente da vez para a abertura que o n8n vai mandar AGORA, com o nome que
     * vai na mensagem. Chamado por api/whatsapp/sdr_roleta.php antes de montar o
     * texto, nos dois fluxos.
     *
     *   - lead já sorteado (pelo card ou pelo telefone): o mesmo atendente;
     *   - card que já tem responsável escolhido por pessoa, ou fora de "Novos
     *     leads": assina quem já atende, sem sortear;
     *   - senão sorteia e guarda pelo telefone (e pelo card, se ele já existe, e aí
     *     já grava no card e na conversa). O card que nascer depois do envio é
     *     ligado em sortear(), pelo sdr_etapa.php.
     *
     * @return array{user_id:int, atendente:string, vendedor:string, novo:bool}|array{pulou:string}
     */
    public static function reservar(int $accountId, string $telefone, ?int $cardId = null, string $origem = 'disparo'): array
    {
        $cfg = self::config($accountId);
        if (!$cfg['ligada'] || !$cfg['participantes']) return ['pulou' => 'roleta desligada'];
        $fone = self::digitos($telefone);
        if (strlen($fone) < 10) return ['pulou' => 'telefone inválido'];

        $pdo   = Database::getConnection();
        $trava = 'sdr_roleta_' . $accountId;
        $pdo->prepare('SELECT GET_LOCK(?, 5)')->execute([$trava]);
        try {
            $card = $cardId ? self::card($pdo, $accountId, $cardId) : null;
            if (!$card) {
                $st = $pdo->prepare("SELECT id FROM cards WHERE account_id = ? AND deleted_at IS NULL
                                        AND RIGHT(REGEXP_REPLACE(COALESCE(telefone_whatsapp,''), '[^0-9]', ''), 8) = ? ORDER BY id DESC LIMIT 1");
                $st->execute([$accountId, substr($fone, -8)]);
                $id = (int)($st->fetchColumn() ?: 0);
                $card = $id ? self::card($pdo, $accountId, $id) : null;
            }
            $chat = $card ? self::conversaDoCard($pdo, (int)$card['id']) : null;

            $ja = self::jaSorteado($pdo, $accountId, $card, $fone);
            if ($ja) {
                if ($card) self::aplicar($pdo, $accountId, $card, $ja, $chat['instance_id'] ?? null, $chat['remote_jid'] ?? null);
                return self::assinatura($accountId, $ja) + ['novo' => false];
            }
            if ($card && $card['responsavel_user_id'] !== null && !self::podeTrocar($pdo, $accountId, $card, $chat['instance_id'] ?? null)) {
                return self::assinatura($accountId, (int)$card['responsavel_user_id']) + ['novo' => false];
            }

            // Lead novo: vai para o vendedor da vez, e o seguinte já é sorteado.
            $pesos = self::pesos($cfg);
            $uid = self::vezAtual($pdo, $accountId, $cfg);
            if (!$uid) return ['pulou' => 'sem participante'];
            self::gravar($pdo, $accountId, $card ? (int)$card['id'] : null, $fone, $uid, $pesos[$uid], $origem, $chat['instance_id'] ?? null, $chat['remote_jid'] ?? null);
            if ($card) self::aplicar($pdo, $accountId, $card, $uid, $chat['instance_id'] ?? null, $chat['remote_jid'] ?? null);
            self::proximaVez($pdo, $accountId, $cfg);
            return self::assinatura($accountId, $uid) + ['novo' => true];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$trava]);
        }
    }

    /**
     * Como o vendedor aparece na mensagem: `vendedor` é o "Nome nas mensagens" que ele
     * escolheu ou, vazio, o primeiro nome do cadastro ("Kaue Souza" -> "Kaue");
     * `atendente` é o nome completo do cadastro. Ver VendedorDoLead.
     * @return array{user_id:int, atendente:string, vendedor:string}
     */
    public static function assinatura(int $accountId, int $uid): array
    {
        $st = Database::getConnection()->prepare('SELECT nome, ' . VendedorDoLead::colunaSql('users') . ' AS escolhido FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: ['nome' => '#' . $uid, 'escolhido' => null];
        $completo = (string)$r['nome'];
        return ['user_id' => $uid, 'atendente' => $completo, 'vendedor' => VendedorDoLead::nomeNaMensagem($completo, $r['escolhido'])];
    }

    /**
     * O vendedor da vez: quem recebe o próximo lead aberto pelo disparo. Já fica
     * sorteado (e guardado) antes da mensagem, para o admin ver na tela. null com a
     * roleta desligada ou sem participantes.
     * @return array{user_id:int, atendente:string, vendedor:string}|null
     */
    public static function daVez(int $accountId): ?array
    {
        $cfg = self::config($accountId);
        if (!$cfg['ligada'] || !$cfg['participantes']) return null;
        $pdo   = Database::getConnection();
        $trava = 'sdr_roleta_' . $accountId;
        $pdo->prepare('SELECT GET_LOCK(?, 5)')->execute([$trava]);
        try {
            $uid = self::vezAtual($pdo, $accountId, $cfg);
            return $uid ? self::assinatura($accountId, $uid) : null;
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
            'SELECT s.card_id, s.telefone, s.user_id, s.percentual, s.origem, s.created_at, u.nome, c.titulo
               FROM sdr_roleta_sorteios s
          LEFT JOIN users u ON u.id = s.user_id
          LEFT JOIN cards c ON c.id = s.card_id AND c.account_id = s.account_id
              WHERE s.account_id = ? ORDER BY s.id DESC LIMIT ' . max(1, min(100, $limite))
        );
        $st->execute([$accountId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int,int> user_id => percentual */
    private static function pesos(array $cfg): array
    {
        $pesos = [];
        foreach ($cfg['participantes'] as $p) $pesos[$p['user_id']] = $p['percentual'];
        return $pesos;
    }

    /** O da vez guardado, se ainda participa e está ativo; senão sorteia um agora e guarda. */
    private static function vezAtual(\PDO $pdo, int $accountId, array $cfg): ?int
    {
        $pesos = self::pesos($cfg);
        $st = $pdo->prepare('SELECT config_value FROM whatsapp_settings WHERE account_id = ? AND config_key = ? LIMIT 1');
        $st->execute([$accountId, self::CHAVE_VEZ]);
        $uid = (int)($st->fetchColumn() ?: 0);
        if ($uid && isset($pesos[$uid]) && VendedorDoLead::doUsuario($accountId, $uid)) return $uid;
        return self::proximaVez($pdo, $accountId, $cfg);
    }

    /** Sorteia e guarda o próximo da vez (depois de cada lead entregue). */
    private static function proximaVez(\PDO $pdo, int $accountId, array $cfg): ?int
    {
        $uid = self::escolher(self::pesos($cfg), self::recebidos($pdo, $accountId, $cfg['desde']));
        self::gravarVez($pdo, $accountId, $uid);
        return $uid;
    }

    private static function gravarVez(\PDO $pdo, int $accountId, ?int $uid): void
    {
        if ($uid === null) {
            $pdo->prepare('DELETE FROM whatsapp_settings WHERE account_id = ? AND config_key = ?')->execute([$accountId, self::CHAVE_VEZ]);
            return;
        }
        $st = $pdo->prepare('SELECT id FROM whatsapp_settings WHERE account_id = ? AND config_key = ? LIMIT 1');
        $st->execute([$accountId, self::CHAVE_VEZ]);
        $id = $st->fetchColumn();
        if ($id) $pdo->prepare('UPDATE whatsapp_settings SET config_value = ?, updated_at = NOW() WHERE id = ?')->execute([(string)$uid, (int)$id]);
        else $pdo->prepare('INSERT INTO whatsapp_settings (account_id, config_key, config_value, updated_at) VALUES (?, ?, ?, NOW())')->execute([$accountId, self::CHAVE_VEZ, (string)$uid]);
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

    /** @return array<string,mixed>|null o card da conta, com o que a roleta precisa */
    private static function card(\PDO $pdo, int $accountId, int $cardId): ?array
    {
        $st = $pdo->prepare('SELECT id, account_id, coluna_id, responsavel_user_id, telefone_whatsapp FROM cards WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$cardId]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        return $c && (int)$c['account_id'] === $accountId ? $c : null;
    }

    /** A conversa mais recente ligada ao card. @return array{instance_id:int, remote_jid:string}|null */
    private static function conversaDoCard(\PDO $pdo, int $cardId): ?array
    {
        $st = $pdo->prepare('SELECT instance_id, remote_jid FROM whatsapp_chats WHERE linked_card_id = ? ORDER BY last_message_at DESC LIMIT 1');
        $st->execute([$cardId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? ['instance_id' => (int)$r['instance_id'], 'remote_jid' => (string)$r['remote_jid']] : null;
    }

    /**
     * Quem já foi sorteado para este lead: pelo card, senão pelo telefone (sorteio
     * feito antes de o card nascer), que então é ligado ao card. 0 = ninguém.
     */
    private static function jaSorteado(\PDO $pdo, int $accountId, ?array $card, string $fone = ''): int
    {
        if ($card) {
            $st = $pdo->prepare('SELECT user_id FROM sdr_roleta_sorteios WHERE account_id = ? AND card_id = ? LIMIT 1');
            $st->execute([$accountId, (int)$card['id']]);
            if ($uid = (int)($st->fetchColumn() ?: 0)) return $uid;
            $fone = $fone !== '' ? $fone : self::digitos($card['telefone_whatsapp'] ?? '');
        }
        if (strlen($fone) < 8) return 0;
        $st = $pdo->prepare('SELECT id, user_id FROM sdr_roleta_sorteios WHERE account_id = ? AND card_id IS NULL AND RIGHT(telefone, 8) = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$accountId, substr($fone, -8)]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return 0;
        if ($card) $pdo->prepare('UPDATE sdr_roleta_sorteios SET card_id = ? WHERE id = ? AND card_id IS NULL')->execute([(int)$card['id'], (int)$r['id']]);
        return (int)$r['user_id'];
    }

    /** A roleta pode dar o card a alguém: está em "Novos leads" e o responsável é vazio ou o automático. */
    private static function podeTrocar(\PDO $pdo, int $accountId, array $card, ?int $instanceId): bool
    {
        $novo = SdrFleetiflow::colunasDaConta($pdo, $accountId)['novo'] ?? null;
        if ($novo === null || (int)$card['coluna_id'] !== (int)$novo) return false;
        $atual = $card['responsavel_user_id'] !== null ? (int)$card['responsavel_user_id'] : null;
        return $atual === null || in_array($atual, self::donosAutomaticos($pdo, $accountId, $instanceId), true);
    }

    /** Dá o card (se a roleta pode) e a conversa ao sorteado. */
    private static function aplicar(\PDO $pdo, int $accountId, array $card, int $uid, ?int $instanceId, ?string $remoteJid): void
    {
        $atual = $card['responsavel_user_id'] !== null ? (int)$card['responsavel_user_id'] : null;
        if ($atual !== $uid && self::podeTrocar($pdo, $accountId, $card, $instanceId)) {
            \App\Prospeccao\Card::update((int)$card['id'], ['responsavel_user_id' => $uid, '_usuario_id' => null]);
        }
        self::aplicarNaConversa($pdo, $accountId, $uid, $instanceId, $remoteJid);
    }

    private static function gravar(\PDO $pdo, int $accountId, ?int $cardId, string $fone, int $uid, int $pct, string $origem, ?int $instanceId, ?string $remoteJid): void
    {
        $pdo->prepare('INSERT INTO sdr_roleta_sorteios (account_id, card_id, telefone, user_id, percentual, origem, instance_id, remote_jid, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$accountId, $cardId, $fone !== '' ? substr($fone, 0, 20) : null, $uid, $pct, mb_substr($origem, 0, 20), $instanceId, $remoteJid]);
    }

    private static function digitos(?string $v): string
    {
        return preg_replace('/\D/', '', (string)$v);
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
