<?php
namespace App\Services\AiIntake;

require_once __DIR__ . '/../../Models/Database.php';
require_once __DIR__ . '/../../Models/Contato.php';
require_once __DIR__ . '/../../Models/Card.php';
require_once __DIR__ . '/../../Models/AccountNotification.php';

use App\Models\Contato;
use App\Models\Card;
use App\Models\AccountNotification;

/**
 * HandoffService — encaminha o lead qualificado para humano, reaproveitando os modulos
 * existentes: cria/atualiza CONTATO (dedupe por telefone), cria CARD em Prospeccao
 * (Card::create) e, se configurado, TAREFA (Task::create) + NOTIFICACAO ao responsavel.
 *
 * IDEMPOTENTE por sessao: se a sessao ja tem prospect_id/task_id, reaproveita e nao duplica
 * (atende os criterios "card de prospeccao nao duplica" e "tarefa nao duplica").
 *
 * NAO usa a Evolution; apenas grava no Yuris. O resumo deixa claro que e classificacao
 * PRELIMINAR, sem parecer juridico (LGPD/seguranca).
 */
final class HandoffService
{
    public static function run(\PDO $pdo, array $cfg, array $p): array
    {
        $accountId = (int)$cfg['account_id'];
        $sessionId = (int)$p['session_id'];
        $remoteJid = (string)$p['remote_jid'];
        $collected = is_array($p['collected'] ?? null) ? $p['collected'] : [];
        $structured = is_array($p['structured'] ?? null) ? $p['structured'] : [];
        $reason    = (string)($p['reason'] ?? 'qualified');
        $hcfg      = self::jdec($cfg['handoff_config_json'] ?? null);

        $phone = self::phoneFromJid($remoteJid);
        $nome  = trim((string)($collected['name'] ?? '')) ?: 'Contato WhatsApp';
        $area  = $structured['primary_practice_area'] ?? ($collected['primary_area'] ?? null);
        $urg   = $structured['urgency_level'] ?? 'normal';
        $summaryTxt = self::buildSummary($nome, $phone, $area, $structured, $collected);

        // Responsavel: handoff_config.default_user_id OU responsavel da area OU null
        $userId = $hcfg['default_user_id'] ?? null;
        if (!$userId && $area) {
            try {
                $st = $pdo->prepare("SELECT responsible_user_id FROM ai_intake_areas WHERE agent_id=? AND code=? AND enabled=1 LIMIT 1");
                $st->execute([(int)$cfg['id'], $area]);
                $userId = $st->fetchColumn() ?: null;
            } catch (\Throwable $_) {}
        }
        $userId = $userId ? (int)$userId : null;

        // ── Contato (dedupe por telefone) ──
        $contatoId = $p['contact_id'] ?? null;
        if (!$contatoId && $phone) {
            try { $contatoId = Contato::findOrCreateByPhone($nome, $phone); } catch (\Throwable $_) {}
        }

        // ── Card de Prospeccao (idempotente) ──
        $prospectId = $p['existing_prospect_id'] ? (int)$p['existing_prospect_id'] : null;
        $createCard = ($hcfg['create_card'] ?? true) !== false;
        if (!$prospectId && $createCard) {
            try {
                $colunaId = $hcfg['column_id'] ?? self::firstPipelineColumn($pdo, $accountId);
                $prospectId = Card::create([
                    'account_id'        => $accountId,
                    'cliente_nome'      => $nome,
                    'titulo'            => $nome . ($area ? " ({$area})" : ''),
                    'telefone_whatsapp' => $phone ?: null,
                    'cidade'            => $collected['city'] ?? null,
                    'uf'                => $collected['state'] ?? null,
                    'coluna_id'         => $colunaId,
                    'responsavel_user_id' => $userId,
                    'descricao'         => $summaryTxt,
                    'status'            => 'aberto',
                ]);
                $prospectId = $prospectId ? (int)$prospectId : null;
            } catch (\Throwable $e) {
                error_log('[ai_intake/handoff] card falhou: ' . $e->getMessage());
            }
        }

        // ── Tarefa (opt-in: precisa de board configurado) ──
        $taskId = $p['existing_task_id'] ? (int)$p['existing_task_id'] : null;
        $boardId = $hcfg['board_id'] ?? null;
        if (!$taskId && ($hcfg['create_task'] ?? false) && $boardId) {
            try {
                require_once __DIR__ . '/../../Models/Task.php';
                require_once __DIR__ . '/../../Models/TaskColumn.php';
                $col = \App\Models\TaskColumn::initialColumn((int)$boardId);
                $colId = $col['id'] ?? null;
                if ($colId) {
                    $prio = $urg === 'critical' ? 'alta' : ($urg === 'high' ? 'alta' : ($hcfg['priority'] ?? 'media'));
                    $taskId = \App\Models\Task::create([
                        'board_id'       => (int)$boardId,
                        'column_id'      => (int)$colId,
                        'titulo'         => 'Pre-atendimento: ' . $nome . ($area ? " ({$area})" : ''),
                        'descricao'      => $summaryTxt,
                        'prioridade'     => $prio,
                        'responsavel_id' => $userId,
                        'criado_por_id'  => $userId ?: ($cfg['user_id'] ?? null) ?: 0,
                    ]);
                    $taskId = $taskId ? (int)$taskId : null;
                }
            } catch (\Throwable $e) {
                error_log('[ai_intake/handoff] tarefa falhou: ' . $e->getMessage());
            }
        }

        // ── Notificacao ao responsavel (ou broadcast da conta) ──
        try {
            $titulo = ($urg === 'critical' ? '[URGENTE] ' : '') . 'Novo lead do pre-atendimento';
            AccountNotification::criar([
                'account_id' => $accountId,
                'user_id'    => $userId, // null = broadcast pra conta
                'tipo'       => 'ai_intake.handoff',
                'titulo'     => $titulo,
                'mensagem'   => $summaryTxt,
                'payload'    => ['session_id' => $sessionId, 'prospect_id' => $prospectId, 'task_id' => $taskId,
                                 'area' => $area, 'urgency' => $urg, 'reason' => $reason],
            ]);
        } catch (\Throwable $e) {
            error_log('[ai_intake/handoff] notificacao falhou: ' . $e->getMessage());
        }

        // ── Registro do handoff (uma vez por sessao) ──
        if (!$p['existing_prospect_id']) {
            try {
                $st = $pdo->prepare(
                    "INSERT INTO ai_intake_handoffs (account_id, session_id, reason, urgency, user_id, team_id, prospect_id, task_id, status, created_at)
                     VALUES (?,?,?,?,?,?,?,?, 'pending', NOW())"
                );
                $st->execute([$accountId, $sessionId, $reason, $urg, $userId, $hcfg['default_team_id'] ?? null, $prospectId, $taskId]);
            } catch (\Throwable $e) {
                error_log('[ai_intake/handoff] registro falhou: ' . $e->getMessage());
            }
        }

        // ── Auditoria (LGPD) ──
        try {
            require_once __DIR__ . '/../../Models/Account.php';
            \App\Models\Account::audit($accountId, 'ai_intake.handoff', [
                'entidade' => 'ai_intake_session', 'entidade_id' => $sessionId,
                'detalhes' => ['area' => $area, 'urgency' => $urg, 'reason' => $reason,
                               'prospect_id' => $prospectId, 'task_id' => $taskId],
            ]);
        } catch (\Throwable $_) {}

        return ['prospect_id' => $prospectId, 'task_id' => $taskId, 'user_id' => $userId, 'contato_id' => $contatoId];
    }

    private static function buildSummary(string $nome, ?string $phone, ?string $area, array $s, array $c): string
    {
        $L = [];
        $L[] = 'Lead recebido pelo pre-atendimento automatico (classificacao PRELIMINAR, sem parecer juridico).';
        $L[] = 'Nome: ' . $nome . ($phone ? ' | Tel: ' . $phone : '');
        if ($area) $L[] = 'Area provavel: ' . $area
            . (!empty($s['secondary_practice_areas']) ? ' | Secundarias: ' . implode(', ', $s['secondary_practice_areas']) : '');
        if (!empty($c['main_report'])) $L[] = 'Relato: ' . $c['main_report'];
        if (!empty($c['city'])) $L[] = 'Cidade: ' . $c['city'] . (!empty($c['state']) ? '/' . $c['state'] : '');
        if (!empty($c['event_date'])) $L[] = 'Quando: ' . $c['event_date'];
        if (!empty($c['has_existing_case'])) $L[] = 'Possui processo: sim' . (!empty($c['case_number']) ? ' (' . $c['case_number'] . ')' : '');
        if (!empty($c['has_deadline'])) $L[] = 'Possui prazo/intimacao: sim' . (!empty($c['deadline_description']) ? ' (' . $c['deadline_description'] . ')' : '');
        if (!empty($c['has_hearing'])) $L[] = 'Audiencia: sim' . (!empty($c['hearing_date']) ? ' (' . $c['hearing_date'] . ')' : '');
        if (!empty($c['mentioned_documents'])) $L[] = 'Documentos citados: ' . implode(', ', $c['mentioned_documents']);
        $L[] = 'Urgencia: ' . ($s['urgency_level'] ?? 'normal')
            . (!empty($s['urgency_reasons']) ? ' (' . implode(', ', $s['urgency_reasons']) . ')' : '');
        return implode("\n", $L);
    }

    private static function firstPipelineColumn(\PDO $pdo, int $accountId): ?int
    {
        foreach (['SELECT id FROM pipeline_columns WHERE account_id = ? ORDER BY ordem ASC, id ASC LIMIT 1',
                  'SELECT id FROM pipeline_columns WHERE account_id = ? ORDER BY id ASC LIMIT 1'] as $sql) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute([$accountId]);
                $id = $st->fetchColumn();
                if ($id) return (int)$id;
            } catch (\Throwable $_) {}
        }
        return null;
    }

    private static function phoneFromJid(string $jid): ?string
    {
        if ($jid === '' || str_ends_with($jid, '@lid') || str_ends_with($jid, '@g.us') || str_ends_with($jid, '@broadcast')) {
            return null;
        }
        $num = explode('@', $jid)[0];
        $digits = preg_replace('/[^0-9]/', '', $num);
        return $digits ?: null;
    }

    private static function jdec($v): array
    {
        if (is_array($v)) return $v;
        if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : []; }
        return [];
    }
}
