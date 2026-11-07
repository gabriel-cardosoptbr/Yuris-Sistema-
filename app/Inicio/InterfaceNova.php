<?php
namespace App\Inicio;

use App\Core\Database;

/**
 * INTERFACE NOVA DO YURIS, EM PRÉVIA (07/11/2026).
 *
 * O pedido: uma interface que lembre um escritório ao abrir, com o menu em
 * relevo (a página atual numa pílula azul que brilha), tema claro e escuro, e a
 * home voltada ao dia da advocacia (agenda, prazos, publicações, equipe,
 * processos) em vez de métricas. As métricas continuam no painel de sempre, que
 * passa a ser "Indicadores", dentro de Gestão. A paleta: cinza da prata do logo,
 * madeira clara como toque de escritório, e o azul do Yuris forte e opaco.
 *
 * É PRÉVIA. Depois da paleta (06/11/2026), nenhuma mudança de aparência sobe
 * para todos sem o dono do produto ver antes. Vê quem:
 *
 *   - está numa CONTA ligada (`accounts.configuracoes.interface_nova = true`,
 *     por `scripts/interface_nova_conta.php`): a conta inteira, em qualquer
 *     navegador, sem precisar de parâmetro. Foi o pedido: "sobe o layout
 *     completo do sistema inteiro dentro da minha conta".
 *   - ou abriu uma tela com ?novo=1 (fica num cookie, um ano). ?novo=0 desliga,
 *     inclusive na conta ligada, para comparar com o desenho antigo.
 *
 * Conta de marca (edição CRM) nunca entra: o desenho dela é outro.
 *
 * A decisão fica num cookie, e não no localStorage como a paleta, porque aqui o
 * PHP precisa saber antes de desenhar: a home nova é outra página, servida pelo
 * dashboard.php no lugar da antiga, e o menu ganha o item "Indicadores".
 *
 * Quando aprovar, a troca para todos é apagar a condição, não reescrever nada.
 */
final class InterfaceNova
{
    public const COOKIE    = 'yuris_interface_nova';
    public const PARAMETRO = 'novo';
    public const CHAVE_CONTA = 'interface_nova';
    private const UM_ANO   = 365 * 86400;

    /** @var array<int,bool> contas já consultadas neste pedido */
    private static array $contas = [];

    /**
     * Vale para este pedido? Sem efeito colateral, para o teste.
     *
     * @param array $get          $_GET
     * @param array $cookie       $_COOKIE
     * @param bool  $contaDeMarca produto fleetiflow: nunca
     * @param bool  $contaLigada  a conta tem interface_nova nas configurações
     */
    public static function ligada(array $get, array $cookie, bool $contaDeMarca, bool $contaLigada = false): bool
    {
        if ($contaDeMarca) return false;
        $m = self::mudanca($get);
        if ($m !== null) return $m === '1';
        if ($contaLigada) return true;
        return (string) ($cookie[self::COOKIE] ?? '') === '1';
    }

    /** A conta tem a interface nova ligada? Lê `configuracoes` da linha de `accounts`. */
    public static function contaLigadaPelaLinha(?array $conta): bool
    {
        if (!$conta) return false;
        $config = json_decode((string) ($conta['configuracoes'] ?? ''), true);
        return is_array($config) && ($config[self::CHAVE_CONTA] ?? false) === true;
    }

    /** O mesmo, pelo id, com cache por pedido. Falha de banco conta como desligada. */
    public static function contaLigada(int $accountId): bool
    {
        if ($accountId <= 0) return false;
        if (array_key_exists($accountId, self::$contas)) return self::$contas[$accountId];
        try {
            $st = Database::getConnection()->prepare('SELECT configuracoes FROM accounts WHERE id = ? LIMIT 1');
            $st->execute([$accountId]);
            $linha = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            return self::$contas[$accountId] = self::contaLigadaPelaLinha($linha);
        } catch (\Throwable $e) {
            return self::$contas[$accountId] = false;
        }
    }

    /** Liga ou desliga na conta, preservando o resto de `configuracoes`. */
    public static function ligarNaConta(\PDO $pdo, int $accountId, bool $ligar): void
    {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = ? FOR UPDATE');
            $st->execute([$accountId]);
            $atual = $st->fetchColumn();
            if ($atual === false) throw new \RuntimeException('conta ' . $accountId . ' não existe');
            $config = json_decode((string) $atual, true);
            if (!is_array($config)) $config = [];
            if ($ligar) $config[self::CHAVE_CONTA] = true; else unset($config[self::CHAVE_CONTA]);
            $pdo->prepare('UPDATE accounts SET configuracoes = ? WHERE id = ?')
                ->execute([json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $accountId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        unset(self::$contas[$accountId]);
    }

    /** O que a URL manda gravar: '1' liga, '0' desliga, null não mexe. */
    public static function mudanca(array $get): ?string
    {
        $p = $get[self::PARAMETRO] ?? null;
        if ($p === '1' || $p === 1) return '1';
        if ($p === '0' || $p === 0) return '0';
        return null;
    }

    /**
     * Grava o cookie quando a URL pede. Chamar ANTES de qualquer saída; quem já
     * mandou cabeçalhos usa scriptCookie().
     */
    public static function aplicar(): void
    {
        $m = self::mudanca($_GET);
        if ($m === null || headers_sent()) return;
        $liga = $m === '1';
        setcookie(self::COOKIE, $liga ? '1' : '', [
            'expires'  => $liga ? time() + self::UM_ANO : time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        if ($liga) $_COOKIE[self::COOKIE] = '1'; else unset($_COOKIE[self::COOKIE]);
    }

    /** O mesmo cookie, por JavaScript, para o menu (que entra no meio da página). */
    public static function scriptCookie(array $get): string
    {
        $m = self::mudanca($get);
        if ($m === null) return '';
        $c = self::COOKIE . '=' . ($m === '1' ? '1; max-age=' . self::UM_ANO : '; max-age=0') . '; path=/; samesite=lax';
        return '<script>try{document.cookie=' . json_encode($c) . ';}catch(e){}</script>';
    }

    /** Endereço da tela atual com o parâmetro trocado (para o botão de desligar). */
    public static function enderecoCom(string $uri, string $valor): string
    {
        $partes = parse_url($uri);
        parse_str($partes['query'] ?? '', $q);
        $q[self::PARAMETRO] = $valor;
        return ($partes['path'] ?? '/') . '?' . http_build_query($q);
    }
}
