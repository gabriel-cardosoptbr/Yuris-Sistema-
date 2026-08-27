<?php
namespace App\Tarefas;

use App\Core\Database;
use App\Processos\ProcessoAudit;

/**
 * TaskAudit — propaga eventos de Tarefa Kanban para o histórico do processo vinculado.
 *
 * Quando uma tarefa Kanban está vinculada a um ou mais processos (via task_links onde
 * link_type='processo'), qualquer ação relevante (criar/editar/concluir/excluir,
 * adicionar comentário/anexo/checklist, gerar instância recorrente) deve aparecer
 * no histórico processual desses processos.
 *
 * Uso:
 *   TaskAudit::onTaskAction($taskId, 'Tarefa concluída', 'Tarefa "X" marcada como concluída');
 *   TaskAudit::onCommentAdded($taskId, 'Nova observação adicionada');
 *
 * Comportamento:
 * - Se a tarefa não tem vínculo com processo, não faz nada (silencioso).
 * - Se tem múltiplos processos vinculados, registra em TODOS.
 * - Reutiliza ProcessoAudit::log para garantir consistência de formato.
 * - Inclui o título da tarefa na descrição automaticamente.
 */
class TaskAudit
{
    /**
     * Registra um evento genérico no histórico de cada processo vinculado à tarefa.
     */
    public static function onTaskAction(int $taskId, string $acao, string $descricao = ''): void
    {
        $processos = self::findLinkedProcessos($taskId);
        if (empty($processos)) return;

        $titulo = self::getTaskTitulo($taskId);
        $desc   = $descricao !== '' ? $descricao : ($titulo ? "Tarefa \"{$titulo}\"" : '');

        foreach ($processos as $procId) {
            ProcessoAudit::log($procId, $acao, $desc);
        }
    }

    /** Atalho: tarefa foi criada. */
    public static function onTaskCreated(int $taskId): void
    {
        $titulo = self::getTaskTitulo($taskId);
        self::onTaskAction($taskId, 'Tarefa vinculada criada', "Tarefa \"{$titulo}\" criada e vinculada ao processo");
    }

    /** Atalho: tarefa foi atualizada (PUT). */
    public static function onTaskUpdated(int $taskId, array $changes = []): void
    {
        $titulo = self::getTaskTitulo($taskId);
        // Label PT-BR amigável + resolução de IDs para nomes (humanizeFieldValue).
        // IDs jamais devem aparecer pro usuário final — sempre resolve pra nome.
        $labels = [
            'titulo'         => 'Título',
            'descricao'      => 'Descrição',
            'prioridade'     => 'Prioridade',
            'prazo'          => 'Prazo',
            'status'         => 'Status',
            'responsavel_id' => 'Responsável',
            'column_id'      => 'Coluna',
            'board_id'       => 'Quadro',
        ];
        $bits = [];
        foreach ($changes as $field => $pair) {
            if (!is_array($pair) || count($pair) < 2) continue;
            [$old, $new] = $pair;
            if ($old === $new) continue;
            $label  = $labels[$field] ?? $field;
            $oldEmpty = ($old === '' || $old === null);
            $newEmpty = ($new === '' || $new === null);
            $oldStr = $oldEmpty ? null : ProcessoAudit::humanizeFieldValue($field, (string)$old);
            $newStr = $newEmpty ? null : ProcessoAudit::humanizeFieldValue($field, (string)$new);
            // Frase natural em PT: definido/removido/alterado.
            if ($oldEmpty && !$newEmpty) {
                $bits[] = "{$label} definido como: {$newStr}";
            } elseif (!$oldEmpty && $newEmpty) {
                $bits[] = "{$label} removido (era: {$oldStr})";
            } else {
                $bits[] = "{$label}: {$oldStr} → {$newStr}";
            }
        }
        $detail = $bits ? ' | ' . implode(' · ', $bits) : '';
        self::onTaskAction($taskId, 'Tarefa vinculada atualizada', "Tarefa \"{$titulo}\" alterada{$detail}");
    }

    /** Atalho: tarefa foi concluída. */
    public static function onTaskCompleted(int $taskId): void
    {
        $titulo = self::getTaskTitulo($taskId);
        self::onTaskAction($taskId, 'Tarefa vinculada concluída', "Tarefa \"{$titulo}\" marcada como concluída");
    }

    /** Atalho: tarefa foi movida de coluna. */
    public static function onTaskMoved(int $taskId, ?string $colunaDestinoNome = null): void
    {
        $titulo = self::getTaskTitulo($taskId);
        $col    = $colunaDestinoNome ? " para \"{$colunaDestinoNome}\"" : '';
        self::onTaskAction($taskId, 'Tarefa vinculada movida', "Tarefa \"{$titulo}\" movida{$col}");
    }

    /** Atalho: tarefa foi arquivada/deletada. */
    public static function onTaskArchived(int $taskId): void
    {
        $titulo = self::getTaskTitulo($taskId);
        self::onTaskAction($taskId, 'Tarefa vinculada removida', "Tarefa \"{$titulo}\" arquivada");
    }

    /** Atalho: comentário foi adicionado à tarefa. */
    public static function onCommentAdded(int $taskId, string $trechoComentario = ''): void
    {
        $titulo = self::getTaskTitulo($taskId);
        $snippet = $trechoComentario !== '' ? ': "' . mb_substr($trechoComentario, 0, 120) . '"' : '';
        self::onTaskAction($taskId, 'Comentário em tarefa', "Comentário adicionado à tarefa \"{$titulo}\"{$snippet}");
    }

    /** Atalho: comentário foi removido. */
    public static function onCommentRemoved(int $taskId): void
    {
        $titulo = self::getTaskTitulo($taskId);
        self::onTaskAction($taskId, 'Comentário removido', "Comentário removido da tarefa \"{$titulo}\"");
    }

    /** Atalho: anexo foi adicionado. */
    public static function onAttachmentAdded(int $taskId, string $nomeArquivo = ''): void
    {
        $titulo = self::getTaskTitulo($taskId);
        $arq    = $nomeArquivo ? " ({$nomeArquivo})" : '';
        self::onTaskAction($taskId, 'Anexo em tarefa', "Anexo adicionado à tarefa \"{$titulo}\"{$arq}");
    }

    /** Atalho: anexo foi removido. */
    public static function onAttachmentRemoved(int $taskId, string $nomeArquivo = ''): void
    {
        $titulo = self::getTaskTitulo($taskId);
        $arq    = $nomeArquivo ? " ({$nomeArquivo})" : '';
        self::onTaskAction($taskId, 'Anexo removido', "Anexo removido da tarefa \"{$titulo}\"{$arq}");
    }

    /** Atalho: item de checklist criado/atualizado/deletado. */
    public static function onChecklistChange(int $taskId, string $acao, string $itemTexto = ''): void
    {
        $titulo = self::getTaskTitulo($taskId);
        $item   = $itemTexto ? ": \"{$itemTexto}\"" : '';
        self::onTaskAction($taskId, "Checklist: {$acao}", "Checklist da tarefa \"{$titulo}\" — {$acao}{$item}");
    }

    /** Atalho: vínculo entre tarefa e processo foi criado. */
    public static function onLinkCreated(int $taskId, string $linkType, int $linkId): void
    {
        if ($linkType !== 'processo') return;
        $titulo = self::getTaskTitulo($taskId);
        ProcessoAudit::log($linkId, 'Tarefa vinculada', "Tarefa \"{$titulo}\" foi vinculada a este processo");
    }

    /** Atalho: vínculo entre tarefa e processo foi removido. */
    public static function onLinkRemoved(int $taskId, string $linkType, int $linkId): void
    {
        if ($linkType !== 'processo') return;
        $titulo = self::getTaskTitulo($taskId);
        ProcessoAudit::log($linkId, 'Vínculo removido', "Tarefa \"{$titulo}\" foi desvinculada deste processo");
    }

    /** Atalho: instância de tarefa recorrente foi gerada. */
    public static function onRecurringInstance(int $taskId): void
    {
        $titulo = self::getTaskTitulo($taskId);
        self::onTaskAction($taskId, 'Tarefa recorrente gerada', "Nova instância da tarefa \"{$titulo}\" foi gerada automaticamente");
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers internos
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Retorna IDs dos processos vinculados a uma tarefa.
     */
    private static function findLinkedProcessos(int $taskId): array
    {
        if ($taskId <= 0) return [];
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT DISTINCT link_id FROM task_links
                 WHERE task_id = :tid AND link_type = 'processo'"
            );
            $stmt->execute(['tid' => $taskId]);
            return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            error_log('TaskAudit::findLinkedProcessos error: ' . $e->getMessage());
            return [];
        }
    }

    /** Retorna o título da tarefa (ou string vazia se não achar). */
    private static function getTaskTitulo(int $taskId): string
    {
        if ($taskId <= 0) return '';
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare('SELECT titulo FROM tasks WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $taskId]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
