<?php
namespace App\Digital;

use App\Core\Database;
use App\Tarefas\Task;
use App\Tarefas\TaskBoard;

/**
 * A entrega de um cartão (20/10/2026). Um cartão do quadro vira entrega quando
 * ganha um tipo (post, vídeo, reels...). A entrega tem QUATRO estados próprios,
 * que não se confundem com a coluna do quadro nem com o checklist:
 *
 *   producao    a_fazer, em_producao, pronto
 *   aprovacao   nao_enviado, aguardando, aprovado, ajustes
 *   entrega     pendente, entregue
 *   publicacao  nao_se_aplica, pendente, agendado, publicado
 *
 * Cada mudança grava a data do estado e uma linha no histórico do cartão
 * (`task_history`, ação `entrega_etapa`). O material recebido (Material) não
 * aprova nem entrega nada: cada estado só muda por decisão de alguém.
 *
 * CONTAGEM DO PERÍODO (`resumo`). O período é o mês de competência da entrega
 * (o mês do prazo do cartão, ou o da criação; editável). Para cada entrega, um
 * estado só, nesta ordem: concluída (entregue), aguardando aprovação, em
 * produção. "Atrasadas" é à parte: as não concluídas com prazo vencido (também
 * contam no estado delas). "Contratadas" vem da frequência do cliente
 * (Frequencia), e "não cadastradas" é o que foi contratado e ainda não virou
 * cartão. Item de checklist nunca conta como entrega.
 */
final class Entrega
{
    public const TIPOS = [
        'post' => 'Post', 'carrossel' => 'Carrossel', 'video' => 'Vídeo', 'reels' => 'Reels', 'story' => 'Story',
        'arte' => 'Arte', 'artigo' => 'Artigo / blog', 'episodio' => 'Episódio', 'campanha' => 'Peça de campanha', 'outro' => 'Outro',
    ];
    public const ESTADOS = [
        'producao'   => ['a_fazer' => 'A fazer', 'em_producao' => 'Em produção', 'pronto' => 'Pronto'],
        'aprovacao'  => ['nao_enviado' => 'Não enviado', 'aguardando' => 'Aguardando aprovação', 'aprovado' => 'Aprovado', 'ajustes' => 'Ajustes pedidos'],
        'entrega'    => ['pendente' => 'Pendente', 'entregue' => 'Entregue'],
        'publicacao' => ['nao_se_aplica' => 'Não se aplica', 'pendente' => 'Pendente', 'agendado' => 'Agendado', 'publicado' => 'Publicado'],
    ];
    /** Coluna de data de cada estado e o valor que a preenche. */
    private const DATA_DO_ESTADO = [
        'producao' => ['producao_em', 'pronto'], 'aprovacao' => ['aprovacao_em', 'aprovado'],
        'entrega' => ['entregue_em', 'entregue'], 'publicacao' => ['publicado_em', 'publicado'],
    ];

    /**
     * O cartão e o quadro, se a pessoa pode EDITAR o quadro (a mesma regra das
     * tarefas). Cartão de outra conta ou de quadro que ela não edita: erro.
     *
     * @return array{task:array, board:array}
     */
    public static function cartaoEditavel(int $taskId, int $userId, array $accountIds, bool $admin): array
    {
        $t = Task::findById($taskId);
        if (!$t) throw new \DomainException('Cartão não encontrado.');
        $b = TaskBoard::findById((int) $t['board_id'], $accountIds);
        if (!$b || !TaskBoard::canEdit((int) $b['id'], $userId, $accountIds, $admin)) throw new \DomainException('Cartão não encontrado.');
        return ['task' => $t, 'board' => $b];
    }

    /** O mesmo, só para ver. */
    public static function cartaoVisivel(int $taskId, int $userId, array $accountIds, bool $admin): array
    {
        $t = Task::findById($taskId);
        if (!$t) throw new \DomainException('Cartão não encontrado.');
        $b = TaskBoard::findById((int) $t['board_id'], $accountIds);
        if (!$b || !TaskBoard::canView((int) $b['id'], $userId, $accountIds, $admin)) throw new \DomainException('Cartão não encontrado.');
        return ['task' => $t, 'board' => $b];
    }

    public static function doCartao(int $taskId): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM task_entregas_digital WHERE task_id = ?');
        $st->execute([$taskId]);
        $e = $st->fetch(\PDO::FETCH_ASSOC);
        return $e ? self::formatar($e) : null;
    }

    /** Entregas dos cartões de um quadro, por id do cartão (o quadro já foi conferido). */
    public static function doQuadro(int $boardId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT e.*, (SELECT COUNT(*) FROM task_arquivos_esperados m WHERE m.task_id = e.task_id) AS materiais,
                    (SELECT COUNT(*) FROM task_arquivos_esperados m WHERE m.task_id = e.task_id AND m.recebido_em IS NOT NULL) AS recebidos
               FROM task_entregas_digital e JOIN tasks t ON t.id = e.task_id
              WHERE t.board_id = ? AND t.status <> \'arquivada\''
        );
        $st->execute([$boardId]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $e) $out[(int) $e['task_id']] = self::formatar($e);
        return $out;
    }

    /**
     * Cria ou atualiza a entrega do cartão. `tipo` vazio desfaz (o cartão volta a
     * ser tarefa comum; o histórico guarda). Estados ausentes ficam como estão.
     */
    public static function salvar(array $cartao, int $userId, array $d): ?array
    {
        $t = $cartao['task']; $b = $cartao['board'];
        $taskId = (int) $t['id']; $acc = (int) $b['account_id'];
        $pdo = Database::getConnection();
        $atual = self::doCartao($taskId);

        $tipo = array_key_exists('tipo', $d) ? (string) $d['tipo'] : ($atual['tipo'] ?? '');
        if ($tipo === '') {
            if ($atual) {
                $pdo->prepare('DELETE FROM task_entregas_digital WHERE task_id = ?')->execute([$taskId]);
                Task::history($taskId, $userId, 'entrega_removida', $atual, null);
            }
            return null;
        }
        if (!isset(self::TIPOS[$tipo])) throw new \InvalidArgumentException('Tipo de entrega inválido.');

        $novo = ['tipo' => $tipo];
        foreach (self::ESTADOS as $campo => $vals) {
            $v = array_key_exists($campo, $d) ? (string) $d[$campo] : ($atual[$campo] ?? array_key_first($vals));
            if (!isset($vals[$v])) throw new \InvalidArgumentException('Estado inválido em ' . $campo . '.');
            $novo[$campo] = $v;
        }
        $comp = array_key_exists('competencia', $d) ? (string) $d['competencia'] : ($atual['competencia'] ?? '');
        if ($comp === '') $comp = substr((string) ($t['prazo'] ?: $t['created_at'] ?: date('Y-m-d')), 0, 7);
        if (!preg_match('/^\d{4}-\d{2}/', $comp)) throw new \InvalidArgumentException('Mês de competência inválido.');
        $novo['competencia'] = substr($comp, 0, 7) . '-01';

        $agora = date('Y-m-d H:i:s');
        $datas = [];
        foreach (self::DATA_DO_ESTADO as $campo => [$col, $valor]) {
            $antes = $atual[$campo] ?? null;
            if ($novo[$campo] === $antes) { $datas[$col] = $atual[$col] ?? null; continue; }
            // Mudou: a data do estado acompanha (sai do estado, a data some).
            $datas[$col] = $novo[$campo] === $valor ? $agora : ($campo === 'aprovacao' && $novo[$campo] !== 'nao_enviado' ? $agora : null);
        }

        $pdo->prepare(
            'INSERT INTO task_entregas_digital (task_id, account_id, cliente_id, tipo, producao, aprovacao, entrega, publicacao, competencia,
                                                producao_em, aprovacao_em, entregue_em, publicado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE cliente_id = VALUES(cliente_id), tipo = VALUES(tipo), producao = VALUES(producao), aprovacao = VALUES(aprovacao),
               entrega = VALUES(entrega), publicacao = VALUES(publicacao), competencia = VALUES(competencia), producao_em = VALUES(producao_em),
               aprovacao_em = VALUES(aprovacao_em), entregue_em = VALUES(entregue_em), publicado_em = VALUES(publicado_em)'
        )->execute([$taskId, $acc, $b['cliente_id'] ? (int) $b['cliente_id'] : null, $novo['tipo'], $novo['producao'], $novo['aprovacao'],
            $novo['entrega'], $novo['publicacao'], $novo['competencia'], $datas['producao_em'], $datas['aprovacao_em'], $datas['entregue_em'], $datas['publicado_em']]);

        $mudou = [];
        foreach (['tipo', 'producao', 'aprovacao', 'entrega', 'publicacao', 'competencia'] as $k) {
            if (($atual[$k] ?? null) !== $novo[$k]) $mudou[$k] = $novo[$k];
        }
        if ($mudou) {
            $antes = $atual ? array_intersect_key($atual, $mudou) : null;
            Task::history($taskId, $userId, $atual ? 'entrega_etapa' : 'entrega_criada', $antes, $mudou);
        }
        return self::doCartao($taskId);
    }

    private static function formatar(array $e): array
    {
        $out = [
            'task_id' => (int) $e['task_id'], 'cliente_id' => $e['cliente_id'] ? (int) $e['cliente_id'] : null,
            'tipo' => $e['tipo'], 'tipo_rotulo' => self::TIPOS[$e['tipo']] ?? $e['tipo'],
            'competencia' => $e['competencia'] ? substr($e['competencia'], 0, 7) : null,
            'producao_em' => $e['producao_em'], 'aprovacao_em' => $e['aprovacao_em'], 'entregue_em' => $e['entregue_em'], 'publicado_em' => $e['publicado_em'],
        ];
        foreach (self::ESTADOS as $campo => $vals) {
            $out[$campo] = $e[$campo];
            $out[$campo . '_rotulo'] = $vals[$e[$campo]] ?? $e[$campo];
        }
        if (isset($e['materiais'])) { $out['materiais'] = (int) $e['materiais']; $out['recebidos'] = (int) $e['recebidos']; }
        $out['situacao'] = self::situacao($out);
        return $out;
    }

    /** Um estado só para o cartão e para a contagem: concluida, aprovacao ou producao. */
    public static function situacao(array $e): string
    {
        if (($e['entrega'] ?? '') === 'entregue') return 'concluida';
        if (($e['aprovacao'] ?? '') === 'aguardando') return 'aprovacao';
        return 'producao';
    }

    /**
     * Os números de um mês, por cliente. Só os clientes passados (a lista já
     * filtrada pela permissão de quem pergunta); vazio = nenhum.
     *
     * @param int[] $clienteIds
     * @return array{total:array, clientes:array<int, array>}
     */
    public static function resumo(int $accountId, string $mes, array $clienteIds, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $mes = substr($mes, 0, 7);
        $zero = ['contratadas' => 0, 'cadastradas' => 0, 'producao' => 0, 'aprovacao' => 0, 'concluidas' => 0, 'atrasadas' => 0, 'nao_cadastradas' => 0];
        $out = ['total' => $zero, 'clientes' => []];
        $clienteIds = array_values(array_unique(array_map('intval', $clienteIds)));
        if (!$clienteIds) return $out;
        foreach ($clienteIds as $c) $out['clientes'][$c] = $zero + ['por_tipo' => []];

        $in = implode(',', array_fill(0, count($clienteIds), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT e.cliente_id, e.tipo, e.producao, e.aprovacao, e.entrega, t.prazo
               FROM task_entregas_digital e JOIN tasks t ON t.id = e.task_id
              WHERE e.account_id = ? AND e.competencia = ? AND e.cliente_id IN ($in) AND t.status <> 'arquivada'"
        );
        $st->execute(array_merge([$accountId, $mes . '-01'], $clienteIds));
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $e) {
            $c = (int) $e['cliente_id'];
            $sit = self::situacao($e);
            $k = ['concluida' => 'concluidas', 'aprovacao' => 'aprovacao', 'producao' => 'producao'][$sit];
            $out['clientes'][$c]['cadastradas']++;
            $out['clientes'][$c][$k]++;
            if ($sit !== 'concluida' && $e['prazo'] && $e['prazo'] < $agora) $out['clientes'][$c]['atrasadas']++;
            $pt = &$out['clientes'][$c]['por_tipo'][$e['tipo']];
            $pt ??= ['previsto' => 0, 'cadastradas' => 0, 'entregues' => 0];
            $pt['cadastradas']++;
            if ($sit === 'concluida') $pt['entregues']++;
            unset($pt);
        }

        foreach (Frequencia::previstoNoMes($accountId, $mes, $clienteIds) as $c => $porTipo) {
            foreach ($porTipo as $tipo => $qtd) {
                $out['clientes'][$c]['contratadas'] += $qtd;
                $pt = &$out['clientes'][$c]['por_tipo'][$tipo];
                $pt ??= ['previsto' => 0, 'cadastradas' => 0, 'entregues' => 0];
                $pt['previsto'] += $qtd;
                unset($pt);
            }
        }

        foreach ($out['clientes'] as $c => &$r) {
            foreach ($r['por_tipo'] as $tipo => &$pt) {
                $pt['pendentes'] = max(0, $pt['previsto'] - $pt['entregues']);
                $pt['rotulo'] = self::TIPOS[$tipo] ?? $tipo;
                $r['nao_cadastradas'] += max(0, $pt['previsto'] - $pt['cadastradas']);
            }
            unset($pt);
            foreach ($zero as $k => $_) $out['total'][$k] += $r[$k];
        }
        unset($r);
        return $out;
    }
}
