<?php
namespace App\Core;

/**
 * ProductHost — identifica qual produto atender pelo domínio (Host) da
 * requisição, ANTES de existir sessão ou conta.
 *
 * Não confundir com `Account::getProduto()` / `AccountContext::getProduto()`:
 * aquele decide o que um usuário JÁ LOGADO vê (pela conta dele). Este decide
 * qual porta de entrada (login/landing) um VISITANTE SEM SESSÃO encontra,
 * só pelo domínio que digitou. As duas coisas coexistem sem se misturar: uma
 * conta Yuris que por engano entrar pelo domínio Fleetiflow vê a tela de
 * login com a marca Fleetiflow, mas ao autenticar cai no dashboard com a
 * marca da PRÓPRIA conta, de novo pela `AccountContext`.
 *
 * Mesmo container, mesmo backend, sem redirect HTTP: o Apache responde por
 * todos os domínios listados (ServerAlias/VirtualHost apontando pro MESMO
 * DocumentRoot), e é esta classe que decide o que servir para cada Host.
 */
final class ProductHost
{
    public const YURIS      = 'yuris';
    public const FLEETIFLOW = 'fleetiflow';

    /** Cache por request. */
    private static ?string $produtoAtual = null;

    /**
     * Produto esperado para o Host desta requisição.
     * Domínio não listado em FLEETIFLOW_DOMAINS => 'yuris' (comportamento de
     * sempre, sem precisar cadastrar cada domínio Yuris existente).
     */
    public static function atual(): string
    {
        if (self::$produtoAtual !== null) return self::$produtoAtual;

        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host); // remove porta (dev: :8090)

        foreach (self::dominiosFleetiflow() as $dominio) {
            if ($host === $dominio) return self::$produtoAtual = self::FLEETIFLOW;
        }
        // Domínio cadastrado na marca de uma conta CRM pelo Painel Master
        // (crm.inovaize.com.br etc.): também é porta da edição CRM, sem mexer
        // no .env. Domínio do Yuris nem chega a consultar (Marca recusa).
        if (self::contaDoHost() !== null) return self::$produtoAtual = self::FLEETIFLOW;
        return self::$produtoAtual = self::YURIS;
    }

    /** @var array|null|false false = ainda não procurado */
    private static array|null|false $contaDoHost = false;

    /** A conta CRM cujo domínio de marca é o Host desta requisição, ou null. */
    public static function contaDoHost(): ?array
    {
        if (self::$contaDoHost !== false) return self::$contaDoHost;
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        $host = (string) preg_replace('/:\d+$/', '', $host);
        try {
            return self::$contaDoHost = \App\Master\Marca::contaPorDominio(Database::getConnection(), $host);
        } catch (\Throwable $e) {
            error_log('[product_host] ' . $e->getMessage());
            return self::$contaDoHost = null;
        }
    }

    /**
     * A marca que a tela de login deste domínio mostra: a da conta dona do
     * domínio, ou a do Fleetiflow (domínios do .env e qualquer outro).
     */
    public static function marcaDoHost(): array
    {
        $conta = self::contaDoHost();
        return $conta ? \App\Master\Marca::daConta($conta) : \App\Master\Marca::padraoFleetiflow();
    }

    public static function isFleetiflow(): bool
    {
        return self::atual() === self::FLEETIFLOW;
    }

    /**
     * Lista de domínios que carregam a experiência Fleetiflow, configurada em
     * `FLEETIFLOW_DOMAINS` no `.env` (separados por vírgula). Adicionar um
     * domínio novo é editar o `.env` e recarregar o Apache: nenhum código
     * muda.
     *
     * @return list<string>
     */
    public static function dominiosFleetiflow(): array
    {
        $lista = EnvLoader::get('FLEETIFLOW_DOMAINS', '');
        return array_values(array_filter(array_map(
            static fn(string $d): string => strtolower(trim($d)),
            explode(',', $lista)
        )));
    }
}
