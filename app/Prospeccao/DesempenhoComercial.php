<?php
namespace App\Prospeccao;

use App\Clientes\CobrancaDoCliente;
use App\Core\Database;
use App\Master\Account;
use App\Tarefas\Otif;
use App\WhatsAppAgente\MetricasAtendimento;
use App\WhatsAppAgente\WhatsAppChannelAccessService;

/**
 * DesempenhoComercial: o comercial por pessoa, para a tela Desempenho da equipe na
 * edição CRM. Vendedor trabalha lead e conversa, não só tarefa: sem isto, quem passa
 * o dia no WhatsApp aparecia com a tela zerada.
 *
 * Não cria métrica nova. As regras são as que já valem no painel:
 *   Leads     cards criados no período, pelo responsável atual
 *   Vendas    a mesma união do painel comercial (api/dashboard_comercial.php): card
 *             fechado mais valores do cliente, sem contar a mesma venda duas vezes
 *   Conversão vendas do período / leads do período
 *   Carteira  card aberto, fora de coluna de fechado e de perdido
 *   Parados   carteira sem nenhuma alteração há DIAS_PARADO dias
 *   Atendimento  App\WhatsAppAgente\MetricasAtendimento, no horário comercial da
 *             conta quando ligado, igual à tela Comunicação › Atendimento
 *
 * Atendimento depende de enxergar os números: quem não tem a permissão `chat` recebe
 * esses campos nulos, e a tela mostra traço.
 */
final class DesempenhoComercial
{
    public const DIAS_PARADO = 7;

    /** Campos que somam para a linha da equipe. */
    private const SOMAM = ['leads', 'carteira', 'parados', 'vendas', 'valor_vendido', 'enviadas', 'conversas', 'respostas', 'ate_meta', 'sem_resposta'];

    /**
     * @param int[] $contas     contas acessíveis
     * @param ?int  $soUsuario  quem não é admin só vê a si mesmo
     * @return array{equipe:array, pessoas:array<int,array>}
     */
    public static function relatorio(array $contas, int $contaAtual, string $de, string $ate, bool $comValoresDoCliente, bool $veAtendimento, ?int $soUsuario = null): array
    {
        $contas = array_values(array_unique(array_filter(array_map('intval', $contas), static fn($c) => $c > 0)));
        if ($contas === []) return self::consolidar([]);

        $pessoas = [];
        $marca = static function (int $uid, string $campo, $valor) use (&$pessoas): void {
            $pessoas[$uid] ??= self::linhaVazia($uid);
            $pessoas[$uid][$campo] = $valor;
        };

        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($contas), '?'));
        $filtro = $soUsuario !== null ? ' AND c.responsavel_user_id = ?' : '';
        $comUsuario = static fn(array $p) => $soUsuario !== null ? array_merge($p, [$soUsuario]) : $p;

        $st = $pdo->prepare("SELECT c.responsavel_user_id AS uid, COUNT(*) AS n FROM cards c
                              WHERE c.deleted_at IS NULL AND c.account_id IN ($in)
                                AND DATE(c.created_at) BETWEEN ? AND ? $filtro GROUP BY uid");
        $st->execute($comUsuario(array_merge($contas, [$de, $ate])));
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $marca((int) $r['uid'], 'leads', (int) $r['n']);

        $parado = date('Y-m-d H:i:s', strtotime('-' . self::DIAS_PARADO . ' days'));
        $st = $pdo->prepare("SELECT c.responsavel_user_id AS uid, COUNT(*) AS n, SUM(c.updated_at < ?) AS parados
                               FROM cards c LEFT JOIN pipeline_columns pc ON pc.id = c.coluna_id
                              WHERE c.deleted_at IS NULL AND c.account_id IN ($in)
                                AND (c.data_fechamento IS NULL OR c.data_fechamento = '0000-00-00')
                                AND COALESCE(pc.conta_fechado, 0) = 0 AND COALESCE(pc.conta_perdido, 0) = 0 $filtro
                              GROUP BY uid");
        $st->execute($comUsuario(array_merge([$parado], $contas)));
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $marca((int) $r['uid'], 'carteira', (int) $r['n']);
            $marca((int) $r['uid'], 'parados', (int) $r['parados']);
        }

        $valor   = "COALESCE(NULLIF(c.valor_fechado_final,0), NULLIF(c.valor_proposta,0), IFNULL(c.valor_estimado,0))";
        $fechado = "c.data_fechamento IS NOT NULL AND c.data_fechamento > '0000-00-00'";
        $vendas  = "SELECT c.account_id, c.responsavel_user_id AS resp, c.data_fechamento AS dt, $valor AS valor, CONCAT('k', c.id) AS venda
                      FROM cards c WHERE c.deleted_at IS NULL AND $fechado"
                 . ($comValoresDoCliente ? CobrancaDoCliente::sqlFechamentoSemDuplicar('c') . ' UNION ALL ' . CobrancaDoCliente::sqlReceitaDosValores() : '');
        $st = $pdo->prepare("SELECT x.resp AS uid, COUNT(DISTINCT x.venda) AS n,
                                    COALESCE(SUM(CASE WHEN x.venda IS NOT NULL THEN x.valor END), 0) AS valor
                               FROM ($vendas) x
                              WHERE x.account_id IN ($in) AND x.dt BETWEEN ? AND ?"
                          . ($soUsuario !== null ? ' AND x.resp = ?' : '') . ' GROUP BY uid');
        $st->execute($comUsuario(array_merge($contas, [$de, $ate])));
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $marca((int) $r['uid'], 'vendas', (int) $r['n']);
            $marca((int) $r['uid'], 'valor_vendido', round((float) $r['valor'], 2));
        }

        if ($veAtendimento) {
            $horario = MetricasAtendimento::horarioDaConta(Account::findById($contaAtual) ?? []);
            $numeros = WhatsAppChannelAccessService::viewableChannelIds($pdo, $contaAtual);
            $rel = MetricasAtendimento::relatorio($contaAtual, $numeros, $de, $ate, $soUsuario, null, null, $horario['ativo'] ? $horario : null);
            foreach ($rel['operadores'] as $o) {
                $uid = (int) $o['user_id'];
                if ($soUsuario !== null && $uid !== $soUsuario) continue;
                foreach (['enviadas', 'conversas', 'respostas', 'ate_meta', 'sem_resposta'] as $k) $marca($uid, $k, (int) $o[$k]);
                $marca($uid, 'media_resposta', $o['media'] !== null ? round((float) $o['media'], 1) : null);
                $marca($uid, 'soma_resposta', (float) ($o['media'] ?? 0) * (int) $o['respostas']);
            }
        }

        return self::consolidar($pessoas, $veAtendimento);
    }

    /** Completa conversão e percentuais e soma a equipe. Pura. */
    public static function consolidar(array $pessoas, bool $veAtendimento = false): array
    {
        $equipe = self::linhaVazia(null);
        $somaResposta = 0.0;
        foreach ($pessoas as $uid => $p) {
            $p += self::linhaVazia((int) $uid);
            foreach (self::SOMAM as $k) $equipe[$k] += $p[$k];
            $somaResposta += (float) $p['soma_resposta'];
            unset($p['soma_resposta']);
            $pessoas[$uid] = self::percentuais($p);
        }
        $equipe['media_resposta'] = $equipe['respostas'] > 0 ? round($somaResposta / $equipe['respostas'], 1) : null;
        unset($equipe['soma_resposta']);
        return ['equipe' => self::percentuais($equipe), 'pessoas' => $pessoas, 've_atendimento' => $veAtendimento];
    }

    private static function percentuais(array $p): array
    {
        $p['conversao'] = $p['leads'] > 0 ? round($p['vendas'] * 100 / $p['leads'], 1) : null;
        $p['ate_meta_pct'] = $p['respostas'] > 0 ? round($p['ate_meta'] * 100 / $p['respostas'], 1) : null;
        return $p;
    }

    private static function linhaVazia(?int $uid): array
    {
        return ['user_id' => $uid, 'leads' => 0, 'carteira' => 0, 'parados' => 0, 'vendas' => 0, 'valor_vendido' => 0.0,
                'enviadas' => 0, 'conversas' => 0, 'respostas' => 0, 'ate_meta' => 0, 'sem_resposta' => 0,
                'media_resposta' => null, 'soma_resposta' => 0.0];
    }

    /** As linhas da tabela: toda a equipe, quem tem movimento primeiro. */
    public static function linhasDaTabela(array $relatorio, array $pessoasDaEquipe): array
    {
        $ids = array_keys($relatorio['pessoas'] + $pessoasDaEquipe);
        $nomes = Otif::nomes($ids);
        $out = [];
        foreach ($ids as $uid) {
            $linha = $relatorio['pessoas'][$uid] ?? self::consolidar([$uid => []])['pessoas'][$uid];
            $out[] = ['nome' => $pessoasDaEquipe[$uid] ?? ($nomes[$uid] ?? ('Usuário #' . $uid))] + $linha + ['user_id' => $uid];
        }
        $peso = static fn(array $l) => $l['valor_vendido'] * 1000 + $l['vendas'] * 100 + $l['leads'] + $l['carteira'] + $l['enviadas'];
        usort($out, static fn($a, $b) => ($peso($b) <=> $peso($a)) ?: strcmp($a['nome'], $b['nome']));
        return $out;
    }
}
