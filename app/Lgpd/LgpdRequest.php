<?php
namespace App\Lgpd;

/**
 * LgpdRequest — solicitações dos titulares de dados pessoais (LGPD Art. 18).
 *
 * Fluxo típico:
 *   1. Titular abre solicitação via /lgpd/solicitar.php → LgpdRequest::create()
 *   2. Sistema gera token único e envia link de acompanhamento
 *   3. DPO recebe notificação, analisa, marca status
 *   4. Cada mudança vira evento em lgpd_request_events (audit trail)
 *   5. Resposta final fica em `resposta` + `respondido_em`
 */
final class LgpdRequest
{
    public const TIPOS = [
        'confirmacao_existencia',
        'acesso',
        'correcao',
        'anonimizacao',
        'bloqueio',
        'eliminacao',
        'portabilidade',
        'info_compartilhamento',
        'revogacao_consentimento',
        'revisao_decisao_automatizada',
    ];

    public const STATUSES = [
        'aberto', 'em_analise', 'aguardando_titular',
        'concluido', 'rejeitado', 'expirado',
    ];

    /** Prazo padrão LGPD Art. 19: 15 dias corridos. */
    public const PRAZO_DIAS = 15;

    /**
     * Cria nova solicitação. Gera token único e prazo automaticamente.
     * Retorna [id, token].
     */
    public static function create(array $data): array
    {
        $tipo = (string)($data['tipo'] ?? '');
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException("Tipo inválido: $tipo");
        }
        $nome  = trim((string)($data['titular_nome']  ?? ''));
        $email = trim((string)($data['titular_email'] ?? ''));
        if ($nome === '' || $email === '') {
            throw new \InvalidArgumentException('titular_nome e titular_email obrigatórios');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('titular_email inválido');
        }

        $pdo   = Database::getConnection();
        $token = bin2hex(random_bytes(32)); // 64 chars hex

        $pdo->prepare(
            "INSERT INTO lgpd_requests
               (titular_nome, titular_email, titular_cpf, titular_telefone,
                account_id, user_id, tipo, descricao, status, prazo_resposta,
                ip_origem, user_agent, token_acesso)
             VALUES
               (:nome, :email, :cpf, :tel,
                :acc, :uid, :tipo, :desc, 'aberto',
                DATE_ADD(NOW(), INTERVAL :prazo DAY),
                :ip, :ua, :tok)"
        )->execute([
            'nome'  => $nome,
            'email' => $email,
            'cpf'   => $data['titular_cpf']      ?? null,
            'tel'   => $data['titular_telefone'] ?? null,
            'acc'   => $data['account_id']       ?? null,
            'uid'   => $data['user_id']          ?? null,
            'tipo'  => $tipo,
            'desc'  => $data['descricao']        ?? null,
            'prazo' => self::PRAZO_DIAS,
            'ip'    => $data['ip']         ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'ua'    => $data['user_agent'] ?? substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'tok'   => $token,
        ]);
        $id = (int)$pdo->lastInsertId();

        // Evento "criado"
        self::addEvent($id, 'criado', "Solicitação aberta pelo titular ({$email})", null);

        // Webhook: lgpd.request_created (etapa 10/11)
        try {
            require_once __DIR__ . '/../Integracoes/WebhookDispatcher.php';
            \App\Integracoes\WebhookDispatcher::fire(
                isset($data['account_id']) ? (int)$data['account_id'] : null,
                'lgpd.request_created',
                \App\Integracoes\WebhookDispatcher::buildPayload('lgpd.request_created', [
                    'entity'    => 'lgpd_request',
                    'entity_id' => $id,
                    'data'      => [
                        'id'             => $id,
                        'tipo'           => $tipo,
                        'status'         => 'aberto',
                        'titular_email'  => $email, // PayloadMasker mascara conforme payload_mode
                    ],
                ])
            );
        } catch (\Throwable $_) { /* fail silently — webhook nao pode bloquear LGPD */ }

        return ['id' => $id, 'token' => $token];
    }

    /** Busca por id (modo admin). */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $st  = $pdo->prepare('SELECT * FROM lgpd_requests WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Busca por token (modo público — titular acompanha sem login).
     * NÃO retorna dados sensíveis (CPF, IP do operador etc); só status + resposta.
     */
    public static function findByToken(string $token): ?array
    {
        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            'SELECT id, titular_nome, titular_email, tipo, status, descricao,
                    recebido_em, prazo_resposta, respondido_em, resposta, motivo_rejeicao
             FROM lgpd_requests WHERE token_acesso = ? LIMIT 1'
        );
        $st->execute([$token]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Lista filtrada para o Painel Master.
     * @param array $f filtros: status, tipo, atrasada, q (busca por nome/email)
     */
    public static function listForAdmin(array $f = [], int $limit = 200): array
    {
        $pdo = Database::getConnection();
        $w   = [];
        $p   = [];
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $w[] = 'status = :st'; $p['st'] = $f['status'];
        }
        if (!empty($f['tipo']) && in_array($f['tipo'], self::TIPOS, true)) {
            $w[] = 'tipo = :tp'; $p['tp'] = $f['tipo'];
        }
        if (!empty($f['atrasada'])) {
            $w[] = "status IN ('aberto','em_analise','aguardando_titular') AND prazo_resposta < NOW()";
        }
        if (!empty($f['q'])) {
            $w[] = '(titular_nome LIKE :q OR titular_email LIKE :q)';
            $p['q'] = '%' . $f['q'] . '%';
        }
        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        $sql = "SELECT id, titular_nome, titular_email, tipo, status, recebido_em,
                       prazo_resposta, respondido_em
                FROM lgpd_requests $where
                ORDER BY
                  CASE WHEN status IN ('aberto','em_analise','aguardando_titular') THEN 0 ELSE 1 END,
                  prazo_resposta ASC LIMIT " . max(1, min(500, $limit));
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza campos permitidos. Cria evento de auditoria automaticamente
     * para mudanças de status ou resposta.
     */
    public static function update(int $id, array $data, ?int $byUserId = null): bool
    {
        $pdo = Database::getConnection();
        $old = self::findById($id);
        if (!$old) return false;

        $allowed = ['status', 'resposta', 'motivo_rejeicao', 'arquivo_resposta_path', 'respondido_em'];
        $fields = []; $params = ['id' => $id];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                if ($f === 'status' && !in_array($data[$f], self::STATUSES, true)) continue;
                $fields[] = "$f = :$f";
                $params[$f] = $data[$f];
            }
        }
        // Quando vira concluido/rejeitado, marca respondido_em + respondido_por
        if (isset($data['status']) && in_array($data['status'], ['concluido','rejeitado'], true)) {
            if (!array_key_exists('respondido_em', $params)) {
                $fields[]            = 'respondido_em = NOW()';
            }
            $fields[]                  = 'respondido_por_user_id = :uid';
            $params['uid']             = $byUserId;
        }
        if (empty($fields)) return false;

        $sql = 'UPDATE lgpd_requests SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        // Evento de auditoria
        if (isset($data['status']) && $data['status'] !== $old['status']) {
            self::addEvent($id, $data['status'],
                "Status: {$old['status']} → {$data['status']}", $byUserId);
        }
        if (isset($data['resposta']) && $data['resposta'] !== ($old['resposta'] ?? '')) {
            self::addEvent($id, 'resposta_atualizada', 'Resposta editada', $byUserId);
        }
        return true;
    }

    /** Registra evento em lgpd_request_events. */
    public static function addEvent(int $requestId, string $evento, ?string $obs = null, ?int $userId = null): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO lgpd_request_events (request_id, evento, observacao, user_id, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $requestId,
            $evento,
            $obs,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }

    /** Lista eventos de uma solicitação (ordem cronológica). */
    public static function listEvents(int $requestId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            'SELECT e.*, u.nome AS user_nome
             FROM lgpd_request_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.request_id = ?
             ORDER BY e.created_at ASC, e.id ASC'
        );
        $st->execute([$requestId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Conta solicitações por status (pra badge no master.php). */
    public static function countByStatus(): array
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query(
            "SELECT status, COUNT(*) AS total,
                    SUM(CASE WHEN prazo_resposta < NOW() AND status IN ('aberto','em_analise','aguardando_titular') THEN 1 ELSE 0 END) AS atrasadas
             FROM lgpd_requests GROUP BY status"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $out = ['total' => 0, 'pendentes' => 0, 'atrasadas' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int)$r['total'];
            $out['total'] += (int)$r['total'];
            if (in_array($r['status'], ['aberto','em_analise','aguardando_titular'], true)) {
                $out['pendentes'] += (int)$r['total'];
            }
            $out['atrasadas'] += (int)$r['atrasadas'];
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // F2 (LGPD v2 — Migration 057): extensões para normalização
    // ═══════════════════════════════════════════════════════════════════════

    /** ENUMs do schema v2 — espelham migration 057. */
    public const TITULAR_TIPOS = [
        'lead', 'cliente', 'advogado_associado', 'usuario_interno',
        'parte_processual', 'contato_whatsapp', 'responsavel_financeiro',
        'visitante', 'outro',
    ];
    public const DOCUMENTO_TIPOS = ['cpf','cnpj','rg','cnh','oab','passaporte','outro'];
    public const CANAIS_ORIGEM = [
        'formulario_publico','email','telefone','presencial',
        'ouvidoria_anpd','painel_interno','outro',
    ];
    public const PRIORIDADES = ['baixa','media','alta','critica'];

    /**
     * Prazo de resposta por tipo de solicitação (LGPD Art. 19).
     *
     * Art. 19 §1º — confirmação de existência: resposta IMEDIATA ou em formato
     * simplificado (usamos 5 dias para dar margem operacional).
     * Art. 19 §3º — demais: 15 dias corridos.
     */
    public const PRAZO_POR_TIPO = [
        'confirmacao_existencia'        => 5,
        'acesso'                        => 15,
        'correcao'                      => 15,
        'anonimizacao'                  => 15,
        'bloqueio'                      => 15,
        'eliminacao'                    => 15,
        'portabilidade'                 => 15,
        'info_compartilhamento'         => 15,
        'revogacao_consentimento'       => 15,
        'revisao_decisao_automatizada'  => 15,
    ];

    /**
     * Calcula o prazo apropriado para um tipo de solicitação.
     * Cai em 15 dias se tipo desconhecido.
     */
    public static function prazoParaTipo(string $tipo): int
    {
        return self::PRAZO_POR_TIPO[$tipo] ?? self::PRAZO_DIAS;
    }

    /**
     * Prazo efetivo de uma solicitação (considerando prorrogação Art. 19 §3º).
     * Retorna DateTime ou null.
     */
    public static function getEffectiveDeadline(array $req): ?\DateTimeImmutable
    {
        $base = $req['prazo_prorrogado_em'] ?? $req['prazo_resposta'] ?? null;
        if (!$base) return null;
        try {
            return new \DateTimeImmutable((string)$base);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Registra prorrogação (Art. 19 §3º). Adiciona N dias ao prazo original
     * e grava motivo. Também cria evento "prorrogacao".
     *
     * @return bool sucesso
     */
    public static function prorrogar(int $id, int $diasAdicionais, string $motivo, ?int $byUserId = null): bool
    {
        $req = self::findById($id);
        if (!$req) return false;
        if (in_array($req['status'], ['concluido','rejeitado','expirado'], true)) {
            throw new \LogicException('Nao se prorroga solicitacao ja encerrada');
        }
        if ($diasAdicionais < 1 || $diasAdicionais > 30) {
            throw new \InvalidArgumentException('diasAdicionais deve estar entre 1 e 30');
        }

        $base = $req['prazo_prorrogado_em'] ?? $req['prazo_resposta'];
        $novoPrazo = (new \DateTimeImmutable($base))
            ->modify("+{$diasAdicionais} days")
            ->format('Y-m-d');

        $pdo = Database::getConnection();
        $pdo->prepare(
            "UPDATE lgpd_requests
             SET prazo_prorrogado_em = :p, motivo_prorrogacao = :m
             WHERE id = :id"
        )->execute(['p' => $novoPrazo, 'm' => $motivo, 'id' => $id]);

        self::addEvent($id, 'prorrogacao',
            "Prazo prorrogado por {$diasAdicionais} dias. Motivo: {$motivo}",
            $byUserId);
        return true;
    }

    /**
     * Inferência heurística do tipo de titular pelo e-mail/cpf.
     * Procura em users, contatos, cards, processos. Retorna o primeiro match.
     * Não é definitivo — DPO deve revisar/confirmar manualmente.
     *
     * @param string $email
     * @param string|null $cpf
     * @return array{tipo:string,entidade:string,entidade_id:int,account_id:int|null}|null
     */
    public static function inferTitularTipo(string $email, ?string $cpf = null): ?array
    {
        $email = strtolower(trim($email));
        $pdo   = Database::getConnection();

        // 1. user_interno / advogado_associado / usuario_interno (users.login = email)
        $st = $pdo->prepare(
            "SELECT id, account_id, codigo_advogado, role
             FROM users WHERE LOWER(login) = ? AND anonymized_at IS NULL LIMIT 1"
        );
        $st->execute([$email]);
        if ($u = $st->fetch(\PDO::FETCH_ASSOC)) {
            $tipo = !empty($u['codigo_advogado']) ? 'advogado_associado' : 'usuario_interno';
            return ['tipo' => $tipo, 'entidade' => 'users',
                    'entidade_id' => (int)$u['id'], 'account_id' => (int)$u['account_id']];
        }

        // 2. cliente (contatos.email = email)
        $st = $pdo->prepare(
            "SELECT id, account_id FROM contatos
             WHERE LOWER(email) = ? AND anonymized_at IS NULL LIMIT 1"
        );
        $st->execute([$email]);
        if ($c = $st->fetch(\PDO::FETCH_ASSOC)) {
            return ['tipo' => 'cliente', 'entidade' => 'contatos',
                    'entidade_id' => (int)$c['id'], 'account_id' => (int)$c['account_id']];
        }

        // 3. lead (cards.email = email)
        $st = $pdo->prepare(
            "SELECT id, account_id FROM cards
             WHERE LOWER(email) = ? AND anonymized_at IS NULL LIMIT 1"
        );
        $st->execute([$email]);
        if ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
            return ['tipo' => 'lead', 'entidade' => 'cards',
                    'entidade_id' => (int)$r['id'], 'account_id' => (int)$r['account_id']];
        }

        // 4. parte_processual (processos.cpf_cnpj_parte_contraria via CPF)
        if ($cpf !== null) {
            $cpfClean = preg_replace('/\D/', '', $cpf);
            if ($cpfClean !== '') {
                $st = $pdo->prepare(
                    "SELECT id, account_id FROM processos
                     WHERE REPLACE(REPLACE(REPLACE(cpf_cnpj_parte_contraria,'.',''),'-',''),'/','') = ?
                       AND anonymized_at IS NULL LIMIT 1"
                );
                $st->execute([$cpfClean]);
                if ($p = $st->fetch(\PDO::FETCH_ASSOC)) {
                    return ['tipo' => 'parte_processual', 'entidade' => 'processos',
                            'entidade_id' => (int)$p['id'], 'account_id' => (int)$p['account_id']];
                }
            }
        }

        return null;
    }

    /**
     * Atualiza tipificação do titular + referência polimórfica + matriz.
     */
    public static function setTitularRef(
        int $id,
        string $tipo,
        ?string $entidade = null,
        ?int $entidadeId = null,
        ?int $accountId = null
    ): bool {
        if (!in_array($tipo, self::TITULAR_TIPOS, true)) {
            throw new \InvalidArgumentException("titular_tipo invalido: $tipo");
        }
        $pdo = Database::getConnection();

        // Resolve matriz_account_id se accountId for filial
        $matrizId = null;
        if ($accountId) {
            $st = $pdo->prepare("SELECT matriz_id FROM accounts WHERE id = ?");
            $st->execute([$accountId]);
            $matrizId = $st->fetchColumn() ?: null;
            // Se for matriz, matriz_id é NULL → mantém NULL
        }

        $sql = "UPDATE lgpd_requests SET
                  titular_tipo = :tipo,
                  titular_referencia_entidade = :ent,
                  titular_referencia_id = :eid,
                  account_id = COALESCE(:acc, account_id),
                  matriz_account_id = :mat
                WHERE id = :id";
        return $pdo->prepare($sql)->execute([
            'tipo' => $tipo, 'ent' => $entidade, 'eid' => $entidadeId,
            'acc'  => $accountId, 'mat' => $matrizId, 'id' => $id,
        ]);
    }

    /**
     * Agregador completo para o drawer da UI Master.
     * Retorna array com request + eventos + modules + findings + attachments + retentions + counts.
     */
    public static function fullDetail(int $id): ?array
    {
        $req = self::findById($id);
        if (!$req) return null;

        // Lazy-load dos models v2 (evita require se classe ja carregada)
        if (!class_exists('App\\Lgpd\\LgpdRequestModule')) {
            require_once __DIR__ . '/LgpdRequestModule.php';
        }
        if (!class_exists('App\\Lgpd\\LgpdRequestFinding')) {
            require_once __DIR__ . '/LgpdRequestFinding.php';
        }
        if (!class_exists('App\\Lgpd\\LgpdRequestAttachment')) {
            require_once __DIR__ . '/LgpdRequestAttachment.php';
        }
        if (!class_exists('App\\Lgpd\\LgpdRequestRetentionJustification')) {
            require_once __DIR__ . '/LgpdRequestRetentionJustification.php';
        }

        return [
            'request'       => $req,
            'eventos'       => self::listEvents($id),
            'modules'       => \App\Lgpd\LgpdRequestModule::summary($id),
            'findings'      => \App\Lgpd\LgpdRequestFinding::listByRequest($id),
            'attachments'   => \App\Lgpd\LgpdRequestAttachment::listByRequest($id),
            'retentions'    => \App\Lgpd\LgpdRequestRetentionJustification::listByRequest($id),
            'counts'        => [
                'findings'    => \App\Lgpd\LgpdRequestFinding::countsByRequest($id),
                'retentions'  => \App\Lgpd\LgpdRequestRetentionJustification::countsByRequest($id),
            ],
            'prazo_efetivo' => self::getEffectiveDeadline($req),
        ];
    }
}
