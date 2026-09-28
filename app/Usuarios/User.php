<?php
namespace App\Usuarios;

use App\Core\Database;

class User
{
    public static function findByLogin($login)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE login = :login AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['login' => $login]);
        return $stmt->fetch();
    }

    public static function findById($id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function create($nome, $login, $password_hash, $perfil = 'user')
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO users (nome, login, senha_hash, perfil, status, created_at, updated_at) VALUES (:nome, :login, :senha_hash, :perfil, :status, NOW(), NOW())');
        $stmt->execute([
            'nome' => $nome,
            'login' => $login,
            'senha_hash' => $password_hash,
            'perfil' => $perfil,
            'status' => 'active'
        ]);
        return $pdo->lastInsertId();
    }

    /** Níveis de acesso (users.role) que são administração da conta. */
    public const NIVEIS_ADMIN = ['owner', 'admin'];
    public const NIVEIS       = ['owner', 'admin', 'manager', 'user', 'viewer'];

    /**
     * Deixa `perfil` e `role` dizendo a mesma coisa.
     *
     * ---------------------------------------------------------------------------
     * POR QUE EXISTEM DOIS CAMPOS, E POR QUE ELES NÃO PODEM DISCORDAR
     * ---------------------------------------------------------------------------
     * `users.perfil` (admin|user) é o antigo: controla a tela de permissões por
     * página e o selo "ADMIN" ao lado do nome. `users.role` (owner|admin|manager|
     * user|viewer) é o do multi-tenancy: é ele que `AccountContext::isOwnerOrAdmin`
     * lê, e portanto é ele que decide o que a pessoa consegue fazer.
     *
     * O cadastro mostra os dois como selects lado a lado, "Perfil" e "Nível de
     * acesso", com "Usuário" pré-selecionado no segundo. Em 28/09/2026 uma conta
     * foi criada com Perfil = Administrador e Nível = Usuário: a tela mostrava
     * ADMIN, e o sistema tratava como usuário comum. Em Tarefas ela abria e via
     * "Nenhum quadro". Havia 2 contas assim em produção.
     *
     * A regra: ADMINISTRAÇÃO EM QUALQUER UM DOS DOIS VALE PARA OS DOIS.
     *   role owner/admin               -> perfil admin
     *   perfil admin e role abaixo     -> role admin
     *
     * "O maior vence" e não "o último vence" porque o erro real foi esquecer o
     * segundo select, não escolher de propósito uma combinação contraditória. A
     * tela passa a sincronizar os dois; isto aqui é a rede para quem chegar pela
     * API sem passar pela tela.
     *
     * Nunca promove a owner: dono é decisão explícita, e só o dono concede.
     *
     * @return array{0:string,1:string} [perfil, role]
     */
    public static function alinharPerfilENivel(?string $perfil, ?string $role): array
    {
        $perfil = $perfil === 'admin' ? 'admin' : 'user';
        // NÃO normaliza o nível para a lista conhecida. Existem contas antigas com
        // `member`, que não está no select, e a primeira versão desta função o
        // reescrevia para `user` em silêncio a cada edição (pego no teste manual,
        // 28/09/2026). Validar o valor é trabalho de quem chama (os endpoints já
        // têm allowlist); aqui só se garante o acoplamento com administração.
        $role   = ($role === null || $role === '') ? 'user' : $role;

        if (in_array($role, self::NIVEIS_ADMIN, true)) {
            return ['admin', $role];
        }
        if ($perfil === 'admin') {
            return ['admin', 'admin'];
        }
        return ['user', $role];
    }
}
