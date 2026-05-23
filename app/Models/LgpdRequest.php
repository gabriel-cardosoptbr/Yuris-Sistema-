<?php
namespace App\Models;

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
}
