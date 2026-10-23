<?php
namespace App\FollowUp;

use App\Core\Database;

/**
 * Cadencia: as cadências de follow-up de uma conta (tabela followup_cadencias,
 * migration 168), editadas em Operação › Follow-up.
 *
 * Uma cadência diz QUANDO começa (gatilho: não respondeu, respondeu e parou, ou
 * qualquer silêncio), EM QUAIS etapas do funil vale (vazio = todas), COM QUEM
 * (todas as conversas, só com o robô, só com uma pessoa), em que horário sai e
 * a lista de passos (espera + texto). A regra de cada conversa está em Regra.
 *
 * Uma conversa segue no máximo UMA cadência: a primeira ativa (pela ordem da
 * tela) em que ela se encaixa (Regra::escolher).
 *
 * Nasce desligada: ligar é um clique de quem conferiu os textos.
 */
final class Cadencia
{
    public const MAX_PASSOS = 10;
    public const MAX_TEXTO  = 2000;
    /** Maior espera de um passo: 30 dias. */
    public const ESPERA_MAX = 43200;

    /** @return list<array> as cadências da conta, já decodificadas, na ordem da tela */
    public static function daConta(int $accountId, bool $soAtivas = false): array
    {
        if ($accountId <= 0) return [];
        $st = Database::getConnection()->prepare(
            'SELECT * FROM followup_cadencias WHERE account_id = ?' . ($soAtivas ? ' AND ativo = 1' : '') . ' ORDER BY ordem, id'
        );
        $st->execute([$accountId]);
        return array_map([self::class, 'decodificar'], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function buscar(int $id, int $accountId): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM followup_cadencias WHERE id = ? AND account_id = ?');
        $st->execute([$id, $accountId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? self::decodificar($r) : null;
    }

    public static function decodificar(array $r): array
    {
        $json = fn($v) => is_array($d = json_decode((string) $v, true)) ? $d : [];
        return [
            'id'              => (int) $r['id'],
            'account_id'      => (int) $r['account_id'],
            'nome'            => (string) $r['nome'],
            'ativo'           => (int) $r['ativo'] === 1,
            'gatilho'         => isset(Regra::GATILHOS[$r['gatilho']]) ? (string) $r['gatilho'] : 'sem_resposta',
            'com_quem'        => isset(Regra::COM_QUEM[$r['com_quem']]) ? (string) $r['com_quem'] : 'todas',
            'etapas'          => array_values(array_map('intval', $json($r['etapas']))),
            'mover_para'      => $r['mover_para'] !== null ? (int) $r['mover_para'] : null,
            'inicio_max_dias' => (int) $r['inicio_max_dias'],
            'horario'         => Regra::horario($json($r['horario'])),
            'passos'          => array_values(array_map(fn($p) => [
                'espera_min' => (int) ($p['espera_min'] ?? 0),
                'texto'      => (string) ($p['texto'] ?? ''),
            ], $json($r['passos']))),
            'ordem'           => (int) $r['ordem'],
            'updated_at'      => (string) $r['updated_at'],
        ];
    }

    /** @return list<array{id:int, nome:string}> as etapas do funil da conta, na ordem */
    public static function colunas(int $accountId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, nome FROM pipeline_columns WHERE account_id = ? ORDER BY ordem, id');
        $st->execute([$accountId]);
        return array_map(fn($r) => ['id' => (int) $r['id'], 'nome' => (string) $r['nome']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Confere e normaliza o que veio da tela. PURA, a não ser pelas colunas da
     * conta, que vêm por parâmetro.
     *
     * @param list<int> $colunasDaConta
     * @return array{ok:bool, error?:string, dados?:array}
     */
    public static function validar(array $in, array $colunasDaConta): array
    {
        $nome = trim((string) ($in['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome) > 120) return ['ok' => false, 'error' => 'Dê um nome à cadência (até 120 letras).'];
        $gatilho = (string) ($in['gatilho'] ?? '');
        if (!isset(Regra::GATILHOS[$gatilho])) return ['ok' => false, 'error' => 'Escolha quando a cadência começa.'];
        $quem = (string) ($in['com_quem'] ?? 'todas');
        if (!isset(Regra::COM_QUEM[$quem])) return ['ok' => false, 'error' => 'Escolha para quais conversas a cadência vale.'];

        $etapas = array_values(array_unique(array_map('intval', (array) ($in['etapas'] ?? []))));
        foreach ($etapas as $e) {
            if (!in_array($e, $colunasDaConta, true)) return ['ok' => false, 'error' => 'Uma das etapas escolhidas não existe mais no funil.'];
        }
        $mover = isset($in['mover_para']) && $in['mover_para'] !== '' && $in['mover_para'] !== null ? (int) $in['mover_para'] : null;
        if ($mover !== null && !in_array($mover, $colunasDaConta, true)) return ['ok' => false, 'error' => 'A etapa para onde o card vai não existe mais.'];

        $dias = (int) ($in['inicio_max_dias'] ?? 3);
        if ($dias < 0 || $dias > 365) return ['ok' => false, 'error' => 'O limite de dias para começar vai de 0 a 365.'];

        $passos = [];
        foreach (array_values((array) ($in['passos'] ?? [])) as $i => $p) {
            $texto = trim(str_replace("\r\n", "\n", (string) ($p['texto'] ?? '')));
            $espera = (int) ($p['espera_min'] ?? 0);
            $n = $i + 1;
            if ($texto === '') return ['ok' => false, 'error' => "Escreva o texto da mensagem {$n}."];
            if (mb_strlen($texto) > self::MAX_TEXTO) return ['ok' => false, 'error' => "A mensagem {$n} passou de " . self::MAX_TEXTO . ' letras.'];
            if ($espera < Regra::ESPERA_MIN) return ['ok' => false, 'error' => "A mensagem {$n} precisa esperar pelo menos " . Regra::ESPERA_MIN . ' minutos.'];
            if ($espera > self::ESPERA_MAX) return ['ok' => false, 'error' => "A espera da mensagem {$n} passa de 30 dias."];
            $passos[] = ['espera_min' => $espera, 'texto' => $texto];
        }
        if (!$passos) return ['ok' => false, 'error' => 'A cadência precisa de pelo menos uma mensagem.'];
        if (count($passos) > self::MAX_PASSOS) return ['ok' => false, 'error' => 'No máximo ' . self::MAX_PASSOS . ' mensagens por cadência.'];

        $h = (array) ($in['horario'] ?? []);
        if (!array_filter((array) ($h['dias'] ?? []))) return ['ok' => false, 'error' => 'Marque pelo menos um dia da semana para os envios.'];
        $horario = Regra::horario($h);
        if (($h['de'] ?? '') !== $horario['de'] || ($h['ate'] ?? '') !== $horario['ate']) {
            return ['ok' => false, 'error' => 'O horário de envio precisa começar antes de terminar (ex.: 08:00 às 18:00).'];
        }

        return ['ok' => true, 'dados' => [
            'nome' => $nome, 'gatilho' => $gatilho, 'com_quem' => $quem, 'etapas' => $etapas, 'mover_para' => $mover,
            'inicio_max_dias' => $dias, 'horario' => $horario, 'passos' => $passos,
        ]];
    }

    /** @return array{ok:bool, error?:string, id?:int} */
    public static function salvar(int $accountId, array $in, int $userId, ?int $id = null): array
    {
        $v = self::validar($in, array_column(self::colunas($accountId), 'id'));
        if (!$v['ok']) return $v;
        $d = $v['dados'];
        $pdo = Database::getConnection();
        $agora = date('Y-m-d H:i:s');
        $json = fn($x) => json_encode($x, JSON_UNESCAPED_UNICODE);
        if ($id) {
            if (!self::buscar($id, $accountId)) return ['ok' => false, 'error' => 'Cadência não encontrada.'];
            $pdo->prepare('UPDATE followup_cadencias SET nome = ?, gatilho = ?, com_quem = ?, etapas = ?, mover_para = ?, inicio_max_dias = ?,
                                  horario = ?, passos = ?, atualizado_por = ?, updated_at = ? WHERE id = ? AND account_id = ?')
                ->execute([$d['nome'], $d['gatilho'], $d['com_quem'], $json($d['etapas']), $d['mover_para'], $d['inicio_max_dias'],
                           $json($d['horario']), $json($d['passos']), $userId ?: null, $agora, $id, $accountId]);
        } else {
            $st = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM followup_cadencias WHERE account_id = ?');
            $st->execute([$accountId]);
            $pdo->prepare('INSERT INTO followup_cadencias (account_id, nome, ativo, gatilho, com_quem, etapas, mover_para, inicio_max_dias,
                                  horario, passos, ordem, criado_por, atualizado_por, created_at, updated_at)
                           VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$accountId, $d['nome'], $d['gatilho'], $d['com_quem'], $json($d['etapas']), $d['mover_para'], $d['inicio_max_dias'],
                           $json($d['horario']), $json($d['passos']), (int) $st->fetchColumn(), $userId ?: null, $userId ?: null, $agora, $agora]);
            $id = (int) $pdo->lastInsertId();
        }
        self::auditar($accountId, 'followup.cadencia.salva', $userId, $id, ['nome' => $d['nome'], 'passos' => count($d['passos'])]);
        return ['ok' => true, 'id' => $id];
    }

    public static function ligar(int $id, int $accountId, bool $ativo, int $userId): bool
    {
        $st = Database::getConnection()->prepare('UPDATE followup_cadencias SET ativo = ?, atualizado_por = ?, updated_at = ? WHERE id = ? AND account_id = ?');
        $st->execute([$ativo ? 1 : 0, $userId ?: null, date('Y-m-d H:i:s'), $id, $accountId]);
        if ($st->rowCount() !== 1) return (bool) self::buscar($id, $accountId);
        self::auditar($accountId, 'followup.cadencia.' . ($ativo ? 'ligada' : 'desligada'), $userId, $id, []);
        return true;
    }

    public static function excluir(int $id, int $accountId, int $userId): bool
    {
        $st = Database::getConnection()->prepare('DELETE FROM followup_cadencias WHERE id = ? AND account_id = ?');
        $st->execute([$id, $accountId]);
        if ($st->rowCount() !== 1) return false;
        self::auditar($accountId, 'followup.cadencia.excluida', $userId, $id, []);
        return true;
    }

    /** Troca a ordem: a primeira da lista ganha da segunda quando a conversa cabe nas duas. */
    public static function ordenar(int $accountId, array $ids): void
    {
        $st = Database::getConnection()->prepare('UPDATE followup_cadencias SET ordem = ? WHERE id = ? AND account_id = ?');
        foreach (array_values(array_map('intval', $ids)) as $i => $id) $st->execute([$i + 1, $id, $accountId]);
    }

    /**
     * Alguma cadência ativa cuida de quem não respondeu à primeira mensagem? Aí o
     * follow-up antigo do n8n (só para quem não respondeu) fica de fora, para o
     * lead não receber dois. Ver AutomacaoSdr::paraOn8n.
     */
    public static function cobreSemResposta(int $accountId): bool
    {
        try {
            $st = Database::getConnection()->prepare(
                "SELECT 1 FROM followup_cadencias WHERE account_id = ? AND ativo = 1 AND gatilho IN ('sem_resposta', 'qualquer') LIMIT 1"
            );
            $st->execute([$accountId]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable $e) {
            return false; // sem a migration 168: o n8n segue como era
        }
    }

    private static function auditar(int $accountId, string $acao, int $userId, int $id, array $detalhes): void
    {
        \App\Master\Account::audit($accountId, $acao, [
            'user_id' => $userId ?: null, 'entidade' => 'followup_cadencias', 'entidade_id' => $id, 'detalhes' => $detalhes,
        ]);
    }
}
