<?php
namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * Métricas de atendimento do WhatsApp (Comunicação › Atendimento, 22/10/2026):
 * quanto tempo o lead esperou por resposta e como está cada operador.
 *
 * A UNIDADE É A RESPOSTA. Numa conversa, a primeira mensagem do contato sem
 * resposta abre uma espera; a próxima mensagem nossa fecha e o tempo entre as
 * duas é o tempo de resposta. Mensagens do contato em sequência não abrem outra
 * espera (ele mandou três e a gente respondeu uma vez: uma resposta, contada do
 * primeiro recado). Conversa que termina o período com espera aberta conta como
 * "sem resposta".
 *
 * QUEM RESPONDEU
 *   1. mensagem enviada pelo Chat do CRM: whatsapp_messages.sent_by_user_id
 *      (migration 165, gravado pelo api/whatsapp/send.php);
 *   2. mensagem da Vitória (agente de IA): o wamid está em ai_intake_messages;
 *      fecha a espera do lead, mas não entra no tempo de nenhum operador;
 *   3. o resto (mensagem de antes da migration, automação do n8n, aparelho):
 *      vale para o dono da conversa (whatsapp_chats.linked_user_id); sem dono,
 *      "Sem operador".
 *
 * HORÁRIO COMERCIAL (22/10/2026): com ele ligado (padrão seg a sex, 08:00 às
 * 18:00, configurável por conta em configuracoes.atendimento.horario), só conta o
 * tempo dentro do expediente: mensagem às 22h respondida às 8h05 vale 5 minutos.
 * A tela também mostra o tempo corrido, 24 horas por dia.
 *
 * FUSO: o banco grava em UTC (date() do PHP no container); tudo aqui é convertido
 * para o horário de Brasília (FUSO) antes de contar: dia, expediente e o que a tela
 * mostra. Grupos, status e newsletter ficam de fora. Faixas: bom até META_BOM
 * minutos, atenção até META_ATENCAO. Toda consulta é escopada pelos números da conta.
 */
final class MetricasAtendimento
{
    public const META_BOM     = 5;    // minutos
    public const META_ATENCAO = 30;   // minutos
    public const DIAS_MAXIMOS = 92;
    /** Faixas de tempo de resposta, em minutos (limite superior; null = acima do anterior). */
    public const FAIXAS = [['ate_5', 5], ['ate_30', 30], ['ate_2h', 120], ['ate_24h', 1440], ['mais_24h', null]];
    public const ROTULO_FAIXA = ['ate_5' => 'Até 5 min', 'ate_30' => '5 a 30 min', 'ate_2h' => '30 min a 2 h', 'ate_24h' => '2 a 24 h', 'mais_24h' => 'Mais de 24 h'];
    public const FUSO = 'America/Sao_Paulo';
    /** Expediente padrão: seg (1) a sex (5), 08:00 às 18:00. Dia ausente = fechado. */
    public const HORARIO_PADRAO = ['ativo' => true, 'dias' => [1 => ['08:00', '18:00'], 2 => ['08:00', '18:00'], 3 => ['08:00', '18:00'], 4 => ['08:00', '18:00'], 5 => ['08:00', '18:00']]];
    public const DIAS_SEMANA = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    /** Hoje em Brasília. */
    public static function hoje(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::FUSO)))->format('Y-m-d');
    }

    /** Agora em Brasília. */
    public static function agora(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::FUSO)))->format('Y-m-d H:i:s');
    }

    /** Data do banco (fuso do PHP, UTC em produção) para Brasília. */
    public static function paraLocal(string $s): string
    {
        return (new \DateTimeImmutable($s, new \DateTimeZone(date_default_timezone_get())))->setTimezone(new \DateTimeZone(self::FUSO))->format('Y-m-d H:i:s');
    }

    /** Data de Brasília para o fuso do banco (para filtrar o período). */
    public static function doLocal(string $s): string
    {
        return (new \DateTimeImmutable($s, new \DateTimeZone(self::FUSO)))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /**
     * Minutos entre duas datas de Brasília. Sem horário (ou desligado): tempo corrido.
     * Com horário: só o que cai dentro do expediente de cada dia.
     */
    public static function minutosEntre(string $de, string $ate, ?array $horario = null): float
    {
        $ta = strtotime($de); $tb = strtotime($ate);
        if ($tb <= $ta) return 0.0;
        if (!$horario || empty($horario['ativo'])) return ($tb - $ta) / 60;
        $total = 0; $dia = strtotime(date('Y-m-d', $ta)); $voltas = 0;
        while ($dia <= $tb && $voltas++ < 400) {
            $janela = $horario['dias'][(int) date('N', $dia)] ?? null;
            if ($janela) {
                $base = date('Y-m-d', $dia);
                $ini = max(strtotime($base . ' ' . $janela[0]), $ta);
                $fim = min(strtotime($base . ' ' . $janela[1]), $tb);
                if ($fim > $ini) $total += $fim - $ini;
            }
            $dia = strtotime('+1 day', $dia);
        }
        return $total / 60;
    }

    /** O expediente da conta (configuracoes.atendimento.horario), ou o padrão. */
    public static function horarioDaConta(array $conta): array
    {
        $cfg = json_decode((string) ($conta['configuracoes'] ?? ''), true);
        $h = is_array($cfg) ? ($cfg['atendimento']['horario'] ?? null) : null;
        return is_array($h) ? self::normalizarHorario($h) : self::HORARIO_PADRAO;
    }

    /** Valida o expediente vindo do formulário. @return array{ativo:bool, dias:array<int,array{0:string,1:string}>} */
    public static function normalizarHorario(array $h): array
    {
        $dias = [];
        foreach (self::DIAS_SEMANA as $n => $_) {
            $j = $h['dias'][$n] ?? $h['dias'][(string) $n] ?? null;
            if (!is_array($j) || count($j) < 2) continue;
            [$a, $b] = [trim((string) $j[0]), trim((string) $j[1])];
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $a) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $b) || $b <= $a) continue;
            $dias[$n] = [$a, $b];
        }
        return ['ativo' => !empty($h['ativo']) && $dias !== [], 'dias' => $dias];
    }

    /** Grava o expediente da conta, preservando o resto de `configuracoes`. */
    public static function salvarHorario(\PDO $pdo, int $accountId, array $h): array
    {
        $h = self::normalizarHorario($h);
        $st = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = ?');
        $st->execute([$accountId]);
        $cfg = json_decode((string) $st->fetchColumn(), true);
        if (!is_array($cfg)) $cfg = [];
        $cfg['atendimento']['horario'] = $h;
        $pdo->prepare('UPDATE accounts SET configuracoes = ? WHERE id = ?')->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), $accountId]);
        return $h;
    }

    /** "Seg a sex, 08:00 às 18:00 · sáb 08:00 às 12:00". */
    public static function descreverHorario(array $h): string
    {
        if (empty($h['ativo']) || empty($h['dias'])) return 'tempo corrido, 24 horas';
        $abrev = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];
        $grupos = []; $atual = null;
        foreach ($h['dias'] as $n => $j) {
            $chave = $j[0] . '|' . $j[1];
            if ($atual && $atual['chave'] === $chave && $atual['fim'] === $n - 1) { $atual['fim'] = $n; continue; }
            if ($atual) $grupos[] = $atual;
            $atual = ['chave' => $chave, 'ini' => $n, 'fim' => $n, 'j' => $j];
        }
        if ($atual) $grupos[] = $atual;
        $partes = array_map(fn($g) => ($g['ini'] === $g['fim'] ? $abrev[$g['ini']] : $abrev[$g['ini']] . ' a ' . $abrev[$g['fim']]) . ', ' . $g['j'][0] . ' às ' . $g['j'][1], $grupos);
        return ucfirst(implode(' · ', $partes));
    }

    /** @return array{0:string,1:string} de/até válidos, no máximo DIAS_MAXIMOS */
    public static function periodo(?string $de, ?string $ate): array
    {
        $valida = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false && date('Y-m-d', strtotime($d)) === $d;
        $hoje = self::hoje();
        $ate  = $valida($ate) ? $ate : $hoje;
        $de   = $valida($de)  ? $de  : date('Y-m-d', strtotime($ate . ' -29 days'));
        if ($de > $ate) [$de, $ate] = [$ate, $de];
        $minimo = date('Y-m-d', strtotime($ate . ' -' . (self::DIAS_MAXIMOS - 1) . ' days'));
        if ($de < $minimo) $de = $minimo;
        return [$de, $ate];
    }

    /** bom | atencao | ruim | sem, pelo tempo médio em minutos. */
    public static function faixa(?float $minutos): string
    {
        if ($minutos === null) return 'sem';
        if ($minutos <= self::META_BOM) return 'bom';
        if ($minutos <= self::META_ATENCAO) return 'atencao';
        return 'ruim';
    }

    /** "2 min", "1 h 05", "3 d 02 h". */
    public static function duracao(?float $minutos): string
    {
        if ($minutos === null) return '-';
        $m = (int) round($minutos);
        if ($m < 1) return 'menos de 1 min';
        if ($m < 60) return $m . ' min';
        $h = intdiv($m, 60); $r = $m % 60;
        if ($h < 24) return $h . ' h ' . str_pad((string) $r, 2, '0', STR_PAD_LEFT);
        $d = intdiv($h, 24); $h = $h % 24;
        return $d . ' d ' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ' h';
    }

    /**
     * O cálculo, sem banco (testável isolado). As mensagens vêm ordenadas por
     * conversa e data; cada uma tem instance_id, remote_jid, direction, created_at,
     * sent_by_user_id (int|null) e bot (bool). $donos: dono da conversa por
     * "instance|jid". $nomes: nome por user_id.
     *
     * @param array<int, array{instance_id:int, remote_jid:string, direction:string, created_at:string, sent_by_user_id:?int, bot:bool}> $msgs
     * @param array<string, ?int> $donos
     * @param array<int, string> $nomes
     * @return array{equipe:array, operadores:array<int,array>, faixas:array<string,int>, dias:array<string,array{respostas:int,soma:float}>, bot:array{respostas:int,soma:float}}
     */
    public static function calcular(array $msgs, array $donos, array $nomes, string $de, string $ate, ?array $horario = null): array
    {
        $porConversa = [];
        foreach ($msgs as $m) $porConversa[$m['instance_id'] . '|' . $m['remote_jid']][] = $m;

        $ops = [];      // user_id|0 => métricas
        $op = function (int $uid) use (&$ops, $nomes): array {
            if (!isset($ops[$uid])) {
                $ops[$uid] = ['user_id' => $uid, 'nome' => $uid ? ($nomes[$uid] ?? ('Usuário #' . $uid)) : 'Sem operador',
                    'respostas' => 0, 'soma' => 0.0, 'tempos' => [], 'maior' => 0.0, 'ate_meta' => 0, 'primeiras' => 0, 'soma_primeiras' => 0.0,
                    'conversas' => [], 'enviadas' => 0, 'sem_resposta' => 0, 'recebidas' => 0];
            }
            return $ops[$uid];
        };
        $faixas = array_fill_keys(array_column(self::FAIXAS, 0), 0);
        $dias = [];
        $bot = ['respostas' => 0, 'soma' => 0.0];
        $conversasComContato = 0; $semResposta = 0; $recebidas = 0;
        $iniDia = $de . ' 00:00:00'; $fimDia = $ate . ' 23:59:59';

        foreach ($porConversa as $chave => $lista) {
            $dono = $donos[$chave] ?? null;
            $esperaDesde = null; $primeiraFeita = false; $teveContato = false;
            foreach ($lista as $m) {
                $dentro = $m['created_at'] >= $iniDia && $m['created_at'] <= $fimDia;
                if ($m['direction'] === 'inbound') {
                    if ($dentro) { $recebidas++; $teveContato = true; }
                    if ($esperaDesde === null && $dentro) $esperaDesde = $m['created_at'];
                    continue;
                }
                // Nossa mensagem.
                $uid = (int) ($m['sent_by_user_id'] ?? 0) ?: (int) ($dono ?? 0);
                if (!$m['bot'] && $dentro) {
                    $o = $op($uid); $ops[$uid]['enviadas']++; $ops[$uid]['conversas'][$chave] = true;
                }
                if ($esperaDesde === null) continue;
                $min = self::minutosEntre($esperaDesde, $m['created_at'], $horario);
                $esperaDesde = null;
                if ($m['bot']) { $bot['respostas']++; $bot['soma'] += $min; continue; }
                $o = $op($uid);
                $ops[$uid]['respostas']++; $ops[$uid]['soma'] += $min; $ops[$uid]['tempos'][] = $min;
                $ops[$uid]['maior'] = max($ops[$uid]['maior'], $min);
                if ($min <= self::META_BOM) $ops[$uid]['ate_meta']++;
                if (!$primeiraFeita) { $primeiraFeita = true; $ops[$uid]['primeiras']++; $ops[$uid]['soma_primeiras'] += $min; }
                foreach (self::FAIXAS as [$k, $lim]) { if ($lim === null || $min <= $lim) { $faixas[$k]++; break; } }
                $dia = substr($m['created_at'], 0, 10);
                $dias[$dia] ??= ['respostas' => 0, 'soma' => 0.0];
                $dias[$dia]['respostas']++; $dias[$dia]['soma'] += $min;
            }
            if ($teveContato) $conversasComContato++;
            if ($esperaDesde !== null) {
                $semResposta++;
                $uid = (int) ($dono ?? 0);
                $op($uid); $ops[$uid]['sem_resposta']++;
            }
        }

        $fechar = function (array $o): array {
            sort($o['tempos']);
            $n = count($o['tempos']);
            $o['media'] = $o['respostas'] ? $o['soma'] / $o['respostas'] : null;
            $o['mediana'] = $n ? ($n % 2 ? $o['tempos'][intdiv($n, 2)] : ($o['tempos'][$n / 2 - 1] + $o['tempos'][$n / 2]) / 2) : null;
            $o['ate_meta_pct'] = $o['respostas'] ? $o['ate_meta'] * 100 / $o['respostas'] : null;
            $o['media_primeira'] = $o['primeiras'] ? $o['soma_primeiras'] / $o['primeiras'] : null;
            $o['conversas'] = count($o['conversas']);
            $o['faixa'] = self::faixa($o['media']);
            unset($o['tempos'], $o['soma'], $o['soma_primeiras']);
            return $o;
        };
        $equipe = ['user_id' => null, 'nome' => 'Equipe', 'respostas' => 0, 'soma' => 0.0, 'tempos' => [], 'maior' => 0.0, 'ate_meta' => 0, 'primeiras' => 0, 'soma_primeiras' => 0.0, 'conversas' => [], 'enviadas' => 0, 'sem_resposta' => 0];
        foreach ($ops as $o) {
            foreach (['respostas', 'soma', 'ate_meta', 'primeiras', 'soma_primeiras', 'enviadas', 'sem_resposta'] as $k) $equipe[$k] += $o[$k];
            $equipe['tempos'] = array_merge($equipe['tempos'], $o['tempos']);
            $equipe['maior'] = max($equipe['maior'], $o['maior']);
            $equipe['conversas'] += $o['conversas'];
        }
        $equipe = $fechar($equipe);
        $equipe['conversas'] = $conversasComContato;
        $equipe['recebidas'] = $recebidas;
        $equipe['sem_resposta'] = $semResposta;
        $operadores = array_map($fechar, $ops);
        usort($operadores, fn($a, $b) => ($b['respostas'] <=> $a['respostas']) ?: (($a['media'] ?? PHP_FLOAT_MAX) <=> ($b['media'] ?? PHP_FLOAT_MAX)));
        ksort($dias);
        return ['equipe' => $equipe, 'operadores' => array_values($operadores), 'faixas' => $faixas, 'dias' => $dias, 'bot' => $bot];
    }

    /**
     * O relatório da tela. $instanceIds: os números que a pessoa enxerga (já
     * autorizados). $soVendedor: vendedor da edição CRM, que vê só o próprio
     * atendimento. $foco: operador escolhido no filtro (dono ou admin).
     */
    public static function relatorio(int $accountId, array $instanceIds, string $de, string $ate, ?int $soVendedor = null, ?int $foco = null, ?int $numero = null, ?array $horario = null): array
    {
        $pdo = Database::getConnection();
        $instanceIds = array_values(array_filter(array_map('intval', $instanceIds)));
        if ($numero && in_array($numero, $instanceIds, true)) $instanceIds = [$numero];
        $vazio = self::calcular([], [], [], $de, $ate);
        if (!$instanceIds) return $vazio + ['aguardando' => [], 'operadores_lista' => [], 'numeros' => [], 'agora' => date('Y-m-d H:i:s'), 'tem_sent_by' => false];
        $in = implode(',', array_fill(0, count($instanceIds), '?'));

        $temSentBy = WhatsAppMessage::temColunaSentBy($pdo);
        $sql = 'SELECT m.instance_id, m.remote_jid, m.direction, m.created_at, ' . ($temSentBy ? 'm.sent_by_user_id' : 'NULL AS sent_by_user_id') . ',
                       EXISTS(SELECT 1 FROM ai_intake_messages ai WHERE ai.wamid = m.wamid AND ai.direction = \'outbound\') AS bot
                  FROM whatsapp_messages m
                 WHERE m.instance_id IN (' . $in . ') AND m.created_at BETWEEN ? AND ?
                   AND m.remote_jid NOT LIKE \'%@g.us\' AND m.remote_jid NOT LIKE \'%@broadcast\' AND m.remote_jid NOT LIKE \'%@newsletter\'
                   AND COALESCE(m.is_deleted, 0) = 0
                 ORDER BY m.instance_id, m.remote_jid, m.created_at, m.id';
        $st = $pdo->prepare($sql);
        // O período é em dias de Brasília; o banco grava no fuso do PHP (UTC em produção).
        $st->execute(array_merge($instanceIds, [self::doLocal($de . ' 00:00:00'), self::doLocal($ate . ' 23:59:59')]));
        $msgs = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $msgs[] = ['instance_id' => (int) $r['instance_id'], 'remote_jid' => (string) $r['remote_jid'], 'direction' => (string) $r['direction'],
                'created_at' => self::paraLocal((string) $r['created_at']), 'sent_by_user_id' => $r['sent_by_user_id'] !== null ? (int) $r['sent_by_user_id'] : null, 'bot' => (bool) $r['bot']];
        }

        $st = $pdo->prepare('SELECT instance_id, remote_jid, linked_user_id FROM whatsapp_chats WHERE instance_id IN (' . $in . ')');
        $st->execute($instanceIds);
        $donos = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) $donos[$c['instance_id'] . '|' . $c['remote_jid']] = $c['linked_user_id'] !== null ? (int) $c['linked_user_id'] : null;

        $st = $pdo->prepare('SELECT id, nome FROM users WHERE account_id = ? AND deleted_at IS NULL');
        $st->execute([$accountId]);
        $nomes = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $u) $nomes[(int) $u['id']] = (string) $u['nome'];

        // Vendedor: só as conversas que são dele (dono) ou que ele respondeu.
        if ($soVendedor !== null) {
            $msgs = array_values(array_filter($msgs, fn($m) => (($donos[$m['instance_id'] . '|' . $m['remote_jid']] ?? null) === $soVendedor) || ((int) ($m['sent_by_user_id'] ?? 0) === $soVendedor)));
            $foco = $soVendedor;
        }
        $rel = self::calcular($msgs, $donos, $nomes, $de, $ate, $horario);

        if ($foco !== null) {
            $msgsFoco = array_values(array_filter($msgs, fn($m) => (($donos[$m['instance_id'] . '|' . $m['remote_jid']] ?? null) === $foco) || ((int) ($m['sent_by_user_id'] ?? 0) === $foco)));
            $relFoco = self::calcular($msgsFoco, $donos, $nomes, $de, $ate, $horario);
            $rel['foco'] = ['user_id' => $foco, 'nome' => $nomes[$foco] ?? ('Usuário #' . $foco)] + ['metricas' => $relFoco['equipe'], 'faixas' => $relFoco['faixas'], 'dias' => $relFoco['dias']];
        } else {
            $rel['foco'] = null;
        }

        // Quem está esperando agora: a última mensagem é do contato.
        $agora = self::agora();
        $sql = 'SELECT c.instance_id, c.remote_jid, c.contact_name, c.phone, c.last_message_at, c.linked_user_id, c.linked_card_id,
                       wi.display_name AS numero_nome, wi.instance_name
                  FROM whatsapp_chats c JOIN whatsapp_instances wi ON wi.id = c.instance_id
                 WHERE c.instance_id IN (' . $in . ') AND c.is_group = 0 AND c.is_archived = 0 AND c.last_message_from_me = 0 AND c.last_message_at IS NOT NULL'
              . (WhatsAppMessage::temColunaExcluida($pdo) ? ' AND c.excluida_em IS NULL' : '') . '
                   AND c.remote_jid NOT LIKE \'%@broadcast\' AND c.remote_jid NOT LIKE \'%@newsletter\''
              . ($soVendedor !== null ? ' AND (c.linked_user_id = ? OR c.linked_user_id IS NULL)' : '')
              . ($foco !== null && $soVendedor === null ? ' AND c.linked_user_id = ?' : '')
              . ' ORDER BY c.last_message_at ASC LIMIT 100';
        $par = $instanceIds;
        if ($soVendedor !== null) $par[] = $soVendedor;
        elseif ($foco !== null) $par[] = $foco;
        $st = $pdo->prepare($sql);
        $st->execute($par);
        $aguardando = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $desde = self::paraLocal((string) $c['last_message_at']);
            $min = self::minutosEntre($desde, $agora, $horario);
            $aguardando[] = ['instance_id' => (int) $c['instance_id'], 'remote_jid' => (string) $c['remote_jid'],
                'nome' => trim((string) ($c['contact_name'] ?? '')) ?: self::telefoneLegivel((string) ($c['phone'] ?: explode('@', (string) $c['remote_jid'])[0])),
                'desde' => $desde, 'minutos' => $min, 'faixa' => self::faixa($min),
                'operador' => $c['linked_user_id'] ? ($nomes[(int) $c['linked_user_id']] ?? '#' . $c['linked_user_id']) : 'Sem operador',
                'numero' => (string) ($c['numero_nome'] ?: $c['instance_name']), 'card_id' => $c['linked_card_id'] ? (int) $c['linked_card_id'] : null];
        }
        $rel['aguardando'] = $aguardando;
        $rel['aguardando_maior'] = $aguardando ? max(array_column($aguardando, 'minutos')) : null;

        // Para os filtros da tela.
        $st = $pdo->prepare("SELECT id, nome FROM users WHERE account_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY nome");
        $st->execute([$accountId]);
        $rel['operadores_lista'] = array_map(fn($u) => ['user_id' => (int) $u['id'], 'nome' => (string) $u['nome']], $st->fetchAll(\PDO::FETCH_ASSOC));
        $st = $pdo->prepare('SELECT id, display_name, instance_name, phone FROM whatsapp_instances WHERE id IN (' . $in . ') ORDER BY id');
        $st->execute($instanceIds);
        $rel['numeros'] = array_map(fn($n) => ['id' => (int) $n['id'], 'nome' => (string) ($n['display_name'] ?: $n['instance_name']), 'phone' => (string) $n['phone']], $st->fetchAll(\PDO::FETCH_ASSOC));
        $rel['agora'] = $agora;
        $rel['tem_sent_by'] = $temSentBy;
        return $rel;
    }

    /** Série diária para o gráfico: todos os dias do período, com zero onde não houve resposta. */
    public static function serieDiaria(array $dias, string $de, string $ate): array
    {
        $rotulos = []; $media = []; $respostas = [];
        for ($d = $de; $d <= $ate; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $rotulos[] = date('d/m', strtotime($d));
            $r = $dias[$d] ?? null;
            $respostas[] = $r ? $r['respostas'] : 0;
            $media[] = $r && $r['respostas'] ? round($r['soma'] / $r['respostas'], 1) : null;
        }
        return ['rotulos' => $rotulos, 'media' => $media, 'respostas' => $respostas];
    }

    public static function telefoneLegivel(string $d): string
    {
        $d = preg_replace('/\D/', '', $d);
        if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $d, $m)) return '+55 (' . $m[1] . ') ' . $m[2] . '-' . $m[3];
        return $d !== '' ? '+' . $d : 'Sem nome';
    }
}
