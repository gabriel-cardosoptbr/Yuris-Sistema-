<?php
namespace App\Usuarios;

/**
 * Consent — consentimentos granulares LGPD (Art. 8º, Art. 18 IX).
 *
 * Diferente de TermAcceptance (aceite de documento inteiro), Consent registra
 * autorização específica POR FINALIDADE — cookies analíticos, marketing,
 * processamento por IA, envio WhatsApp comercial etc.
 *
 * Pode ser concedido por user logado OU por titular anônimo (email).
 * Pode ser revogado a qualquer momento (LGPD Art. 18 IX).
 */
final class Consent
{
    public const FINALIDADES_PADRAO = [
        'cookies_essenciais',
        'cookies_analiticos',
        'cookies_marketing',
        'cookies_funcionais',
        'marketing_email',
        'ia_processar_dados',
        'envio_whatsapp_comercial',
    ];

    public const BASES_LEGAIS = [
        'consentimento','contrato','obrigacao_legal','exercicio_direito',
        'interesse_legitimo','tutela_saude','protecao_credito','interesse_publico',
    ];

    /**
     * Concede consentimento. Se já existe ATIVO para mesma finalidade+titular,
     * é idempotente (não duplica — atualiza ip/user_agent/evidencia).
     *
     * @return int id (linha criada ou atualizada)
     */
    public static function grant(array $data): int
    {
        $finalidade = (string)($data['finalidade'] ?? '');
        if ($finalidade === '') {
            throw new \InvalidArgumentException('Consent: finalidade é obrigatória');
        }
        $baseLegal = $data['base_legal'] ?? 'consentimento';
        if (!in_array($baseLegal, self::BASES_LEGAIS, true)) {
            throw new \InvalidArgumentException('Consent: base_legal inválida');
        }

        $pdo = Database::getConnection();

        // Procura ativo anterior pra mesmo titular+finalidade
        $existing = self::findActive(
            $data['user_id']       ?? null,
            $data['titular_email'] ?? null,
            $finalidade
        );

        $ip = $data['ip']         ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $ua = $data['user_agent'] ?? substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $ev = isset($data['evidencia']) ? (is_string($data['evidencia']) ? $data['evidencia'] : json_encode($data['evidencia'])) : null;

        if ($existing) {
            $pdo->prepare(
                "UPDATE lgpd_consents SET ip = :ip, user_agent = :ua, evidencia = :ev,
                        concedido_em = NOW(), revogado_em = NULL, status = 'ativo'
                 WHERE id = :id"
            )->execute(['ip' => $ip, 'ua' => $ua, 'ev' => $ev, 'id' => $existing['id']]);
            return (int)$existing['id'];
        }

        $pdo->prepare(
            "INSERT INTO lgpd_consents
              (user_id, account_id, titular_email, titular_telefone, finalidade,
               base_legal, status, versao_termo_id, concedido_em, fonte, ip, user_agent, evidencia)
             VALUES
              (:uid, :acc, :email, :tel, :fin, :bl, 'ativo', :vt, NOW(), :fonte, :ip, :ua, :ev)"
        )->execute([
            'uid'  => $data['user_id']         ?? null,
            'acc'  => $data['account_id']      ?? null,
            'email'=> $data['titular_email']   ?? null,
            'tel'  => $data['titular_telefone']?? null,
            'fin'  => $finalidade,
            'bl'   => $baseLegal,
            'vt'   => $data['versao_termo_id'] ?? null,
            'fonte'=> $data['fonte']           ?? null,
            'ip'   => $ip,
            'ua'   => $ua,
            'ev'   => $ev,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Webhook: lgpd.consent_given (etapa 10/11) — somente em INSERT novo;
        // re-grant idempotente (caminho `existing`) acima ja retornou sem chegar aqui.
        try {
            require_once __DIR__ . '/../Integracoes/WebhookDispatcher.php';
            $isTerms = ($finalidade === 'termos_uso_login');
            $eventCode = $isTerms ? 'lgpd.terms_accepted' : 'lgpd.consent_given';
            \App\Integracoes\WebhookDispatcher::fire(
                isset($data['account_id']) ? (int)$data['account_id'] : null,
                $eventCode,
                \App\Integracoes\WebhookDispatcher::buildPayload($eventCode, [
                    'entity'    => 'lgpd_consent',
                    'entity_id' => $newId,
                    'data'      => [
                        'id'             => $newId,
                        'finalidade'     => $finalidade,
                        'base_legal'     => $baseLegal,
                        'fonte'          => $data['fonte'] ?? null,
                        'titular_email'  => $data['titular_email'] ?? null,
                    ],
                ])
            );
        } catch (\Throwable $_) { /* fail silently — consent nao pode quebrar */ }

        return $newId;
    }

    /**
     * Revoga consentimento ativo. Retorna número de linhas afetadas.
     * Não deleta — marca como 'revogado' + grava revogado_em (auditável).
     */
    public static function revoke(?int $userId, ?string $titularEmail, string $finalidade): int
    {
        $pdo = Database::getConnection();
        $sql = "UPDATE lgpd_consents
                SET status = 'revogado', revogado_em = NOW()
                WHERE status = 'ativo' AND finalidade = :fin AND ";
        $params = ['fin' => $finalidade];

        if ($userId) {
            $sql .= 'user_id = :uid';
            $params['uid'] = $userId;
        } elseif ($titularEmail) {
            $sql .= 'titular_email = :email AND user_id IS NULL';
            $params['email'] = $titularEmail;
        } else {
            throw new \InvalidArgumentException('Consent::revoke: precisa user_id ou titular_email');
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * Retorna o consentimento ATIVO mais recente (ou null).
     */
    public static function findActive(?int $userId, ?string $titularEmail, string $finalidade): ?array
    {
        $pdo = Database::getConnection();
        $sql = "SELECT * FROM lgpd_consents
                WHERE status = 'ativo' AND finalidade = :fin AND ";
        $params = ['fin' => $finalidade];
        if ($userId) {
            $sql .= 'user_id = :uid';
            $params['uid'] = $userId;
        } elseif ($titularEmail) {
            $sql .= 'titular_email = :email AND user_id IS NULL';
            $params['email'] = $titularEmail;
        } else {
            return null;
        }
        $sql .= ' ORDER BY concedido_em DESC LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Lista todos os consentimentos (ativos + revogados) de um titular. */
    public static function listAll(?int $userId, ?string $titularEmail): array
    {
        if (!$userId && !$titularEmail) return [];
        $pdo = Database::getConnection();
        if ($userId) {
            $st = $pdo->prepare("SELECT * FROM lgpd_consents WHERE user_id = :uid ORDER BY concedido_em DESC");
            $st->execute(['uid' => $userId]);
        } else {
            $st = $pdo->prepare("SELECT * FROM lgpd_consents WHERE titular_email = :email AND user_id IS NULL ORDER BY concedido_em DESC");
            $st->execute(['email' => $titularEmail]);
        }
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Diz se o titular tem consentimento ATIVO para a finalidade. */
    public static function isGranted(?int $userId, ?string $titularEmail, string $finalidade): bool
    {
        return self::findActive($userId, $titularEmail, $finalidade) !== null;
    }
}
