<?php
namespace App\Helpers;

use App\Models\Database;

/**
 * Anonymizer — substitui dados pessoais por placeholder preservando FKs.
 *
 * LGPD Art. 12: dados anonimizados não são considerados dados pessoais.
 * Diferente de hard-delete: preserva o registro (e suas relações) mas torna
 * impossível identificar o titular.
 *
 * Cada operação loga em `anonymization_log` (auditoria irreversível mas
 * rastreável).
 *
 * USO típico — disparado pela Central LGPD quando DPO conclui solicitação
 * de eliminação/anonimização:
 *
 *   Anonymizer::user(42, 'Solicitação LGPD #15', $dpoUserId, $lgpdReqId);
 *   Anonymizer::contato(99, 'Eliminação solicitada', $dpoUserId, $lgpdReqId);
 *   $path = Anonymizer::exportTitular('titular@example.com');  // portabilidade
 *
 * Métodos retornam true/false (sucesso) ou path (export).
 */
final class Anonymizer
{
    private const PLACEHOLDER_NOME = 'Titular Anonimizado';
    private const PLACEHOLDER_EMAIL_DOMAIN = '@anonimizado.local';

    /**
     * Anonimiza um usuário: nome/login/telefone/oab.
     * MANTÉM: id, account_id, role, codigo_advogado (UNIQUE), histórico em logs.
     */
    public static function user(int $id, ?string $motivo = null, ?int $byUserId = null, ?int $lgpdReqId = null): bool
    {
        $pdo = Database::getConnection();
        $row = $pdo->prepare('SELECT id, nome, login FROM users WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        $u = $row->fetch(\PDO::FETCH_ASSOC);
        if (!$u) return false;

        $newEmail = 'anon-' . substr(hash('sha256', $u['login'] . $id), 0, 12) . self::PLACEHOLDER_EMAIL_DOMAIN;

        $sql = "UPDATE users SET
                  nome = :nome,
                  login = :login,
                  senha_hash = '__ANONIMIZADO__',
                  telefone = NULL,
                  oab = NULL,
                  oab_uf = NULL,
                  nome_advogado = NULL,
                  anonymized_at = NOW(),
                  deletion_reason = :motivo
                WHERE id = :id";
        $ok = $pdo->prepare($sql)->execute([
            'nome'   => self::PLACEHOLDER_NOME . ' #' . $id,
            'login'  => $newEmail,
            'motivo' => $motivo,
            'id'     => $id,
        ]);
        if ($ok) {
            self::log('user', $id, $motivo, $byUserId, $lgpdReqId,
                ['nome','login','senha_hash','telefone','oab','oab_uf','nome_advogado']);
            // Cascade: apaga anotações pessoais do user em intimações (LGPD Etapa 4 — F2026-05-24)
            try { self::pushUserStatus($id, $motivo, $byUserId, $lgpdReqId); } catch (\Throwable $_) {}
        }
        return $ok;
    }

    /** Anonimiza contato: nome, telefone, email, observações. */
    public static function contato(int $id, ?string $motivo = null, ?int $byUserId = null, ?int $lgpdReqId = null): bool
    {
        $pdo = Database::getConnection();
        $row = $pdo->prepare('SELECT id FROM contatos WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        if (!$row->fetch(\PDO::FETCH_ASSOC)) return false;

        $sql = "UPDATE contatos SET
                  nome = :nome,
                  telefone = NULL,
                  remote_jid = NULL,
                  email = NULL,
                  observacoes = NULL,
                  anonymized_at = NOW(),
                  deletion_reason = :motivo
                WHERE id = :id";
        $ok = $pdo->prepare($sql)->execute([
            'nome'   => self::PLACEHOLDER_NOME . ' #' . $id,
            'motivo' => $motivo,
            'id'     => $id,
        ]);
        if ($ok) {
            self::log('contato', $id, $motivo, $byUserId, $lgpdReqId,
                ['nome','telefone','remote_jid','email','observacoes']);
        }
        return $ok;
    }

    /** Anonimiza card (lead): nome, empresa, telefone, email, descrição. */
    public static function card(int $id, ?string $motivo = null, ?int $byUserId = null, ?int $lgpdReqId = null): bool
    {
        $pdo = Database::getConnection();
        $row = $pdo->prepare('SELECT id FROM cards WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        if (!$row->fetch(\PDO::FETCH_ASSOC)) return false;

        $sql = "UPDATE cards SET
                  cliente_nome = :nome,
                  empresa_nome = NULL,
                  telefone_whatsapp = NULL,
                  email = NULL,
                  descricao = NULL,
                  titulo = :titulo,
                  anonymized_at = NOW(),
                  deletion_reason = :motivo
                WHERE id = :id";
        $ok = $pdo->prepare($sql)->execute([
            'nome'   => self::PLACEHOLDER_NOME . ' #' . $id,
            'titulo' => 'Lead anonimizado #' . $id,
            'motivo' => $motivo,
            'id'     => $id,
        ]);
        if ($ok) {
            self::log('card', $id, $motivo, $byUserId, $lgpdReqId,
                ['cliente_nome','empresa_nome','telefone_whatsapp','email','descricao','titulo']);
        }
        return $ok;
    }

    /** Anonimiza PARTE CONTRÁRIA de um processo (não o cliente representado). */
    public static function processoParte(int $id, ?string $motivo = null, ?int $byUserId = null, ?int $lgpdReqId = null): bool
    {
        $pdo = Database::getConnection();
        $row = $pdo->prepare('SELECT id FROM processos WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        if (!$row->fetch(\PDO::FETCH_ASSOC)) return false;

        $sql = "UPDATE processos SET
                  parte_contraria = :parte,
                  cpf_cnpj_parte_contraria = NULL,
                  anonymized_at = NOW(),
                  deletion_reason = :motivo
                WHERE id = :id";
        $ok = $pdo->prepare($sql)->execute([
            'parte'  => 'Parte anonimizada',
            'motivo' => $motivo,
            'id'     => $id,
        ]);
        if ($ok) {
            self::log('processo', $id, $motivo, $byUserId, $lgpdReqId,
                ['parte_contraria','cpf_cnpj_parte_contraria']);
        }
        return $ok;
    }

    /**
     * Apaga comentários internos de um usuário em intimações.
     * Conteúdo da publicação em si (push_events.conteudo) NÃO é tocado —
     * é dado público (Diário de Justiça Eletrônico Nacional).
     *
     * Chamado em cascata por Anonymizer::user() (idempotente).
     */
    public static function pushUserStatus(int $userId, ?string $motivo = null, ?int $byUserId = null, ?int $lgpdReqId = null): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "UPDATE push_event_user_status
             SET comentario = NULL, updated_at = NOW()
             WHERE user_id = :uid AND comentario IS NOT NULL"
        );
        $ok = $stmt->execute(['uid' => $userId]);
        if ($ok && $stmt->rowCount() > 0) {
            self::log('push_event_user_status', $userId, $motivo, $byUserId, $lgpdReqId,
                ['comentario', '__rowcount__' => $stmt->rowCount()]);
        }
        return $ok;
    }

    /**
     * Purge total de intimações de uma conta (eliminação de tenant).
     * Apaga cache temporário, eventos persistidos, status dos usuários,
     * monitores e logs.
     *
     * Chamado apenas em deleção definitiva da conta.
     */
    public static function pushAccountPurge(int $accountId, ?string $motivo = null, ?int $byUserId = null, ?int $lgpdReqId = null): array
    {
        $pdo = Database::getConnection();
        $counts = [];

        $tables = [
            'push_event_user_status' => 'account_id',
            'push_today_cache'       => 'account_id',
            'push_query_logs'        => 'account_id',
            'push_monitors'          => 'account_id',
            'push_events'            => 'account_id', // CASCADE apaga user_status linkado
            // AASP — credenciais e auditoria de credencial
            // chave_encrypted apagada junto (não dá pra decifrar depois)
            'aasp_credential_audit'  => 'account_id',
            'aasp_integrations'      => 'account_id',
        ];
        foreach ($tables as $tbl => $col) {
            try {
                $st = $pdo->prepare("DELETE FROM {$tbl} WHERE {$col} = :acc");
                $st->execute(['acc' => $accountId]);
                $counts[$tbl] = $st->rowCount();
            } catch (\Throwable $e) {
                $counts[$tbl] = 'error: ' . $e->getMessage();
            }
        }

        self::log('push_account_purge', $accountId, $motivo, $byUserId, $lgpdReqId,
            array_merge(array_keys($tables), ['__counts__' => $counts]));

        return $counts;
    }

    /**
     * Exporta TODOS os dados de um titular (identificado por email) em JSON.
     * Implementa Art. 18 V LGPD (portabilidade).
     *
     * Retorna path absoluto do ZIP gerado (em storage/lgpd_exports/).
     * O caller (UI Master) anexa esse path na lgpd_request.arquivo_resposta_path.
     */
    public static function exportTitular(string $email, ?int $lgpdReqId = null): string
    {
        $pdo = Database::getConnection();
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException('email obrigatório');
        }

        $data = [
            'export_generated_at' => date('c'),
            'titular_email'       => $email,
            'lgpd_request_id'     => $lgpdReqId,
            'aviso'               => 'Este arquivo contém dados pessoais. Distribua apenas para o titular ou autoridade competente.',
            'dados' => [
                'users'     => self::collectUsers($pdo, $email),
                'contatos'  => self::collectContatos($pdo, $email),
                'cards'     => self::collectCards($pdo, $email),
                'mensagens_whatsapp' => self::collectWhatsAppMessages($pdo, $email),
                'aceites_termos'     => self::collectTermAcceptances($pdo, $email),
                'consentimentos'     => self::collectConsents($pdo, $email),
                'solicitacoes_lgpd'  => self::collectLgpdRequests($pdo, $email),
            ],
        ];

        $storageDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lgpd_exports';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0750, true);
        }
        $safeName = 'export_' . preg_replace('/[^a-z0-9]+/i', '_', $email) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $jsonPath = $storageDir . DIRECTORY_SEPARATOR . $safeName . '.json';
        file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zipPath = $storageDir . DIRECTORY_SEPARATOR . $safeName . '.zip';
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $zip->addFile($jsonPath, 'dados_titular.json');
                $zip->addFromString('LEIA-ME.txt',
                    "Export LGPD — Portabilidade de Dados\n" .
                    "Titular: {$email}\n" .
                    "Gerado em: " . date('c') . "\n\n" .
                    "Este arquivo contém todos os dados pessoais armazenados pelo Yuris vinculados ao email acima.\n" .
                    "Mantenha em local seguro. LGPD Art. 18 V.\n"
                );
                $zip->close();
                @unlink($jsonPath); // remove JSON solto, mantém só ZIP
            }
        }
        return file_exists($zipPath) ? $zipPath : $jsonPath;
    }

    // ─── Coletores internos para export ─────────────────────────────────────
    private static function collectUsers(\PDO $pdo, string $email): array
    {
        $st = $pdo->prepare(
            'SELECT id, account_id, nome, login, telefone, oab, oab_uf, perfil, role, status,
                    created_at, updated_at, anonymized_at
             FROM users WHERE LOWER(login) = ? LIMIT 50'
        );
        $st->execute([$email]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    private static function collectContatos(\PDO $pdo, string $email): array
    {
        $st = $pdo->prepare(
            'SELECT id, account_id, nome, telefone, email, observacoes, created_at, anonymized_at
             FROM contatos WHERE LOWER(email) = ? LIMIT 100'
        );
        $st->execute([$email]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    private static function collectCards(\PDO $pdo, string $email): array
    {
        $st = $pdo->prepare(
            'SELECT id, account_id, cliente_nome, empresa_nome, telefone_whatsapp, email,
                    descricao, status, created_at, anonymized_at
             FROM cards WHERE LOWER(email) = ? LIMIT 100'
        );
        $st->execute([$email]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    private static function collectWhatsAppMessages(\PDO $pdo, string $email): array
    {
        // WhatsApp não tem email; correlaciona via contatos.remote_jid → messages
        $st = $pdo->prepare(
            'SELECT m.id, m.account_id, m.remote_jid, m.contact_name, m.message_type,
                    m.message_content, m.created_at
             FROM whatsapp_messages m
             INNER JOIN contatos c ON c.remote_jid = m.remote_jid
             WHERE LOWER(c.email) = ?
             ORDER BY m.created_at DESC LIMIT 500'
        );
        $st->execute([$email]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    private static function collectTermAcceptances(\PDO $pdo, string $email): array
    {
        $st = $pdo->prepare(
            'SELECT ta.id, ta.legal_document_id, ld.tipo, ld.versao, ta.contexto, ta.accepted_at
             FROM term_acceptances ta
             LEFT JOIN legal_documents ld ON ld.id = ta.legal_document_id
             WHERE LOWER(ta.titular_email) = ?
                OR ta.user_id IN (SELECT id FROM users WHERE LOWER(login) = ?)'
        );
        $st->execute([$email, $email]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    private static function collectConsents(\PDO $pdo, string $email): array
    {
        $st = $pdo->prepare(
            'SELECT id, finalidade, base_legal, status, concedido_em, revogado_em, fonte
             FROM lgpd_consents
             WHERE LOWER(titular_email) = ?
                OR user_id IN (SELECT id FROM users WHERE LOWER(login) = ?)'
        );
        $st->execute([$email, $email]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    private static function collectLgpdRequests(\PDO $pdo, string $email): array
    {
        $st = $pdo->prepare(
            'SELECT id, titular_nome, tipo, status, recebido_em, prazo_resposta,
                    respondido_em, resposta
             FROM lgpd_requests WHERE LOWER(titular_email) = ?'
        );
        $st->execute([$email]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── Audit log ──────────────────────────────────────────────────────────
    private static function log(string $entidade, int $entidadeId, ?string $motivo,
                                ?int $userId, ?int $lgpdReqId, array $campos): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO anonymization_log
              (entidade, entidade_id, motivo, executado_por_user_id, lgpd_request_id, campos_afetados, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $entidade, $entidadeId, $motivo, $userId, $lgpdReqId,
            json_encode($campos, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // F3 (LGPD v2): busca estruturada multi-módulo para Central LGPD
    // ═══════════════════════════════════════════════════════════════════════

    /** Limite padrão por módulo para evitar travar a UI. */
    private const SEARCH_LIMIT_PER_MODULE = 500;

    /**
     * Executa busca completa por dados do titular em todas as fontes do sistema.
     * Para cada módulo:
     *  - Registra entrada em lgpd_request_modules (mesmo com 0 achados)
     *  - Para cada achado: registra em lgpd_request_findings (com mascaramento)
     *  - Registra evento 'busca_modulo' em lgpd_request_events
     *
     * @param int $requestId   lgpd_requests.id que disparou a busca
     * @param string $email    e-mail do titular
     * @param string|null $cpf CPF/CNPJ (opcional — aumenta cobertura)
     * @param string|null $tel telefone (opcional)
     * @param int $byUserId    DPO que disparou
     * @return array sumário {modulo => total_encontrado}
     */
    public static function searchAllModules(
        int $requestId,
        string $email,
        ?string $cpf = null,
        ?string $tel = null,
        int $byUserId = 0
    ): array {
        // Lazy-load Models v2
        if (!class_exists('App\\Models\\LgpdRequestModule')) {
            require_once dirname(__DIR__) . '/Models/LgpdRequestModule.php';
        }
        if (!class_exists('App\\Models\\LgpdRequestFinding')) {
            require_once dirname(__DIR__) . '/Models/LgpdRequestFinding.php';
        }
        if (!class_exists('App\\Models\\LgpdRequest')) {
            require_once dirname(__DIR__) . '/Models/LgpdRequest.php';
        }

        $email = strtolower(trim($email));
        $cpfClean = $cpf ? preg_replace('/\D/', '', $cpf) : null;
        $telClean = $tel ? preg_replace('/\D/', '', $tel) : null;

        $pdo = Database::getConnection();
        $totals = [];

        // 1. usuarios
        $totals['usuarios'] = self::searchUsers($pdo, $requestId, $email, $cpfClean, $telClean, $byUserId);

        // 2. contatos
        $totals['contatos'] = self::searchContatos($pdo, $requestId, $email, $cpfClean, $telClean, $byUserId);

        // 3. leads_cards
        $totals['leads_cards'] = self::searchCards($pdo, $requestId, $email, $telClean, $byUserId);

        // 4. processos (parte_contraria + cliente_nome)
        $totals['processos'] = self::searchProcessos($pdo, $requestId, $email, $cpfClean, $byUserId);

        // 5. whatsapp (mensagens via contato.email/telefone → remote_jid)
        $totals['whatsapp'] = self::searchWhatsApp($pdo, $requestId, $email, $telClean, $byUserId);

        // 6. chat_interno (mensagens onde titular = usuario_id)
        $totals['chat_interno'] = self::searchChatInterno($pdo, $requestId, $email, $byUserId);

        // 7. task_attachments (nomes de arquivo podem citar pessoas)
        $totals['task_attachments'] = self::searchTaskAttachments($pdo, $requestId, $email, $byUserId);

        // 8. financeiro (invoices via account_id do user titular)
        $totals['financeiro'] = self::searchFinanceiro($pdo, $requestId, $email, $byUserId);

        // 9. logs_auditoria (master_audit + account_audit por user_id)
        $totals['logs_auditoria'] = self::searchLogsAuditoria($pdo, $requestId, $email, $byUserId);

        // 10. consentimentos
        $totals['consentimentos'] = self::searchConsentimentos($pdo, $requestId, $email, $byUserId);

        // 11. aceites_termos
        $totals['aceites_termos'] = self::searchAceitesTermos($pdo, $requestId, $email, $byUserId);

        // Registra evento sumário na timeline da solicitação
        $totalGeral = array_sum($totals);
        $resumoModulos = implode(', ', array_map(
            fn($m, $n) => "$m=$n",
            array_keys($totals), array_values($totals)
        ));
        \App\Models\LgpdRequest::addEvent($requestId, 'busca_completa',
            "Busca multi-modulo concluida. Total: $totalGeral achados. Detalhes: $resumoModulos",
            $byUserId);

        return $totals;
    }

    // ─── Buscas por módulo ──────────────────────────────────────────────────

    private static function searchUsers(\PDO $pdo, int $reqId, string $email, ?string $cpf, ?string $tel, int $byUid): int
    {
        $where = ['anonymized_at IS NULL']; $params = [];
        $where[] = '(LOWER(login) = :em' . ($tel ? ' OR REPLACE(REPLACE(telefone,"(",""),")","") LIKE :tel' : '') . ')';
        $params['em'] = $email;
        if ($tel) $params['tel'] = '%' . $tel . '%';

        $sql = "SELECT id, account_id, nome, login, telefone, oab
                FROM users WHERE " . implode(' AND ', $where) .
               " LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            if ($r['nome'])     \App\Models\LgpdRequestFinding::record($reqId, 'usuarios', 'users', (int)$r['id'], 'pii_basica', $r['nome'], 'nome', (int)$r['account_id'], 'contrato', 'Cadastro como usuario do sistema');
            if ($r['login'])    \App\Models\LgpdRequestFinding::record($reqId, 'usuarios', 'users', (int)$r['id'], 'pii_basica', $r['login'], 'email', (int)$r['account_id'], 'contrato', 'E-mail de login');
            if ($r['telefone']) \App\Models\LgpdRequestFinding::record($reqId, 'usuarios', 'users', (int)$r['id'], 'pii_basica', $r['telefone'], 'telefone', (int)$r['account_id'], 'contrato', 'Telefone de contato');
            if ($r['oab'])      \App\Models\LgpdRequestFinding::record($reqId, 'usuarios', 'users', (int)$r['id'], 'documento', $r['oab'], 'oab', (int)$r['account_id'], 'contrato', 'Registro OAB');
        }
        \App\Models\LgpdRequestModule::record($reqId, 'usuarios', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhum usuario encontrado' : count($rows) . ' usuario(s) encontrado(s)');
        return count($rows);
    }

    private static function searchContatos(\PDO $pdo, int $reqId, string $email, ?string $cpf, ?string $tel, int $byUid): int
    {
        $where = ['anonymized_at IS NULL']; $params = [];
        $or = ['LOWER(email) = :em'];
        $params['em'] = $email;
        if ($tel) { $or[] = "telefone LIKE :tel"; $params['tel'] = '%' . $tel . '%'; }
        $where[] = '(' . implode(' OR ', $or) . ')';

        $sql = "SELECT id, account_id, nome, telefone, email
                FROM contatos WHERE " . implode(' AND ', $where) .
               " LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            if ($r['nome'])     \App\Models\LgpdRequestFinding::record($reqId, 'contatos', 'contatos', (int)$r['id'], 'pii_basica', $r['nome'], 'nome', (int)$r['account_id'], 'contrato', 'Contato cadastrado');
            if ($r['telefone']) \App\Models\LgpdRequestFinding::record($reqId, 'contatos', 'contatos', (int)$r['id'], 'pii_basica', $r['telefone'], 'telefone', (int)$r['account_id'], 'contrato', 'Telefone do contato');
            if ($r['email'])    \App\Models\LgpdRequestFinding::record($reqId, 'contatos', 'contatos', (int)$r['id'], 'pii_basica', $r['email'], 'email', (int)$r['account_id'], 'contrato', 'E-mail do contato');
        }
        \App\Models\LgpdRequestModule::record($reqId, 'contatos', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhum contato encontrado' : count($rows) . ' contato(s) encontrado(s)');
        return count($rows);
    }

    private static function searchCards(\PDO $pdo, int $reqId, string $email, ?string $tel, int $byUid): int
    {
        $where = ['anonymized_at IS NULL']; $params = [];
        $or = ['LOWER(email) = :em'];
        $params['em'] = $email;
        if ($tel) { $or[] = "telefone_whatsapp LIKE :tel"; $params['tel'] = '%' . $tel . '%'; }
        $where[] = '(' . implode(' OR ', $or) . ')';

        $sql = "SELECT id, account_id, cliente_nome, telefone_whatsapp, email
                FROM cards WHERE " . implode(' AND ', $where) .
               " LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            if ($r['cliente_nome'])      \App\Models\LgpdRequestFinding::record($reqId, 'leads_cards', 'cards', (int)$r['id'], 'pii_basica', $r['cliente_nome'], 'cliente_nome', (int)$r['account_id'], 'interesse_legitimo', 'Lead/prospect do funil');
            if ($r['telefone_whatsapp']) \App\Models\LgpdRequestFinding::record($reqId, 'leads_cards', 'cards', (int)$r['id'], 'pii_basica', $r['telefone_whatsapp'], 'telefone_whatsapp', (int)$r['account_id'], 'interesse_legitimo', 'Contato WhatsApp do lead');
            if ($r['email'])             \App\Models\LgpdRequestFinding::record($reqId, 'leads_cards', 'cards', (int)$r['id'], 'pii_basica', $r['email'], 'email', (int)$r['account_id'], 'interesse_legitimo', 'E-mail do lead');
        }
        \App\Models\LgpdRequestModule::record($reqId, 'leads_cards', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhum lead encontrado' : count($rows) . ' lead(s) encontrado(s)');
        return count($rows);
    }

    /**
     * processos: busca em cpf_cnpj_parte_contraria (CPF) + cliente_nome / parte_contraria (string).
     * Atenção: cliente_nome geralmente é a cliente representada pelo escritório
     * (NÃO necessariamente o titular da solicitação). Buscamos por matching
     * de string só se o titular pediu/é parte processual.
     */
    private static function searchProcessos(\PDO $pdo, int $reqId, string $email, ?string $cpf, int $byUid): int
    {
        $where = ['anonymized_at IS NULL']; $params = []; $or = [];
        if ($cpf) {
            $or[] = "REPLACE(REPLACE(REPLACE(cpf_cnpj_parte_contraria,'.',''),'-',''),'/','') = :cpf";
            $params['cpf'] = $cpf;
        }
        // Email não bate diretamente em processos; busca via contato vinculado
        $or[] = "contato_id IN (SELECT id FROM contatos WHERE LOWER(email) = :em AND anonymized_at IS NULL)";
        $params['em'] = $email;
        $where[] = '(' . implode(' OR ', $or) . ')';

        $sql = "SELECT id, account_id, numero_cnj, cliente_nome, parte_contraria, cpf_cnpj_parte_contraria
                FROM processos WHERE " . implode(' AND ', $where) .
               " LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            if ($r['cpf_cnpj_parte_contraria']) {
                \App\Models\LgpdRequestFinding::record($reqId, 'processos', 'processos', (int)$r['id'], 'juridico_processual',
                    $r['cpf_cnpj_parte_contraria'], 'cpf_cnpj_parte_contraria',
                    (int)$r['account_id'], 'exercicio_direito', 'Parte processual (CPF/CNPJ)');
            }
            if ($r['parte_contraria']) {
                \App\Models\LgpdRequestFinding::record($reqId, 'processos', 'processos', (int)$r['id'], 'juridico_processual',
                    $r['parte_contraria'], 'parte_contraria',
                    (int)$r['account_id'], 'exercicio_direito', 'Nome da parte contraria');
            }
            if ($r['numero_cnj']) {
                // CNJ não é PII direta, mas vincula titular ao processo
                \App\Models\LgpdRequestFinding::record($reqId, 'processos', 'processos', (int)$r['id'], 'metadado',
                    $r['numero_cnj'], 'numero_cnj',
                    (int)$r['account_id'], 'exercicio_direito', 'Numero CNJ do processo vinculado');
            }
        }
        \App\Models\LgpdRequestModule::record($reqId, 'processos', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhum processo encontrado' : count($rows) . ' processo(s) encontrado(s)');
        return count($rows);
    }

    private static function searchWhatsApp(\PDO $pdo, int $reqId, string $email, ?string $tel, int $byUid): int
    {
        // WhatsApp não tem email direto. Correlaciona via contatos.remote_jid → messages.
        $sql = "SELECT m.id, m.account_id, m.remote_jid, m.contact_name, m.message_type, m.created_at
                FROM whatsapp_messages m
                INNER JOIN contatos c ON c.remote_jid = m.remote_jid
                WHERE LOWER(c.email) = ? AND m.deleted_at IS NULL
                ORDER BY m.created_at DESC
                LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute([$email]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            if ($r['contact_name']) {
                \App\Models\LgpdRequestFinding::record($reqId, 'whatsapp', 'whatsapp_messages', (int)$r['id'], 'comunicacao',
                    $r['contact_name'], 'contact_name', (int)$r['account_id'],
                    'interesse_legitimo', 'Mensagem WhatsApp (' . $r['message_type'] . ')');
            }
        }
        \App\Models\LgpdRequestModule::record($reqId, 'whatsapp', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhuma mensagem WhatsApp' : count($rows) . ' mensagem(ns) WhatsApp');
        return count($rows);
    }

    /**
     * chat_interno: só faz sentido se titular for usuário interno do sistema.
     * Busca via usuario_id resolvido a partir do email.
     */
    private static function searchChatInterno(\PDO $pdo, int $reqId, string $email, int $byUid): int
    {
        $sql = "SELECT cm.id, cm.account_id, cm.created_at
                FROM chat_mensagens cm
                INNER JOIN users u ON u.id = cm.usuario_id
                WHERE LOWER(u.login) = ? AND cm.deleted_at IS NULL
                ORDER BY cm.created_at DESC
                LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute([$email]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            \App\Models\LgpdRequestFinding::record($reqId, 'chat_interno', 'chat_mensagens', (int)$r['id'], 'comunicacao',
                'msg-' . $r['id'], 'mensagem', (int)$r['account_id'],
                'interesse_legitimo', 'Mensagem enviada no chat interno em ' . $r['created_at']);
        }
        \App\Models\LgpdRequestModule::record($reqId, 'chat_interno', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhuma mensagem no chat interno' : count($rows) . ' mensagem(ns) chat interno');
        return count($rows);
    }

    /**
     * task_attachments: busca anexos enviados pelo titular (uploaded_by = users.id).
     * Não inspeciona conteúdo (file_name pode ser sensível, valor mascarado).
     */
    private static function searchTaskAttachments(\PDO $pdo, int $reqId, string $email, int $byUid): int
    {
        // tasks nao tem account_id direto — derivamos via user uploader
        $sql = "SELECT ta.id, u.account_id, ta.file_name, ta.created_at
                FROM task_attachments ta
                INNER JOIN users u ON u.id = ta.uploaded_by
                WHERE LOWER(u.login) = ?
                LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute([$email]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            \App\Models\LgpdRequestFinding::record($reqId, 'task_attachments', 'task_attachments', (int)$r['id'], 'metadado',
                $r['file_name'] ?? 'arquivo', 'file_name', (int)($r['account_id'] ?? 0),
                'interesse_legitimo', 'Anexo enviado em ' . $r['created_at']);
        }
        \App\Models\LgpdRequestModule::record($reqId, 'task_attachments', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhum anexo enviado pelo titular' : count($rows) . ' anexo(s) enviado(s)');
        return count($rows);
    }

    /**
     * financeiro: invoices vinculadas a accounts cujo usuário admin tem email = titular.
     * Cobre: cliente PJ (admin) que pediu seus dados financeiros.
     */
    private static function searchFinanceiro(\PDO $pdo, int $reqId, string $email, int $byUid): int
    {
        $sql = "SELECT i.id, i.account_id, i.numero, i.amount_cents, i.status, i.due_date, i.descricao
                FROM invoices i
                WHERE i.account_id IN (
                    SELECT DISTINCT account_id FROM users
                    WHERE LOWER(login) = ? AND role IN ('admin','owner')
                )
                ORDER BY i.created_at DESC
                LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute([$email]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            \App\Models\LgpdRequestFinding::record($reqId, 'financeiro', 'invoices', (int)$r['id'], 'financeiro',
                'invoice-' . $r['numero'], 'numero', (int)$r['account_id'],
                'cumprimento_contrato', 'Fatura ' . $r['status'] . ' R$ ' . number_format($r['amount_cents']/100, 2, ',', '.'));
        }
        \App\Models\LgpdRequestModule::record($reqId, 'financeiro', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhuma fatura encontrada (titular nao e admin de tenant)' : count($rows) . ' fatura(s)');
        return count($rows);
    }

    /**
     * logs_auditoria: ações executadas pelo titular (se for user interno) em
     * master_audit_log e account_audit_log. Não inclui payload completo.
     */
    private static function searchLogsAuditoria(\PDO $pdo, int $reqId, string $email, int $byUid): int
    {
        // master_audit_log via super_admins
        $totalMaster = 0;
        $sql = "SELECT mal.id, mal.acao, mal.target_type, mal.target_id, mal.created_at
                FROM master_audit_log mal
                INNER JOIN super_admins sa ON sa.id = mal.super_admin_id
                INNER JOIN users u ON u.id = sa.user_id
                WHERE LOWER(u.login) = ?
                ORDER BY mal.created_at DESC
                LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute([$email]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            \App\Models\LgpdRequestFinding::record($reqId, 'logs_auditoria', 'master_audit_log', (int)$r['id'], 'metadado',
                $r['acao'], 'acao', null,
                'obrigacao_legal', 'Acao master em ' . $r['created_at'] . ' (alvo: ' . ($r['target_type'] ?? '-') . '#' . ($r['target_id'] ?? '-') . ')');
            $totalMaster++;
        }

        // account_audit_log via user_id
        $totalAcc = 0;
        $sql = "SELECT aal.id, aal.account_id, aal.acao, aal.created_at
                FROM account_audit_log aal
                INNER JOIN users u ON u.id = aal.user_id
                WHERE LOWER(u.login) = ?
                ORDER BY aal.created_at DESC
                LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute([$email]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            \App\Models\LgpdRequestFinding::record($reqId, 'logs_auditoria', 'account_audit_log', (int)$r['id'], 'metadado',
                $r['acao'], 'acao', (int)$r['account_id'],
                'obrigacao_legal', 'Acao do tenant em ' . $r['created_at']);
            $totalAcc++;
        }

        $totalLogs = $totalMaster + $totalAcc;
        \App\Models\LgpdRequestModule::record($reqId, 'logs_auditoria', $byUid, $totalLogs,
            "$totalMaster acao(es) master + $totalAcc acao(es) tenant");
        return $totalLogs;
    }

    private static function searchConsentimentos(\PDO $pdo, int $reqId, string $email, int $byUid): int
    {
        $sql = "SELECT id, account_id, finalidade, base_legal, status, concedido_em
                FROM lgpd_consents
                WHERE LOWER(titular_email) = ?
                   OR user_id IN (SELECT id FROM users WHERE LOWER(login) = ?)
                LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute([$email, $email]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            \App\Models\LgpdRequestFinding::record($reqId, 'consentimentos', 'lgpd_consents', (int)$r['id'], 'metadado',
                $r['finalidade'], 'finalidade', (int)($r['account_id'] ?? 0),
                strtolower($r['base_legal']), 'Consentimento ' . $r['status'] . ' em ' . $r['concedido_em']);
        }
        \App\Models\LgpdRequestModule::record($reqId, 'consentimentos', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhum consentimento' : count($rows) . ' consentimento(s)');
        return count($rows);
    }

    private static function searchAceitesTermos(\PDO $pdo, int $reqId, string $email, int $byUid): int
    {
        $sql = "SELECT ta.id, ta.account_id, ld.tipo, ld.versao, ta.accepted_at, ta.contexto
                FROM term_acceptances ta
                LEFT JOIN legal_documents ld ON ld.id = ta.legal_document_id
                WHERE LOWER(ta.titular_email) = ?
                   OR ta.user_id IN (SELECT id FROM users WHERE LOWER(login) = ?)
                LIMIT " . self::SEARCH_LIMIT_PER_MODULE;
        $st = $pdo->prepare($sql);
        $st->execute([$email, $email]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            \App\Models\LgpdRequestFinding::record($reqId, 'aceites_termos', 'term_acceptances', (int)$r['id'], 'metadado',
                ($r['tipo'] ?? '?') . ' v' . ($r['versao'] ?? '?'), 'tipo_versao', (int)($r['account_id'] ?? 0),
                'consentimento', 'Aceite via ' . ($r['contexto'] ?? '-') . ' em ' . $r['accepted_at']);
        }
        \App\Models\LgpdRequestModule::record($reqId, 'aceites_termos', $byUid, count($rows),
            count($rows) === 0 ? 'Nenhum aceite registrado' : count($rows) . ' aceite(s)');
        return count($rows);
    }
}
