<?php
namespace App\Ambientes;

use App\Core\Database;
use App\Drive\Pasta;
use App\Orcamentos\Orcamento;
use App\Tarefas\Task;
use App\Tarefas\TaskBoard;
use App\Tarefas\TaskColumn;

/**
 * O quadro de cada área na ficha do cliente (21/10/2026), além do de entregas do
 * Digital (FichaDoCliente::producao):
 *
 *   Software   o quadro do projeto do cliente como no Trello: as colunas do
 *              próprio quadro (Backlog, Em desenvolvimento, Revisão, Homologação,
 *              Entregue), arrastar move a demanda, arquivo do Drive em cada uma
 *   Comercial  as propostas (orçamentos) do cliente pela situação: Rascunho,
 *              Aprovada, Negócio fechado, Recusada; arrastar segue as regras do
 *              orçamento (App\Orcamentos\Orcamento)
 *
 * e os dois relatórios em Excel. Quadros só os que a pessoa vê; mexer, só quem
 * edita. Proposta só da conta e do cliente (direto ou pelo lead dele).
 */
final class QuadrosDaFicha
{
    private static function in(array $ids): string { return implode(',', array_fill(0, count($ids), '?')); }

    // ── Software ──────────────────────────────────────────────────────────

    /**
     * Um quadro de Software do cliente com colunas e cartões (os ativos e os
     * concluídos nos últimos 30 dias). Sem $boardId, o primeiro.
     */
    public static function software(int $clienteId, array $quadrosVisiveis, ?int $boardId, callable $podeEditar, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $quadros = QuadroDaArea::filtrar($quadrosVisiveis, 'software', $clienteId);
        $out = ['quadros' => array_map(fn($b) => ['id' => (int) $b['id'], 'nome' => $b['nome'], 'modelo' => $b['modelo'] ?? null], $quadros), 'quadro' => null, 'colunas' => [], 'pode_editar' => false];
        if (!$quadros) return $out;
        $atual = null;
        foreach ($quadros as $b) if (!$boardId || (int) $b['id'] === $boardId) { $atual = $b; break; }
        $atual ??= $quadros[0];
        $bid = (int) $atual['id'];
        $out['quadro'] = ['id' => $bid, 'nome' => $atual['nome']];
        $out['pode_editar'] = (bool) $podeEditar($bid);
        $pdo = Database::getConnection();
        $cols = $pdo->prepare('SELECT id, nome, is_coluna_concluido FROM task_columns WHERE board_id = ? ORDER BY ordem, id');
        $cols->execute([$bid]);
        $st = $pdo->prepare("SELECT t.id, t.titulo, t.prazo, t.prioridade, t.column_id, t.status, t.concluida_em, t.created_at, u.nome AS responsavel
                               FROM tasks t LEFT JOIN users u ON u.id = t.responsavel_id
                              WHERE t.board_id = ? AND (t.status = 'ativa' OR (t.status = 'concluida' AND t.concluida_em >= ?))
                              ORDER BY t.ordem, t.id");
        $st->execute([$bid, date('Y-m-d H:i:s', strtotime($agora . ' -30 days'))]);
        $tarefas = $st->fetchAll(\PDO::FETCH_ASSOC);
        $arq = self::arquivos(array_map(fn($t) => (int) $t['id'], $tarefas));
        $porCol = [];
        $colunas = $cols->fetchAll(\PDO::FETCH_ASSOC);
        $ultima = $colunas ? (int) end($colunas)['id'] : 0;
        foreach ($tarefas as $t) {
            $col = $t['status'] === 'concluida' && $ultima ? $ultima : (int) $t['column_id'];
            $porCol[$col][] = ['id' => (int) $t['id'], 'titulo' => $t['titulo'], 'prazo' => $t['prazo'], 'prioridade' => $t['prioridade'], 'responsavel' => $t['responsavel'],
                'concluida_em' => $t['concluida_em'], 'criada_em' => $t['created_at'], 'arquivos' => $arq[(int) $t['id']] ?? [],
                'atrasada' => $t['status'] === 'ativa' && $t['prazo'] && $t['prazo'] < $agora && (int) $t['column_id'] !== $ultima];
        }
        foreach ($colunas as $c) {
            $out['colunas'][] = ['id' => (int) $c['id'], 'nome' => $c['nome'], 'concluido' => (bool) $c['is_coluna_concluido'], 'cartoes' => $porCol[(int) $c['id']] ?? []];
        }
        return $out;
    }

    /** Arquivos do Drive ligados a cada cartão. */
    private static function arquivos(array $taskIds): array
    {
        if (!$taskIds) return [];
        $st = Database::getConnection()->prepare('SELECT id, task_id, file_id, nome, mime, link FROM task_drive_arquivos WHERE task_id IN (' . self::in($taskIds) . ')
                                                    ORDER BY (mime LIKE \'image/%\' OR mime LIKE \'video/%\') DESC, id');
        $st->execute($taskIds);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $a) $out[(int) $a['task_id']][] = ['id' => (int) $a['id'], 'file_id' => $a['file_id'], 'nome' => $a['nome'], 'tipo' => Pasta::tipo((string) $a['mime']), 'link' => $a['link']];
        return $out;
    }

    /**
     * Demanda nova no quadro de Software do cliente (o escolhido, se a pessoa
     * edita; senão o primeiro que edita; sem nenhum, cria "Projeto · Cliente").
     */
    public static function novaDemanda(int $accountId, int $userId, int $clienteId, string $nomeCliente, array $d, array $quadrosVisiveis, callable $podeEditar): int
    {
        $titulo = trim((string) ($d['titulo'] ?? ''));
        if ($titulo === '') throw new \InvalidArgumentException('Descreva a demanda (ex.: Tela de relatórios).');
        if (mb_strlen($titulo) > 255) throw new \InvalidArgumentException('Texto longo demais.');
        $prazo = trim((string) ($d['prazo'] ?? ''));
        if ($prazo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo)) throw new \InvalidArgumentException('Prazo inválido.');
        $prio = in_array($d['prioridade'] ?? '', ['urgente', 'alta', 'media', 'baixa'], true) ? $d['prioridade'] : 'media';
        $resp = (int) ($d['responsavel_id'] ?? 0) ?: null;
        if ($resp) {
            $st = Database::getConnection()->prepare('SELECT id FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $st->execute([$resp, $accountId]);
            if (!$st->fetchColumn()) throw new \InvalidArgumentException('Responsável de outra conta.');
        }
        $quadros = QuadroDaArea::filtrar($quadrosVisiveis, 'software', $clienteId);
        $board = null;
        foreach ($quadros as $b) if ((int) $b['id'] === (int) ($d['board_id'] ?? 0) && $podeEditar((int) $b['id'])) $board = (int) $b['id'];
        if (!$board) foreach ($quadros as $b) if ($podeEditar((int) $b['id'])) { $board = (int) $b['id']; break; }
        if (!$board) {
            $board = TaskBoard::create(['nome' => mb_substr('Projeto · ' . $nomeCliente, 0, 150), 'tipo' => 'compartilhado', 'owner_id' => $userId, 'account_id' => $accountId, 'cor' => '#6366f1']);
            QuadroDaArea::definir($accountId, $board, ['area' => 'software', 'cliente_id' => $clienteId, 'modelo' => 'demandas']);
            QuadroDaArea::semearColunas($board, 'demandas');
        }
        $col = TaskColumn::initialColumn($board);
        return Task::create(['board_id' => $board, 'column_id' => (int) $col['id'], 'titulo' => $titulo, 'prazo' => $prazo !== '' ? $prazo . ' 18:00:00' : null,
            'criado_por_id' => $userId, 'responsavel_id' => $resp, 'prioridade' => $prio]);
    }

    /**
     * Arrastar a demanda para outra coluna do mesmo quadro. Entrar na coluna de
     * concluído grava quando foi entregue; sair dela apaga.
     * @param array{task:array, board:array} $cartao (Entrega::cartaoEditavel)
     */
    public static function moverDemanda(array $cartao, int $userId, int $columnId): void
    {
        $col = TaskColumn::findById($columnId);
        if (!$col || (int) $col['board_id'] !== (int) $cartao['board']['id']) throw new \InvalidArgumentException('Coluna de outro quadro.');
        $tid = (int) $cartao['task']['id'];
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM tasks WHERE column_id = ?');
        $st->execute([$columnId]);
        Task::move($tid, $columnId, (int) $st->fetchColumn(), $userId);
        if (!empty($col['is_coluna_concluido'])) $pdo->prepare('UPDATE tasks SET concluida_em = COALESCE(concluida_em, NOW()) WHERE id = ?')->execute([$tid]);
        elseif ($cartao['task']['status'] === 'ativa') $pdo->prepare('UPDATE tasks SET concluida_em = NULL WHERE id = ? AND status = \'ativa\'')->execute([$tid]);
        try { \App\Tarefas\TaskAudit::onTaskMoved($tid, $col['nome'] ?? null); } catch (\Throwable $e) { /* histórico processual não vale para Software */ }
    }

    /** Demandas dos quadros de Software do cliente que estiveram em jogo no período (relatório). */
    public static function demandasDoPeriodo(int $clienteId, array $quadrosVisiveis, string $de, string $ate): array
    {
        $quadros = QuadroDaArea::filtrar($quadrosVisiveis, 'software', $clienteId);
        if (!$quadros) return [];
        $ids = array_map(fn($b) => (int) $b['id'], $quadros);
        $ini = $de . '-01 00:00:00'; $fim = date('Y-m-t 23:59:59', strtotime($ate . '-01'));
        $st = Database::getConnection()->prepare("SELECT t.id, t.titulo, t.prazo, t.prioridade, t.status, t.created_at, t.concluida_em, b.nome AS quadro, c.nome AS etapa, c.is_coluna_concluido, u.nome AS responsavel
                                                    FROM tasks t JOIN task_boards b ON b.id = t.board_id LEFT JOIN task_columns c ON c.id = t.column_id LEFT JOIN users u ON u.id = t.responsavel_id
                                                   WHERE t.board_id IN (" . self::in($ids) . ") AND t.status <> 'arquivada' AND t.created_at <= ?
                                                     AND (t.concluida_em IS NULL OR t.concluida_em >= ?)
                                                   ORDER BY b.nome, c.ordem, t.ordem, t.id");
        $st->execute(array_merge($ids, [$fim, $ini]));
        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        $arq = self::arquivos(array_map(fn($t) => (int) $t['id'], $linhas));
        return array_map(fn($t) => $t + ['arquivos' => $arq[(int) $t['id']] ?? []], $linhas);
    }

    // ── Comercial: propostas ──────────────────────────────────────────────

    public const SITUACOES = ['RASCUNHO' => 'Rascunho', 'APROVADO' => 'Aprovada', 'CONVERTIDO' => 'Negócio fechado', 'REJEITADO' => 'Recusada'];

    /** As propostas do cliente: as ligadas a ele e as dos leads que viraram ele. */
    public static function propostas(int $accountId, int $clienteId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT o.id, o.numero, o.status, o.created_at, o.aprovado_em, o.convertido_em, o.card_id, o.canal, u.nome AS vendedor,
                    (SELECT COALESCE(SUM(ROUND(i.quantidade * i.valor_unitario - i.desconto, 2)), 0) FROM crm_orcamento_itens i WHERE i.orcamento_id = o.id AND i.account_id = o.account_id) AS total,
                    (SELECT GROUP_CONCAT(i.descricao ORDER BY i.id SEPARATOR ' | ') FROM crm_orcamento_itens i WHERE i.orcamento_id = o.id AND i.account_id = o.account_id) AS itens
               FROM crm_orcamentos o LEFT JOIN users u ON u.id = o.vendedor_id
              WHERE o.account_id = ? AND (o.cliente_id = ? OR o.card_id IN (SELECT c.id FROM cards c WHERE c.account_id = ? AND c.cliente_id = ? AND c.deleted_at IS NULL))
              ORDER BY o.created_at DESC, o.id DESC LIMIT 300");
        $st->execute([$accountId, $clienteId, $accountId, $clienteId]);
        return array_map(fn($o) => ['id' => (int) $o['id'], 'numero' => (int) $o['numero'], 'status' => $o['status'], 'situacao' => self::SITUACOES[$o['status']] ?? $o['status'],
            'total' => round((float) $o['total'], 2), 'itens' => (string) ($o['itens'] ?? ''), 'vendedor' => $o['vendedor'], 'canal' => $o['canal'],
            'criada_em' => $o['created_at'], 'aprovada_em' => $o['aprovado_em'], 'fechada_em' => $o['convertido_em'], 'lead_id' => $o['card_id'] ? (int) $o['card_id'] : null],
            $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Arrastar a proposta para outra situação, pelo caminho que o orçamento
     * permite (rascunho, aprovada, negócio fechado; recusada; reabrir).
     */
    public static function moverProposta(int $accountId, int $userId, int $clienteId, int $id, string $para): void
    {
        $atual = null;
        foreach (self::propostas($accountId, $clienteId) as $p) if ($p['id'] === $id) $atual = $p;
        if (!$atual) throw new \InvalidArgumentException('Proposta não encontrada neste cliente.');
        $de = $atual['status'];
        if ($de === $para) return;
        if ($de === 'CONVERTIDO') throw new \InvalidArgumentException('Negócio fechado não muda de situação.');
        switch ($para) {
            case 'RASCUNHO':
                if ($de !== 'REJEITADO') throw new \InvalidArgumentException('Para voltar a rascunho, edite a proposta (editar volta para rascunho).');
                Orcamento::reabrir($accountId, $id);
                return;
            case 'APROVADO':
                if ($de === 'REJEITADO') Orcamento::reabrir($accountId, $id);
                Orcamento::aprovar($accountId, $id, $userId);
                return;
            case 'CONVERTIDO':
                if ($de === 'REJEITADO') Orcamento::reabrir($accountId, $id);
                if ($de !== 'APROVADO') Orcamento::aprovar($accountId, $id, $userId);
                Orcamento::converter($accountId, $id, $userId);
                return;
            case 'REJEITADO':
                Orcamento::rejeitar($accountId, $id);
                return;
        }
        throw new \InvalidArgumentException('Situação inválida.');
    }

    // ── Relatórios em Excel ───────────────────────────────────────────────

    private static function dh(?string $v): string { return $v ? date('d/m/Y H:i', strtotime($v)) : ''; }
    private static function d(?string $v): string { return $v ? date('d/m/Y', strtotime($v)) : ''; }
    private static function per(string $de, string $ate): string { $m = fn($x) => substr($x, 5, 2) . '/' . substr($x, 0, 4); return $m($de) . ($ate !== $de ? ' a ' . $m($ate) : ''); }

    public static function abasSoftware(string $cliente, string $de, string $ate, array $demandas, string $quem): array
    {
        $PRIO = ['urgente' => 'Urgente', 'alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa'];
        $entregues = 0;
        $linhas = [[['titulo' => 'Relatório de demandas · ' . $cliente]], ['Período: ' . self::per($de, $ate) . ' · gerado em ' . date('d/m/Y H:i') . ($quem ? ' por ' . $quem : '')], [], [],
            ['Demanda', 'Quadro', 'Etapa', 'Prioridade', 'Responsável', 'Prazo', 'Criada em', 'Entregue em', 'Situação', 'Arquivo do Drive', 'Outros arquivos']];
        foreach ($demandas as $t) {
            $feita = $t['status'] === 'concluida' || (!empty($t['is_coluna_concluido']) && $t['concluida_em']);
            if ($feita) $entregues++;
            $atrasada = !$feita && $t['prazo'] && $t['prazo'] < date('Y-m-d H:i:s');
            $a = $t['arquivos'][0] ?? null;
            $linhas[] = [(string) $t['titulo'], (string) $t['quadro'], (string) ($t['etapa'] ?? ''), $PRIO[$t['prioridade']] ?? (string) $t['prioridade'], (string) ($t['responsavel'] ?? ''),
                self::d($t['prazo']), self::dh($t['created_at']), $feita ? self::dh($t['concluida_em']) : '', $feita ? 'Entregue' : ($atrasada ? 'Atrasada' : 'Em andamento'),
                $a ? ($a['link'] ? ['link' => $a['link'], 'texto' => $a['nome']] : $a['nome']) : null,
                count($t['arquivos']) > 1 ? implode(' | ', array_map(fn($x) => $x['nome'] . ($x['link'] ? ' (' . $x['link'] . ')' : ''), array_slice($t['arquivos'], 1))) : null];
        }
        $linhas[2] = ['Demandas: ' . count($demandas) . ' · Entregues: ' . $entregues . ' · Em andamento: ' . (count($demandas) - $entregues)];
        if (!$demandas) $linhas[] = ['Nenhuma demanda no período.'];
        return [['nome' => 'Demandas', 'cabecalho' => 5, 'larguras' => [40, 22, 18, 11, 18, 11, 16, 16, 14, 36, 30], 'linhas' => $linhas]];
    }

    public static function abasPropostas(string $cliente, array $propostas, string $quem): array
    {
        $tot = ['CONVERTIDO' => 0.0, 'APROVADO' => 0.0, 'RASCUNHO' => 0.0];
        foreach ($propostas as $p) if (isset($tot[$p['status']])) $tot[$p['status']] += $p['total'];
        $brl = fn($v) => 'R$ ' . number_format($v, 2, ',', '.');
        $linhas = [[['titulo' => 'Propostas · ' . $cliente]], ['Gerado em ' . date('d/m/Y H:i') . ($quem ? ' por ' . $quem : '')],
            ['Negócio fechado: ' . $brl($tot['CONVERTIDO']) . ' · Aprovadas: ' . $brl($tot['APROVADO']) . ' · Em rascunho: ' . $brl($tot['RASCUNHO']) . ' · Propostas: ' . count($propostas)], [],
            ['Nº', 'Criada em', 'Situação', 'Total (R$)', 'Itens', 'Vendedor', 'Canal', 'Aprovada em', 'Negócio fechado em']];
        foreach ($propostas as $p) {
            $linhas[] = [$p['numero'], self::dh($p['criada_em']), $p['situacao'], $p['total'], $p['itens'], (string) ($p['vendedor'] ?? ''), (string) ($p['canal'] ?? ''), self::dh($p['aprovada_em']), self::dh($p['fechada_em'])];
        }
        if (!$propostas) $linhas[] = ['Nenhuma proposta deste cliente.'];
        return [['nome' => 'Propostas', 'cabecalho' => 5, 'larguras' => [7, 16, 16, 12, 50, 20, 16, 16, 18], 'linhas' => $linhas]];
    }
}
