<?php
namespace App\Processos;

use App\Core\Database;
use App\Financas\Parcelamento;

/**
 * Valores do processo, resultado e projeção (T3, 10/11/2026, migration 180).
 *
 * O processo guarda o valor da causa, o valor estimado (projetado) e o resultado
 * (tipo, valor, à vista ou parcelado, parcelas e primeiro vencimento). Registrar
 * o resultado gera as receitas previstas no Financeiro, sem recadastrar, pelo
 * mecanismo do T2 (App\Financas\Parcelamento): um fin_parcelamentos com
 * origem 'processo_resultado' e origem_id = processo, e uma parcela por mês.
 *
 * Editar o resultado nunca duplica: o parcelamento do processo é um só, e só as
 * parcelas PENDENTES mudam (valor, data, quantidade). Parcela baixada nunca é
 * alterada nem removida: o restante (resultado menos o já recebido) é dividido
 * entre as pendentes. Resultado tirado ou improcedente desativa as pendentes
 * (ativo = 0, nada é apagado).
 *
 * Os lançamentos ficam na conta DONA do processo, com o cliente do processo.
 */
class ValoresDoProcesso
{
    public const TIPOS = [
        'acordo'       => 'Acordo',
        'sentenca'     => 'Sentença ou condenação',
        'execucao'     => 'Execução',
        'improcedente' => 'Improcedente',
        'outro'        => 'Outro',
    ];
    public const ORIGEM = 'processo_resultado';

    /** Os valores gravados do processo e o resumo do que está no Financeiro. */
    public static function doProcesso(int $processoId): ?array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id, account_id, numero, cliente_id, valor_causa, valor_estimado, valor_resultado, resultado_tipo,
                                    resultado_forma, resultado_parcelas, resultado_primeiro_vencimento, resultado_em
                               FROM processos WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$processoId]);
        $p = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$p) return null;
        foreach (['valor_causa', 'valor_estimado', 'valor_resultado'] as $k) $p[$k] = $p[$k] === null ? null : (float) $p[$k];
        $p['financeiro'] = self::resumoFinanceiro($processoId, (int) $p['account_id']);
        return $p;
    }

    /** Parcelas do resultado no Financeiro: quantas, total, recebido, pendente. */
    public static function resumoFinanceiro(int $processoId, int $accountId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT d.id, d.valor_fixo, d.vencimento, d.pago_em, d.parcela_numero
               FROM dre_accounts d JOIN fin_parcelamentos f ON f.id = d.parcelamento_id
              WHERE f.account_id = ? AND f.origem = ? AND f.origem_id = ? AND d.ativo = 1
           ORDER BY d.vencimento, d.id"
        );
        $st->execute([$accountId, self::ORIGEM, $processoId]);
        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        $total = 0; $pago = 0;
        foreach ($linhas as $l) {
            $c = (int) round(((float) $l['valor_fixo']) * 100);
            $total += $c;
            if ($l['pago_em'] !== null) $pago += $c;
        }
        return ['parcelas' => count($linhas), 'total' => $total / 100, 'recebido' => $pago / 100,
                'pendente' => ($total - $pago) / 100, 'itens' => $linhas];
    }

    /**
     * Confere e normaliza o que veio da tela. Lança InvalidArgumentException.
     * @return array<string,mixed> as colunas para gravar
     */
    public static function normalizar(array $d): array
    {
        $dinheiro = function ($v, string $rotulo): ?float {
            if ($v === null || $v === '') return null;
            $n = Parcelamento::numero($v);
            if ($n < 0) throw new \InvalidArgumentException("$rotulo não pode ser negativo.");
            return $n;
        };
        $out = [
            'valor_causa'    => $dinheiro($d['valor_causa'] ?? null, 'Valor da causa'),
            'valor_estimado' => $dinheiro($d['valor_estimado'] ?? null, 'Valor estimado'),
            'resultado_tipo' => null, 'valor_resultado' => null, 'resultado_forma' => null,
            'resultado_parcelas' => null, 'resultado_primeiro_vencimento' => null,
        ];
        $tipo = trim((string) ($d['resultado_tipo'] ?? ''));
        if ($tipo === '') return $out;
        if (!isset(self::TIPOS[$tipo])) throw new \InvalidArgumentException('Tipo de resultado inválido.');
        $out['resultado_tipo'] = $tipo;
        if ($tipo === 'improcedente') { $out['valor_resultado'] = 0.0; return $out; }
        $valor = $dinheiro($d['valor_resultado'] ?? null, 'Valor do resultado');
        if ($valor === null || $valor <= 0) throw new \InvalidArgumentException('Informe o valor do resultado.');
        $out['valor_resultado'] = $valor;
        $forma = ($d['resultado_forma'] ?? 'avista') === 'parcelado' ? 'parcelado' : 'avista';
        $out['resultado_forma'] = $forma;
        $qtd = $forma === 'parcelado' ? (int) ($d['resultado_parcelas'] ?? 0) : 1;
        if ($forma === 'parcelado' && ($qtd < 2 || $qtd > Parcelamento::MAX_PARCELAS)) {
            throw new \InvalidArgumentException('Parcelado precisa de 2 a ' . Parcelamento::MAX_PARCELAS . ' parcelas.');
        }
        $out['resultado_parcelas'] = $qtd;
        $primeiro = (string) ($d['resultado_primeiro_vencimento'] ?? '');
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $primeiro);
        if (!$dt || $dt->format('Y-m-d') !== $primeiro) throw new \InvalidArgumentException('Informe o vencimento do primeiro recebimento.');
        $out['resultado_primeiro_vencimento'] = $primeiro;
        Parcelamento::dividir($valor, $qtd); // valor pequeno demais para as parcelas é recusado aqui
        return $out;
    }

    /**
     * Grava os valores e sincroniza o Financeiro. O chamador já conferiu o acesso
     * ao processo (TenantGuard::assertProcessoAcessivel).
     * @return array o mesmo de doProcesso()
     */
    public static function salvar(int $processoId, array $dados, ?int $userId = null): array
    {
        $novo = self::normalizar($dados);
        $pdo = Database::getConnection();
        $antes = self::doProcesso($processoId);
        if (!$antes) throw new \InvalidArgumentException('Processo não encontrado.');

        $dono = !$pdo->inTransaction();
        if ($dono) $pdo->beginTransaction();
        try {
            $resultadoEm = $novo['resultado_tipo'] === null ? null
                : ($antes['resultado_tipo'] !== null && $antes['resultado_em'] ? $antes['resultado_em'] : Parcelamento::hoje());
            $pdo->prepare('UPDATE processos SET valor_causa = ?, valor_estimado = ?, valor_resultado = ?, resultado_tipo = ?,
                                  resultado_forma = ?, resultado_parcelas = ?, resultado_primeiro_vencimento = ?, resultado_em = ?,
                                  updated_at = NOW() WHERE id = ?')
                ->execute([$novo['valor_causa'], $novo['valor_estimado'], $novo['valor_resultado'], $novo['resultado_tipo'],
                           $novo['resultado_forma'], $novo['resultado_parcelas'], $novo['resultado_primeiro_vencimento'], $resultadoEm, $processoId]);
            self::sincronizar($pdo, $processoId, $userId);
            if ($dono) $pdo->commit();
        } catch (\Throwable $e) {
            if ($dono && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $mudou = [];
        $fmt = fn ($v) => $v === null ? 'vazio' : 'R$ ' . number_format((float) $v, 2, ',', '.');
        foreach (['valor_causa' => 'valor da causa', 'valor_estimado' => 'valor estimado', 'valor_resultado' => 'resultado'] as $k => $rot) {
            if ((string) ($antes[$k] ?? '') !== (string) ($novo[$k] ?? '')) $mudou[] = "$rot: {$fmt($antes[$k])} → {$fmt($novo[$k])}";
        }
        if (($antes['resultado_tipo'] ?? null) !== $novo['resultado_tipo']) {
            $mudou[] = 'tipo do resultado: ' . (self::TIPOS[$antes['resultado_tipo']] ?? 'nenhum') . ' → ' . (self::TIPOS[$novo['resultado_tipo']] ?? 'nenhum');
        }
        if ($mudou) ProcessoAudit::log($processoId, 'Valores do processo atualizados', implode('; ', $mudou));
        return self::doProcesso($processoId);
    }

    /** Põe o Financeiro de acordo com o resultado gravado no processo. */
    public static function sincronizar(\PDO $pdo, int $processoId, ?int $userId = null): void
    {
        $st = $pdo->prepare('SELECT * FROM processos WHERE id = ?');
        $st->execute([$processoId]);
        $p = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$p) return;
        $conta = (int) $p['account_id'];
        $cliente = !empty($p['cliente_id']) ? (int) $p['cliente_id'] : null;
        $tipo = $p['resultado_tipo'];
        $valor = ($tipo === null || $tipo === 'improcedente') ? 0.0 : round((float) $p['valor_resultado'], 2);
        $descricao = 'Resultado do processo ' . ($p['numero'] ?: ('#' . $processoId)) . ($tipo ? ' (' . self::TIPOS[$tipo] . ')' : '');

        $st = $pdo->prepare('SELECT * FROM fin_parcelamentos WHERE account_id = ? AND origem = ? AND origem_id = ? ORDER BY id LIMIT 1');
        $st->execute([$conta, self::ORIGEM, $processoId]);
        $parc = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        $linhas = [];
        if ($parc) {
            $st = $pdo->prepare('SELECT * FROM dre_accounts WHERE parcelamento_id = ? AND ativo = 1 ORDER BY parcela_numero, id');
            $st->execute([(int) $parc['id']]);
            $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        }
        $pagas = array_values(array_filter($linhas, fn ($l) => $l['pago_em'] !== null));
        $pendentes = array_values(array_filter($linhas, fn ($l) => $l['pago_em'] === null));
        $desativar = function (array $ids) use ($pdo): void {
            if (!$ids) return;
            $pdo->prepare('UPDATE dre_accounts SET ativo = 0 WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND pago_em IS NULL')
                ->execute(array_map('intval', $ids));
        };
        $somaPagas = array_sum(array_map(fn ($l) => (int) round(((float) $l['valor_fixo']) * 100), $pagas)) / 100;

        if ($valor <= 0) {
            $desativar(array_column($pendentes, 'id'));
            if ($parc) $pdo->prepare('UPDATE fin_parcelamentos SET quantidade = ?, valor_total = ?, descricao = ? WHERE id = ?')
                           ->execute([count($pagas), $somaPagas, mb_substr($descricao, 0, 191), (int) $parc['id']]);
            return;
        }

        $qtd = $p['resultado_forma'] === 'parcelado' ? max(1, (int) $p['resultado_parcelas']) : 1;
        $primeiro = (string) $p['resultado_primeiro_vencimento'];
        if (!$parc) {
            Parcelamento::criar([
                // cliente_id vazio: validarVinculos tira o cliente do próprio processo (pode ser de filial).
                'tipo' => 'receita', 'descricao' => $descricao, 'cliente_id' => null, 'processo_id' => $processoId,
                'quantidade' => $qtd, 'primeiro_vencimento' => $primeiro, 'valor_total' => $valor,
                'origem' => self::ORIGEM, 'origem_id' => $processoId,
            ], $conta, [$conta], $userId);
            return;
        }

        $restante = round($valor - $somaPagas, 2);
        $nPagas = count($pagas);
        if ($restante <= 0) {
            $desativar(array_column($pendentes, 'id'));
            $pdo->prepare('UPDATE fin_parcelamentos SET quantidade = ?, valor_total = ?, primeiro_vencimento = ?, descricao = ? WHERE id = ?')
                ->execute([$nPagas, $valor, $primeiro, mb_substr($descricao, 0, 191), (int) $parc['id']]);
            return;
        }
        $nPend = max($qtd - $nPagas, 1);
        $total = $nPagas + $nPend;
        $datas = array_slice(Parcelamento::vencimentos($primeiro, $total), $nPagas);
        $valores = Parcelamento::dividir($restante, $nPend);
        for ($i = 0; $i < $nPend; $i++) {
            $numero = $nPagas + $i + 1;
            $nome = $total > 1 ? sprintf('%s (%d/%d)', $descricao, $numero, $total) : $descricao;
            if (isset($pendentes[$i])) {
                $pdo->prepare('UPDATE dre_accounts SET valor_fixo = ?, vencimento = ?, data_referencia = ?, nome = ?, parcela_numero = ?,
                                      parcela_total = ?, cliente_id = ?, processo_id = ? WHERE id = ? AND pago_em IS NULL')
                    ->execute([$valores[$i], $datas[$i], $datas[$i], mb_substr($nome, 0, 191), $numero, $total, $cliente, $processoId,
                               (int) $pendentes[$i]['id']]);
            } else {
                Parcelamento::inserirParcela($pdo, $conta, (int) $parc['id'], 'receita', $descricao, null, $cliente, $processoId,
                                             $numero, $total, $valores[$i], $datas[$i]);
            }
        }
        $desativar(array_column(array_slice($pendentes, $nPend), 'id'));
        $pdo->prepare('UPDATE fin_parcelamentos SET quantidade = ?, valor_total = ?, primeiro_vencimento = ?, descricao = ?, cliente_id = ? WHERE id = ?')
            ->execute([$total, $valor, $primeiro, mb_substr($descricao, 0, 191), $cliente, (int) $parc['id']]);
    }

    /**
     * Carteira: potencial (soma do estimado), em aberto (sem resultado) e o
     * comparativo projetado x obtido dos que têm resultado (improcedente = 0).
     * Filtros: processo_ids (int[]).
     */
    public static function carteira(array $accountIds, array $f = []): array
    {
        [$where, $p] = self::filtro($accountIds, $f, 'p');
        $st = Database::getConnection()->prepare(
            "SELECT
                COUNT(*) AS processos,
                SUM(p.valor_estimado IS NOT NULL) AS com_estimativa,
                COALESCE(SUM(ROUND(p.valor_estimado * 100)), 0) AS potencial,
                SUM(p.resultado_tipo IS NULL AND p.valor_estimado IS NOT NULL) AS abertos,
                COALESCE(SUM(CASE WHEN p.resultado_tipo IS NULL THEN ROUND(p.valor_estimado * 100) END), 0) AS em_aberto,
                SUM(p.resultado_tipo IS NOT NULL) AS encerrados,
                COALESCE(SUM(CASE WHEN p.resultado_tipo IS NOT NULL THEN ROUND(COALESCE(p.valor_estimado, 0) * 100) END), 0) AS projetado,
                COALESCE(SUM(CASE WHEN p.resultado_tipo IS NOT NULL THEN ROUND(COALESCE(p.valor_resultado, 0) * 100) END), 0) AS obtido,
                SUM(p.resultado_tipo = 'improcedente') AS improcedentes,
                COALESCE(SUM(ROUND(p.valor_causa * 100)), 0) AS causa
               FROM processos p WHERE $where"
        );
        $st->execute($p);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        $cent = fn ($k) => ((int) ($r[$k] ?? 0)) / 100;
        $projetado = $cent('projetado');
        $obtido = $cent('obtido');
        return [
            'processos' => (int) ($r['processos'] ?? 0), 'com_estimativa' => (int) ($r['com_estimativa'] ?? 0),
            'valor_causa' => $cent('causa'), 'potencial' => $cent('potencial'),
            'abertos' => (int) ($r['abertos'] ?? 0), 'em_aberto' => $cent('em_aberto'),
            'encerrados' => (int) ($r['encerrados'] ?? 0), 'improcedentes' => (int) ($r['improcedentes'] ?? 0),
            'projetado' => $projetado, 'obtido' => $obtido, 'diferenca' => round($obtido - $projetado, 2),
            'aproveitamento' => $projetado > 0 ? round($obtido / $projetado * 100, 1) : null,
        ];
    }

    /**
     * Projeção mensal de recebimentos: por mês, o previsto (receitas pendentes
     * pelo vencimento) e o recebido (baixadas pela data da baixa). O pendente já
     * vencido fica em "atrasado". Filtros: processo_ids, so_processos (só títulos
     * ligados a processo).
     */
    public static function projecaoMensal(array $accountIds, int $meses = 12, array $f = []): array
    {
        $meses = max(1, min(36, $meses));
        $accountIds = array_values(array_filter(array_map('intval', $accountIds), fn ($v) => $v > 0));
        $hoje = Parcelamento::hoje();
        $inicio = substr($hoje, 0, 7) . '-01';
        $out = ['atrasado' => 0.0, 'meses' => []];
        $d = new \DateTimeImmutable($inicio);
        for ($i = 0; $i < $meses; $i++) $out['meses'][$d->modify("+$i month")->format('Y-m')] = ['previsto' => 0, 'recebido' => 0];
        if (!$accountIds) { $out['atrasado'] = 0.0; return $out; }
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $where = "d.ativo = 1 AND d.tipo = 'receita' AND d.vencimento IS NOT NULL AND d.account_id IN ($in)";
        $p = $accountIds;
        if (!empty($f['processo_ids'])) {
            $ids = array_values(array_filter(array_map('intval', (array) $f['processo_ids'])));
            $where .= ' AND d.processo_id IN (' . implode(',', array_fill(0, max(1, count($ids)), '?')) . ')';
            $p = array_merge($p, $ids ?: [0]);
        } elseif (!empty($f['so_processos'])) {
            $where .= ' AND d.processo_id IS NOT NULL';
        }
        $st = Database::getConnection()->prepare("SELECT d.valor_fixo, d.vencimento, d.pago_em FROM dre_accounts d WHERE $where");
        $st->execute($p);
        $atrasado = 0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $l) {
            $c = (int) round(((float) $l['valor_fixo']) * 100);
            if ($l['pago_em'] !== null) {
                $m = substr($l['pago_em'], 0, 7);
                if (isset($out['meses'][$m])) $out['meses'][$m]['recebido'] += $c;
            } elseif ($l['vencimento'] < $hoje) {
                $atrasado += $c;
            } else {
                $m = substr($l['vencimento'], 0, 7);
                if (isset($out['meses'][$m])) $out['meses'][$m]['previsto'] += $c;
            }
        }
        foreach ($out['meses'] as $m => $v) $out['meses'][$m] = ['previsto' => $v['previsto'] / 100, 'recebido' => $v['recebido'] / 100];
        $out['atrasado'] = $atrasado / 100;
        return $out;
    }

    private static function filtro(array $accountIds, array $f, string $a): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds), fn ($v) => $v > 0)) ?: [0];
        $where = "$a.deleted_at IS NULL AND $a.account_id IN (" . implode(',', array_fill(0, count($accountIds), '?')) . ')';
        $p = $accountIds;
        if (!empty($f['processo_ids'])) {
            $ids = array_values(array_filter(array_map('intval', (array) $f['processo_ids']))) ?: [0];
            $where .= " AND $a.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
            $p = array_merge($p, $ids);
        }
        return [$where, $p];
    }
}
