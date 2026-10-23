<?php
namespace App\FollowUp;

/**
 * Regra: quando sai o próximo follow-up de uma conversa. PURA, sem banco.
 *
 * O card do funil, a aba Operação › Follow-up e o worker que manda chamam esta
 * mesma conta: o horário que o card mostra é o horário em que a mensagem sai
 * (ou o primeiro momento da fila, se o número estiver ocupado com outro envio).
 *
 * ---------------------------------------------------------------------------
 * COMO SE CONTA O PASSO
 * ---------------------------------------------------------------------------
 * O "ciclo" é o silêncio do lead: começa na primeira mensagem nossa depois da
 * última mensagem dele (ou na primeira de todas, se ele nunca escreveu). As
 * mensagens nossas desse ciclo são agrupadas em rajadas (até JUNTO_SEG entre
 * uma e outra, porque a abertura e a Vitória quebram a fala em vários balões):
 *
 *   1ª rajada        a mensagem que espera resposta (a abertura, ou a nossa
 *                    última resposta antes de o lead sumir)
 *   cada rajada a +  um follow-up já dado, nosso ou de uma pessoa
 *
 * Então o próximo passo é o número de rajadas: com 1 rajada sai o passo 1, com
 * 2 sai o passo 2. Contar pelas mensagens, e não por um registro nosso, faz a
 * troca do n8n para cá continuar do passo em que cada lead estava, e uma pessoa
 * que cutucou o lead à mão conta como um passo dado (não manda dois seguidos).
 *
 * A espera de cada passo conta da NOSSA última mensagem e cai no próximo
 * momento dentro do horário de envio da cadência.
 */
final class Regra
{
    public const FUSO = 'America/Sao_Paulo';

    /** Mensagens nossas com até isto entre uma e outra são uma rajada só. */
    public const JUNTO_SEG = 600;

    /** Menor espera de um passo, em minutos: abaixo disso as rajadas se misturam. */
    public const ESPERA_MIN = 30;

    public const GATILHOS = [
        'sem_resposta' => 'Não respondeu à primeira mensagem',
        'parou'        => 'Respondeu e depois parou de responder',
        'qualquer'     => 'Qualquer silêncio do lead',
    ];

    public const COM_QUEM = [
        'todas'  => 'Todas as conversas',
        'robo'   => 'Só conversas com o robô',
        'pessoa' => 'Só conversas com uma pessoa',
    ];

    public const MOTIVOS = [
        'sem_passos'   => 'A cadência não tem mensagens',
        'grupo'        => 'Conversa de grupo',
        'etapa'        => 'O card está numa etapa fora da cadência',
        'com_quem'     => 'A cadência não vale para quem atende esta conversa',
        'sem_mensagem' => 'Ainda não mandamos mensagem',
        'lead_falou'   => 'O lead falou por último: é a vez de responder a ele',
        'respondeu'    => 'O lead já respondeu',
        'nao_respondeu'=> 'O lead nunca respondeu',
        'antiga'       => 'A conversa parou há mais tempo do que a cadência começa',
    ];

    public const HORARIO_PADRAO = ['dias' => [1, 2, 3, 4, 5, 6], 'de' => '08:00', 'ate' => '18:00'];

    /** Horário de envio válido: dias 1 (segunda) a 7 (domingo), de < até. */
    public static function horario($h): array
    {
        $h = is_array($h) ? $h : [];
        $dias = array_values(array_unique(array_filter(array_map('intval', (array) ($h['dias'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
        sort($dias);
        $hora = fn($v, $pad) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : $pad;
        $de  = $hora($h['de'] ?? '', self::HORARIO_PADRAO['de']);
        $ate = $hora($h['ate'] ?? '', self::HORARIO_PADRAO['ate']);
        if ($ate <= $de) [$de, $ate] = [self::HORARIO_PADRAO['de'], self::HORARIO_PADRAO['ate']];
        return ['dias' => $dias ?: self::HORARIO_PADRAO['dias'], 'de' => $de, 'ate' => $ate];
    }

    /** O primeiro instante a partir de $ts que cai dentro do horário de envio. */
    public static function proximaJanela(int $ts, array $horario): int
    {
        $h = self::horario($horario);
        $fuso = new \DateTimeZone(self::FUSO);
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone($fuso);
        [$hDe, $mDe] = array_map('intval', explode(':', $h['de']));
        for ($i = 0; $i < 8; $i++) {
            $abre  = $d->setTime($hDe, $mDe);
            $fecha = $d->setTime(...array_map('intval', explode(':', $h['ate'])));
            if (in_array((int) $d->format('N'), $h['dias'], true)) {
                if ($d < $abre) return $abre->getTimestamp();
                if ($d < $fecha) return $d->getTimestamp();
            }
            $d = $d->modify('+1 day')->setTime(0, 0);
        }
        return $ts;
    }

    /** Quantas rajadas há nos horários (em segundos) das mensagens nossas. */
    public static function rajadas(array $saidas): int
    {
        sort($saidas);
        $n = 0; $ant = null;
        foreach ($saidas as $t) {
            if ($ant === null || $t - $ant > self::JUNTO_SEG) $n++;
            $ant = $t;
        }
        return $n;
    }

    /**
     * Situação da conversa numa cadência.
     *
     * $cad: passos (list<array{espera_min:int, texto:string}>), gatilho, com_quem,
     *       etapas (list<int>, vazia = todas), inicio_max_dias, horario
     * $f:   grupo (bool), etapa (?int, coluna do card), com_robo (bool),
     *       tem_entrada (bool, o lead já escreveu alguma vez),
     *       ultima_entrada (?int), saidas (list<int>, nossas mensagens depois da
     *       última do lead; todas, se ele nunca escreveu)
     *
     * @return array{estado:string, motivo:?string, passo:int, total:int, previsto:?int,
     *               ciclo_desde:?int, base:?int, ultima_saida:?int}
     *   estado: agendado (previsto pode já ter passado: está na fila) | encerrada | fora
     */
    public static function situacao(array $cad, array $f, int $agora): array
    {
        $passos = array_values((array) ($cad['passos'] ?? []));
        $total = count($passos);
        $saidas = array_map('intval', (array) ($f['saidas'] ?? []));
        sort($saidas);
        $r = ['estado' => 'fora', 'motivo' => null, 'passo' => 0, 'total' => $total, 'previsto' => null,
              'ciclo_desde' => $saidas[0] ?? null, 'base' => null, 'ultima_saida' => $saidas ? end($saidas) : null];
        $fora = function (string $m) use (&$r) { $r['motivo'] = $m; return $r; };

        if (!$total) return $fora('sem_passos');
        if (!empty($f['grupo'])) return $fora('grupo');
        $etapas = array_map('intval', (array) ($cad['etapas'] ?? []));
        if ($etapas && !in_array((int) ($f['etapa'] ?? 0), $etapas, true)) return $fora('etapa');
        $quem = (string) ($cad['com_quem'] ?? 'todas');
        if (($quem === 'robo' && empty($f['com_robo'])) || ($quem === 'pessoa' && !empty($f['com_robo']))) return $fora('com_quem');

        $gat = (string) ($cad['gatilho'] ?? 'sem_resposta');
        $entrou = !empty($f['tem_entrada']);
        $ultEntrada = isset($f['ultima_entrada']) ? (int) $f['ultima_entrada'] : null;
        if (!$saidas) return $fora($ultEntrada ? 'lead_falou' : 'sem_mensagem');
        if ($ultEntrada !== null && $ultEntrada >= end($saidas)) return $fora('lead_falou');
        if ($gat === 'sem_resposta' && $entrou) return $fora('respondeu');
        if ($gat === 'parou' && !$entrou) return $fora('nao_respondeu');

        $passo = self::rajadas($saidas);
        $base = (int) end($saidas);
        $r['passo'] = $passo; $r['base'] = $base;
        if ($passo > $total) { $r['estado'] = 'encerrada'; $r['passo'] = $total; return $r; }

        $dias = (int) ($cad['inicio_max_dias'] ?? 0);
        if ($passo === 1 && $dias > 0 && $agora - $base > $dias * 86400) return $fora('antiga');

        $espera = max(self::ESPERA_MIN, (int) ($passos[$passo - 1]['espera_min'] ?? 0));
        $r['estado'] = 'agendado';
        $r['previsto'] = self::proximaJanela($base + $espera * 60, (array) ($cad['horario'] ?? []));
        return $r;
    }

    /**
     * A cadência que a conversa segue: a primeira ativa (na ordem da tela) em que
     * ela se encaixa. Encerrada também segura a conversa: a próxima cadência não
     * recomeça a insistir com quem já recebeu a última mensagem de outra.
     *
     * @return array{0:?array, 1:?array} [cadência, situação]
     */
    public static function escolher(array $cadencias, array $f, int $agora): array
    {
        foreach ($cadencias as $c) {
            if (empty($c['ativo'])) continue;
            $s = self::situacao($c, $f, $agora);
            if ($s['estado'] !== 'fora') return [$c, $s];
        }
        return [null, null];
    }

    /**
     * O texto com os campos do lead. {nome} = primeiro nome do contato, {empresa}.
     * Campo vazio some sem deixar espaço sobrando ("Oi {nome}, tudo bem?" → "Oi, tudo bem?").
     */
    public static function texto(string $modelo, array $vars): string
    {
        $nome = trim((string) ($vars['nome'] ?? ''));
        $primeiro = $nome !== '' && !preg_match('/^\+?[\d\s()-]+$/', $nome) ? explode(' ', $nome)[0] : '';
        $t = strtr($modelo, ['{nome}' => $primeiro, '{empresa}' => trim((string) ($vars['empresa'] ?? ''))]);
        $t = preg_replace('/[ \t]+([,.!?;:])/u', '$1', $t);
        $t = preg_replace('/[ \t]{2,}/u', ' ', $t);
        return trim((string) $t);
    }
}
