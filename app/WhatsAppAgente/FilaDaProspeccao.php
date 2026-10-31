<?php
namespace App\WhatsAppAgente;

use App\Core\Database;
use App\FollowUp\Regra;

/**
 * FilaDaProspeccao: o painel da fila para o admin (Operação › Fila, 31/10/2026, pedido do
 * dono da Fleetiflow: "disparo, follow-up, tudo tem que mostrar qual é a ordem, quem vai ser
 * disparado, dia, horário... tudo aproximado, porque a gente está de forma randômica... a
 * gente tem que saber quem é o próximo da fila, como é que está a fila, quem deu erro").
 *
 * As aberturas vivem fora do CRM: a fila do robô está na planilha Google e a do disparo das
 * listas no staticData do n8n. Então os dois fluxos mandam ao CRM um RETRATO da fila
 * (api/whatsapp/sdr_fila.php, mesmo token do sdr_etapa.php) e o CRM guarda o último de cada
 * um em sdr_fila_retratos (migration 172, MEDIUMTEXT: a fila INTEIRA, sem corte). Os fluxos
 * só LEEM a fila para montar o retrato: lógica, espera e staticData não mudam.
 *
 * Tudo é APROXIMADO de propósito: as esperas entre envios são sorteadas, então cada lead
 * recebe uma faixa de uma hora ("hoje 10h às 11h"), nunca um horário exato. Os follow-ups
 * (Operação › Follow-up) vêm do motor do próprio Yuris (App\\FollowUp\\Envio).
 */
final class FilaDaProspeccao
{
    public const FLUXOS = [
        'planilha' => 'Robô da planilha',
        'listas'   => 'Disparo das listas',
    ];
    /** Retrato mais velho que isto (minutos) aparece como "desatualizado". */
    public const VELHO_MIN = 45;
    /** Só uma trava de segurança contra lixo: a fila real tem centenas, não milhares. */
    private const MAX_ITENS = 5000;

    /**
     * Guarda o retrato que o n8n mandou, depois de limpar: só os campos conhecidos, com
     * tamanho e quantidade limitados (o conteúdo é de um fluxo nosso, mas vira tela).
     * @return bool false se o fluxo for desconhecido
     */
    public static function guardar(int $accountId, string $fluxo, array $r): bool
    {
        if ($accountId <= 0 || !isset(self::FLUXOS[$fluxo])) return false;
        $txt = fn($v, int $n = 120): string => mb_substr(trim(strip_tags((string) $v)), 0, $n);
        $int = fn($v): int => max(0, (int) $v);
        $hora = fn($v, string $pad): string => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : $pad;

        $janela = (array) ($r['janela'] ?? []);
        $limpo = [
            'gerado_em' => date('Y-m-d H:i:s'),
            'janela' => [
                'dias' => array_values(array_unique(array_filter(array_map('intval', (array) ($janela['dias'] ?? [1, 2, 3, 4, 5, 6])), fn($d) => $d >= 1 && $d <= 7))),
                'de' => $hora($janela['de'] ?? '', '08:00'),
                'ate' => $hora($janela['ate'] ?? '', '18:00'),
                'espera_min' => max(1, $int($janela['espera_min'] ?? 15)),
                'espera_max' => max(1, $int($janela['espera_max'] ?? 20)),
            ],
            'restantes' => $int($r['restantes'] ?? 0),
            'por_grupo' => [],
            'enviados_total' => $int($r['enviados_total'] ?? 0),
            'enviados_por_dia' => [],
            'pulados' => [],
            'proximos' => [],
            'problemas' => [],
        ];
        foreach (array_slice((array) ($r['por_grupo'] ?? []), 0, 8, true) as $k => $v) $limpo['por_grupo'][$txt($k, 40)] = $int($v);
        foreach (array_slice((array) ($r['enviados_por_dia'] ?? []), -60, null, true) as $dia => $n) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dia)) $limpo['enviados_por_dia'][(string) $dia] = $int($n);
        }
        foreach (array_slice((array) ($r['pulados'] ?? []), 0, 12, true) as $k => $v) $limpo['pulados'][$txt($k, 40)] = $int($v);
        foreach (array_slice((array) ($r['proximos'] ?? []), 0, self::MAX_ITENS) as $p) {
            $p = (array) $p;
            $limpo['proximos'][] = ['id' => $txt($p['id'] ?? '', 40), 'nome' => $txt($p['nome'] ?? '', 120), 'grupo' => $txt($p['grupo'] ?? '', 40), 'fone' => preg_replace('/\D/', '', (string) ($p['fone'] ?? ''))];
        }
        foreach (array_slice((array) ($r['problemas'] ?? []), 0, self::MAX_ITENS) as $p) {
            $p = (array) $p;
            $limpo['problemas'][] = ['id' => $txt($p['id'] ?? '', 40), 'nome' => $txt($p['nome'] ?? '', 120), 'status' => $txt($p['status'] ?? '', 40), 'detalhe' => $txt($p['detalhe'] ?? '', 160), 'quando' => $txt($p['quando'] ?? '', 20)];
        }

        Database::getConnection()->prepare(
            'INSERT INTO sdr_fila_retratos (account_id, fluxo, retrato, atualizado_em) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE retrato = VALUES(retrato), atualizado_em = VALUES(atualizado_em)'
        )->execute([$accountId, $fluxo, json_encode($limpo, JSON_UNESCAPED_UNICODE)]);
        return true;
    }

    /** O último retrato do fluxo, ou null. */
    public static function retrato(int $accountId, string $fluxo): ?array
    {
        if (!isset(self::FLUXOS[$fluxo])) return null;
        try {
            $st = Database::getConnection()->prepare('SELECT retrato FROM sdr_fila_retratos WHERE account_id = ? AND fluxo = ? LIMIT 1');
            $st->execute([$accountId, $fluxo]);
            $j = json_decode((string) $st->fetchColumn(), true);
            return is_array($j) ? $j : null;
        } catch (\Throwable $e) {
            return null; // migration 172 ainda não rodou: o painel diz "sem dados"
        }
    }

    /**
     * Estimativa de quando cada lead da fila sai: um a cada espera média, só dentro da janela
     * do fluxo, a partir do último envio. Cada um ganha a FAIXA da hora em que cai (nunca um
     * minuto exato), porque a espera é sorteada.
     *
     * @param list<array> $proximos
     * @return list<array> os mesmos, com `de` e `ate` (timestamps, ponta de hora)
     */
    public static function faixas(array $proximos, array $janela, ?int $ultimoEnvio, int $agora): array
    {
        $horario = ['dias' => $janela['dias'] ?? [1, 2, 3, 4, 5, 6], 'de' => $janela['de'] ?? '08:00', 'ate' => $janela['ate'] ?? '18:00'];
        $gap = (int) round((($janela['espera_min'] ?? 15) + ($janela['espera_max'] ?? 20)) / 2 * 60);
        $t = max($agora, ($ultimoEnvio ?? 0) + $gap);
        foreach ($proximos as $i => $p) {
            $t = Regra::proximaJanela($t, $horario);
            $de = intdiv($t, 3600) * 3600; // fuso de Brasília tem hora cheia de diferença: a ponta da hora é a mesma
            $proximos[$i]['de'] = $de;
            $proximos[$i]['ate'] = $de + 3600;
            $t += $gap;
        }
        return $proximos;
    }

    /**
     * O painel das aberturas: por fluxo, como está a fila (faltam, saíram hoje, ritmo,
     * término), a ordem dos próximos com a faixa de saída e o que deu problema.
     *
     * @return array{fluxos: list<array>, da_vez: ?array}
     */
    public static function painel(int $accountId, ?int $agora = null): array
    {
        $agora = $agora ?? time();
        $fuso = new \DateTimeZone(Regra::FUSO);
        $hoje = (new \DateTimeImmutable('@' . $agora))->setTimezone($fuso)->format('Y-m-d');
        $saida = [];
        foreach (self::FLUXOS as $fluxo => $nome) {
            $r = self::retrato($accountId, $fluxo);
            if (!$r) { $saida[] = ['fluxo' => $fluxo, 'nome' => $nome, 'sem_dados' => true]; continue; }

            $gerado = (int) strtotime((string) $r['gerado_em']);
            $ultimo = self::ultimoEnvio($accountId, $fluxo);
            $porDia = (array) ($r['enviados_por_dia'] ?? []);
            $hojeN = max((int) ($porDia[$hoje] ?? 0), self::enviadosHoje($accountId, $fluxo, $hoje));

            // Ritmo: média dos dias anteriores com envio (até 5); sem histórico, o que a janela comporta.
            $anteriores = array_values(array_filter(array_diff_key($porDia, [$hoje => 1]), fn($n) => $n > 0));
            $anteriores = array_slice($anteriores, -5);
            $j = (array) $r['janela'];
            $gapMin = max(1, (($j['espera_min'] ?? 15) + ($j['espera_max'] ?? 20)) / 2);
            [$hDe, $mDe] = array_map('intval', explode(':', (string) $j['de']));
            [$hAte, $mAte] = array_map('intval', explode(':', (string) $j['ate']));
            $capacidade = (int) floor((($hAte * 60 + $mAte) - ($hDe * 60 + $mDe)) / $gapMin);
            $ritmo = $anteriores ? (int) round(array_sum($anteriores) / count($anteriores)) : $capacidade;
            $ritmo = max(1, $ritmo);

            $restantes = (int) $r['restantes'];
            $diasUteis = max(count((array) $j['dias']), 1);
            $faltamDias = $restantes > 0 ? (int) ceil($restantes / $ritmo) : 0;
            // Dia previsto de término: anda dias de envio a partir de hoje (hoje já contou o que saiu).
            $termino = null;
            if ($restantes > 0) {
                $cursor = $agora; $restoHoje = max(0, $ritmo - $hojeN); $faltam = $restantes - $restoHoje;
                $dias = 0;
                while ($faltam > 0 && $dias < 400) {
                    $cursor = Regra::proximaJanela((int) ((new \DateTimeImmutable('@' . $cursor))->setTimezone($fuso)->modify('+1 day')->setTime(0, 0)->getTimestamp()), ['dias' => $j['dias'], 'de' => $j['de'], 'ate' => $j['ate']]);
                    $faltam -= $ritmo; $dias++;
                }
                $termino = (new \DateTimeImmutable('@' . $cursor))->setTimezone($fuso)->format('Y-m-d');
                if ($faltam <= 0 && $restoHoje >= $restantes) $termino = $hoje;
            }

            $proximos = self::faixas((array) $r['proximos'], $j, $ultimo, $agora);
            $saida[] = [
                'fluxo' => $fluxo, 'nome' => $nome, 'sem_dados' => false,
                'gerado_em' => date('c', $gerado), 'desatualizado' => $agora - $gerado > self::VELHO_MIN * 60,
                'janela' => $j, 'restantes' => $restantes, 'por_grupo' => (array) $r['por_grupo'],
                'enviados_hoje' => $hojeN, 'enviados_total' => (int) $r['enviados_total'], 'ritmo_dia' => $ritmo,
                'termino' => $termino, 'dias_restantes' => $faltamDias,
                'ultimo_envio' => $ultimo ? date('c', $ultimo) : null,
                'proximos' => array_map(fn($p) => $p + ['de_iso' => date('c', $p['de']), 'ate_iso' => date('c', $p['ate'])], $proximos),
                'pulados' => (array) $r['pulados'], 'problemas' => (array) $r['problemas'],
            ];
        }
        $vez = null;
        try { $vez = Roleta::daVez($accountId); } catch (\Throwable $e) { $vez = null; }
        return ['fluxos' => $saida, 'da_vez' => $vez];
    }

    /** Quando saiu a última abertura do fluxo (a Roleta guarda cada uma). */
    private static function ultimoEnvio(int $accountId, string $fluxo): ?int
    {
        try {
            $st = Database::getConnection()->prepare('SELECT MAX(created_at) FROM sdr_roleta_sorteios WHERE account_id = ? AND origem = ?');
            $st->execute([$accountId, $fluxo]);
            $t = $st->fetchColumn();
            return $t ? (int) strtotime((string) $t) : null;
        } catch (\Throwable $e) { return null; }
    }

    /** Quantas aberturas do fluxo saíram no dia (data de Brasília), pelos sorteios da Roleta. */
    private static function enviadosHoje(int $accountId, string $fluxo, string $hoje): int
    {
        try {
            $ini = (new \DateTimeImmutable($hoje . ' 00:00:00', new \DateTimeZone(Regra::FUSO)))->getTimestamp();
            $st = Database::getConnection()->prepare('SELECT COUNT(*) FROM sdr_roleta_sorteios WHERE account_id = ? AND origem = ? AND created_at >= ? AND created_at < ?');
            $st->execute([$accountId, $fluxo, date('Y-m-d H:i:s', $ini), date('Y-m-d H:i:s', $ini + 86400)]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) { return 0; }
    }
}
