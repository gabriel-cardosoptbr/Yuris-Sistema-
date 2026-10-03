<?php
namespace App\Financas;

use App\Core\Database;

/**
 * Reembolsos: alguém pagou uma conta da empresa do próprio bolso e a empresa
 * devolve, à vista ou parcelado (pedido da Inovaize, 03/10/2026).
 *
 * Tabelas `reembolsos` e `reembolso_parcelas` (migration 138). Regras:
 *
 *  - Toda leitura e escrita recebe a lista de contas acessíveis e filtra por
 *    ela, inclusive nas parcelas (que também guardam account_id). Reembolso de
 *    outra conta não existe para quem chama: devolve null ou false.
 *  - Valores em centavos dentro da classe e DECIMAL(12,2) no banco. A divisão
 *    em parcelas manda os centavos que sobram para as primeiras, e a soma das
 *    parcelas é sempre o total exato.
 *  - Vencimento mensal a partir do primeiro; dia 31 em mês curto cai no último
 *    dia do mês, sem pular para o seguinte.
 *  - A situação não é gravada, sai das parcelas (situacao()).
 *  - Com parcela já paga, valor e parcelamento ficam travados: mudar exigiria
 *    redistribuir dinheiro que já saiu. Desfaz o pagamento antes.
 */
final class Reembolso
{
    public const MAX_PARCELAS = 24;
    public const MAX_VALOR_CENTAVOS = 999999999; // R$ 9.999.999,99, o teto do DECIMAL(12,2) com folga

    /** "1.234,56", "1234,56", "1234.56" ou número => centavos; null se inválido. */
    public static function centavos($valor): ?int
    {
        if (is_int($valor) || is_float($valor)) return (int) round($valor * 100);
        $s = trim((string) $valor);
        $s = (string) preg_replace('/^R\$\s*/', '', $s);
        if ($s === '') return null;
        if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
        return (int) round(((float) $s) * 100);
    }

    /**
     * Divide o total (centavos) em $n parcelas iguais; os centavos que sobram
     * vão um para cada uma das primeiras. Soma = total, sempre.
     *
     * @return list<int>
     */
    public static function dividir(int $totalCentavos, int $n): array
    {
        $n = max(1, $n);
        $base = intdiv($totalCentavos, $n);
        $resto = $totalCentavos - $base * $n;
        $out = [];
        for ($i = 0; $i < $n; $i++) $out[] = $base + ($i < $resto ? 1 : 0);
        return $out;
    }

    /**
     * Vencimentos mensais a partir do primeiro (Y-m-d). O dia do primeiro é o
     * dia de todos; em mês sem esse dia, vale o último dia do mês.
     *
     * @return list<string>
     */
    public static function vencimentos(string $primeiro, int $n): array
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $primeiro);
        if (!$d || $d->format('Y-m-d') !== $primeiro) return [];
        $dia = (int) $d->format('d');
        $inicioMes = $d->modify('first day of this month');
        $out = [];
        for ($i = 0; $i < max(1, $n); $i++) {
            $mes = $inicioMes->modify("+$i month");
            $ultimo = (int) $mes->format('t');
            $out[] = $mes->format('Y-m-') . str_pad((string) min($dia, $ultimo), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /**
     * Situação pelas parcelas: 'pago' (todas pagas), 'atrasado' (alguma em
     * aberto com vencimento antes de hoje), 'pagando' (alguma paga, nenhuma
     * atrasada) ou 'pendente' (nenhuma paga, nenhuma atrasada).
     *
     * @param list<array{vencimento:string,pago_em:?string}> $parcelas
     */
    public static function situacao(array $parcelas, string $hoje): string
    {
        $pagas = 0; $atrasada = false;
        foreach ($parcelas as $p) {
            if (!empty($p['pago_em'])) { $pagas++; continue; }
            if ((string) $p['vencimento'] < $hoje) $atrasada = true;
        }
        if ($parcelas && $pagas === count($parcelas)) return 'pago';
        if ($atrasada) return 'atrasado';
        return $pagas > 0 ? 'pagando' : 'pendente';
    }

    /** Hoje no horário de Brasília (o banco e o PHP rodam em UTC). */
    public static function hoje(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    }

    /**
     * Valida e normaliza o que vem do formulário.
     *
     * @return array{0:?array,1:?string} [dados, erro]
     */
    public static function validar(array $in): array
    {
        $fav = trim((string) ($in['favorecido'] ?? ''));
        $desc = trim((string) ($in['descricao'] ?? ''));
        $obs = trim((string) ($in['observacao'] ?? ''));
        $data = (string) ($in['data_despesa'] ?? '');
        $prim = (string) ($in['primeiro_vencimento'] ?? '');
        $n = (int) ($in['parcelas'] ?? 1);
        $cent = self::centavos($in['valor_total'] ?? '');

        if ($fav === '') return [null, 'Informe para quem é o reembolso.'];
        if (mb_strlen($fav) > 150) return [null, 'O nome do favorecido passa de 150 caracteres.'];
        if ($desc === '') return [null, 'Descreva o que foi pago.'];
        if (mb_strlen($desc) > 255) return [null, 'A descrição passa de 255 caracteres.'];
        if (mb_strlen($obs) > 2000) return [null, 'A observação passa de 2.000 caracteres.'];
        if (!self::dataValida($data)) return [null, 'Informe a data da despesa.'];
        if ($cent === null || $cent <= 0) return [null, 'Informe um valor maior que zero.'];
        if ($cent > self::MAX_VALOR_CENTAVOS) return [null, 'Valor alto demais.'];
        if ($n < 1 || $n > self::MAX_PARCELAS) return [null, 'O parcelamento vai de 1 a ' . self::MAX_PARCELAS . ' vezes.'];
        if ($cent < $n) return [null, 'Cada parcela precisa ter pelo menos R$ 0,01.'];
        if (!self::dataValida($prim)) return [null, 'Informe o vencimento da primeira parcela.'];

        return [[
            'favorecido' => $fav, 'descricao' => $desc, 'observacao' => $obs === '' ? null : $obs,
            'data_despesa' => $data, 'valor_centavos' => $cent, 'parcelas' => $n, 'primeiro_vencimento' => $prim,
        ], null];
    }

    private static function dataValida(string $d): bool
    {
        $o = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $o !== false && $o->format('Y-m-d') === $d && $d >= '2000-01-01' && $d <= '2100-12-31';
    }

    /** @return array{0:string,1:array<string,int>} [IN (...), parâmetros] */
    private static function inContas(array $contas, string $pref = 'rc'): array
    {
        $contas = array_values(array_unique(array_map('intval', $contas))) ?: [0];
        $ph = []; $p = [];
        foreach ($contas as $i => $id) { $ph[] = ":{$pref}{$i}"; $p["{$pref}{$i}"] = $id; }
        return ['(' . implode(',', $ph) . ')', $p];
    }

    /**
     * Reembolsos das contas, com as parcelas, a situação e os totais.
     *
     * @return list<array<string,mixed>>
     */
    public static function listar(array $contas): array
    {
        $pdo = Database::getConnection();
        [$in, $p] = self::inContas($contas);
        $st = $pdo->prepare("SELECT r.id, r.account_id, r.favorecido, r.descricao, r.data_despesa, r.valor_total, r.observacao, r.created_at
                               FROM reembolsos r
                              WHERE r.account_id IN $in AND r.deleted_at IS NULL
                              ORDER BY r.data_despesa DESC, r.id DESC");
        $st->execute($p);
        $lista = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!$lista) return [];

        [$in2, $p2] = self::inContas($contas, 'pc');
        $ids = array_map(fn($r) => (int) $r['id'], $lista);
        $phIds = []; foreach ($ids as $i => $id) { $phIds[] = ":ri{$i}"; $p2["ri{$i}"] = $id; }
        $sp = $pdo->prepare("SELECT id, reembolso_id, numero, valor, vencimento, pago_em
                               FROM reembolso_parcelas
                              WHERE account_id IN $in2 AND reembolso_id IN (" . implode(',', $phIds) . ")
                              ORDER BY reembolso_id, numero");
        $sp->execute($p2);
        $porReemb = [];
        foreach ($sp->fetchAll(\PDO::FETCH_ASSOC) as $pa) $porReemb[(int) $pa['reembolso_id']][] = $pa;

        $hoje = self::hoje();
        return array_map(fn($r) => self::montar($r, $porReemb[(int) $r['id']] ?? [], $hoje), $lista);
    }

    /** Um reembolso das contas, ou null. */
    public static function buscar(int $id, array $contas): ?array
    {
        foreach (self::listarUm($id, $contas) as $r) return $r;
        return null;
    }

    /** @return list<array<string,mixed>> */
    private static function listarUm(int $id, array $contas): array
    {
        $pdo = Database::getConnection();
        [$in, $p] = self::inContas($contas);
        $st = $pdo->prepare("SELECT id, account_id, favorecido, descricao, data_despesa, valor_total, observacao, created_at
                               FROM reembolsos WHERE id = :id AND account_id IN $in AND deleted_at IS NULL");
        $st->execute(['id' => $id] + $p);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return [];
        $sp = $pdo->prepare("SELECT id, reembolso_id, numero, valor, vencimento, pago_em FROM reembolso_parcelas
                              WHERE reembolso_id = :id AND account_id = :acc ORDER BY numero");
        $sp->execute(['id' => $id, 'acc' => (int) $r['account_id']]);
        return [self::montar($r, $sp->fetchAll(\PDO::FETCH_ASSOC), self::hoje())];
    }

    private static function montar(array $r, array $parcelas, string $hoje): array
    {
        $pago = 0; $aberto = 0; $atrasado = 0; $pagas = 0;
        $ps = [];
        foreach ($parcelas as $pa) {
            $c = (int) round(((float) $pa['valor']) * 100);
            $paga = !empty($pa['pago_em']);
            if ($paga) { $pago += $c; $pagas++; }
            else { $aberto += $c; if ($pa['vencimento'] < $hoje) $atrasado += $c; }
            $ps[] = ['id' => (int) $pa['id'], 'numero' => (int) $pa['numero'], 'valor' => $c / 100,
                     'vencimento' => $pa['vencimento'], 'pago_em' => $pa['pago_em'] ?: null,
                     'atrasada' => !$paga && $pa['vencimento'] < $hoje];
        }
        $proxima = null;
        foreach ($ps as $pa) { if (!$pa['pago_em']) { $proxima = $pa['vencimento']; break; } }
        return [
            'id' => (int) $r['id'], 'favorecido' => $r['favorecido'], 'descricao' => $r['descricao'],
            'data_despesa' => $r['data_despesa'], 'valor_total' => round((float) $r['valor_total'], 2),
            'observacao' => $r['observacao'], 'parcelas' => $ps, 'qtd_parcelas' => count($ps), 'qtd_pagas' => $pagas,
            'valor_pago' => $pago / 100, 'valor_aberto' => $aberto / 100, 'valor_atrasado' => $atrasado / 100,
            'proximo_vencimento' => $proxima, 'situacao' => self::situacao($parcelas, $hoje),
        ];
    }

    /** Totais para a faixa de resumo. */
    public static function resumo(array $lista): array
    {
        $aberto = 0; $pago = 0; $atrasado = 0; $qAberto = 0;
        foreach ($lista as $r) {
            $aberto += (int) round($r['valor_aberto'] * 100);
            $pago += (int) round($r['valor_pago'] * 100);
            $atrasado += (int) round($r['valor_atrasado'] * 100);
            if ($r['situacao'] !== 'pago') $qAberto++;
        }
        return ['a_pagar' => $aberto / 100, 'pago' => $pago / 100, 'atrasado' => $atrasado / 100,
                'em_aberto' => $qAberto, 'total' => count($lista)];
    }

    /** Cria o reembolso e as parcelas numa transação. Devolve o id. */
    public static function criar(int $accountId, array $d, ?int $userId): int
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("INSERT INTO reembolsos (account_id, favorecido, descricao, data_despesa, valor_total, observacao, created_by)
                                 VALUES (:acc, :fav, :desc, :data, :valor, :obs, :uid)");
            $st->execute(['acc' => $accountId, 'fav' => $d['favorecido'], 'desc' => $d['descricao'], 'data' => $d['data_despesa'],
                          'valor' => self::decimal($d['valor_centavos']), 'obs' => $d['observacao'], 'uid' => $userId]);
            $id = (int) $pdo->lastInsertId();
            self::gerarParcelas($pdo, $id, $accountId, $d);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Atualiza. Sem parcela paga, valor e parcelamento podem mudar e as
     * parcelas são refeitas. Com parcela paga, só os dados descritivos.
     *
     * @return ?string erro, ou null se gravou
     */
    public static function atualizar(int $id, array $contas, array $d): ?string
    {
        $atual = self::buscar($id, $contas);
        if (!$atual) return 'Reembolso não encontrado.';
        $pdo = Database::getConnection();
        $acc = self::contaDe($pdo, $id);
        $mudaDinheiro = $d['valor_centavos'] !== (int) round($atual['valor_total'] * 100)
            || $d['parcelas'] !== $atual['qtd_parcelas']
            || $d['primeiro_vencimento'] !== ($atual['parcelas'][0]['vencimento'] ?? null);
        if ($mudaDinheiro && $atual['qtd_pagas'] > 0) {
            return 'Este reembolso já tem parcela paga, então valor e parcelamento não mudam. Desfaça os pagamentos antes de alterar.';
        }
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE reembolsos SET favorecido = :fav, descricao = :desc, data_despesa = :data, valor_total = :valor, observacao = :obs
                                  WHERE id = :id AND account_id = :acc AND deleted_at IS NULL");
            $st->execute(['fav' => $d['favorecido'], 'desc' => $d['descricao'], 'data' => $d['data_despesa'],
                          'valor' => self::decimal($d['valor_centavos']), 'obs' => $d['observacao'], 'id' => $id, 'acc' => $acc]);
            if ($mudaDinheiro) {
                $pdo->prepare("DELETE FROM reembolso_parcelas WHERE reembolso_id = :id AND account_id = :acc")->execute(['id' => $id, 'acc' => $acc]);
                self::gerarParcelas($pdo, $id, $acc, $d);
            }
            $pdo->commit();
            return null;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Exclusão lógica. */
    public static function excluir(int $id, array $contas): bool
    {
        $pdo = Database::getConnection();
        [$in, $p] = self::inContas($contas);
        $st = $pdo->prepare("UPDATE reembolsos SET deleted_at = NOW() WHERE id = :id AND account_id IN $in AND deleted_at IS NULL");
        $st->execute(['id' => $id] + $p);
        return $st->rowCount() > 0;
    }

    /**
     * Marca a parcela como paga na data dada, ou desfaz (data null). Devolve o
     * id do reembolso, ou null se a parcela não é das contas.
     */
    public static function marcarParcela(int $parcelaId, array $contas, ?string $pagoEm, ?int $userId): ?int
    {
        if ($pagoEm !== null && !self::dataValida($pagoEm)) return null;
        $pdo = Database::getConnection();
        [$in, $p] = self::inContas($contas);
        $st = $pdo->prepare("SELECT pa.reembolso_id FROM reembolso_parcelas pa
                               JOIN reembolsos r ON r.id = pa.reembolso_id AND r.account_id = pa.account_id AND r.deleted_at IS NULL
                              WHERE pa.id = :id AND pa.account_id IN $in");
        $st->execute(['id' => $parcelaId] + $p);
        $reembId = $st->fetchColumn();
        if ($reembId === false) return null;
        $up = $pdo->prepare("UPDATE reembolso_parcelas SET pago_em = :em, pago_por = :uid WHERE id = :id AND account_id IN $in");
        $up->execute(['em' => $pagoEm, 'uid' => $pagoEm === null ? null : $userId, 'id' => $parcelaId] + $p);
        return (int) $reembId;
    }

    /** Marca todas as parcelas em aberto como pagas na data dada. */
    public static function quitar(int $id, array $contas, string $pagoEm, ?int $userId): bool
    {
        if (!self::dataValida($pagoEm) || !self::buscar($id, $contas)) return false;
        $pdo = Database::getConnection();
        $acc = self::contaDe($pdo, $id);
        $pdo->prepare("UPDATE reembolso_parcelas SET pago_em = :em, pago_por = :uid WHERE reembolso_id = :id AND account_id = :acc AND pago_em IS NULL")
            ->execute(['em' => $pagoEm, 'uid' => $userId, 'id' => $id, 'acc' => $acc]);
        return true;
    }

    private static function contaDe(\PDO $pdo, int $id): int
    {
        $st = $pdo->prepare("SELECT account_id FROM reembolsos WHERE id = :id");
        $st->execute(['id' => $id]);
        return (int) $st->fetchColumn();
    }

    private static function gerarParcelas(\PDO $pdo, int $id, int $accountId, array $d): void
    {
        $valores = self::dividir($d['valor_centavos'], $d['parcelas']);
        $vencs = self::vencimentos($d['primeiro_vencimento'], $d['parcelas']);
        $ins = $pdo->prepare("INSERT INTO reembolso_parcelas (reembolso_id, account_id, numero, valor, vencimento) VALUES (:r, :acc, :n, :v, :venc)");
        foreach ($valores as $i => $c) {
            $ins->execute(['r' => $id, 'acc' => $accountId, 'n' => $i + 1, 'v' => self::decimal($c), 'venc' => $vencs[$i]]);
        }
    }

    private static function decimal(int $centavos): string
    {
        return intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}
