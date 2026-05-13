<?php
namespace App\Services;

use App\Models\Database;

class WebhookDispatcher
{
    // ── Event catalog ─────────────────────────────────────────────────────────
    public static function catalog(): array
    {
        return [
            'prospeccao_clientes' => [
                'label' => 'Prospecção — Clientes',
                'icon'  => 'user',
                'events' => [
                    'cliente.created'              => 'Cliente criado',
                    'cliente.updated'              => 'Cliente atualizado',
                    'cliente.deleted'              => 'Cliente excluído',
                    'cliente.converted_to_processo'=> 'Cliente convertido em processo',
                ],
            ],
            'prospeccao_cards' => [
                'label' => 'Prospecção — Cards',
                'icon'  => 'card',
                'events' => [
                    'card.created'                  => 'Card criado',
                    'card.updated'                  => 'Card atualizado',
                    'card.deleted'                  => 'Card excluído',
                    'card.stage_changed'            => 'Card mudou de etapa (funil)',
                    'card.responsavel_changed'      => 'Responsável do card alterado',
                    'card.tag_added'                => 'Tag adicionada ao card',
                    'card.comment_added'            => 'Comentário adicionado ao card',
                    'card.file_uploaded'            => 'Arquivo enviado ao card',
                    'card.followup_created'         => 'Follow-up criado',
                    'card.followup_completed'       => 'Follow-up concluído',
                    'card.dados_pessoais.updated'   => 'Aba Dados Pessoais atualizada',
                    'card.atendimento.updated'      => 'Aba Atendimento atualizada',
                    'card.qualificacao.updated'     => 'Aba Qualificação atualizada',
                    'card.documentos.updated'       => 'Aba Documentos atualizada',
                    'card.observacoes.updated'      => 'Aba Observações atualizada',
                    'card.historico.updated'        => 'Aba Histórico atualizada',
                    'card.financeiro.updated'       => 'Aba Financeiro atualizada',
                    'card.contrato.updated'         => 'Aba Contrato atualizada',
                ],
            ],
            'processos' => [
                'label' => 'Processos / Jurídico',
                'icon'  => 'scale',
                'events' => [
                    'processo.created'                      => 'Processo criado',
                    'processo.updated'                      => 'Processo atualizado',
                    'processo.deleted'                      => 'Processo excluído',
                    'processo.status_changed'               => 'Status do processo alterado',
                    'processo.responsavel_changed'          => 'Responsável do processo alterado',
                    'processo.etapa_changed'                => 'Etapa processual alterada',
                    'processo.prazo_created'                => 'Prazo criado',
                    'processo.prazo_updated'                => 'Prazo atualizado',
                    'processo.prazo_completed'              => 'Prazo concluído',
                    'processo.prazo_vencendo'               => 'Prazo prestes a vencer (≤ 3 dias)',
                    'processo.tarefa_created'               => 'Tarefa criada',
                    'processo.tarefa_completed'             => 'Tarefa concluída',
                    'processo.tarefa_atrasada'              => 'Tarefa em atraso',
                    'processo.andamento_added'              => 'Andamento processual adicionado',
                    'processo.documento_uploaded'           => 'Documento enviado ao processo',
                    'processo.audiencia_created'            => 'Audiência criada',
                    'processo.audiencia_updated'            => 'Audiência atualizada',
                    'processo.audiencia_realizada'          => 'Audiência marcada como realizada',
                    'processo.dados_gerais.updated'         => 'Aba Dados Gerais atualizada',
                    'processo.partes.updated'               => 'Aba Partes atualizada',
                    'processo.andamento_processual.updated' => 'Aba Andamento Processual atualizada',
                    'processo.prazos.updated'               => 'Aba Prazos atualizada',
                    'processo.tarefas.updated'              => 'Aba Tarefas atualizada',
                    'processo.documentos.updated'           => 'Aba Documentos atualizada',
                    'processo.audiencias.updated'           => 'Aba Audiências atualizada',
                    'processo.financeiro.updated'           => 'Aba Financeiro atualizada',
                    'processo.honorarios.updated'           => 'Aba Honorários atualizada',
                    'processo.observacoes.updated'          => 'Aba Observações atualizada',
                    'processo.historico.updated'            => 'Aba Histórico atualizada',
                ],
            ],
            'tarefas' => [
                'label' => 'Tarefas',
                'icon'  => 'checklist',
                'events' => [
                    'task.created'    => 'Tarefa criada',
                    'task.updated'    => 'Tarefa atualizada',
                    'task.completed'  => 'Tarefa concluída',
                    'task.archived'   => 'Tarefa arquivada',
                    'task.due_soon'   => 'Tarefa vencendo em breve (≤ 24h)',
                    'task.overdue'    => 'Tarefa em atraso',
                ],
            ],
            'financeiro' => [
                'label' => 'Financeiro',
                'icon'  => 'money',
                'events' => [
                    'financeiro.receita_created' => 'Receita criada',
                    'financeiro.despesa_created' => 'Despesa criada',
                    'financeiro.updated'         => 'Lançamento atualizado',
                    'financeiro.status_changed'  => 'Status do lançamento alterado',
                    'financeiro.paid'            => 'Lançamento pago / recebido',
                    'financeiro.overdue'         => 'Lançamento em atraso',
                    'financeiro.deleted'         => 'Lançamento excluído',
                    'financeiro.parcela_created' => 'Parcela criada',
                    'financeiro.parcela_paid'    => 'Parcela paga',
                    'financeiro.relatorio_dre'   => 'DRE gerado',
                ],
            ],
            'usuarios' => [
                'label' => 'Usuários / Equipe',
                'icon'  => 'users',
                'events' => [
                    'usuario.created'           => 'Usuário criado',
                    'usuario.updated'           => 'Usuário atualizado',
                    'usuario.deleted'           => 'Usuário excluído',
                    'usuario.permission_changed'=> 'Permissões alteradas',
                    'usuario.mentioned'         => 'Usuário mencionado',
                    'usuario.login'             => 'Login realizado',
                    'usuario.senha_changed'     => 'Senha alterada',
                ],
            ],
            'sistema' => [
                'label' => 'Sistema',
                'icon'  => 'system',
                'events' => [
                    'arquivo.uploaded'      => 'Arquivo enviado ao sistema',
                    'comentario.created'    => 'Comentário criado',
                    'comentario.updated'    => 'Comentário atualizado',
                    'comentario.deleted'    => 'Comentário excluído',
                    'notificacao.created'   => 'Notificação gerada',
                    'relatorio.generated'   => 'Relatório gerado',
                    'login.created'         => 'Acesso ao sistema',
                    'agente.resposta'       => 'Agente IA respondeu',
                    'whatsapp.mensagem'     => 'Mensagem WhatsApp recebida',
                    'whatsapp.vinculo'      => 'Chat vinculado a processo/card',
                    'chat.mensagem'         => 'Mensagem no chat interno',
                    'webhook.test'          => 'Evento de teste',
                ],
            ],
        ];
    }

    // ── Flat list of all valid event keys ─────────────────────────────────────
    public static function allEventKeys(): array
    {
        $keys = [];
        foreach (self::catalog() as $group) {
            foreach (array_keys($group['events']) as $k) $keys[] = $k;
        }
        return $keys;
    }

    // ── Build standardized payload ────────────────────────────────────────────
    public static function buildPayload(string $eventKey, array $opts = []): array
    {
        $parts  = explode('.', $eventKey);
        $module = $parts[0] ?? $eventKey;
        $action = end($parts);
        return [
            'event'         => $eventKey,
            'module'        => $module,
            'entity'        => $opts['entity']        ?? null,
            'entity_id'     => $opts['entity_id']     ?? null,
            'processo_id'   => $opts['processo_id']   ?? null,
            'cliente_id'    => $opts['cliente_id']    ?? null,
            'card_id'       => $opts['card_id']       ?? null,
            'action'        => $opts['action']        ?? $action,
            'user_id'       => $opts['user_id']       ?? ($_SESSION['user_id'] ?? null),
            'timestamp'     => date('Y-m-d H:i:s'),
            'data'          => $opts['data']          ?? null,
            'previous_data' => $opts['previous_data'] ?? null,
        ];
    }

    // ── Fire event — call this from any module ────────────────────────────────
    public static function fire(string $eventKey, array $payload): void
    {
        try {
            $pdo   = Database::getConnection();
            $stmt  = $pdo->query("SELECT * FROM webhooks WHERE ativo = 1 AND deleted_at IS NULL");
            $hooks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($hooks as $hook) {
                $eventos = json_decode($hook['eventos'] ?? '[]', true) ?: [];
                if (!in_array('*', $eventos) && !in_array($eventKey, $eventos)) continue;
                self::deliver($pdo, $hook, $eventKey, $payload);
            }
        } catch (\Throwable $e) {
            error_log('[WebhookDispatcher] fire error: ' . $e->getMessage());
        }
    }

    // ── HTTP delivery ─────────────────────────────────────────────────────────
    private static function deliver(\PDO $pdo, array $hook, string $eventKey, array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig  = 'sha256=' . hash_hmac('sha256', $body, $hook['secret'] ?? '');

        $start = microtime(true);
        $ctx   = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-Yuris-Event: {$eventKey}\r\nX-Yuris-Signature: {$sig}\r\nUser-Agent: Yuris-Webhook/1.0",
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        ]]);

        $resp   = @file_get_contents($hook['url'], false, $ctx);
        $ms     = (int)((microtime(true) - $start) * 1000);
        $status = null;

        if (!empty($http_response_header[0])) {
            preg_match('/HTTP\/[\d.]+ (\d+)/', $http_response_header[0], $m);
            $status = isset($m[1]) ? (int)$m[1] : null;
        }

        $success = ($status >= 200 && $status < 300) ? 1 : 0;

        try {
            $pdo->prepare("INSERT INTO webhook_logs (webhook_id, event_key, payload, response_status, response_body, duration_ms, success, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
                ->execute([$hook['id'], $eventKey, json_encode($payload), $status, substr($resp ?? '', 0, 2000), $ms, $success]);
        } catch (\Throwable $e) {
            error_log('[WebhookDispatcher] log error: ' . $e->getMessage());
        }
    }
}
