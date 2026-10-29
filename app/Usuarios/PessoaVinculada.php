<?php
namespace App\Usuarios;

use App\Core\Database;

/**
 * PessoaVinculada: a mesma pessoa trabalhando em mais de uma conta (29/10/2026).
 *
 * Pedido: o Kauê vende para a Inovaize e para a Fleetiflow, com o MESMO login;
 * um botão troca de empresa, e o WhatsApp mostra as conversas das duas juntas,
 * com a etiqueta da empresa.
 *
 * ---------------------------------------------------------------------------
 * COMO É FEITO (e por que assim)
 * ---------------------------------------------------------------------------
 * Cada conta tem o SEU usuário da pessoa (o da Fleetiflow é usuário de verdade
 * da Fleetiflow: vendedor, responsável de lead, dono de conversa, com as
 * permissões de lá). A tabela usuario_vinculos (migration 169) diz que esses
 * usuários são a mesma pessoa. Nada de um usuário enxergar conta alheia: em cada
 * conta valem as regras daquele usuário naquela conta, e o isolamento entre
 * contas continua o de sempre.
 *
 *   Login      a senha é a do usuário de origem (o login digitado). Num
 *              endereço de marca (yuris.inovaize.com, crm.fleetiflow.com.br) a
 *              sessão abre no usuário da pessoa naquela conta (doEndereco).
 *   Trocar     o botão "Trocar de empresa" abre a sessão do outro usuário da
 *              pessoa, pela mesma rotina do login (trocar).
 *   WhatsApp   o Chat pede as conversas de um número da outra conta. Aquela
 *              chamada (só /api/whatsapp/, só aquela requisição) roda com a
 *              sessão do usuário da pessoa na conta dona do número, montada
 *              pela mesma rotina do login e NUNCA gravada (SessaoEmprestada).
 *              Todas as regras de acesso do Chat valem como se ele tivesse
 *              entrado lá: o vendedor só alcança as conversas dele.
 */
final class PessoaVinculada
{
    /** Status de conta que entram (os mesmos do login). */
    public const CONTA_OK = ['active', 'trial', 'overdue'];

    /** Endpoints do pacote WhatsApp que nunca emprestam sessão (sem sessão, por token). */
    private const SEM_EMPRESTIMO = ['webhook.php', 'sdr_automacao.php', 'sdr_etapa.php', 'sdr_transferencia.php', 'identidade_resolve.php', 'opcache_clear.php'];

    /**
     * Os usuários da mesma pessoa (inclusive o próprio), ativos e em conta que
     * pode entrar, na ordem: o próprio primeiro, depois pelo nome da conta.
     *
     * @return list<array{user_id:int, account_id:int, conta:string, cor:string, atual:bool}>
     */
    public static function contas(int $userId): array
    {
        if ($userId <= 0) return [];
        try {
            $st = Database::getConnection()->prepare(
                "SELECT u.id AS uid, a.*
                   FROM usuario_vinculos v
                   JOIN usuario_vinculos eu ON eu.pessoa = v.pessoa AND eu.user_id = ?
                   JOIN users u ON u.id = v.user_id AND u.deleted_at IS NULL AND u.status = 'active'
                   JOIN accounts a ON a.id = u.account_id AND a.deleted_at IS NULL
                  WHERE a.status IN ('active', 'trial', 'overdue')"
            );
            $st->execute([$userId]);
            $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return []; // sem a migration 169: ninguém tem vínculo
        }
        $out = [];
        foreach ($linhas as $r) {
            $marca = [];
            try { $marca = \App\Master\Marca::daConta($r); } catch (\Throwable $e) {}
            $out[] = [
                'user_id' => (int) $r['uid'], 'account_id' => (int) $r['id'],
                'conta' => (string) (($marca['nome'] ?? '') ?: $r['nome']),
                'cor' => (string) (($marca['paleta']['marca'] ?? '') ?: '#015DFC'),
                'atual' => (int) $r['uid'] === $userId,
            ];
        }
        usort($out, fn($a, $b) => [$b['atual'], $a['conta']] <=> [$a['atual'], $b['conta']]);
        return count($out) > 1 ? $out : [];
    }

    /** O usuário da pessoa naquela conta (o próprio, se já é dela), ou null. */
    public static function usuarioNaConta(int $userId, int $accountId): ?int
    {
        foreach (self::contas($userId) as $c) {
            if ($c['account_id'] === $accountId) return $c['user_id'];
        }
        return null;
    }

    /** Liga dois usuários como a mesma pessoa. */
    public static function vincular(int $userId, int $outroUserId, ?int $porUserId = null): void
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT pessoa FROM usuario_vinculos WHERE user_id = ?');
        $st->execute([$userId]);
        $pessoa = (string) ($st->fetchColumn() ?: '');
        if ($pessoa === '') {
            $st->execute([$outroUserId]);
            $pessoa = (string) ($st->fetchColumn() ?: '') ?: bin2hex(random_bytes(12));
        }
        $ins = $pdo->prepare('INSERT INTO usuario_vinculos (user_id, pessoa, criado_em, criado_por) VALUES (?, ?, NOW(), ?)
                              ON DUPLICATE KEY UPDATE pessoa = VALUES(pessoa)');
        $ins->execute([$userId, $pessoa, $porUserId]);
        $ins->execute([$outroUserId, $pessoa, $porUserId]);
    }

    /**
     * Login num endereço de marca: a sessão abre no usuário da pessoa na conta
     * daquele endereço. Fora disso, segue o usuário do login.
     */
    public static function doEndereco(array $user): array
    {
        $conta = self::contaDoEndereco();
        if (!$conta || $conta === (int) ($user['account_id'] ?? 0)) return $user;
        $outro = self::usuarioNaConta((int) $user['id'], $conta);
        if (!$outro) return $user;
        $st = Database::getConnection()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$outro]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: $user;
    }

    /** A conta dona do endereço desta requisição: a da marca, ou a Fleetiflow no domínio dela. */
    public static function contaDoEndereco(): ?int
    {
        try {
            $c = \App\Core\ProductHost::contaDoHost();
            if ($c && !empty($c['id'])) return (int) $c['id'];
            if (\App\Core\ProductHost::atual() === \App\Core\ProductHost::FLEETIFLOW) {
                return \App\WhatsAppAgente\AutomacaoSdr::contaDoRobo();
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /**
     * Troca a sessão para o usuário da pessoa em outra conta. Abre pela mesma
     * rotina do login (AuthController::abrirSessao), que regenera o id.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function trocar(int $userIdAtual, int $paraUserId): array
    {
        $alvo = null;
        foreach (self::contas($userIdAtual) as $c) {
            if ($c['user_id'] === $paraUserId && !$c['atual']) $alvo = $c;
        }
        if (!$alvo) return ['ok' => false, 'error' => 'Empresa não disponível para você.'];
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND status = "active" LIMIT 1');
        $st->execute([$paraUserId]);
        $user = $st->fetch(\PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT id, nome, tipo, status, plano, deleted_at FROM accounts WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$alvo['account_id']]);
        $account = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$user || !$account || !in_array($account['status'], self::CONTA_OK, true)) return ['ok' => false, 'error' => 'Empresa não disponível para você.'];

        // Nada da conta anterior fica na sessão (ambiente, filtros, super admin...).
        $csrf = $_SESSION['csrf_token'] ?? null;
        $_SESSION = [];
        if ($csrf) $_SESSION['csrf_token'] = $csrf;
        AuthController::abrirSessao($user, $account);
        \App\Master\Account::audit((int) $account['id'], 'usuario.trocou_empresa', [
            'user_id' => $paraUserId, 'entidade' => 'users', 'entidade_id' => $paraUserId,
            'detalhes' => ['de_user_id' => $userIdAtual],
        ]);
        return ['ok' => true];
    }

    // ── Sessão emprestada do WhatsApp ─────────────────────────────────────────

    /**
     * Chamado pelo app/bootstrap.php. Numa chamada do pacote WhatsApp para um
     * número (ou empresa) de outra conta da pessoa, faz a sessão DESTA requisição
     * ser a do usuário da pessoa naquela conta. A sessão de verdade não muda.
     */
    public static function emprestarSessaoSePreciso(): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) return;
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if (!str_starts_with($script, '/api/whatsapp/') || in_array(basename($script), self::SEM_EMPRESTIMO, true)) return;
        if (empty($_COOKIE[session_name()])) return;
        try {
            $conta = self::contaPedida();
            if (!$conta) return;
            session_start(['read_and_close' => true]);
            $real = $_SESSION;
            $uid = (int) ($real['user_id'] ?? 0);
            if ($uid <= 0 || $conta === (int) ($real['account_id'] ?? 0)) return;
            $dados = self::sessaoNaConta($uid, $conta);
            if ($dados === null) return;
            if (!empty($real['csrf_token'])) $dados['csrf_token'] = $real['csrf_token'];
            $dados['sessao_emprestada_de'] = $uid;
            $codificada = SessaoEmprestada::codificar($dados);
            if ($codificada === null) return;
            $_SESSION = [];
            session_set_save_handler(new SessaoEmprestada($codificada), true);
        } catch (\Throwable $e) {
            error_log('[pessoa_vinculada] emprestar sessão: ' . $e->getMessage());
        }
    }

    /**
     * A sessão que o usuário da pessoa teria na conta, pela mesma rotina do login.
     * null quando a pessoa não tem usuário ativo lá, ou a conta não entra.
     */
    public static function sessaoNaConta(int $userId, int $accountId): ?array
    {
        $outro = self::usuarioNaConta($userId, $accountId);
        if (!$outro || $outro === $userId) return null;
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND status = "active" LIMIT 1');
        $st->execute([$outro]);
        $user = $st->fetch(\PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT id, nome, tipo, status, plano FROM accounts WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$accountId]);
        $account = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$user || !$account || !in_array($account['status'], self::CONTA_OK, true)) return null;
        return AuthController::dadosDaSessao($user, $account);
    }

    /** A conta de que a chamada fala: a dona do número pedido, a da mensagem (mídia) ou ?empresa=. */
    private static function contaPedida(): ?int
    {
        $pdo = Database::getConnection();
        $corpo = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
            $corpo = json_decode((string) file_get_contents('php://input'), true) ?: [];
        }
        $canal = (int) ($_GET['channel_id'] ?? $_POST['channel_id'] ?? $corpo['channel_id'] ?? 0);
        if ($canal > 0) {
            $st = $pdo->prepare('SELECT account_id FROM whatsapp_instances WHERE id = ? LIMIT 1');
            $st->execute([$canal]);
            return (int) ($st->fetchColumn() ?: 0) ?: null;
        }
        $empresa = (int) ($_GET['empresa'] ?? 0);
        if ($empresa > 0) return $empresa;
        if (basename((string) $_SERVER['SCRIPT_NAME']) === 'media.php' && (int) ($_GET['msg_id'] ?? 0) > 0) {
            $st = $pdo->prepare('SELECT wi.account_id FROM whatsapp_messages m JOIN whatsapp_instances wi ON wi.id = m.instance_id WHERE m.id = ? LIMIT 1');
            $st->execute([(int) $_GET['msg_id']]);
            return (int) ($st->fetchColumn() ?: 0) ?: null;
        }
        return null;
    }

}
