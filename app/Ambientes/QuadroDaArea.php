<?php
namespace App\Ambientes;

use App\Core\Database;

/**
 * O quadro de Tarefas como quadro de uma área (20/10/2026). O mesmo kanban de
 * sempre (colunas configuráveis, cartão arrastável, checklist, comentário,
 * anexo, histórico) ganha três dados em `task_boards`:
 *
 *   area        software, digital, empresa ou comercial (NULL = quadro de sempre,
 *               que continua só em Tarefas)
 *   cliente_id  o cliente do quadro: o mesmo cadastro em todas as áreas
 *   modelo      como as colunas se organizam; ao criar, semeia colunas que a
 *               equipe renomeia, apaga e reordena como quiser (Trello)
 *
 * Quem vê e quem edita continua sendo `TaskBoard::acesso`; aqui só se filtra a
 * lista que a pessoa já podia ver.
 */
final class QuadroDaArea
{
    public const AREAS = ['software' => 'Software', 'digital' => 'Digital / Marketing', 'empresa' => 'Empresa', 'comercial' => 'Comercial'];

    /** Modelo => [rótulo, colunas iniciais (a última é a de concluído)]. */
    public const MODELOS = [
        'etapas'    => ['Etapas de produção', ['Briefing', 'Em produção', 'Aprovação do cliente', 'Ajustes', 'Entregue']],
        'episodios' => ['Episódios', ['Ideias', 'Episódio 1', 'Episódio 2', 'Episódio 3', 'Publicados']],
        'campanhas' => ['Campanhas', ['Planejamento', 'Criativos', 'Em veiculação', 'Otimização', 'Encerradas']],
        'demandas'  => ['Demandas de desenvolvimento', ['Backlog', 'Em desenvolvimento', 'Revisão', 'Homologação', 'Entregue']],
        'suporte'   => ['Suporte', ['Novos chamados', 'Em atendimento', 'Aguardando cliente', 'Resolvidos']],
        'livre'     => ['Colunas livres', ['A fazer', 'Fazendo', 'Feito']],
    ];

    /** @return array{area:?string, cliente_id:?int, modelo:?string} validado na conta */
    public static function validar(int $accountId, array $d): array
    {
        $area = isset($d['area']) && $d['area'] !== '' ? (string) $d['area'] : null;
        if ($area !== null && !isset(self::AREAS[$area])) throw new \InvalidArgumentException('Área inválida.');
        $modelo = isset($d['modelo']) && $d['modelo'] !== '' ? (string) $d['modelo'] : null;
        if ($modelo !== null && !isset(self::MODELOS[$modelo])) throw new \InvalidArgumentException('Modelo de quadro inválido.');
        $cliente = !empty($d['cliente_id']) ? (int) $d['cliente_id'] : null;
        if ($cliente) {
            $st = Database::getConnection()->prepare('SELECT id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
            $st->execute([$cliente, $accountId]);
            if (!$st->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado nesta conta.');
        }
        return ['area' => $area, 'cliente_id' => $cliente, 'modelo' => $modelo];
    }

    /** Grava área, cliente e modelo de um quadro da conta. */
    public static function definir(int $accountId, int $boardId, array $d): void
    {
        $v = self::validar($accountId, $d);
        Database::getConnection()->prepare('UPDATE task_boards SET area = ?, cliente_id = ?, modelo = ? WHERE id = ? AND account_id = ?')
            ->execute([$v['area'], $v['cliente_id'], $v['modelo'], $boardId, $accountId]);
    }

    /** Colunas iniciais do modelo, num quadro que ainda não tem nenhuma. @return int colunas criadas */
    public static function semearColunas(int $boardId, ?string $modelo): int
    {
        if ($modelo === null || !isset(self::MODELOS[$modelo])) return 0;
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT COUNT(*) FROM task_columns WHERE board_id = ?');
        $st->execute([$boardId]);
        if ((int) $st->fetchColumn() > 0) return 0;
        $nomes = self::MODELOS[$modelo][1];
        $ins = $pdo->prepare('INSERT INTO task_columns (board_id, nome, ordem, cor, is_coluna_inicial, is_coluna_concluido) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($nomes as $i => $nome) {
            $ins->execute([$boardId, $nome, $i + 1, '#64748b', $i === 0 ? 1 : 0, $i === count($nomes) - 1 ? 1 : 0]);
        }
        return count($nomes);
    }

    /**
     * Filtra a lista de quadros que a pessoa já pode ver (TaskBoard::findForUser).
     * Com área, só os daquela área; com cliente, só os daquele cliente.
     */
    public static function filtrar(array $quadros, ?string $area, ?int $clienteId = null): array
    {
        return array_values(array_filter($quadros, function ($b) use ($area, $clienteId) {
            if ($area !== null && ($b['area'] ?? null) !== $area) return false;
            if ($clienteId && (int) ($b['cliente_id'] ?? 0) !== $clienteId) return false;
            return true;
        }));
    }

    /** Nome do cliente de cada quadro, para o seletor mostrar "Cliente · Quadro". */
    public static function comNomeDoCliente(int $accountId, array $quadros): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn($b) => (int) ($b['cliente_id'] ?? 0), $quadros))));
        if (!$ids) return $quadros;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::getConnection()->prepare("SELECT id, nome FROM clientes WHERE account_id = ? AND id IN ($in)");
        $st->execute(array_merge([$accountId], $ids));
        $nomes = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($quadros as &$b) $b['cliente_nome'] = $nomes[(int) ($b['cliente_id'] ?? 0)] ?? null;
        return $quadros;
    }
}
