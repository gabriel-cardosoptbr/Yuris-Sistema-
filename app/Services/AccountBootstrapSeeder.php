<?php
namespace App\Services;

use PDO;

/**
 * AccountBootstrapSeeder — popula a "primeira casca" de uma conta nova
 * (matriz, advogado ou filial) com configurações default editáveis.
 *
 * Filosofia: a conta não nasce 100% crua. Vem com modelo razoável que o admin
 * pode ajustar (renomear, reordenar, arquivar) via UI a qualquer momento.
 * Funciona como template — não trava o usuário em nada.
 *
 * Chamado por:
 *   - public/api/master/create_account.php  (matriz / advogado)
 *   - public/api/master/create_filial.php   (filial)
 *
 * IMPORTANTE:
 *   - Idempotente: só semeia se a conta tiver 0 registros do tipo (defensivo
 *     contra calls duplicadas ou re-bootstrap manual).
 *   - Aceita o PDO da transação corrente — não cria conexão própria. Se a
 *     transação que chama der rollback, os seeds caem junto.
 *   - Contas EXISTENTES antes desse seeder não são tocadas — quem precisar
 *     pode chamar `bootstrapExisting()` manualmente via script.
 */
final class AccountBootstrapSeeder
{
    // ── Defaults da Prospecção (Pipeline) ───────────────────────────────
    // Aplicado em matriz/advogado. Filial herda da matriz, então não recebe.
    private const PIPELINE_COLUMNS = [
        // [nome, slug, cor, ordem, conta_funil, conta_oportunidade, conta_fechado, conta_perdido]
        ['Leads em atendimento', 'leads-em-atendimento', '#94a3b8', 1, 1, 0, 0, 0],
        ['Proposta enviada',     'proposta-enviada',     '#f59e0b', 2, 1, 1, 0, 0],
        ['Negociação jurídica',  'negociacao-juridica',  '#6366f1', 3, 1, 1, 0, 0],
        ['Contrato fechado',     'contrato-fechado',     '#22c55e', 4, 0, 0, 1, 0],
    ];

    // ── Defaults de Clientes (setores operacionais) ─────────────────────
    // Aplicado em matriz/advogado E filial (cada conta tem os seus).
    private const CLIENTES_SETORES = [
        // [nome, slug, cor, ordem]
        ['Atendimento Inicial',   'atendimento-inicial',   '#3b82f6', 1],
        ['Documentação',          'documentacao',          '#8b5cf6', 2],
        ['Jurídico',              'juridico',              '#6366f1', 3],
        ['Financeiro',            'financeiro',            '#0ea5e9', 4],
        ['Em Andamento',          'em-andamento',          '#f59e0b', 5],
        ['Aguardando Cliente',    'aguardando-cliente',    '#eab308', 6],
        ['Finalizado',            'finalizado',            '#22c55e', 7],
        ['Arquivado',             'arquivado',             '#94a3b8', 8],
    ];

    // ── Defaults de Origens do cadastro de Cliente ──────────────────────
    private const CLIENTES_ORIGENS = [
        // [nome, slug, ordem]
        ['Cadastro manual',   'cadastro_manual',  1],
        ['Cliente antigo',    'cliente_antigo',   2],
        ['Cliente ativo',     'cliente_ativo',    3],
        ['Indicação',         'indicacao',        4],
        ['Anúncio',           'anuncio',          5],
        ['Site',              'site',             6],
        ['Redes sociais',     'redes_sociais',    7],
        ['Evento / Palestra', 'evento_palestra',  8],
        ['Captação ativa',    'captacao_ativa',   9],
        ['Outro',             'outro',           10],
    ];

    /**
     * Bootstrap completo de uma conta nova.
     *
     * Roda dentro da transação do caller — se algo falhar, propaga exception
     * e o caller rollback. Idempotente: re-rodar é seguro (skip se já tem dados).
     *
     * @param PDO $pdo Conexão com transação aberta (recomendado)
     * @param int $accountId ID da conta recém-criada
     * @param string $tipo 'matriz' | 'advogado' | 'filial'
     * @return array{pipeline_columns:int, clientes_setores:int, clientes_origens:int} Counts inseridos
     */
    public static function bootstrapNew(PDO $pdo, int $accountId, string $tipo): array
    {
        $result = [
            'pipeline_columns' => 0,
            'clientes_setores' => 0,
            'clientes_origens' => 0,
        ];
        if ($accountId <= 0) return $result;

        // Filial herda pipeline da matriz — não recebe pipeline_columns próprias.
        if (in_array($tipo, ['matriz', 'advogado'], true)) {
            $result['pipeline_columns'] = self::seedPipelineColumns($pdo, $accountId);
        }

        // Setores e origens de Clientes são SEMPRE per-conta (sem herança), inclusive filial.
        $result['clientes_setores'] = self::seedClientesSetores($pdo, $accountId);
        $result['clientes_origens'] = self::seedClientesOrigens($pdo, $accountId);

        return $result;
    }

    // ── Seeds individuais (públicos pra serem reusáveis em scripts) ────

    public static function seedPipelineColumns(PDO $pdo, int $accountId): int
    {
        if (self::countOf($pdo, 'pipeline_columns', $accountId) > 0) return 0;

        $stmt = $pdo->prepare(
            'INSERT INTO pipeline_columns
               (account_id, nome, slug, cor, ordem,
                conta_funil, conta_oportunidade, conta_fechado, conta_perdido,
                peso_projecao, created_at, updated_at)
             VALUES
               (:aid, :nome, :slug, :cor, :ordem,
                :cf, :co, :cfe, :cp,
                0, NOW(), NOW())'
        );
        $n = 0;
        foreach (self::PIPELINE_COLUMNS as [$nome, $slug, $cor, $ordem, $cf, $co, $cfe, $cp]) {
            $stmt->execute([
                'aid'  => $accountId,
                'nome' => $nome,  'slug' => $slug,  'cor' => $cor,  'ordem' => $ordem,
                'cf'   => $cf,    'co'   => $co,    'cfe' => $cfe,  'cp'    => $cp,
            ]);
            $n++;
        }
        return $n;
    }

    public static function seedClientesSetores(PDO $pdo, int $accountId): int
    {
        if (self::countOf($pdo, 'clientes_setores', $accountId) > 0) return 0;

        $stmt = $pdo->prepare(
            'INSERT INTO clientes_setores (account_id, nome, slug, cor, ordem, ativo, created_at, updated_at)
             VALUES (:aid, :nome, :slug, :cor, :ordem, 1, NOW(), NOW())'
        );
        $n = 0;
        foreach (self::CLIENTES_SETORES as [$nome, $slug, $cor, $ordem]) {
            $stmt->execute(['aid' => $accountId, 'nome' => $nome, 'slug' => $slug, 'cor' => $cor, 'ordem' => $ordem]);
            $n++;
        }
        return $n;
    }

    public static function seedClientesOrigens(PDO $pdo, int $accountId): int
    {
        if (self::countOf($pdo, 'clientes_origens', $accountId) > 0) return 0;

        $stmt = $pdo->prepare(
            'INSERT INTO clientes_origens (account_id, nome, slug, ordem, ativo, created_at, updated_at)
             VALUES (:aid, :nome, :slug, :ordem, 1, NOW(), NOW())'
        );
        $n = 0;
        foreach (self::CLIENTES_ORIGENS as [$nome, $slug, $ordem]) {
            $stmt->execute(['aid' => $accountId, 'nome' => $nome, 'slug' => $slug, 'ordem' => $ordem]);
            $n++;
        }
        return $n;
    }

    /** Helper: conta linhas dessa conta numa tabela (com guard pra tabela inexistente). */
    private static function countOf(PDO $pdo, string $table, int $accountId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE account_id = ?");
            $stmt->execute([$accountId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Tabela ainda não migrada — comporta-se como vazia (deixa o INSERT falhar feio
            // se a tabela for o problema; aqui só precisamos saber se já tem dado).
            return 0;
        }
    }
}
