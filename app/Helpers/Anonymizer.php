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
                ['nome','login','senha_hash','telefone','oab','oab_uf']);
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
}
