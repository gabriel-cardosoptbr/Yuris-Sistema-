<?php
namespace App\Models;

/**
 * SecurityIncident — incidentes de segurança envolvendo dados pessoais
 * (LGPD Art. 48 — comunicação obrigatória à ANPD e aos titulares afetados).
 *
 * Fluxo típico:
 *   1. Equipe técnica detecta evento suspeito → SecurityIncident::create()
 *   2. DPO classifica severidade, identifica titulares afetados
 *   3. Status evolui: detectado → em_analise → contido → mitigado
 *   4. Se houver risco/dano relevante: notificacao_anpd_em + notificacao_titulares_em
 *   5. Após mitigação completa e comunicações: encerrado
 *   6. Cada mudança de status / ação fica em security_incident_events (imutável)
 *
 * Quem grava: tipicamente super_admin via Painel Master, mas a tabela suporta
 * registros automatizados (user_id NULL = sistema, ex.: detector de brute-force).
 */
final class SecurityIncident
{
    public const TIPOS = [
        'vazamento_dados',
        'acesso_indevido',
        'ransomware',
        'phishing',
        'dos_ddos',
        'exposicao_credenciais',
        'perda_dispositivo',
        'engenharia_social',
        'config_indevida',
        'outro',
    ];

    public const SEVERIDADES = ['baixa', 'media', 'alta', 'critica'];

    public const STATUSES = [
        'detectado',
        'em_analise',
        'contido',
        'mitigado',
        'notificado_anpd',
        'notificado_titulares',
        'encerrado',
        'falso_positivo',
    ];

    /** Categorias de dados afetados (validar contra esta lista no JSON dados_afetados). */
    public const CATEGORIAS_DADOS = [
        'pii_basica',          // nome, email, telefone
        'documentos',          // CPF, RG, CNH, OAB
        'financeiro',          // dados bancários, pagamentos
        'juridico',            // processos, documentos de causas
        'autenticacao',        // senhas (hash), MFA secrets, tokens
        'comunicacoes',        // mensagens chat, WhatsApp
        'dados_sensiveis',     // saúde, orientação sexual, religião etc (Art. 5 II)
    ];

    /**
     * Cria novo incidente. Retorna o id criado.
     * Já grava evento "criado" automaticamente em events.
     */
    public static function create(array $data, ?int $byUserId = null): int
    {
        $tipo       = (string)($data['tipo'] ?? '');
        $severidade = (string)($data['severidade'] ?? 'media');
        $titulo     = trim((string)($data['titulo'] ?? ''));

        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException("Tipo inválido: $tipo");
        }
        if (!in_array($severidade, self::SEVERIDADES, true)) {
            throw new \InvalidArgumentException("Severidade inválida: $severidade");
        }
        if ($titulo === '') {
            throw new \InvalidArgumentException('titulo obrigatório');
        }

        $detectadoEm = $data['detectado_em'] ?? date('Y-m-d H:i:s');
        $dadosAfet   = isset($data['dados_afetados']) && is_array($data['dados_afetados'])
            ? json_encode($data['dados_afetados'], JSON_UNESCAPED_UNICODE)
            : null;

        // request_id para correlação com master_audit_log (se helper disponível)
        $rid = null;
        if (class_exists('App\\Helpers\\RequestId')) {
            $rid = \App\Helpers\RequestId::get();
        }

        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO security_incidents
               (account_id, tipo, severidade, status, titulo,
                descricao_interna, descricao_publica, dados_afetados, impacto,
                causa_raiz, medidas_imediatas, medidas_corretivas,
                detectado_em, ocorrido_em,
                reportado_por_user_id, responsavel_user_id, request_id)
             VALUES
               (:acc, :tipo, :sev, 'detectado', :tit,
                :di, :dp, :da, :imp,
                :cr, :mi, :mc,
                :det, :occ,
                :rep, :resp, :rid)"
        )->execute([
            'acc'  => $data['account_id']         ?? null,
            'tipo' => $tipo,
            'sev'  => $severidade,
            'tit'  => $titulo,
            'di'   => $data['descricao_interna'] ?? null,
            'dp'   => $data['descricao_publica'] ?? null,
            'da'   => $dadosAfet,
            'imp'  => $data['impacto']           ?? null,
            'cr'   => $data['causa_raiz']        ?? null,
            'mi'   => $data['medidas_imediatas'] ?? null,
            'mc'   => $data['medidas_corretivas']?? null,
            'det'  => $detectadoEm,
            'occ'  => $data['ocorrido_em']       ?? null,
            'rep'  => $byUserId ?? ($data['reportado_por_user_id'] ?? null),
            'resp' => $data['responsavel_user_id'] ?? null,
            'rid'  => $rid,
        ]);
        $id = (int)$pdo->lastInsertId();

        // Evento "criado"
        self::addEvent($id, 'criado',
            "Incidente registrado por usuario #" . (int)($byUserId ?? 0) .
            " — tipo={$tipo}, severidade={$severidade}",
            $byUserId,
            ['titulo' => $titulo, 'tipo' => $tipo, 'severidade' => $severidade],
            null, 'detectado'
        );

        return $id;
    }

    /** Busca por id. */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT * FROM security_incidents WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;

        // Decodifica dados_afetados se vier JSON válido
        if (!empty($r['dados_afetados'])) {
            $decoded = json_decode($r['dados_afetados'], true);
            if (is_array($decoded)) $r['dados_afetados'] = $decoded;
        }
        return $r;
    }

    /**
     * Lista filtrada para Painel Master.
     * Filtros: status, severidade, tipo, q (busca em titulo), account_id, abertos (não-encerrados)
     */
    public static function listForMaster(array $f = [], int $limit = 200): array
    {
        $pdo = Database::getConnection();
        $w = [];
        $p = [];

        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $w[] = 'i.status = :st'; $p['st'] = $f['status'];
        }
        if (!empty($f['severidade']) && in_array($f['severidade'], self::SEVERIDADES, true)) {
            $w[] = 'i.severidade = :sv'; $p['sv'] = $f['severidade'];
        }
        if (!empty($f['tipo']) && in_array($f['tipo'], self::TIPOS, true)) {
            $w[] = 'i.tipo = :tp'; $p['tp'] = $f['tipo'];
        }
        if (!empty($f['q'])) {
            $w[] = 'i.titulo LIKE :q';
            $p['q'] = '%' . $f['q'] . '%';
        }
        if (!empty($f['account_id'])) {
            $w[] = 'i.account_id = :acc'; $p['acc'] = (int)$f['account_id'];
        }
        if (!empty($f['abertos'])) {
            $w[] = "i.status NOT IN ('encerrado','falso_positivo')";
        }

        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        $limit = max(1, min(500, $limit));

        $sql = "SELECT i.id, i.account_id, i.tipo, i.severidade, i.status, i.titulo,
                       i.detectado_em, i.ocorrido_em, i.encerrado_em,
                       i.notificacao_anpd_em, i.notificacao_titulares_em,
                       a.nome AS account_nome
                FROM security_incidents i
                LEFT JOIN accounts a ON a.id = i.account_id
                $where
                ORDER BY
                  CASE i.severidade WHEN 'critica' THEN 0 WHEN 'alta' THEN 1
                                    WHEN 'media' THEN 2 WHEN 'baixa' THEN 3 END,
                  CASE WHEN i.status IN ('encerrado','falso_positivo') THEN 1 ELSE 0 END,
                  i.detectado_em DESC
                LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza campos permitidos. Auto-gera eventos para mudanças relevantes:
     *  - status (vira evento status_alterado)
     *  - notificacao_anpd_em      (vira evento notificacao_anpd)
     *  - notificacao_titulares_em (vira evento notificacao_titulares)
     */
    public static function update(int $id, array $data, ?int $byUserId = null): bool
    {
        $pdo = Database::getConnection();
        $old = self::findById($id);
        if (!$old) return false;

        $allowed = [
            'tipo', 'severidade', 'status', 'titulo',
            'descricao_interna', 'descricao_publica', 'impacto',
            'causa_raiz', 'medidas_imediatas', 'medidas_corretivas',
            'ocorrido_em', 'contido_em', 'mitigado_em',
            'notificacao_anpd_em', 'notificacao_anpd_protocolo',
            'notificacao_titulares_em', 'notificacao_titulares_canal',
            'encerrado_em', 'responsavel_user_id', 'account_id',
        ];

        $fields = []; $params = ['id' => $id];

        foreach ($allowed as $f) {
            if (!array_key_exists($f, $data)) continue;
            $v = $data[$f];

            // Validações de enum
            if ($f === 'tipo'       && !in_array($v, self::TIPOS, true))       continue;
            if ($f === 'severidade' && !in_array($v, self::SEVERIDADES, true)) continue;
            if ($f === 'status'     && !in_array($v, self::STATUSES, true))    continue;

            $fields[]    = "$f = :$f";
            $params[$f]  = $v;
        }

        // dados_afetados sempre serializa
        if (array_key_exists('dados_afetados', $data)) {
            $fields[] = 'dados_afetados = :dafl';
            $params['dafl'] = is_array($data['dados_afetados'])
                ? json_encode($data['dados_afetados'], JSON_UNESCAPED_UNICODE)
                : $data['dados_afetados'];
        }

        // Quando vira encerrado/falso_positivo, marca encerrado_em automaticamente
        if (isset($data['status']) && in_array($data['status'], ['encerrado','falso_positivo'], true)
            && empty($data['encerrado_em']) && empty($old['encerrado_em'])) {
            $fields[] = 'encerrado_em = NOW()';
        }

        if (empty($fields)) return false;

        $sql = 'UPDATE security_incidents SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        // Eventos de auditoria
        if (isset($data['status']) && $data['status'] !== $old['status']) {
            self::addEvent($id, 'status_alterado',
                "Status: {$old['status']} → {$data['status']}",
                $byUserId,
                ['from' => $old['status'], 'to' => $data['status']],
                $old['status'], $data['status']
            );
        }
        if (isset($data['notificacao_anpd_em']) && $data['notificacao_anpd_em']
            && empty($old['notificacao_anpd_em'])) {
            self::addEvent($id, 'notificacao_anpd',
                'Notificação enviada à ANPD' .
                (!empty($data['notificacao_anpd_protocolo'])
                    ? ' — protocolo ' . $data['notificacao_anpd_protocolo'] : ''),
                $byUserId,
                ['protocolo' => $data['notificacao_anpd_protocolo'] ?? null]
            );
        }
        if (isset($data['notificacao_titulares_em']) && $data['notificacao_titulares_em']
            && empty($old['notificacao_titulares_em'])) {
            self::addEvent($id, 'notificacao_titulares',
                'Notificação enviada aos titulares' .
                (!empty($data['notificacao_titulares_canal'])
                    ? ' via ' . $data['notificacao_titulares_canal'] : ''),
                $byUserId,
                ['canal' => $data['notificacao_titulares_canal'] ?? null]
            );
        }
        return true;
    }

    /** Atalho: marca notificação ANPD + grava evento. */
    public static function markNotifiedAnpd(int $id, string $protocolo = '', ?int $byUserId = null): bool
    {
        return self::update($id, [
            'notificacao_anpd_em'         => date('Y-m-d H:i:s'),
            'notificacao_anpd_protocolo'  => $protocolo ?: null,
        ], $byUserId);
    }

    /** Atalho: marca notificação aos titulares + grava evento. */
    public static function markNotifiedHolders(int $id, string $canal = 'email', ?int $byUserId = null): bool
    {
        return self::update($id, [
            'notificacao_titulares_em'    => date('Y-m-d H:i:s'),
            'notificacao_titulares_canal' => $canal,
        ], $byUserId);
    }

    /**
     * Insere evento em security_incident_events.
     * Captura IP / UA / request_id automaticamente.
     */
    public static function addEvent(
        int $incidentId,
        string $tipoEvento,
        ?string $descricao = null,
        ?int $userId = null,
        ?array $payload = null,
        ?string $statusAnterior = null,
        ?string $statusNovo = null
    ): void {
        $pdo = Database::getConnection();
        $rid = null;
        if (class_exists('App\\Helpers\\RequestId')) {
            $rid = \App\Helpers\RequestId::get();
        }
        $pdo->prepare(
            "INSERT INTO security_incident_events
               (incident_id, tipo_evento, descricao, status_anterior, status_novo,
                payload, user_id, ip, user_agent, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $incidentId,
            $tipoEvento,
            $descricao,
            $statusAnterior,
            $statusNovo,
            $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $rid,
        ]);
    }

    /** Lista eventos cronológicos. */
    public static function listEvents(int $incidentId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            'SELECT e.*, u.nome AS user_nome
             FROM security_incident_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.incident_id = ?
             ORDER BY e.created_at ASC, e.id ASC'
        );
        $st->execute([$incidentId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        // Decodifica payload JSON
        foreach ($rows as &$r) {
            if (!empty($r['payload'])) {
                $d = json_decode($r['payload'], true);
                if (is_array($d)) $r['payload'] = $d;
            }
        }
        return $rows;
    }

    /**
     * Contagens para badge no master.php.
     * Retorna: total, abertos, criticos_abertos, notificacao_pendente
     */
    public static function countByStatus(): array
    {
        $pdo = Database::getConnection();
        $out = [
            'total' => 0,
            'abertos' => 0,
            'criticos_abertos' => 0,
            'notificacao_pendente' => 0,
        ];
        $rows = $pdo->query(
            "SELECT status, severidade, COUNT(*) AS total,
                    SUM(CASE WHEN notificacao_anpd_em IS NULL
                              AND notificacao_titulares_em IS NULL
                              AND status NOT IN ('encerrado','falso_positivo')
                              AND severidade IN ('alta','critica')
                        THEN 1 ELSE 0 END) AS notif_pend
             FROM security_incidents
             GROUP BY status, severidade"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $n = (int)$r['total'];
            $out['total'] += $n;
            $out['notificacao_pendente'] += (int)$r['notif_pend'];

            if (!in_array($r['status'], ['encerrado','falso_positivo'], true)) {
                $out['abertos'] += $n;
                if ($r['severidade'] === 'critica') {
                    $out['criticos_abertos'] += $n;
                }
            }
        }
        return $out;
    }

    /**
     * Gera relatório público (sanitizado) para anexar à notificação ao titular.
     * NÃO inclui causa_raiz, medidas técnicas internas ou descricao_interna.
     */
    public static function generatePublicReport(int $id): ?array
    {
        $row = self::findById($id);
        if (!$row) return null;
        return [
            'protocolo_interno'   => 'INC-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT),
            'tipo'                => $row['tipo'],
            'severidade'          => $row['severidade'],
            'titulo'              => $row['titulo'],
            'descricao'           => $row['descricao_publica'] ?? '(em elaboração)',
            'dados_afetados'      => $row['dados_afetados']    ?? null,
            'impacto'             => $row['impacto']           ?? '(em avaliação)',
            'detectado_em'        => $row['detectado_em'],
            'ocorrido_em'         => $row['ocorrido_em'],
            'medidas_imediatas'   => $row['medidas_imediatas'] ?? '(em curso)',
            'status'              => $row['status'],
            'notificacao_anpd_em' => $row['notificacao_anpd_em'],
            'gerado_em'           => date('Y-m-d H:i:s'),
        ];
    }
}
