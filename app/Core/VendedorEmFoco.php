<?php
namespace App\Core;

/**
 * Vendedor em foco da edição CRM (30/10/2026): o dono ou admin escolhe um vendedor
 * uma vez e todas as telas passam a mostrar só o que é dele (conversas do Chat,
 * leads do funil, clientes, painel, relatórios), até voltar para "Todos".
 *
 * A escolha mora na sessão (`$_SESSION['vendedor_em_foco']`): vale para o login
 * atual, em todas as abas, e some ao sair. Não é permissão. O que decide o que
 * cada pessoa PODE ver e fazer continua sendo `AccountContext::vendedorDaSessao()`;
 * esta classe só estreita listagens. Por isso `id()` devolve o vendedor logado
 * quando quem está logado é vendedor (ele já só vê o que é dele), e o escolhido
 * quando é dono ou admin.
 *
 * Fora da edição CRM (conta jurídica) e sem sessão de usuário, é sempre null.
 */
final class VendedorEmFoco
{
    public const CHAVE = 'vendedor_em_foco';

    /** @var array<int, ?int> */
    private static array $cache = [];

    /** O vendedor para estreitar listagens nesta requisição, ou null (todos). */
    public static function id(): ?int
    {
        return AccountContext::vendedorDaSessao() ?? self::escolhido();
    }

    /**
     * O vendedor que o dono/admin escolheu, já conferido: usuário ativo de uma conta
     * que a sessão alcança. Escolha que deixou de valer (usuário apagado, inativo,
     * de outra conta) é ignorada.
     */
    public static function escolhido(): ?int
    {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $vid = (int) ($_SESSION[self::CHAVE] ?? 0);
        if ($uid <= 0 || $vid <= 0) return null;
        if (array_key_exists($vid, self::$cache)) return self::$cache[$vid];
        self::$cache[$vid] = null;
        try {
            $ctx = AccountContext::fromSession();
            if (!self::podeEscolher($ctx)) return null;
            foreach (self::vendedores($ctx) as $v) {
                if ((int) $v['id'] === $vid) return self::$cache[$vid] = $vid;
            }
        } catch (\Throwable $e) {
            self::$cache[$vid] = null;
        }
        return self::$cache[$vid];
    }

    /** Só dono e admin da edição CRM escolhem: o vendedor já vê só o que é dele. */
    public static function podeEscolher(AccountContext $ctx): bool
    {
        if (!$ctx->isOwnerOrAdmin() && !$ctx->isSuperAdmin()) return false;
        try { return $ctx->getProduto() === 'fleetiflow'; } catch (\Throwable $e) { return false; }
    }

    /** Usuários ativos que a sessão alcança. @return array<int, array{id:int, nome:string}> */
    public static function vendedores(AccountContext $ctx): array
    {
        return array_map(
            fn ($u) => ['id' => (int) $u['id'], 'nome' => (string) $u['nome']],
            $ctx->getAccessibleUsers(true)
        );
    }

    /** Grava a escolha (null = todos). A sessão precisa estar aberta para escrita. */
    public static function definir(?int $vendedorId): void
    {
        if ($vendedorId) $_SESSION[self::CHAVE] = $vendedorId;
        else unset($_SESSION[self::CHAVE]);
        self::$cache = [];
    }

    /** Esquece o que foi conferido (teste). */
    public static function esquecer(): void { self::$cache = []; }
}
