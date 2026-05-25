<?php
namespace App\Models;

use App\Models\Database;

/**
 * Model: Account (Tenant)
 *
 * Representa uma conta no sistema — pode ser Matriz ou Filial.
 * Padrão de mercado: cada conta é um tenant isolado (shared-database/shared-schema).
 * Referência: Clio multi-office, HubSpot organizations, Salesforce orgs.
 */
class Account
{
    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA
    // ─────────────────────────────────────────────────────────────────────────

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM accounts WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByCodigoVinculo(string $codigo): ?array
    {
        // Aceita active/trial/overdue — mesma whitelist do AuthController.
        // Contas em teste ou inadimplentes continuam operáveis; só
        // suspended/cancelled/inactive ficam invisíveis ao lookup.
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT * FROM accounts
             WHERE codigo_vinculo = :codigo
               AND deleted_at IS NULL
               AND status IN ('active','trial','overdue')
             LIMIT 1"
        );
        $stmt->execute(['codigo' => $codigo]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Lista todas as filiais vinculadas a uma matriz (vínculo ativo) */
    public static function listFiliaisVinculadas(int $matrizId): array
    {
        $pdo  = Database::getConnection();
        // Inclui flags de sincronização — AccountContext usa pra filtrar quais
        // filiais a matriz puxa por módulo (processos / cards / tarefas).
        $stmt = $pdo->prepare(
            'SELECT a.*,
                    av.id AS vinculo_id,
                    av.status AS vinculo_status,
                    av.aprovado_em,
                    av.sync_enabled,
                    av.sync_processos,
                    av.sync_cards,
                    av.sync_tarefas,
                    av.sync_updated_at
             FROM accounts a
             INNER JOIN account_vinculos av ON av.filial_account_id = a.id
             WHERE av.matriz_account_id = :matriz_id
               AND av.status = "active"
               AND a.deleted_at IS NULL
             ORDER BY a.nome ASC'
        );
        $stmt->execute(['matriz_id' => $matrizId]);
        return $stmt->fetchAll();
    }

    /** Retorna a matriz de uma filial (se tiver vínculo ativo) */
    public static function findMatrizDaFilial(int $filialId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT a.*, av.id AS vinculo_id, av.status AS vinculo_status
             FROM accounts a
             INNER JOIN account_vinculos av ON av.matriz_account_id = a.id
             WHERE av.filial_account_id = :filial_id
               AND av.status = "active"
               AND a.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['filial_id' => $filialId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA
    // ─────────────────────────────────────────────────────────────────────────

    public static function create(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO accounts (nome, tipo, matriz_id, codigo_vinculo, plano, status, created_at, updated_at)
             VALUES (:nome, :tipo, :matriz_id, :codigo_vinculo, :plano, :status, NOW(), NOW())'
        );
        $stmt->execute([
            'nome'           => $data['nome'],
            'tipo'           => $data['tipo'] ?? 'matriz',
            'matriz_id'      => $data['matriz_id'] ?? null,
            'codigo_vinculo' => implode('-', str_split(bin2hex(random_bytes(8)), 4)), // XXXX-XXXX-XXXX-XXXX
            'plano'          => $data['plano'] ?? 'basico',
            'status'         => 'active',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $pdo    = Database::getConnection();
        $fields = [];
        $params = ['id' => $id];

        if (isset($data['nome']))           { $fields[] = 'nome = :nome';                   $params['nome']           = $data['nome']; }
        if (isset($data['plano']))          { $fields[] = 'plano = :plano';                 $params['plano']          = $data['plano']; }
        if (isset($data['status']))         { $fields[] = 'status = :status';               $params['status']         = $data['status']; }
        if (isset($data['configuracoes']))  { $fields[] = 'configuracoes = :configuracoes'; $params['configuracoes']  = json_encode($data['configuracoes']); }

        if (empty($fields)) return false;

        $sql = 'UPDATE accounts SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        return $pdo->prepare($sql)->execute($params);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTORIZAÇÃO: verifica se duas contas têm vínculo ativo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retorna true se as duas contas têm vínculo ativo (em qualquer direção).
     * Usado pelo AccountContext para validar compartilhamentos.
     */
    public static function temVinculoAtivo(int $accountA, int $accountB): bool
    {
        if ($accountA === $accountB) return true;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM account_vinculos
             WHERE status = "active"
               AND (
                 (matriz_account_id = :a AND filial_account_id = :b)
                 OR
                 (matriz_account_id = :b2 AND filial_account_id = :a2)
               )
             LIMIT 1'
        );
        $stmt->execute(['a' => $accountA, 'b' => $accountB, 'a2' => $accountA, 'b2' => $accountB]);
        return (bool) $stmt->fetch();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUDITORIA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Registra no audit log imutável.
     *
     * Colunas reais da tabela account_audit_log (migration 025):
     *   account_id, user_id, acao, entidade, entidade_id, dados_antes, dados_depois, ip
     *
     * Mapeamento de parâmetros de entrada:
     *   $extras['resource_type'] → entidade
     *   $extras['resource_id']   → entidade_id
     *   $extras['detalhes']      → dados_depois (JSON com contexto da operação)
     *   $extras['dados_antes']   → dados_antes  (opcional, para operações de update)
     */
    public static function audit(int $accountId, string $acao, array $extras = []): void
    {
        try {
            // LGPD Etapa 4: user_agent + request_id pra trilha forense
            if (!class_exists('App\\Helpers\\RequestId')) {
                require_once dirname(__DIR__) . '/Helpers/RequestId.php';
            }

            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'INSERT INTO account_audit_log
                   (account_id, user_id, acao, entidade, entidade_id,
                    dados_antes, dados_depois, ip, user_agent, request_id)
                 VALUES
                   (:account_id, :user_id, :acao, :entidade, :entidade_id,
                    :dados_antes, :dados_depois, :ip, :ua, :rid)'
            );
            $stmt->execute([
                'account_id'   => $accountId,
                'user_id'      => $extras['user_id']       ?? null,
                'acao'         => $acao,
                'entidade'     => $extras['resource_type'] ?? ($extras['entidade'] ?? null),
                'entidade_id'  => $extras['resource_id']   ?? ($extras['entidade_id'] ?? null),
                'dados_antes'  => isset($extras['dados_antes'])
                                    ? json_encode($extras['dados_antes'])
                                    : null,
                'dados_depois' => isset($extras['detalhes'])
                                    ? json_encode($extras['detalhes'])
                                    : (isset($extras['dados_depois']) ? json_encode($extras['dados_depois']) : null),
                'ip'           => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'           => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'rid'          => \App\Helpers\RequestId::get(),
            ]);
        } catch (\Throwable $e) {
            // Auditoria NUNCA derruba o fluxo principal — só loga silenciosamente
            error_log('[Account::audit] Falha: ' . $e->getMessage());
        }
    }
}
