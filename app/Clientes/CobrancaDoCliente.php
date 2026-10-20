<?php
namespace App\Clientes;

use App\Core\Database;

/**
 * As cobranças dos clientes: as contas a receber que saem dos valores
 * combinados (ValoresDoCliente), 20/10/2026.
 *
 *   mensal    uma cobrança por mês, de "começa em" até o fim (se houver), no dia
 *             de vencimento (sem dia: o dia do início). Dia 31 em mês curto cai no
 *             último dia do mês.
 *   anual     uma por ano, no aniversário do início.
 *   fixo,     uma só, na data informada.
 *   servico
 *   demanda   não gera cobrança (é só valor de referência).
 *
 * Geradas até o fim do mês seguinte (a próxima já aparece) pelo worker diário e
 * sempre que o valor é salvo. (valor_id, competencia) é único: gerar de novo não
 * duplica. Não se gera para trás antes do mês em que o valor foi cadastrado:
 * mensalidade antiga cadastrada hoje não vira uma pilha de cobranças atrasadas.
 *
 * Mudou o valor, o dia ou o período: as cobranças ABERTAS a partir de hoje são
 * refeitas; as pagas e as já vencidas ficam como estão. Encerrou o valor: as
 * abertas a partir de hoje viram canceladas. A cancelada à mão continua cancelada,
 * a não ser que o valor ou o vencimento dela não batam mais com o valor corrigido:
 * aí ela era a cobrança errada (ex.: "2.500" lido como R$ 2,50 e cancelado) e volta
 * certa.
 *
 * "Hoje" é o de Brasília. Toda consulta filtra account_id.
 */
final class CobrancaDoCliente
{
    public const STATUS = ['aberta' => 'Em aberto', 'paga' => 'Paga', 'cancelada' => 'Cancelada'];
    public const FORMAS = ['pix' => 'Pix', 'boleto' => 'Boleto', 'cartao' => 'Cartão', 'transferencia' => 'Transferência', 'dinheiro' => 'Dinheiro', 'outro' => 'Outro'];

    public static function hoje(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    }

    /** Último dia de cobrança gerada: o fim do mês seguinte ao de $hoje. */
    public static function horizonte(?string $hoje = null): string
    {
        $h = new \DateTimeImmutable(($hoje ?? self::hoje()) . ' 00:00:00');
        return $h->modify('first day of next month')->modify('last day of this month')->format('Y-m-d');
    }

    /** Dia $dia do mês de $mes (Y-m-01), sem passar do último dia do mês. */
    public static function diaNoMes(string $mes, int $dia): string
    {
        $m = new \DateTimeImmutable(substr($mes, 0, 7) . '-01');
        return $m->format('Y-m-') . str_pad((string) min($dia, (int) $m->format('t')), 2, '0', STR_PAD_LEFT);
    }

    /**
     * As cobranças que um valor deve ter (pura, para o teste).
     * @return array<int, array{competencia:string, vencimento:string}>
     */
    public static function previstas(array $v, ?string $hoje = null): array
    {
        $tipo = (string) $v['tipo'];
        if ($tipo === 'demanda' || empty($v['inicio']) || empty($v['ativo'])) return [];
        $limite = self::horizonte($hoje);
        if (!empty($v['fim']) && $v['fim'] < $limite) $limite = (string) $v['fim'];
        $inicio = (string) $v['inicio'];
        if (in_array($tipo, ['fixo', 'servico'], true)) {
            return [['competencia' => $inicio, 'vencimento' => $inicio]];
        }
        // Não gera para trás antes do mês do cadastro.
        $piso = !empty($v['criado_em']) ? substr((string) $v['criado_em'], 0, 7) . '-01' : '0000-01-01';
        $dia = !empty($v['dia_vencimento']) ? (int) $v['dia_vencimento'] : (int) substr($inicio, 8, 2);
        $out = [];
        $passo = $tipo === 'anual' ? '+1 year' : '+1 month';
        $mes = new \DateTimeImmutable(substr($inicio, 0, 7) . '-01');
        for ($i = 0; $i < 600; $i++, $mes = $mes->modify($passo)) {
            $comp = $mes->format('Y-m-d');
            $venc = self::diaNoMes($comp, $dia);
            if ($venc < $inicio && $i === 0) $venc = $inicio;   // começou depois do dia de vencimento: o primeiro vence no início
            if ($venc > $limite) break;
            if ($comp < $piso && $tipo === 'mensal') continue;
            $out[] = ['competencia' => $comp, 'vencimento' => $venc];
        }
        return $out;
    }

    /** Acerta as cobranças de um valor (salvo, editado, encerrado). @return int cobranças criadas */
    public static function sincronizar(int $accountId, int $valorId, ?string $hoje = null): int
    {
        $hoje = $hoje ?? self::hoje();
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT * FROM crm_cliente_valores WHERE id = ? AND account_id = ?');
        $st->execute([$valorId, $accountId]);
        $v = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$v) return 0;
        $v['criado_em'] = $v['created_at'];

        // Encerrado, sem cobrança (demanda) ou cobranças abertas que não batem mais: as abertas
        // de hoje em diante saem (canceladas se o valor foi encerrado, refeitas se mudou).
        $previstas = self::previstas($v, $hoje);
        $porComp = [];
        foreach ($previstas as $p) $porComp[$p['competencia']] = $p;
        $atualiza = $pdo->prepare('UPDATE crm_cliente_cobrancas SET tipo = ?, vencimento = ?, valor = ?, descricao = ?, contrato_id = ? WHERE id = ?');
        $abertas = $pdo->prepare("SELECT id, tipo, competencia, vencimento, valor, descricao, contrato_id FROM crm_cliente_cobrancas
                                   WHERE account_id = ? AND valor_id = ? AND status = 'aberta' AND vencimento >= ?");
        $abertas->execute([$accountId, $valorId, $hoje]);
        foreach ($abertas->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $p = $porComp[$c['competencia']] ?? null;
            if (!$p) {
                $pdo->prepare("UPDATE crm_cliente_cobrancas SET status = 'cancelada', observacao = ? WHERE id = ?")
                    ->execute([empty($v['ativo']) ? 'Valor encerrado' : 'Fora do período do valor', (int) $c['id']]);
                continue;
            }
            if ($c['vencimento'] !== $p['vencimento'] || (float) $c['valor'] !== (float) $v['valor'] || $c['descricao'] !== $v['descricao']
                || $c['tipo'] !== $v['tipo'] || (int) $c['contrato_id'] !== (int) $v['contrato_id']) {
                $atualiza->execute([$v['tipo'], $p['vencimento'], $v['valor'], $v['descricao'], $v['contrato_id'] ?: null, (int) $c['id']]);
            }
        }
        // Reativado ou período estendido de novo: a cancelada pelo sistema (não a cancelada à
        // mão) volta a valer se está prevista e não venceu. INSERT IGNORE não a recriaria.
        if ($porComp) {
            $canc = $pdo->prepare("SELECT id, competencia, vencimento, valor, observacao FROM crm_cliente_cobrancas
                                    WHERE account_id = ? AND valor_id = ? AND status = 'cancelada'
                                      AND observacao IN ('Valor encerrado', 'Fora do período do valor', 'Cancelada à mão')");
            $canc->execute([$accountId, $valorId]);
            foreach ($canc->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $p = $porComp[$c['competencia']] ?? null;
                if (!$p || $p['vencimento'] < $hoje) continue;
                // À mão: só volta se era a cobrança errada (valor ou vencimento antigos).
                if ($c['observacao'] === 'Cancelada à mão' && (float) $c['valor'] === (float) $v['valor'] && $c['vencimento'] === $p['vencimento']) continue;
                $pdo->prepare("UPDATE crm_cliente_cobrancas SET status = 'aberta', observacao = NULL WHERE id = ?")->execute([(int) $c['id']]);
                $atualiza->execute([$v['tipo'], $p['vencimento'], $v['valor'], $v['descricao'], $v['contrato_id'] ?: null, (int) $c['id']]);
            }
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO crm_cliente_cobrancas (account_id, cliente_id, valor_id, contrato_id, tipo, descricao, competencia, vencimento, valor)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $n = 0;
        foreach ($previstas as $p) {
            $ins->execute([$accountId, (int) $v['cliente_id'], $valorId, $v['contrato_id'] ?: null, $v['tipo'], $v['descricao'], $p['competencia'], $p['vencimento'], $v['valor']]);
            $n += $ins->rowCount();
        }
        return $n;
    }

    /**
     * Pedaço de WHERE (começa com AND) para a soma de "negócios fechados" do financeiro:
     * tira o card cujo cliente tem valor que vira cobrança (tudo menos "por demanda").
     * A receita desse cliente passa a ser o que ele paga; contar também o valor do
     * fechamento dobraria. Cliente só com "por demanda", ou card sem cliente, segue
     * contando pelo fechamento. Usado em financas.php e api/dre_accounts.php, só na
     * edição CRM.
     */
    public static function sqlFechamentoSemDuplicar(string $cards = 'cards'): string
    {
        return " AND NOT ($cards.cliente_id IS NOT NULL AND EXISTS (SELECT 1 FROM crm_cliente_valores vdup
                   WHERE vdup.account_id = $cards.account_id AND vdup.cliente_id = $cards.cliente_id AND vdup.tipo <> 'demanda'))";
    }

    /** O worker diário: gera o que falta de todos os valores ativos. @return int criadas */
    public static function gerarTodas(?string $hoje = null): int
    {
        $pdo = Database::getConnection();
        $n = 0;
        foreach ($pdo->query("SELECT id, account_id FROM crm_cliente_valores WHERE ativo = 1 AND tipo <> 'demanda' AND inicio IS NOT NULL") as $v) {
            $n += self::sincronizar((int) $v['account_id'], (int) $v['id'], $hoje);
        }
        return $n;
    }

    /** As cobranças de um cliente e os totais. */
    public static function doCliente(int $accountId, int $clienteId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT c.*, k.titulo AS contrato_titulo FROM crm_cliente_cobrancas c
               LEFT JOIN crm_contratos k ON k.id = c.contrato_id AND k.account_id = c.account_id
              WHERE c.account_id = ? AND c.cliente_id = ?
              ORDER BY (c.status = 'aberta') DESC, c.vencimento ASC, c.id LIMIT 300"
        );
        $st->execute([$accountId, $clienteId]);
        $hoje = self::hoje();
        $itens = array_map(fn($r) => self::formatar($r, $hoje), $st->fetchAll(\PDO::FETCH_ASSOC));
        $tot = ['aberto' => 0.0, 'atrasado' => 0.0, 'recebido_ano' => 0.0];
        foreach ($itens as $i) {
            if ($i['status'] === 'aberta') { $tot['aberto'] += $i['valor']; if ($i['atrasada']) $tot['atrasado'] += $i['valor']; }
            if ($i['status'] === 'paga' && substr((string) $i['pago_em'], 0, 4) === substr($hoje, 0, 4)) $tot['recebido_ano'] += (float) ($i['valor_pago'] ?? $i['valor']);
        }
        return ['itens' => $itens, 'totais' => array_map(fn($x) => round($x, 2), $tot)];
    }

    /** O painel "Recebimentos de clientes" da tela Finanças. */
    public static function painel(int $accountId, string $filtro = 'abertas'): array
    {
        $pdo = Database::getConnection();
        $hoje = self::hoje();
        $mesIni = substr($hoje, 0, 7) . '-01';
        $mesFim = (new \DateTimeImmutable($mesIni))->modify('last day of this month')->format('Y-m-d');
        $proxIni = (new \DateTimeImmutable($mesIni))->modify('+1 month')->format('Y-m-d');
        $proxFim = (new \DateTimeImmutable($proxIni))->modify('last day of this month')->format('Y-m-d');
        $k = $pdo->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN status = 'aberta' AND vencimento BETWEEN ? AND ? THEN valor END), 0) AS a_receber_mes,
               COALESCE(SUM(CASE WHEN status = 'aberta' AND vencimento < ? THEN valor END), 0) AS atrasado,
               COUNT(CASE WHEN status = 'aberta' AND vencimento < ? THEN 1 END) AS atrasadas,
               COALESCE(SUM(CASE WHEN status = 'paga' AND pago_em BETWEEN ? AND ? THEN COALESCE(valor_pago, valor) END), 0) AS recebido_mes,
               COALESCE(SUM(CASE WHEN status <> 'cancelada' AND vencimento BETWEEN ? AND ? THEN valor END), 0) AS previsto_proximo
             FROM crm_cliente_cobrancas WHERE account_id = ?"
        );
        $k->execute([$hoje, $mesFim, $hoje, $hoje, $mesIni, $mesFim, $proxIni, $proxFim, $accountId]);
        $kpi = array_map('floatval', $k->fetch(\PDO::FETCH_ASSOC));
        $mr = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM crm_cliente_valores WHERE account_id = ? AND ativo = 1 AND tipo = 'mensal' AND (fim IS NULL OR fim >= ?)");
        $mr->execute([$accountId, $hoje]);
        $kpi['mensalidades_ativas'] = (float) $mr->fetchColumn();

        $where = match ($filtro) {
            'atrasadas' => "c.status = 'aberta' AND c.vencimento < ?",
            'pagas'     => "c.status = 'paga' AND c.pago_em >= ?",
            default     => "c.status = 'aberta' AND c.vencimento <= ?",
        };
        $arg = match ($filtro) {
            'atrasadas' => $hoje,
            'pagas'     => (new \DateTimeImmutable($hoje))->modify('-90 days')->format('Y-m-d'),
            default     => $proxFim,
        };
        $st = $pdo->prepare(
            "SELECT c.*, cl.nome AS cliente_nome, k.titulo AS contrato_titulo FROM crm_cliente_cobrancas c
               JOIN clientes cl ON cl.id = c.cliente_id AND cl.account_id = c.account_id
               LEFT JOIN crm_contratos k ON k.id = c.contrato_id AND k.account_id = c.account_id
              WHERE c.account_id = ? AND $where
              ORDER BY " . ($filtro === 'pagas' ? 'c.pago_em DESC' : 'c.vencimento ASC') . ', c.id LIMIT 300'
        );
        $st->execute([$accountId, $arg]);
        $itens = array_map(fn($r) => self::formatar($r, $hoje) + ['cliente' => $r['cliente_nome'], 'cliente_id' => (int) $r['cliente_id']], $st->fetchAll(\PDO::FETCH_ASSOC));
        return ['kpi' => array_map(fn($x) => is_float($x) ? round($x, 2) : $x, $kpi), 'itens' => $itens, 'hoje' => $hoje];
    }

    public static function baixar(int $accountId, int $userId, int $id, array $d): void
    {
        $pago = trim((string) ($d['pago_em'] ?? '')) ?: self::hoje();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pago)) throw new \InvalidArgumentException('Data do pagamento inválida.');
        $valor = ValoresDoCliente::numero($d['valor_pago'] ?? null);
        $forma = (string) ($d['forma_pagamento'] ?? '');
        if ($forma !== '' && !isset(self::FORMAS[$forma])) throw new \InvalidArgumentException('Forma de pagamento inválida.');
        $st = Database::getConnection()->prepare("UPDATE crm_cliente_cobrancas SET status = 'paga', pago_em = ?, valor_pago = COALESCE(?, valor), forma_pagamento = ?, baixado_por = ?
                                                   WHERE id = ? AND account_id = ? AND status = 'aberta'");
        $st->execute([$pago, $valor, $forma ?: null, $userId ?: null, $id, $accountId]);
        if ($st->rowCount() === 0) throw new \InvalidArgumentException('Cobrança não encontrada ou já baixada.');
    }

    public static function desfazer(int $accountId, int $id): void
    {
        $st = Database::getConnection()->prepare("UPDATE crm_cliente_cobrancas SET status = 'aberta', pago_em = NULL, valor_pago = NULL, forma_pagamento = NULL, baixado_por = NULL
                                                   WHERE id = ? AND account_id = ? AND status = 'paga'");
        $st->execute([$id, $accountId]);
        if ($st->rowCount() === 0) throw new \InvalidArgumentException('Cobrança não encontrada ou não está paga.');
    }

    /** Desfaz um cancelamento (à mão ou do sistema). */
    public static function reabrir(int $accountId, int $id): void
    {
        $st = Database::getConnection()->prepare("UPDATE crm_cliente_cobrancas SET status = 'aberta', observacao = NULL WHERE id = ? AND account_id = ? AND status = 'cancelada'");
        $st->execute([$id, $accountId]);
        if ($st->rowCount() === 0) throw new \InvalidArgumentException('Cobrança não encontrada ou não está cancelada.');
    }

    public static function cancelar(int $accountId, int $id): void
    {
        $st = Database::getConnection()->prepare("UPDATE crm_cliente_cobrancas SET status = 'cancelada', observacao = 'Cancelada à mão' WHERE id = ? AND account_id = ? AND status = 'aberta'");
        $st->execute([$id, $accountId]);
        if ($st->rowCount() === 0) throw new \InvalidArgumentException('Cobrança não encontrada ou não está em aberto.');
    }

    private static function formatar(array $r, string $hoje): array
    {
        $tipos = ['mensal' => 'Mensalidade', 'anual' => 'Anuidade', 'fixo' => 'Taxa', 'servico' => 'Serviço'];
        return [
            'id' => (int) $r['id'], 'tipo' => $r['tipo'], 'tipo_rotulo' => $tipos[$r['tipo']] ?? $r['tipo'], 'descricao' => $r['descricao'],
            'competencia' => $r['competencia'], 'vencimento' => $r['vencimento'], 'valor' => (float) $r['valor'],
            'status' => $r['status'], 'status_rotulo' => self::STATUS[$r['status']] ?? $r['status'],
            'atrasada' => $r['status'] === 'aberta' && $r['vencimento'] < $hoje,
            'pago_em' => $r['pago_em'], 'valor_pago' => $r['valor_pago'] !== null ? (float) $r['valor_pago'] : null,
            'forma_pagamento' => $r['forma_pagamento'] ? (self::FORMAS[$r['forma_pagamento']] ?? $r['forma_pagamento']) : null,
            'contrato_id' => $r['contrato_id'] ? (int) $r['contrato_id'] : null, 'contrato' => $r['contrato_titulo'] ?? null,
            'observacao' => $r['observacao'] ?? null,
        ];
    }
}
