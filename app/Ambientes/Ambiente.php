<?php
namespace App\Ambientes;

use App\Core\AccountContext;
use App\Core\Database;

/**
 * Os ambientes da Inovaize (pedido de 20/10/2026): Empresa, Comercial, Software e
 * Digital / Marketing. Cada um é um menu próprio, com a sua Visão Geral como
 * primeiro item, sobre as MESMAS telas e os MESMOS cadastros do sistema. Nada aqui
 * duplica dado: o ambiente decide o que o menu mostra e, nas telas que mudam por
 * área (Tarefas e Clientes), o filtro.
 *
 * Como o ambiente é decidido em cada página (`resolver`):
 *   1. `?amb=` no endereço, se a pessoa pode entrar nele;
 *   2. a página pertence a um ambiente só: esse;
 *   3. a página está em vários: o último usado, se for um deles, senão o primeiro;
 *   4. página fora de todos (Configurações): o último usado.
 * Por isso link direto e atualizar o navegador mantêm o menu certo. O último
 * ambiente fica gravado por pessoa (`user_ambientes`).
 *
 * Só vale na conta com o módulo ligado (`configuracoes.modulos.ambientes`), hoje
 * só a Inovaize. Nas outras contas o menu é o de sempre.
 */
final class Ambiente
{
    public const LISTA = [
        'empresa' => [
            'rotulo' => 'Empresa', 'descricao' => 'Toda a Inovaize: receita, clientes, projetos e pendências',
            'inicio' => '/empresa', 'ativo' => 'amb_empresa',
            'icone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/></svg>',
        ],
        'comercial' => [
            'rotulo' => 'Comercial', 'descricao' => 'Prospecção, propostas, contratos e metas',
            'inicio' => '/dashboard?amb=comercial', 'ativo' => 'dashboard',
            'icone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
        ],
        'software' => [
            'rotulo' => 'Software', 'descricao' => 'Projetos, demandas, suporte e prazos',
            'inicio' => '/software', 'ativo' => 'amb_software',
            'icone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        ],
        'digital' => [
            'rotulo' => 'Digital / Marketing', 'descricao' => 'Produção por cliente, entregas e campanhas',
            'inicio' => '/digital', 'ativo' => 'amb_digital',
            'icone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>',
        ],
    ];

    /**
     * Itens que só existem nos ambientes. As páginas de sempre entram pelo href
     * com que o menu já as conhece (o sidebar só oferece o que a pessoa pode ver).
     */
    public const ITENS_PROPRIOS = [
        'software_quadros' => ['perm' => 'tarefas', 'href' => 'tarefas.php?amb=software', 'active' => 'tarefas', 'icon' => 'tarefas', 'label' => 'Demandas e suporte'],
        'digital_quadros'  => ['perm' => 'tarefas', 'href' => 'tarefas.php?amb=digital', 'active' => 'tarefas', 'icon' => 'tarefas', 'label' => 'Produção'],
        'digital_entregas' => ['perm' => 'tarefas', 'href' => 'digital.php?ver=entregas', 'active' => 'digital_entregas', 'icon' => 'desempenho', 'label' => 'Entregas'],
        'digital_campanhas' => ['perm_any' => ['tarefas', 'clientes'], 'href' => 'digital.php?ver=campanhas', 'active' => 'digital_campanhas', 'icon' => 'funil', 'label' => 'Campanhas'],
    ];

    /** Menu de cada ambiente: seções e itens (href do menu de sempre, "*" no fim = prefixo, "@x" = item próprio). */
    public const LAYOUT = [
        'empresa' => [
            ['key' => 'emp_cadastros', 'label' => 'Cadastros', 'g' => 'gestao', 'itens' => ['clientes.php', 'usuarios.php', 'usuarios.php?tab=setores']],
            ['key' => 'emp_contratos', 'label' => 'Contratos', 'g' => 'contratos', 'direto' => true, 'itens' => ['contratos.php']],
            ['key' => 'emp_financeiro', 'label' => 'Financeiro', 'g' => 'financeiro', 'itens' => ['financas.php']],
            ['key' => 'emp_projetos', 'label' => 'Projetos', 'g' => 'projetos', 'itens' => ['projetos.php*']],
            ['key' => 'emp_gestao', 'label' => 'Gestão', 'g' => 'gestao', 'itens' => ['tarefas.php', 'relatorios.php', 'desempenho.php', 'escritorios.php']],
            ['key' => 'emp_infra', 'label' => 'Infraestrutura', 'g' => 'automacoes', 'itens' => ['evolution.php', 'agente.php', 'webhooks.php']],
        ],
        'comercial' => [
            ['key' => 'com_vendas', 'label' => 'Vendas', 'g' => 'operacao', 'itens' => ['planejamento.php', 'prospeccao.php', 'orcamentos.php', 'plataforma.php', 'clientes.php']],
            ['key' => 'com_contratos', 'label' => 'Contratos', 'g' => 'contratos', 'direto' => true, 'itens' => ['contratos.php']],
            ['key' => 'com_comunicacao', 'label' => 'Comunicação', 'g' => 'comunicacao', 'itens' => ['chat.php', 'chat_interno.php', 'agente.php']],
            ['key' => 'com_metas', 'label' => 'Metas e resultados', 'g' => 'gestao', 'itens' => ['desempenho.php', 'relatorios.php']],
        ],
        'software' => [
            ['key' => 'sw_operacao', 'label' => 'Operação', 'g' => 'operacao', 'itens' => ['@software_quadros', 'clientes.php']],
            ['key' => 'sw_projetos', 'label' => 'Projetos', 'g' => 'projetos', 'itens' => ['projetos.php*']],
            ['key' => 'sw_infra', 'label' => 'Infraestrutura', 'g' => 'automacoes', 'itens' => ['evolution.php']],
            ['key' => 'sw_equipe', 'label' => 'Equipe', 'g' => 'comunicacao', 'itens' => ['chat_interno.php', 'desempenho.php']],
        ],
        'digital' => [
            ['key' => 'dg_operacao', 'label' => 'Operação', 'g' => 'operacao', 'itens' => ['clientes.php', '@digital_quadros', '@digital_entregas', '@digital_campanhas']],
            ['key' => 'dg_equipe', 'label' => 'Equipe', 'g' => 'comunicacao', 'itens' => ['chat_interno.php', 'desempenho.php']],
        ],
    ];

    /** Página (valor de $activePage) de cada href conhecido; serve para decidir o ambiente antes de montar o menu. */
    private const ATIVO_DO_HREF = [
        'planejamento.php' => 'funil', 'prospeccao.php' => 'prospeccao', 'orcamentos.php' => 'orcamentos', 'plataforma.php' => 'plataforma',
        'clientes.php' => 'clientes', 'tarefas.php' => 'tarefas', 'contratos.php' => 'contratos', 'chat.php' => 'chat',
        'chat_interno.php' => 'chat_interno', 'agente.php' => 'agente', 'webhooks.php' => 'webhooks', 'relatorios.php' => 'relatorios',
        'desempenho.php' => 'desempenho', 'financas.php' => 'dre', 'usuarios.php' => 'usuarios', 'usuarios.php?tab=setores' => 'setores',
        'escritorios.php' => 'escritorios', 'evolution.php' => 'evolution', 'projetos.php*' => 'projetos',
    ];

    public static function habilitado(array $conta): bool
    {
        if (!$conta || \App\Master\Account::moduloJuridicoDisponivel($conta)) return false;
        $config = json_decode((string) ($conta['configuracoes'] ?? ''), true);
        return is_array($config) && ($config['modulos']['ambientes'] ?? false) === true;
    }

    /** Liga ou desliga o módulo na conta, preservando o resto de `configuracoes`. */
    public static function ligar(\PDO $pdo, int $accountId, bool $ligado): void
    {
        $st = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = ?');
        $st->execute([$accountId]);
        $config = json_decode((string) $st->fetchColumn(), true);
        if (!is_array($config)) $config = [];
        $config['modulos']['ambientes'] = $ligado;
        $pdo->prepare('UPDATE accounts SET configuracoes = ? WHERE id = ?')
            ->execute([json_encode($config, JSON_UNESCAPED_UNICODE), $accountId]);
    }

    /** Permissão de página da sessão (a mesma regra do menu), relida do banco se a sessão perdeu a lista. */
    public static function podePagina(string $pagina): bool
    {
        if (strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin') return true;
        $perms = $_SESSION['user_permissions'] ?? [];
        if (!$perms && !empty($_SESSION['user_id'])) {
            $st = Database::getConnection()->prepare('SELECT page FROM user_permissions WHERE user_id = ?');
            $st->execute([(int) $_SESSION['user_id']]);
            $perms = $st->fetchAll(\PDO::FETCH_COLUMN);
        }
        return in_array('*', $perms, true) || in_array($pagina, $perms, true);
    }

    public static function valido(?string $amb): bool
    {
        return $amb !== null && isset(self::LISTA[$amb]);
    }

    /**
     * Quem entra em cada ambiente. É a mesma pergunta que a Visão Geral faz no
     * servidor (`exigir`), então o seletor nunca oferece o que a página recusa.
     *
     * @param callable(string):bool $pode permissão de página da pessoa
     */
    public static function podeEntrar(string $amb, callable $pode, bool $admin): bool
    {
        return match ($amb) {
            'empresa'   => $admin || $pode('financas'),
            'comercial' => $pode('dashboard') || $pode('prospeccao'),
            'software'  => $pode('tarefas') || $pode('financas'),
            'digital'   => $pode('tarefas') || $pode('clientes'),
            default     => false,
        };
    }

    /** @return string[] os ambientes que a pessoa pode abrir, na ordem do seletor */
    public static function permitidos(callable $pode, bool $admin): array
    {
        return array_values(array_filter(array_keys(self::LISTA), fn($a) => self::podeEntrar($a, $pode, $admin)));
    }

    /** @return array<string, string[]> ambiente => páginas ($activePage) que ele mostra */
    public static function mapaDePaginas(): array
    {
        $mapa = [];
        foreach (self::LAYOUT as $amb => $secoes) {
            $mapa[$amb] = [self::LISTA[$amb]['ativo']];
            foreach ($secoes as $s) {
                foreach ($s['itens'] as $h) {
                    $ativo = $h[0] === '@' ? (self::ITENS_PROPRIOS[substr($h, 1)]['active'] ?? null) : (self::ATIVO_DO_HREF[$h] ?? null);
                    if ($ativo !== null) $mapa[$amb][] = $ativo;
                }
            }
            if ($amb === 'digital') { $mapa[$amb][] = 'digital_entregas'; $mapa[$amb][] = 'digital_campanhas'; }
            $mapa[$amb] = array_values(array_unique($mapa[$amb]));
        }
        return $mapa;
    }

    /** Ambiente de uma página. Ver a regra no cabeçalho da classe. */
    public static function resolver(string $pagina, ?string $pedido, ?string $ultimo, array $permitidos): string
    {
        if (self::valido($pedido) && in_array($pedido, $permitidos, true)) return $pedido;
        if (str_starts_with($pagina, 'projeto:')) $pagina = 'projetos';
        $donos = [];
        foreach (self::mapaDePaginas() as $amb => $paginas) {
            if (in_array($pagina, $paginas, true) && in_array($amb, $permitidos, true)) $donos[] = $amb;
        }
        if (count($donos) === 1) return $donos[0];
        if ($donos) return ($ultimo && in_array($ultimo, $donos, true)) ? $ultimo : $donos[0];
        if ($ultimo && in_array($ultimo, $permitidos, true)) return $ultimo;
        return $permitidos[0] ?? 'empresa';
    }

    public static function ultimo(int $userId): ?string
    {
        if ($userId <= 0) return null;
        $st = Database::getConnection()->prepare('SELECT ambiente FROM user_ambientes WHERE user_id = ?');
        $st->execute([$userId]);
        $a = $st->fetchColumn();
        return self::valido($a ?: null) ? $a : null;
    }

    public static function lembrar(int $userId, int $accountId, string $amb): void
    {
        if ($userId <= 0 || !self::valido($amb)) return;
        Database::getConnection()->prepare(
            'INSERT INTO user_ambientes (user_id, account_id, ambiente) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), ambiente = VALUES(ambiente)'
        )->execute([$userId, $accountId, $amb]);
    }

    /**
     * O contexto do menu para a página atual, ou null quando a conta não usa
     * ambientes. Grava o ambiente como último usado quando muda.
     *
     * @param callable(string):bool $pode
     * @return array{atual:string, permitidos:string[], lista:array}|null
     */
    public static function contexto(AccountContext $ctx, string $pagina, ?string $pedido, callable $pode, bool $admin): ?array
    {
        if (!self::habilitado(\App\Master\Account::findById((int) $ctx->getAccountId()) ?? [])) return null;
        $permitidos = self::permitidos($pode, $admin || $ctx->isOwnerOrAdmin());
        if (!$permitidos) return null;
        $uid = (int) $ctx->getUserId();
        $ultimo = self::ultimo($uid);
        $atual = self::resolver($pagina, $pedido, $ultimo, $permitidos);
        if ($atual !== $ultimo) {
            try { self::lembrar($uid, (int) $ctx->getAccountId(), $atual); } catch (\Throwable $e) { /* lembrar nunca derruba a página */ }
        }
        return ['atual' => $atual, 'permitidos' => $permitidos, 'lista' => self::LISTA];
    }

    /**
     * Troca as seções do menu de sempre pelas do ambiente. Recebe as seções já
     * montadas pelo sidebar (com as condições aplicadas: contrato só para quem vê
     * contrato, Projetos só com o módulo) e só reorganiza os itens; o filtro de
     * permissão continua sendo o do sidebar. Página que aparece em mais de um
     * ambiente ganha `?amb=` no link, para o endereço dizer onde a pessoa está.
     *
     * @param array<string, string> $icones ícone de grupo por chave
     */
    public static function secoes(string $amb, array $secoesDeSempre, array $icones): array
    {
        $porHref = [];
        foreach ($secoesDeSempre as $s) {
            foreach ($s['items'] as $it) $porHref[$it['href']] = $it;
            if (!empty($s['icon'])) $icones[$s['key']] ??= $s['icon'];
        }

        $vezes = [];
        foreach (self::LAYOUT as $secs) {
            $vistos = [];
            foreach ($secs as $s) foreach ($s['itens'] as $h) if ($h[0] !== '@') $vistos[rtrim($h, '*')] = true;
            foreach (array_keys($vistos) as $h) $vezes[$h] = ($vezes[$h] ?? 0) + 1;
        }

        $out = [];
        foreach (self::LAYOUT[$amb] ?? [] as $s) {
            $itens = [];
            foreach ($s['itens'] as $h) {
                if ($h[0] === '@') {
                    $it = self::ITENS_PROPRIOS[substr($h, 1)] ?? null;
                    if ($it) $itens[] = $it;
                    continue;
                }
                $lista = [];
                if (str_ends_with($h, '*')) {
                    $pre = rtrim($h, '*');
                    foreach ($porHref as $k => $it) if (str_starts_with($k, $pre)) $lista[] = $it;
                } elseif (isset($porHref[$h])) {
                    $lista[] = $porHref[$h];
                }
                foreach ($lista as $it) {
                    if (($vezes[rtrim($h, '*')] ?? 0) > 1) $it['href'] .= (str_contains($it['href'], '?') ? '&' : '?') . 'amb=' . $amb;
                    $itens[] = $it;
                }
            }
            if (!$itens) continue;
            $sec = ['key' => $s['key'], 'label' => $s['label'], 'icon' => $icones[$s['g']] ?? '', 'items' => $itens];
            if (!empty($s['direto'])) $sec['direto'] = true;
            $out[] = $sec;
        }
        return $out;
    }

    /**
     * Guarda das Visões Gerais: sem login vai para o login, sem o módulo ou sem
     * permissão para o ambiente vai para o painel. Devolve o contexto da conta.
     */
    public static function exigir(string $amb): AccountContext
    {
        if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }
        $ctx = AccountContext::fromSession();
        $ctx->assertAccountActive();
        $conta = \App\Master\Account::findById((int) $ctx->getAccountId()) ?? [];
        if (!self::habilitado($conta) || !self::podeEntrar($amb, [self::class, 'podePagina'], $ctx->isOwnerOrAdmin())) {
            header('Location: /dashboard'); exit;
        }
        return $ctx;
    }
}
