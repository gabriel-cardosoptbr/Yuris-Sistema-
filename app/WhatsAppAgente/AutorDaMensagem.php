<?php
namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * AutorDaMensagem: quem mandou cada mensagem NOSSA, na edição CRM.
 *
 * Pedido de 10/10/2026: o lead recebeu "Olá! Sou a Vitória..." e o cliente
 * apontou "aqui não é a Vitória, é o robô". O Chat mostrava todo envio igual.
 * Agora cada balão nosso leva um rótulo: Robô de disparo, Vitória, Follow-up
 * automático, "Ana pelo Chat" ou "Pelo celular".
 *
 * ---------------------------------------------------------------------------
 * DE ONDE VEM CADA RÓTULO
 * ---------------------------------------------------------------------------
 * Gravado (whatsapp_msg_autor, migration 142), quando o Yuris SABE:
 *   'chat'     uma pessoa mandou pelo Chat do CRM (send.php grava, com user_id);
 *   'celular'  foi digitada no aparelho do número (o webhook vê pelo evento,
 *              ver SdrFleetiflow::origemEfetiva).
 *
 * Deduzido pelo horário, para o que o n8n manda pela API (o robô de disparo, a
 * Vitória e a cadência de follow-up chegam todos iguais ao Yuris):
 *   - balão logo depois de outro nosso (até 2 min) é do mesmo autor, porque a
 *     Vitória e o robô quebram a fala em vários balões;
 *   - a primeira mensagem da conversa, sem nada antes, é o robô de disparo;
 *   - resposta até 10 min depois de mensagem do lead, ou depois de uma entrega
 *     ao agente (sdr_encaminhamentos), é o agente (a Vitória responde em segundos);
 *   - mensagem nossa depois de outra nossa, sem o lead ter respondido, é o
 *     follow-up (a Vitória só responde ao lead, e o robô só abre conversa);
 *   - o resto é "Envio automático", sem chutar.
 * Mensagem sem payload do webhook e sem autor gravado (recuperada pela
 * sincronização, ou enviada pelo Chat antes desta mudança) fica sem rótulo:
 * melhor nenhum do que um errado.
 *
 * Só age em conta da edição CRM (SdrFleetiflow::contaUsa). Nas outras, as
 * mensagens saem exatamente como antes.
 */
final class AutorDaMensagem
{
    public const CHAT       = 'chat';
    public const CELULAR    = 'celular';
    public const DISPARO    = 'disparo';
    public const AGENTE     = 'agente';
    public const FOLLOWUP   = 'followup';
    public const AUTOMATICO = 'automatico';

    /** Balões seguidos do mesmo autor. */
    public const JUNTO_SEG = 120;
    /** Folga para a resposta do agente depois da mensagem do lead ou da entrega. */
    public const RESPOSTA_SEG = 600;

    private const GRAVAVEIS = [self::CHAT, self::CELULAR];

    /** Grava quem mandou. Best-effort: nunca derruba o envio nem o webhook. */
    public static function registrar(int $accountId, int $instanceId, ?string $wamid, string $autor, ?int $userId = null): void
    {
        $wamid = trim((string)$wamid);
        if ($accountId <= 0 || $instanceId <= 0 || $wamid === '' || !in_array($autor, self::GRAVAVEIS, true)) return;
        try {
            Database::getConnection()->prepare(
                'INSERT IGNORE INTO whatsapp_msg_autor (account_id, instance_id, wamid, autor, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$accountId, $instanceId, $wamid, $autor, $userId ?: null, date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log('[autor_mensagem] registrar falhou (migration 142?): ' . $e->getMessage());
        }
    }

    /**
     * Classifica as mensagens NOSSAS de uma conversa. PURA.
     *
     * @param list<array{id:int,dir:string,ts:int,api:bool,autor:?string}> $msgs  em ordem cronológica
     * @param list<int> $entregas  horários (ts) em que a mensagem do lead foi entregue ao agente
     * @return array<int,string>  id => código (só das que ganharam rótulo)
     */
    public static function classificar(array $msgs, array $entregas = []): array
    {
        $saida = [];
        $houveIn = false; $houveOut = false;
        $prev = null; $prevCod = null;
        foreach ($msgs as $m) {
            $cod = null;
            if ($m['dir'] === 'outbound') {
                if (in_array($m['autor'] ?? null, self::GRAVAVEIS, true)) {
                    $cod = $m['autor'];
                } elseif (!empty($m['api'])) {
                    $gap = $prev ? $m['ts'] - $prev['ts'] : PHP_INT_MAX;
                    $entregueAntes = false;
                    foreach ($entregas as $t) {
                        if ($t <= $m['ts'] && $m['ts'] - $t <= self::RESPOSTA_SEG) { $entregueAntes = true; break; }
                    }
                    if ($prev && $prev['dir'] === 'outbound' && $gap <= self::JUNTO_SEG
                        && in_array($prevCod, [self::DISPARO, self::AGENTE, self::FOLLOWUP, self::AUTOMATICO], true)) {
                        $cod = $prevCod;
                    } elseif (!$houveIn && !$houveOut) {
                        $cod = self::DISPARO;
                    } elseif ($entregueAntes || ($prev && $prev['dir'] === 'inbound' && $gap <= self::RESPOSTA_SEG)) {
                        $cod = self::AGENTE;
                    } elseif ($prev && $prev['dir'] === 'outbound') {
                        $cod = self::FOLLOWUP;
                    } else {
                        $cod = self::AUTOMATICO;
                    }
                }
                $houveOut = true;
            } else {
                $houveIn = true;
            }
            if ($cod !== null) $saida[(int)$m['id']] = $cod;
            $prev = $m; $prevCod = $cod;
        }
        return $saida;
    }

    /** Texto do rótulo. PURA. */
    public static function rotulo(string $cod, string $nomeAgente, ?string $nomePessoa = null): string
    {
        switch ($cod) {
            case self::CHAT:       return $nomePessoa ? $nomePessoa . ' pelo Chat' : 'Pelo Chat';
            case self::CELULAR:    return 'Pelo celular';
            case self::DISPARO:    return 'Robô de disparo';
            case self::AGENTE:     return $nomeAgente;
            case self::FOLLOWUP:   return 'Follow-up automático';
            default:               return 'Envio automático';
        }
    }

    /** "Vitória (pré-qualificação)" vira "Vitória": o rótulo é curto. PURA. */
    public static function nomeCurto(string $nome): string
    {
        $n = trim(preg_replace('/\s*\(.*\)\s*$/u', '', $nome));
        return $n !== '' ? $n : 'Agente';
    }

    /**
     * Acrescenta `autor` (código), `autor_rotulo` e `autor_deduzido` às mensagens
     * nossas de uma página do Chat. Conta fora da edição CRM: devolve igual.
     *
     * @param array<int,array> $msgs  como saem do WhatsAppMessage (findByJid/findAfter)
     */
    public static function rotular(int $ownerAccountId, int $instanceId, string $remoteJid, array $msgs): array
    {
        if (!$msgs || str_ends_with($remoteJid, '@g.us') || !SdrFleetiflow::contaUsa($ownerAccountId)) return $msgs;
        $ids = [];
        foreach ($msgs as $m) if (($m['direction'] ?? '') === 'outbound' && !empty($m['id'])) $ids[] = (int)$m['id'];
        if (!$ids) return $msgs;

        try {
            $pdo = Database::getConnection();

            // A página e tudo o que veio antes dela na conversa (até 200), para o
            // primeiro balão da página saber o que o precedeu.
            $primeiro = null;
            foreach ($msgs as $m) {
                $k = [(string)($m['created_at'] ?? ''), (int)($m['id'] ?? 0)];
                if ($primeiro === null || $k < $primeiro) $primeiro = $k;
            }
            $st = $pdo->prepare(
                'SELECT id, direction, created_at, wamid, raw_payload IS NOT NULL AS api FROM (
                   SELECT id, direction, created_at, wamid, raw_payload FROM whatsapp_messages
                    WHERE instance_id = ? AND remote_jid = ? AND deleted_at IS NULL
                      AND (created_at < ? OR (created_at = ? AND id < ?))
                    ORDER BY created_at DESC, id DESC LIMIT 200
                 ) antes'
            );
            $st->execute([$instanceId, $remoteJid, $primeiro[0], $primeiro[0], $primeiro[1]]);
            $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);

            $marc = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, direction, created_at, wamid, raw_payload IS NOT NULL AS api
                                   FROM whatsapp_messages WHERE instance_id = ? AND id IN ($marc)");
            $st->execute(array_merge([$instanceId], $ids));
            $daPagina = $st->fetchAll(\PDO::FETCH_ASSOC);
            $idsPagina = array_column($daPagina, 'id');
            foreach ($msgs as $m) {
                if (($m['direction'] ?? '') !== 'outbound' && !empty($m['id']) && !in_array($m['id'], $idsPagina)) {
                    $daPagina[] = ['id' => $m['id'], 'direction' => $m['direction'] ?? 'inbound',
                                   'created_at' => $m['created_at'] ?? '', 'wamid' => $m['wamid'] ?? '', 'api' => 0];
                }
            }
            $todas = array_merge($linhas, $daPagina);
            usort($todas, fn($a, $b) => [(string)$a['created_at'], (int)$a['id']] <=> [(string)$b['created_at'], (int)$b['id']]);

            // Autores gravados (pelo wamid das nossas).
            $wamids = [];
            foreach ($todas as $t) if ($t['direction'] === 'outbound' && (string)$t['wamid'] !== '') $wamids[] = (string)$t['wamid'];
            $gravados = []; $pessoas = [];
            if ($wamids) {
                $m2 = implode(',', array_fill(0, count($wamids), '?'));
                $st = $pdo->prepare("SELECT wamid, autor, user_id FROM whatsapp_msg_autor WHERE instance_id = ? AND wamid IN ($m2)");
                $st->execute(array_merge([$instanceId], $wamids));
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $g) $gravados[(string)$g['wamid']] = $g;
                $uids = array_values(array_unique(array_filter(array_map(fn($g) => (int)$g['user_id'], $gravados))));
                if ($uids) {
                    $m3 = implode(',', array_fill(0, count($uids), '?'));
                    $st = $pdo->prepare("SELECT id, nome FROM users WHERE account_id = ? AND id IN ($m3)");
                    $st->execute(array_merge([$ownerAccountId], $uids));
                    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $u) $pessoas[(int)$u['id']] = self::primeiroNome((string)$u['nome']);
                }
            }

            // Entregas ao agente no período (deixam rotular a resposta a um "Mandar para a Vitória").
            $entregas = [];
            if ($todas) {
                $st = $pdo->prepare('SELECT created_at FROM sdr_encaminhamentos
                                      WHERE instance_id = ? AND remote_jid = ? AND ok = 1 AND created_at BETWEEN ? AND ?');
                $ini = date('Y-m-d H:i:s', strtotime((string)$todas[0]['created_at']) - self::RESPOSTA_SEG);
                $st->execute([$instanceId, $remoteJid, $ini, (string)end($todas)['created_at']]);
                foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $t) $entregas[] = (int)strtotime((string)$t);
            }

            $seq = [];
            foreach ($todas as $t) {
                $g = $gravados[(string)$t['wamid']] ?? null;
                $seq[] = ['id' => (int)$t['id'], 'dir' => (string)$t['direction'], 'ts' => (int)strtotime((string)$t['created_at']),
                          'api' => (bool)$t['api'], 'autor' => $g['autor'] ?? null];
            }
            $cods = self::classificar($seq, $entregas);

            $agente = self::nomeCurto(SdrFleetiflow::nomeAgenteDaConta($ownerAccountId));
            foreach ($msgs as &$m) {
                $id = (int)($m['id'] ?? 0);
                if (!isset($cods[$id])) continue;
                $g = $gravados[(string)($m['wamid'] ?? '')] ?? null;
                $m['autor']          = $cods[$id];
                $m['autor_rotulo']   = self::rotulo($cods[$id], $agente, $g ? ($pessoas[(int)$g['user_id']] ?? null) : null);
                $m['autor_deduzido'] = !in_array($cods[$id], self::GRAVAVEIS, true);
            }
            unset($m);
        } catch (\Throwable $e) {
            error_log('[autor_mensagem] rotular falhou (mensagens seguem sem rótulo): ' . $e->getMessage());
        }
        return $msgs;
    }

    private static function primeiroNome(string $nome): string
    {
        $p = preg_split('/\s+/u', trim($nome)) ?: [];
        return (string)($p[0] ?? '');
    }
}
