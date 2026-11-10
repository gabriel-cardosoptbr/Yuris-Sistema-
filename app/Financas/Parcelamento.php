<?php
namespace App\Financas;

use App\Core\Database;

/**
 * Receitas e despesas ligadas a cliente e processo, parceladas, com baixa
 * (T2, 10/11/2026, migration 179).
 *
 * Cada parcela é um lançamento comum do Financeiro (dre_accounts) com
 * recorrencia 'variavel' e data_referencia = vencimento: o DRE e as tabelas de
 * Finanças já a contam no mês do vencimento, sem regra nova. O que a torna um
 * título (a receber ou a pagar) é ter `vencimento`; `pago_em` NULL = pendente.
 * O grupo fica em fin_parcelamentos.
 *
 * Regras de data e valor:
 *   - mensal a partir do primeiro vencimento, sempre pelo DIA do primeiro: 31/01
 *     dá 28 ou 29/02, 31/03, 30/04 (o dia que não existe vira o último do mês,
 *     e o mês seguinte volta ao dia 31);
 *   - valor total dividido em centavos, com o resto na última: R$ 100,00 em 3
 *     dá 33,33 + 33,33 + 33,34; ou o mesmo valor em cada parcela.
 *
 * Tudo filtra por conta. Cliente e processo têm de ser das contas que a sessão
 * alcança, e o processo, quando o cliente vem junto, tem de ser desse cliente.
 */
class Parcelamento
{
    public const MAX_PARCELAS = 120;

    /** Vencimentos mensais a partir do primeiro (Y-m-d), pelo dia do primeiro. */
    public static function vencimentos(string $primeiro, int $quantidade): array
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $primeiro);
        if (!$d || $d->format('Y-m-d') !== $primeiro) throw new \InvalidArgumentException('Primeiro vencimento inválido.');
        if ($quantidade < 1 || $quantidade > self::MAX_PARCELAS) throw new \InvalidArgumentException('Quantidade de parcelas inválida (1 a ' . self::MAX_PARCELAS . ').');
        $dia = (int) $d->format('j');
        $ano = (int) $d->format('Y');
        $mes = (int) $d->format('n');
        $out = [];
        for ($i = 0; $i < $quantidade; $i++) {
            $m = $mes + $i;
            $a = $ano + intdiv($m - 1, 12);
            $m = (($m - 1) % 12) + 1;
            // 't' = dias do mês, sem depender da extensão calendar.
            $ultimo = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $a, $m)))->format('t');
            $out[] = sprintf('%04d-%02d-%02d', $a, $m, min($dia, $ultimo));
        }
        return $out;
    }

    /** Divide o total em centavos; o resto vai para a última parcela. */
    public static function dividir(float $total, int $quantidade): array
    {
        if ($quantidade < 1) throw new \InvalidArgumentException('Quantidade de parcelas inválida.');
        $centavos = (int) round($total * 100);
        if ($centavos <= 0) throw new \InvalidArgumentException('O valor precisa ser maior que zero.');
        $base = intdiv($centavos, $quantidade);
        if ($base <= 0) throw new \InvalidArgumentException('Valor pequeno demais para tantas parcelas.');
        $out = array_fill(0, $quantidade, $base);
        $out[$quantidade - 1] += $centavos - $base * $quantidade;
        return array_map(static fn ($c) => round($c / 100, 2), $out);
    }

    /** Número em formato brasileiro ou de máquina ("1.234,56", "1234.56", 1234.56). */
    public static function numero(mixed $v): float
    {
        if (is_int($v) || is_float($v)) return round((float) $v, 2);
        $s = trim((string) $v);
        if ($s === '') return 0.0;
        $s = preg_replace('/[^\d,.\-]/', '', $s);
        if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
        return round((float) $s, 2);
    }

    /** "Hoje" no fuso de Brasília (o PHP do sistema roda em UTC). */
    public static function hoje(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    }

    /**
     * Confere cliente e processo contra as contas alcançadas. Devolve [cliente_id, processo_id]
     * (null quando não informados). Lança InvalidArgumentException com a mensagem para a tela.
     */
    public static function validarVinculos(mixed $clienteId, mixed $processoId, array $accountIds): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds), fn ($v) => $v > 0));
        $cli = (int) $clienteId > 0 ? (int) $clienteId : null;
        $pro = (int) $processoId > 0 ? (int) $processoId : null;
        if (!$accountIds) throw new \InvalidArgumentException('Conta inválida.');
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $pdo = Database::getConnection();
        if ($cli) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE id = ? AND deleted_at IS NULL AND account_id IN ($in)");
            $st->execute(array_merge([$cli], $accountIds));
            if (!(int) $st->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado.');
        }
        if ($pro) {
            $st = $pdo->prepare("SELECT cliente_id FROM processos WHERE id = ? AND deleted_at IS NULL AND account_id IN ($in)");
            $st->execute(array_merge([$pro], $accountIds));
            $linha = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$linha) throw new \InvalidArgumentException('Processo não encontrado.');
            if ($cli && (int) ($linha['cliente_id'] ?? 0) !== $cli) {
                throw new \InvalidArgumentException('O processo escolhido não é deste cliente.');
            }
            if (!$cli && !empty($linha['cliente_id'])) $cli = (int) $linha['cliente_id'];
        }
        return [$cli, $pro];
    }

    /**
     * Cria o parcelamento e as N parcelas.
     *
     * $d: tipo (receita|despesa), descricao, codigo?, cliente_id?, processo_id?,
     *     quantidade, primeiro_vencimento (Y-m-d), e valor_total OU valor_parcela,
     *     origem? ('manual'), origem_id?.
     * @return array{parcelamento_id:int, parcelas:int[]}
     */
    public static function criar(array $d, int $accountId, array $accountIds, ?int $userId = null): array
    {
        $tipo = ($d['tipo'] ?? '') === 'despesa' ? 'despesa' : 'receita';
        $descricao = trim((string) ($d['descricao'] ?? ''));
        if ($descricao === '') throw new \InvalidArgumentException('Informe a descrição.');
        $descricao = mb_substr($descricao, 0, 150);
        $qtd = (int) ($d['quantidade'] ?? 1);
        $datas = self::vencimentos((string) ($d['primeiro_vencimento'] ?? ''), $qtd);
        if (isset($d['valor_parcela']) && $d['valor_parcela'] !== '' && $d['valor_parcela'] !== null) {
            $vp = self::numero($d['valor_parcela']);
            if ($vp <= 0) throw new \InvalidArgumentException('O valor precisa ser maior que zero.');
            $valores = array_fill(0, $qtd, $vp);
        } else {
            $valores = self::dividir(self::numero($d['valor_total'] ?? 0), $qtd);
        }
        [$cli, $pro] = self::validarVinculos($d['cliente_id'] ?? null, $d['processo_id'] ?? null, $accountIds);
        $origem = preg_replace('/[^a-z_]/', '', (string) ($d['origem'] ?? 'manual')) ?: 'manual';
        $codigo = isset($d['codigo']) && trim((string) $d['codigo']) !== '' ? mb_substr(trim((string) $d['codigo']), 0, 64) : null;

        $pdo = Database::getConnection();
        $dono = !$pdo->inTransaction();
        if ($dono) $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO fin_parcelamentos
                    (account_id, tipo, descricao, cliente_id, processo_id, quantidade, valor_total, primeiro_vencimento, origem, origem_id, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$accountId, $tipo, $descricao, $cli, $pro, $qtd, round(array_sum($valores), 2), $datas[0],
                           $origem, isset($d['origem_id']) ? (int) $d['origem_id'] : null, $userId]);
            $parcId = (int) $pdo->lastInsertId();
            $ids = [];
            foreach ($datas as $i => $venc) {
                $ids[] = self::inserirParcela($pdo, $accountId, $parcId, $tipo, $descricao, $codigo, $cli, $pro, $i + 1, $qtd, $valores[$i], $venc);
            }
            if ($dono) $pdo->commit();
        } catch (\Throwable $e) {
            if ($dono && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['parcelamento_id' => $parcId, 'parcelas' => $ids];
    }

    /** Uma parcela (um lançamento do Financeiro). Uso interno e do T3. */
    public static function inserirParcela(\PDO $pdo, int $accountId, int $parcId, string $tipo, string $descricao, ?string $codigo,
                                          ?int $cli, ?int $pro, int $numero, int $total, float $valor, string $venc): int
    {
        $nome = $total > 1 ? sprintf('%s (%d/%d)', $descricao, $numero, $total) : $descricao;
        $pdo->prepare("INSERT INTO dre_accounts
                (account_id, codigo, nome, tipo, valor_fixo, ativo, recorrencia, data_referencia,
                 cliente_id, processo_id, vencimento, parcelamento_id, parcela_numero, parcela_total)
                VALUES (?,?,?,?,?,1,'variavel',?,?,?,?,?,?,?)")
            ->execute([$accountId, $codigo, mb_substr($nome, 0, 191), $tipo, round($valor, 2), $venc,
                       $cli, $pro, $venc, $parcId, $numero, $total]);
        return (int) $pdo->lastInsertId();
    }

    /** O título (lançamento com vencimento) da conta, ou null. */
    public static function titulo(int $id, array $accountIds): ?array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds), fn ($v) => $v > 0));
        if (!$accountIds) return null;
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT * FROM dre_accounts WHERE id = ? AND ativo = 1 AND vencimento IS NOT NULL AND account_id IN ($in) LIMIT 1"
        );
        $st->execute(array_merge([$id], $accountIds));
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Baixa: marca pago/recebido na data (padrão: hoje em Brasília), com a
     * observação (atraso, acordo...) e, se houver, juros ou multa (migration 181).
     * O juro vira um lançamento próprio, do mesmo tipo da parcela, na data da baixa,
     * com o mesmo cliente e processo e juros_de_id = parcela: o DRE soma e mostra a origem.
     */
    public static function baixar(int $id, array $accountIds, ?string $data = null, ?int $userId = null,
                                  mixed $juros = null, ?string $obs = null): bool
    {
        $t = self::titulo($id, $accountIds);
        if (!$t) throw new \InvalidArgumentException('Lançamento não encontrado.');
        if ($t['pago_em'] !== null) throw new \InvalidArgumentException('Este lançamento já foi baixado.');
        $data = $data ?: self::hoje();
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$d || $d->format('Y-m-d') !== $data) throw new \InvalidArgumentException('Data da baixa inválida.');
        $vJuros = ($juros === null || $juros === '') ? 0.0 : self::numero($juros);
        if ($vJuros < 0) throw new \InvalidArgumentException('Juros não pode ser negativo.');
        $obs = $obs !== null ? trim(mb_substr($obs, 0, 500)) : null;
        $obs = $obs === '' ? null : $obs;

        $pdo = Database::getConnection();
        $dono = !$pdo->inTransaction();
        if ($dono) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('UPDATE dre_accounts SET pago_em = ?, pago_por = ?, baixa_obs = ? WHERE id = ? AND pago_em IS NULL');
            $st->execute([$data, $userId, $obs, $id]);
            $ok = $st->rowCount() > 0;
            if ($ok && $vJuros > 0) {
                $nome = mb_substr('Juros/multa: ' . $t['nome'], 0, 191);
                $pdo->prepare("INSERT INTO dre_accounts
                        (account_id, codigo, nome, tipo, valor_fixo, ativo, recorrencia, data_referencia,
                         cliente_id, processo_id, juros_de_id, baixa_obs)
                        VALUES (?,?,?,?,?,1,'variavel',?,?,?,?,?)")
                    ->execute([(int) $t['account_id'], $t['codigo'], $nome, $t['tipo'], $vJuros, $data,
                               $t['cliente_id'], $t['processo_id'], $id, $obs]);
            }
            if ($dono) $pdo->commit();
        } catch (\Throwable $e) {
            if ($dono && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $ok;
    }

    /** Desfaz a baixa: volta a ser pendente, e o juro lançado na baixa sai do DRE (ativo = 0). */
    public static function desfazerBaixa(int $id, array $accountIds): bool
    {
        $t = self::titulo($id, $accountIds);
        if (!$t) throw new \InvalidArgumentException('Lançamento não encontrado.');
        $pdo = Database::getConnection();
        $st = $pdo->prepare('UPDATE dre_accounts SET pago_em = NULL, pago_por = NULL, baixa_obs = NULL WHERE id = ?');
        $st->execute([$id]);
        $pdo->prepare('UPDATE dre_accounts SET ativo = 0 WHERE juros_de_id = ? AND account_id = ?')->execute([$id, (int) $t['account_id']]);
        return $st->rowCount() > 0;
    }

    /** Edita valor e vencimento de uma parcela pendente (baixada não muda: desfaça a baixa antes). */
    public static function editarParcela(int $id, array $accountIds, mixed $valor, ?string $vencimento): bool
    {
        $t = self::titulo($id, $accountIds);
        if (!$t) throw new \InvalidArgumentException('Lançamento não encontrado.');
        if ($t['pago_em'] !== null) throw new \InvalidArgumentException('Parcela já baixada não muda. Desfaça a baixa antes de editar.');
        $v = self::numero($valor);
        if ($v <= 0) throw new \InvalidArgumentException('O valor precisa ser maior que zero.');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $vencimento);
        if (!$d || $d->format('Y-m-d') !== $vencimento) throw new \InvalidArgumentException('Vencimento inválido.');
        $st = Database::getConnection()->prepare(
            'UPDATE dre_accounts SET valor_fixo = ?, vencimento = ?, data_referencia = ? WHERE id = ? AND pago_em IS NULL'
        );
        $st->execute([$v, $vencimento, $vencimento, $id]);
        return true;
    }

    /**
     * Títulos (a receber e a pagar) com totais, para a visão por cliente e por processo.
     * Filtros: cliente_id, processo_id, tipo, situacao (pendente|pago).
     * @return array{itens:array, totais:array, por_cliente:array, por_processo:array}
     */
    public static function visao(array $accountIds, array $f = []): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds), fn ($v) => $v > 0));
        $vazio = ['itens' => [], 'totais' => self::totais([]), 'por_cliente' => [], 'por_processo' => []];
        if (!$accountIds) return $vazio;
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $where = ["d.ativo = 1", "d.vencimento IS NOT NULL", "d.account_id IN ($in)"];
        $p = $accountIds;
        if (!empty($f['cliente_id']))  { $where[] = 'd.cliente_id = ?';  $p[] = (int) $f['cliente_id']; }
        if (!empty($f['processo_id'])) { $where[] = 'd.processo_id = ?'; $p[] = (int) $f['processo_id']; }
        if (in_array($f['tipo'] ?? '', ['receita', 'despesa'], true)) { $where[] = 'd.tipo = ?'; $p[] = $f['tipo']; }
        if (($f['situacao'] ?? '') === 'pendente') $where[] = 'd.pago_em IS NULL';
        if (($f['situacao'] ?? '') === 'pago')     $where[] = 'd.pago_em IS NOT NULL';
        $st = Database::getConnection()->prepare(
            "SELECT d.id, d.nome, d.tipo, d.valor_fixo, d.vencimento, d.pago_em, d.cliente_id, d.processo_id,
                    d.parcelamento_id, d.parcela_numero, d.parcela_total, d.baixa_obs,
                    (SELECT COALESCE(SUM(j.valor_fixo), 0) FROM dre_accounts j WHERE j.juros_de_id = d.id AND j.ativo = 1) AS juros,
                    c.nome AS cliente_nome, p.numero AS processo_numero, p.cliente_nome AS processo_cliente_nome
               FROM dre_accounts d
          LEFT JOIN clientes  c ON c.id = d.cliente_id
          LEFT JOIN processos p ON p.id = d.processo_id
              WHERE " . implode(' AND ', $where) . "
           ORDER BY d.vencimento, d.id
              LIMIT 2000"
        );
        $st->execute($p);
        $itens = $st->fetchAll(\PDO::FETCH_ASSOC);
        $hoje = self::hoje();
        foreach ($itens as &$it) {
            $it['valor_fixo'] = (float) $it['valor_fixo'];
            $it['juros'] = (float) $it['juros'];
            $it['situacao'] = $it['pago_em'] !== null ? 'pago' : ($it['vencimento'] < $hoje ? 'atrasado' : 'pendente');
            // Dias de atraso: na baixa, da data do vencimento à da baixa; pendente, até hoje.
            $fim = $it['pago_em'] ?? $hoje;
            $it['dias_atraso'] = max(0, (int) ((new \DateTimeImmutable($it['vencimento']))->diff(new \DateTimeImmutable($fim))->format('%r%a')));
        }
        unset($it);
        $agrupa = function (string $chave, string $rotulo) use ($itens): array {
            $g = [];
            foreach ($itens as $it) {
                if (empty($it[$chave])) continue;
                $k = (int) $it[$chave];
                $g[$k] ??= ['id' => $k, 'nome' => (string) ($it[$rotulo] ?? ''), 'itens' => []];
                $g[$k]['itens'][] = $it;
            }
            return array_values(array_map(fn ($x) => ['id' => $x['id'], 'nome' => $x['nome'], 'totais' => self::totais($x['itens'])], $g));
        };
        return [
            'itens'        => $itens,
            'totais'       => self::totais($itens),
            'por_cliente'  => $agrupa('cliente_id', 'cliente_nome'),
            'por_processo' => $agrupa('processo_id', 'processo_numero'),
        ];
    }

    /**
     * Projeção futura (pedido de 10/11/2026): por tipo (receita = a receber,
     * despesa = a pagar), sobre os títulos pendentes:
     *   atrasado      vencimento antes de hoje
     *   mes_atual     vence de hoje até o fim deste mês
     *   proximo_mes   vence no mês que vem
     *   saldo_futuro  tudo que vence de hoje em diante, sem diluir em meses
     *   total_aberto  atrasado + saldo_futuro
     *   baixado_mes   baixado neste mês (pela data da baixa)
     * e `meses`: o previsto e o baixado de cada um dos próximos N meses.
     * "Hoje" é o de Brasília. Valores em reais, somados em centavos.
     */
    public static function projecao(array $accountIds, int $meses = 12): array
    {
        $meses = max(1, min(36, $meses));
        $hoje = self::hoje();
        $ini = new \DateTimeImmutable(substr($hoje, 0, 7) . '-01');
        $mesAtual = $ini->format('Y-m');
        $proximo = $ini->modify('+1 month')->format('Y-m');
        $zero = ['atrasado' => 0, 'mes_atual' => 0, 'proximo_mes' => 0, 'saldo_futuro' => 0, 'total_aberto' => 0, 'baixado_mes' => 0];
        $c = ['receita' => $zero, 'despesa' => $zero];
        $m = [];
        for ($i = 0; $i < $meses; $i++) $m[$ini->modify("+$i month")->format('Y-m')] = ['receita' => 0, 'despesa' => 0, 'recebido' => 0, 'pago' => 0];
        $accountIds = array_values(array_filter(array_map('intval', $accountIds), fn ($v) => $v > 0));
        if ($accountIds) {
            $in = implode(',', array_fill(0, count($accountIds), '?'));
            $st = Database::getConnection()->prepare(
                "SELECT tipo, valor_fixo, vencimento, pago_em FROM dre_accounts
                  WHERE ativo = 1 AND vencimento IS NOT NULL AND account_id IN ($in)"
            );
            $st->execute($accountIds);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $l) {
                $t = $l['tipo'] === 'despesa' ? 'despesa' : 'receita';
                $v = (int) round(((float) $l['valor_fixo']) * 100);
                if ($l['pago_em'] !== null) {
                    $mp = substr($l['pago_em'], 0, 7);
                    if ($mp === $mesAtual) $c[$t]['baixado_mes'] += $v;
                    if (isset($m[$mp])) $m[$mp][$t === 'receita' ? 'recebido' : 'pago'] += $v;
                    continue;
                }
                $mv = substr($l['vencimento'], 0, 7);
                if ($l['vencimento'] < $hoje) { $c[$t]['atrasado'] += $v; continue; }
                $c[$t]['saldo_futuro'] += $v;
                if ($mv === $mesAtual) $c[$t]['mes_atual'] += $v;
                if ($mv === $proximo) $c[$t]['proximo_mes'] += $v;
                if (isset($m[$mv])) $m[$mv][$t] += $v;
            }
        }
        foreach ($c as $t => $x) {
            $x['total_aberto'] = $x['atrasado'] + $x['saldo_futuro'];
            $c[$t] = array_map(fn ($cent) => $cent / 100, $x);
        }
        foreach ($m as $k => $x) $m[$k] = array_map(fn ($cent) => $cent / 100, $x);
        return ['hoje' => $hoje, 'mes_atual' => $mesAtual, 'proximo_mes' => $proximo, 'receita' => $c['receita'], 'despesa' => $c['despesa'], 'meses' => $m];
    }

    /** Totais em centavos para não acumular erro: total, pago, pendente, separados por tipo. */
    public static function totais(array $itens): array
    {
        $c = ['receita' => ['total' => 0, 'pago' => 0, 'juros' => 0], 'despesa' => ['total' => 0, 'pago' => 0, 'juros' => 0]];
        foreach ($itens as $it) {
            $t = $it['tipo'] === 'despesa' ? 'despesa' : 'receita';
            $v = (int) round(((float) $it['valor_fixo']) * 100);
            $c[$t]['total'] += $v;
            if ($it['pago_em'] !== null) $c[$t]['pago'] += $v;
            $c[$t]['juros'] += (int) round(((float) ($it['juros'] ?? 0)) * 100);
        }
        $out = [];
        foreach ($c as $t => $x) {
            // total, pago e pendente são do principal; juros (lançado na baixa) vem à parte.
            $out[$t] = ['total' => $x['total'] / 100, 'pago' => $x['pago'] / 100, 'pendente' => ($x['total'] - $x['pago']) / 100,
                        'juros' => $x['juros'] / 100];
        }
        return $out;
    }
}
