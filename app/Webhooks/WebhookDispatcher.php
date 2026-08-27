<?php
namespace App\Webhooks;

use App\Core\Database;

class WebhookDispatcher
{
    // ── Event catalog ─────────────────────────────────────────────────────────
    //
    // NOTA (auditoria 2026-06-01, achado BAIXA #28): este catalogo hardcoded e a
    // FONTE DE VERDADE em runtime — a UI (public/webhooks.php) e a validacao de
    // eventos (allEventKeys()) leem daqui. A tabela `webhook_events` (migration 068)
    // e populada APENAS pelo seed database/seed_webhook_events.php e NAO e lida pelo
    // app; serve como espelho/catalogo para consulta externa (ex.: BI), nao para o
    // dispatcher. Decisao: manter o catalogo em codigo (zero query no hot path,
    // versionado junto do dispatcher). Se um dia o catalogo precisar ser editavel
    // pelo usuario, migrar a leitura para webhook_events de forma explicita.
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
                    'usuario.activated'         => 'Usuário ativado',
                    'usuario.deactivated'       => 'Usuário desativado',
                    'usuario.role_changed'      => 'Papel (role) alterado',
                    'usuario.permission_changed'=> 'Permissões alteradas',
                    'usuario.password_changed'  => 'Senha alterada',
                    'usuario.senha_changed'     => 'Senha alterada (legado)',
                    'usuario.mentioned'         => 'Usuário mencionado',
                    'usuario.login'             => 'Login realizado (legado)',
                    'usuario.login_success'     => 'Login bem-sucedido',
                    'usuario.login_failed'      => 'Tentativa de login falhou',
                    'usuario.logout'            => 'Logout realizado',
                    'usuario.2fa_enabled'       => '2FA ativado',
                    'usuario.2fa_disabled'      => '2FA desativado',
                    'usuario.2fa_required'      => '2FA exigido para acesso',
                ],
            ],
            'advogados' => [
                'label' => 'Advogados',
                'icon'  => 'scale',
                'events' => [
                    'advogado.created'            => 'Advogado cadastrado',
                    'advogado.updated'            => 'Advogado atualizado',
                    'advogado.activated'          => 'Advogado ativado',
                    'advogado.deactivated'        => 'Advogado desativado',
                    'advogado.deleted'            => 'Advogado excluído',
                    'advogado.linked_to_matriz'   => 'Advogado vinculado à matriz',
                    'advogado.linked_to_filial'   => 'Advogado vinculado a filial',
                    'advogado.unlinked'           => 'Vínculo removido',
                    'advogado.oab_updated'        => 'OAB atualizada',
                ],
            ],
            'leads' => [
                'label' => 'Leads',
                'icon'  => 'user',
                'events' => [
                    'lead.created'        => 'Lead criado',
                    'lead.updated'        => 'Lead atualizado',
                    'lead.converted'      => 'Lead convertido em cliente',
                    'lead.deleted'        => 'Lead excluído',
                    'lead.status_changed' => 'Status do lead alterado',
                ],
            ],
            'contatos' => [
                'label' => 'Contatos',
                'icon'  => 'user',
                'events' => [
                    'contato.created' => 'Contato criado',
                    'contato.updated' => 'Contato atualizado',
                    'contato.deleted' => 'Contato excluído',
                ],
            ],
            'pipeline' => [
                'label' => 'Funil / Pipeline',
                'icon'  => 'card',
                'events' => [
                    'pipeline.created'        => 'Funil criado',
                    'pipeline.updated'        => 'Funil atualizado',
                    'pipeline.stage_created'  => 'Etapa criada',
                    'pipeline.stage_updated'  => 'Etapa atualizada',
                    'pipeline.stage_deleted'  => 'Etapa excluída',
                ],
            ],
            'whatsapp' => [
                'label' => 'WhatsApp / Conversas',
                'icon'  => 'whatsapp',
                'events' => [
                    'whatsapp.message_received'          => 'Mensagem recebida',
                    'whatsapp.message_sent'              => 'Mensagem enviada',
                    'whatsapp.conversation_started'      => 'Conversa iniciada',
                    'whatsapp.conversation_closed'       => 'Conversa encerrada',
                    'whatsapp.conversation_assigned'     => 'Conversa atribuída',
                    'whatsapp.conversation_unassigned'   => 'Conversa desatribuída',
                    'whatsapp.handoff_requested'         => 'Handoff (bot→humano) solicitado',
                    'whatsapp.handoff_completed'         => 'Handoff completado',
                ],
            ],
            'lgpd' => [
                'label' => 'LGPD / Privacidade',
                'icon'  => 'shield',
                'events' => [
                    'lgpd.request_created'           => 'Solicitação LGPD criada',
                    'lgpd.request_updated'           => 'Solicitação LGPD atualizada',
                    'lgpd.request_completed'         => 'Solicitação LGPD concluída',
                    'lgpd.request_denied'            => 'Solicitação LGPD negada',
                    'lgpd.consent_given'             => 'Consentimento concedido',
                    'lgpd.consent_revoked'           => 'Consentimento revogado',
                    'lgpd.data_exported'             => 'Dados exportados (DSAR)',
                    'lgpd.data_deleted'              => 'Dados excluídos (DSAR)',
                    'lgpd.data_anonymized'           => 'Dados anonimizados',
                    'lgpd.privacy_document_published'=> 'Documento de privacidade publicado',
                    'lgpd.terms_accepted'            => 'Termos aceitos',
                    'lgpd.cookies_accepted'          => 'Cookies aceitos',
                ],
            ],
            'seguranca' => [
                'label' => 'Segurança',
                'icon'  => 'shield',
                'events' => [
                    'security.incident_created'      => 'Incidente de segurança criado',
                    'security.incident_updated'      => 'Incidente atualizado',
                    'security.incident_resolved'     => 'Incidente resolvido',
                    'security.incident_reported'     => 'Incidente reportado externamente',
                    'security.suspicious_login'      => 'Login suspeito detectado',
                    'security.access_denied'         => 'Acesso negado por política',
                    'security.permission_violation'  => 'Violação de permissão',
                ],
            ],
            'auditoria' => [
                'label' => 'Auditoria / Sistema',
                'icon'  => 'system',
                'events' => [
                    'audit.log_created'              => 'Log de auditoria criado',
                    'system.error'                   => 'Erro de sistema',
                    'system.warning'                 => 'Aviso de sistema',
                    'integration.webhook_failed'     => 'Webhook falhou (auto-disable)',
                    'integration.webhook_recovered'  => 'Webhook recuperado',
                    'integration.api_error'          => 'Erro em integração externa',
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
                    'login.created'         => 'Acesso ao sistema (legado)',
                    'agente.resposta'       => 'Agente IA respondeu',
                    'whatsapp.mensagem'     => 'Mensagem WhatsApp (legado)',
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

    /**
     * Dispara um evento para os webhooks subscritos.
     *
     * P0 LGPD (Fase 1) — assinatura alterada para INCLUIR o accountId do tenant
     * que originou o evento. Antes desta correção, a query era global
     * (SELECT * FROM webhooks WHERE ativo = 1), o que entregava cada evento
     * de QUALQUER tenant para os webhooks de TODOS os outros tenants —
     * vazamento estrutural de dados pessoais cross-tenant.
     *
     * Agora:
     *   • $accountId é OBRIGATÓRIO conceitualmente; passe int.
     *   • $accountId = null mantém a porta legada para eventos sistêmicos
     *     que NÃO podem ser atribuídos a um tenant específico (raro). Emite
     *     warning em error_log para auditar usos remanescentes.
     *
     * @param int|null $accountId Tenant originador do evento. Null = global (legado).
     */
    public static function fire(?int $accountId, string $eventKey, array $v1Payload): void
    {
        try {
            $pdo   = Database::getConnection();
            $hooks = self::findSubscribers($pdo, $accountId, $eventKey);
            if (empty($hooks)) return;

            require_once __DIR__ . '/WebhookPayloadBuilder.php';
            // event_id estavel: todas as entregas deste mesmo evento compartilham,
            // permitindo correlacao no destino (idempotencia por evento).
            $eventId = WebhookPayloadBuilder::generateEventId();
            $async   = self::isAsyncEnabled();

            foreach ($hooks as $hook) {
                $deliveryId = self::enqueueDelivery($pdo, $hook, $eventKey, $eventId, $v1Payload);
                if ($deliveryId && !$async) {
                    self::tryDeliver($pdo, $hook, $eventKey, $v1Payload, $deliveryId, 1, $eventId);
                }
            }
        } catch (\Throwable $e) {
            error_log('[WebhookDispatcher] fire error: ' . $e->getMessage());
        }
    }

    /**
     * FIX #28 (auditoria 2026-06-01): entrega de TESTE para UM endpoint especifico,
     * SEM passar por findSubscribers(). O botao "Testar" antes chamava fire(), que
     * filtrava por assinatura — um webhook que nao assinava 'webhook.test' recebia
     * zero entregas mas a UI mostrava "Teste enviado" (falso sucesso). Aqui forcamos
     * a entrega ao endpoint informado e devolvemos o resultado REAL (status HTTP),
     * pra UI refletir a saude verdadeira do endpoint.
     *
     * @param array $hook Linha completa de webhook_endpoints (ja filtrada por tenant pelo caller).
     * @return array{delivery_id:?int, status:string, http_status:?int} resultado da entrega sincrona.
     */
    public static function deliverTest(\PDO $pdo, array $hook, array $v1Payload): array
    {
        require_once __DIR__ . '/WebhookPayloadBuilder.php';
        $eventKey   = 'webhook.test';
        $eventId    = WebhookPayloadBuilder::generateEventId();
        $deliveryId = self::enqueueDelivery($pdo, $hook, $eventKey, $eventId, $v1Payload);
        if (!$deliveryId) {
            return ['delivery_id' => null, 'status' => 'error', 'http_status' => null];
        }
        // Entrega sincrona SEMPRE (teste manual precisa de feedback imediato), mesmo
        // que o modo global seja async — o worker so processaria depois.
        self::tryDeliver($pdo, $hook, $eventKey, $v1Payload, $deliveryId, 1, $eventId);

        // Le o resultado consolidado da entrega pra reportar honestamente.
        $st = $pdo->prepare("SELECT status, response_status FROM webhook_deliveries WHERE id = ? LIMIT 1");
        $st->execute([$deliveryId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'delivery_id' => $deliveryId,
            'status'      => (string)($row['status'] ?? 'pending'),
            'http_status' => isset($row['response_status']) ? (int)$row['response_status'] : null,
        ];
    }

    /** Wrapper usado pelo worker (bin/webhook_worker.php) ao consumir 1 row de webhook_deliveries. */
    public static function processDelivery(\PDO $pdo, array $delivery): void
    {
        try {
            $hookStmt = $pdo->prepare("SELECT * FROM webhook_endpoints WHERE id = ? AND deleted_at IS NULL LIMIT 1");
            $hookStmt->execute([(int)$delivery['webhook_endpoint_id']]);
            $hook = $hookStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$hook) {
                $pdo->prepare("UPDATE webhook_deliveries SET status='canceled', erro=?, updated_at=NOW() WHERE id=?")
                    ->execute(['Endpoint not found or deleted', (int)$delivery['id']]);
                return;
            }
            $payload    = json_decode((string)$delivery['payload'], true) ?: [];
            $tentativa  = max(1, (int)($delivery['tentativa'] ?? 1));
            self::tryDeliver(
                $pdo, $hook, (string)$delivery['event_code'], $payload,
                (int)$delivery['id'], $tentativa, (string)($delivery['event_id'] ?? '')
            );
        } catch (\Throwable $e) {
            error_log('[WebhookDispatcher] processDelivery error: ' . $e->getMessage());
        }
    }

    // ── Helpers internos ──────────────────────────────────────────────────────

    private static function findSubscribers(\PDO $pdo, ?int $accountId, string $eventKey): array
    {
        if ($accountId === null) {
            error_log("[WebhookDispatcher] WARNING: fire(null, '{$eventKey}') — entrega global. Identifique o accountId apropriado.");
            $stmt = $pdo->query("SELECT * FROM webhook_endpoints WHERE ativo = 1 AND deleted_at IS NULL");
            $hooks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return self::filterBySubscription($hooks, $eventKey);
        }

        // ── FIX #21 (auditoria 2026-06-01): a coluna `escopo` era totalmente orfa.
        // findSubscribers() filtrava SO account_id = ?, entao um webhook de matriz
        // com escopo='matriz_e_filiais' NUNCA era encontrado quando uma FILIAL
        // disparava um evento (o fire() vem com o account_id da filial). Aqui
        // expandimos o conjunto de contas-alvo via account_vinculos (status='active'),
        // espelhando a regra canonica matriz<->filial — o vinculo vive SEMPRE em
        // account_vinculos (accounts.matriz_id e sempre NULL). Nao usamos
        // AccountContext::getAccessibleAccountIds() porque o dispatcher roda fora de
        // contexto de sessao (eventos disparam de cards.php, prazos, worker, etc.).
        $relatedIds = self::relatedAccountIds($pdo, $accountId); // [matriz?, ...filiais]

        // Conjunto candidato: SEMPRE os webhooks do proprio tenant originador +
        // (quando ha contas vinculadas) os webhooks das contas relacionadas QUE
        // optaram por escopo='matriz_e_filiais'. Sem vinculos ativos, cai no
        // comportamento original (so o proprio account_id) — evita IN () invalido.
        if (empty($relatedIds)) {
            $stmt = $pdo->prepare("SELECT * FROM webhook_endpoints WHERE ativo = 1 AND deleted_at IS NULL AND account_id = ?");
            $stmt->execute([$accountId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
            $sql = "SELECT * FROM webhook_endpoints
                     WHERE ativo = 1 AND deleted_at IS NULL
                       AND (
                         account_id = ?
                         OR (account_id IN ($placeholders) AND escopo = 'matriz_e_filiais')
                       )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$accountId], $relatedIds));
        }
        $hooks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return self::filterBySubscription($hooks, $eventKey);
    }

    /**
     * Resolve as contas relacionadas ao tenant originador via account_vinculos
     * (status='active'), nos DOIS sentidos:
     *   - se o originador e uma FILIAL → devolve a matriz vinculada;
     *   - se o originador e uma MATRIZ → devolve as filiais vinculadas.
     * Assim um webhook 'matriz_e_filiais' da matriz recebe eventos das filiais e
     * vice-versa. Retorna array (possivelmente vazio) de ints distintos, sem o
     * proprio $accountId (esse ja entra incondicionalmente no SELECT).
     */
    private static function relatedAccountIds(\PDO $pdo, int $accountId): array
    {
        $ids = [];
        try {
            // Originador como filial → matriz(es)
            $st = $pdo->prepare(
                "SELECT matriz_account_id AS rel FROM account_vinculos
                  WHERE filial_account_id = ? AND status = 'active'
                 UNION
                 SELECT filial_account_id AS rel FROM account_vinculos
                  WHERE matriz_account_id = ? AND status = 'active'"
            );
            $st->execute([$accountId, $accountId]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $rel) {
                $rel = (int)$rel;
                if ($rel > 0 && $rel !== $accountId) $ids[$rel] = true;
            }
        } catch (\Throwable $e) {
            error_log('[WebhookDispatcher] relatedAccountIds error: ' . $e->getMessage());
        }
        return array_keys($ids);
    }

    /** Mantem apenas hooks cuja lista `eventos` assina o evento (ou '*'). */
    private static function filterBySubscription(array $hooks, string $eventKey): array
    {
        return array_values(array_filter($hooks, function ($h) use ($eventKey) {
            $eventos = json_decode($h['eventos'] ?? '[]', true) ?: [];
            return in_array('*', $eventos, true) || in_array($eventKey, $eventos, true);
        }));
    }

    // NOTA (auditoria 2026-06-01, achado BAIXA #27): a fila de eventos vive em
    // `webhook_deliveries` (state machine: pending/retrying/success/failed/canceled),
    // consumida pelo worker bin/webhook_worker.php. A tabela `webhook_event_queue`
    // (migration 070) foi criada mas NUNCA usada — `webhook_deliveries` (migration 069)
    // a substituiu antes de qualquer codigo passar a escrever nela. Nao ha codigo
    // referenciando webhook_event_queue em lugar nenhum (a tabela permanece no banco,
    // a ser dropada em migration dedicada com controle). Toda enfileiramento e aqui.
    private static function enqueueDelivery(\PDO $pdo, array $hook, string $eventKey, string $eventId, array $v1Payload): ?int
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO webhook_deliveries
                   (webhook_endpoint_id, account_id, event_code, event_id, payload, request_url, status, tentativa, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', 1, NOW(), NOW())"
            );
            $stmt->execute([
                (int)$hook['id'],
                (int)$hook['account_id'],
                $eventKey,
                $eventId,
                json_encode($v1Payload, JSON_UNESCAPED_UNICODE),
                (string)$hook['url'],
            ]);
            return (int)$pdo->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[WebhookDispatcher] enqueueDelivery error: ' . $e->getMessage());
            return null;
        }
    }

    private static function tryDeliver(\PDO $pdo, array $hook, string $eventKey, array $v1Payload, int $deliveryId, int $tentativa, string $eventIdOverride): void
    {
        require_once __DIR__ . '/WebhookPayloadBuilder.php';
        require_once __DIR__ . '/WebhookRetryPolicy.php';
        require_once __DIR__ . '/../Lgpd/PayloadMasker.php';
        require_once __DIR__ . '/../Core/WebhookUrlValidator.php';

        $envelope = WebhookPayloadBuilder::v2($eventKey, $v1Payload, $hook, null, $eventIdOverride ?: null);

        // SSRF — bloqueia destinos perigosos.
        [$urlOk, $urlErr] = \App\Core\WebhookUrlValidator::isPublicUrl((string)$hook['url']);
        if (!$urlOk) {
            self::finalizeDelivery($pdo, $deliveryId, 'canceled', null, 0, null, 'SSRF-blocked: ' . $urlErr, null, null);
            error_log("[WebhookDispatcher] SSRF block hook={$hook['id']} url={$hook['url']} reason={$urlErr}");
            return;
        }

        $timeout   = (int)($hook['timeout_segundos'] ?? 10);
        if ($timeout < 1 || $timeout > 60) $timeout = 10;
        $body      = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $deliveryHeader = (string)($envelope['event_id'] ?? '');
        $sig       = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, (string)($hook['secret'] ?? ''));

        $headerLines = [
            'Content-Type: application/json',
            'User-Agent: Yuris-Webhook/2.0',
            "X-Yuris-Event: {$eventKey}",
            "X-Yuris-Delivery: {$deliveryHeader}",
            "X-Yuris-Timestamp: {$timestamp}",
            "X-Yuris-Tenant: " . (int)($hook['account_id'] ?? 0),
            "X-Yuris-Signature: {$sig}",
        ];
        if (!empty($hook['headers_customizados'])) {
            $custom = json_decode((string)$hook['headers_customizados'], true);
            if (is_array($custom)) {
                foreach ($custom as $k => $v) {
                    if (!is_string($k) || !is_scalar($v)) continue;
                    if (stripos($k, 'X-Yuris-') === 0) continue;
                    $headerLines[] = trim($k) . ': ' . trim((string)$v);
                }
            }
        }

        $start = microtime(true);
        $ctx   = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headerLines),
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);

        $resp   = @file_get_contents($hook['url'], false, $ctx);
        $ms     = (int)((microtime(true) - $start) * 1000);
        $status = null;
        if (!empty($http_response_header[0])) {
            preg_match('/HTTP\/[\d.]+ (\d+)/', $http_response_header[0], $m);
            $status = isset($m[1]) ? (int)$m[1] : null;
        }
        $success = $status !== null && $status >= 200 && $status < 300;

        if ($success) {
            self::finalizeDelivery($pdo, $deliveryId, 'success', $status, $ms, $body, null, $resp, $headerLines);
        } else {
            $erro          = 'HTTP ' . ($status ?? 'no_response');
            $retryEnabled  = (int)($hook['retry_enabled'] ?? 1) === 1;
            $maxRetries    = max(1, (int)($hook['max_retries'] ?? 3));
            if ($retryEnabled && \App\Webhooks\WebhookRetryPolicy::canRetry($tentativa, $maxRetries)) {
                $nextAt = \App\Webhooks\WebhookRetryPolicy::nextAttemptAt($tentativa);
                $pdo->prepare(
                    "UPDATE webhook_deliveries
                       SET status='retrying', tentativa=?, scheduled_retry_at=?,
                           response_status=?, response_body=?, response_time_ms=?,
                           erro=?, request_headers=?, payload=?, updated_at=NOW()
                     WHERE id=?"
                )->execute([
                    $tentativa, $nextAt, $status, substr((string)$resp, 0, 4000), $ms,
                    $erro, json_encode($headerLines, JSON_UNESCAPED_SLASHES), $body, $deliveryId
                ]);
            } else {
                self::finalizeDelivery($pdo, $deliveryId, 'failed', $status, $ms, $body, $erro, $resp, $headerLines);
            }
        }

        // FIX #22 (auditoria 2026-06-01): removido o dual-write morto em webhook_logs.
        // O painel e todas as leituras (public/api/webhooks.php action=logs/list/get)
        // usam webhook_deliveries desde a Etapa 8; webhook_logs nao tem mais consumidor.
        // A tabela legada nao e dropada aqui (fica para migration dedicada com controle).
    }

    private static function finalizeDelivery(\PDO $pdo, int $deliveryId, string $status, ?int $httpStatus, int $ms, ?string $body, ?string $erro, ?string $resp, ?array $headerLines): void
    {
        try {
            $pdo->prepare(
                "UPDATE webhook_deliveries
                   SET status=?, response_status=?, response_body=?, response_time_ms=?, erro=?,
                       request_headers=COALESCE(?, request_headers),
                       payload=COALESCE(?, payload),
                       delivered_at=NOW(), updated_at=NOW()
                 WHERE id=?"
            )->execute([
                $status, $httpStatus, substr((string)$resp, 0, 4000), $ms, $erro,
                $headerLines !== null ? json_encode($headerLines, JSON_UNESCAPED_SLASHES) : null,
                $body, $deliveryId
            ]);
        } catch (\Throwable $e) {
            error_log('[WebhookDispatcher] finalizeDelivery error: ' . $e->getMessage());
        }
    }

    /** Toggle global por .env: WEBHOOK_DISPATCH_MODE=async | sync (default sync). */
    private static function isAsyncEnabled(): bool
    {
        $val = $_ENV['WEBHOOK_DISPATCH_MODE'] ?? getenv('WEBHOOK_DISPATCH_MODE');
        return strtolower((string)$val) === 'async';
    }
}
