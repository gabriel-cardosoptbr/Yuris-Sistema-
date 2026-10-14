<?php
namespace App\Projetos;

use App\Core\Database;

/**
 * Contas de um projeto: cada cliente/escritório/assinante-empresa que usa o
 * projeto (os escritórios do Yuris, as contas da plataforma Fleetiflow). Pedido
 * da Inovaize, 14/10/2026: "tem 10 contas cadastradas, quanto cada conta está
 * consumindo dentro do projeto". Tabela `projeto_contas` (migration 147).
 *
 * Regras:
 *
 *  - Receita da conta (`receita_mensal`, NULL = a preencher) entra na receita do
 *    projeto só se a conta paga: situação `ativa` ou `inadimplente`.
 *  - Custo atribuído à conta = custo compartilhado do projeto (itens de custo +
 *    rateio do servidor) dividido entre as contas que consomem (tudo menos
 *    `cancelada`) pelo `peso_consumo`, mais o `custo_direto` dela (o que só ela
 *    gasta, ex. IA medida pela sincronização). O custo direto também soma no
 *    custo do projeto.
 *  - Custo por usuário da conta = custo atribuído / usuários.
 *  - `origem = 'sync'`: a linha veio de scripts/manutencao/sincronizar_contas_projetos.php,
 *    que tira a foto das contas reais do próprio sistema (usuários, mensagens,
 *    IA, plano). A sincronização nunca mexe em receita, peso nem observação, que
 *    são da pessoa.
 *  - Toda leitura e escrita filtra pela lista de contas acessíveis.
 */
final class Conta
{
    public const STATUS = ['ativa' => 'Ativa', 'teste' => 'Em teste', 'inadimplente' => 'Inadimplente', 'cancelada' => 'Cancelada'];
    public const PAGANTES = ['ativa', 'inadimplente'];

    /** Consome recurso (entra no rateio)? Tudo menos cancelada. */
    public static function consome(array $c): bool
    {
        return ($c['status'] ?? 'ativa') !== 'cancelada';
    }

    /** @return array{0:?array,1:?string} */
    public static function validar(array $in): array
    {
        $nome = trim((string) ($in['nome'] ?? ''));
        if ($nome === '') return [null, 'Informe o nome da conta.'];
        if (mb_strlen($nome) > 150) return [null, 'O nome passa de 150 caracteres.'];
        $status = (string) ($in['status'] ?? 'ativa');
        if (!isset(self::STATUS[$status])) return [null, 'Situação inválida.'];
        $d = ['nome' => $nome, 'status' => $status];
        foreach (['identificador' => 80, 'plano' => 80, 'observacao' => 2000] as $c => $max) {
            $v = trim((string) ($in[$c] ?? ''));
            if (mb_strlen($v) > $max) return [null, "O campo $c passa de $max caracteres."];
            $d[$c] = $v === '' ? null : $v;
        }
        foreach (['receita_mensal' => 'Receita', 'custo_direto' => 'Custo direto'] as $c => $rot) {
            $bruto = $in[$c] ?? null;
            $d[$c . '_centavos'] = null;
            if ($bruto !== null && trim((string) $bruto) !== '') {
                $cent = Projeto::centavos($bruto);
                if ($cent === null) return [null, "$rot inválido. Use o formato 1.234,56."];
                if ($cent > Projeto::MAX_VALOR_CENTAVOS) return [null, "$rot alto demais."];
                $d[$c . '_centavos'] = $cent;
            }
        }
        $moeda = strtoupper((string) ($in['moeda'] ?? 'BRL'));
        if (!in_array($moeda, Projeto::MOEDAS, true)) return [null, 'Moeda inválida.'];
        $d['moeda'] = $moeda;
        $usu = trim((string) ($in['usuarios'] ?? ''));
        if ($usu !== '' && (!ctype_digit($usu) || (int) $usu > 10000000)) return [null, 'Usuários precisa ser um número inteiro.'];
        $d['usuarios'] = $usu === '' ? null : (int) $usu;
        $peso = Projeto::quantidade($in['peso_consumo'] ?? 1);
        if ($peso === null || $peso > 10000) return [null, 'Peso de consumo inválido (use 1 para dividir igual).'];
        $d['peso_consumo'] = $peso;
        $ini = trim((string) ($in['inicio'] ?? ''));
        if ($ini !== '') {
            $o = \DateTimeImmutable::createFromFormat('!Y-m-d', $ini);
            if (!$o || $o->format('Y-m-d') !== $ini) return [null, 'Data de início inválida.'];
        }
        $d['inicio'] = $ini === '' ? null : $ini;
        return [$d, null];
    }

    /** Valor em centavos de real (converte dólar). null = a preencher. */
    public static function brl($valor, string $moeda, float $cotacao): ?int
    {
        if ($valor === null || $valor === '') return null;
        $c = (int) round(((float) $valor) * 100);
        return $moeda === 'USD' ? (int) round($c * $cotacao) : $c;
    }

    /**
     * Rateio do custo compartilhado do projeto entre as contas, pelo peso, mais o
     * custo direto de cada uma. Os centavos que sobram vão para as primeiras.
     *
     * @param list<array> $contas linhas de projeto_contas
     * @return array<int,array{receita:?int,custo:int,direto:int}> conta_id => centavos
     */
    public static function rateio(int $custoCompartilhado, array $contas, float $cotacao): array
    {
        $consomem = array_values(array_filter($contas, [self::class, 'consome']));
        $pesos = array_map(fn($c) => max(0.01, (float) $c['peso_consumo']), $consomem);
        $soma = array_sum($pesos);
        $partes = []; $dist = 0;
        foreach ($pesos as $i => $w) { $partes[$i] = $soma > 0 ? (int) floor($custoCompartilhado * $w / $soma) : 0; $dist += $partes[$i]; }
        for ($i = 0; $consomem && $dist < $custoCompartilhado; $i++, $dist++) $partes[$i % count($partes)]++;
        $out = [];
        foreach ($contas as $c) {
            $out[(int) $c['id']] = ['receita' => in_array($c['status'], self::PAGANTES, true) ? self::brl($c['receita_mensal'], $c['moeda'], $cotacao) : 0,
                                    'custo' => 0, 'direto' => 0];
        }
        foreach ($consomem as $i => $c) {
            $direto = (int) self::brl($c['custo_direto'], 'BRL', $cotacao);
            $out[(int) $c['id']]['custo'] = $partes[$i] + $direto;
            $out[(int) $c['id']]['direto'] = $direto;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ escrita */

    private static function params(array $d): array
    {
        $dec = fn(?int $c) => $c === null ? null : intdiv($c, 100) . '.' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
        return ['nome' => $d['nome'], 'ident' => $d['identificador'], 'plano' => $d['plano'], 'status' => $d['status'],
                'receita' => $dec($d['receita_mensal_centavos']), 'moeda' => $d['moeda'], 'usu' => $d['usuarios'],
                'peso' => number_format($d['peso_consumo'], 2, '.', ''), 'direto' => $dec($d['custo_direto_centavos']),
                'inicio' => $d['inicio'], 'obs' => $d['observacao']];
    }

    public static function criar(int $projetoId, array $contas, array $d, ?int $userId): ?int
    {
        $acc = Projeto::contaDoProjeto($projetoId, $contas);
        if ($acc === null) return null;
        $pdo = Database::getConnection();
        $pdo->prepare("INSERT INTO projeto_contas (account_id, projeto_id, nome, identificador, plano, status, receita_mensal, moeda, usuarios,
                                                   peso_consumo, custo_direto, inicio, observacao, origem, created_by)
                       VALUES (:acc, :pid, :nome, :ident, :plano, :status, :receita, :moeda, :usu, :peso, :direto, :inicio, :obs, 'manual', :uid)")
            ->execute(self::params($d) + ['acc' => $acc, 'pid' => $projetoId, 'uid' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function atualizar(int $id, array $contas, array $d): bool
    {
        $acc = Projeto::donoDa('projeto_contas', $id, $contas);
        if ($acc === null) return false;
        Database::getConnection()->prepare("UPDATE projeto_contas SET nome = :nome, identificador = :ident, plano = :plano, status = :status,
                    receita_mensal = :receita, moeda = :moeda, usuarios = :usu, peso_consumo = :peso, custo_direto = :direto,
                    inicio = :inicio, observacao = :obs WHERE id = :id AND account_id = :acc AND deleted_at IS NULL")
            ->execute(self::params($d) + ['id' => $id, 'acc' => $acc]);
        return true;
    }

    public static function excluir(int $id, array $contas): bool
    {
        $acc = Projeto::donoDa('projeto_contas', $id, $contas);
        if ($acc === null) return false;
        Database::getConnection()->prepare("UPDATE projeto_contas SET deleted_at = NOW() WHERE id = ? AND account_id = ?")->execute([$id, $acc]);
        return true;
    }

    /**
     * Grava a foto das contas reais (sincronização). Casa pelo identificador
     * dentro do projeto; conta nova é criada, existente tem atualizados só os
     * campos medidos (nome, plano, situação, usuários, custo direto, métricas,
     * início vazio). Receita, peso e observação ficam como a pessoa deixou.
     *
     * @param list<array{identificador:string,nome:string,plano:?string,status:string,usuarios:int,
     *                   custo_direto_centavos:int,inicio:?string,metricas:array}> $linhas
     * @return list<string> log
     */
    public static function sincronizar(\PDO $pdo, int $accountId, int $projetoId, array $linhas, bool $aplicar): array
    {
        $log = [];
        $dec = fn(int $c) => intdiv($c, 100) . '.' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
        $busca = $pdo->prepare("SELECT id FROM projeto_contas WHERE account_id = ? AND projeto_id = ? AND identificador = ? AND deleted_at IS NULL LIMIT 1");
        foreach ($linhas as $l) {
            $busca->execute([$accountId, $projetoId, $l['identificador']]);
            $id = $busca->fetchColumn();
            $met = json_encode($l['metricas'], JSON_UNESCAPED_UNICODE);
            $log[] = ($id ? '   atualiza ' : '   cria ') . "{$l['identificador']} {$l['nome']} ({$l['status']}, {$l['usuarios']} usuários)";
            if (!$aplicar) continue;
            if ($id) {
                $pdo->prepare("UPDATE projeto_contas SET nome = ?, plano = ?, status = ?, usuarios = ?, custo_direto = ?, metricas = ?,
                                      inicio = COALESCE(inicio, ?), origem = 'sync', sincronizado_em = NOW() WHERE id = ? AND account_id = ?")
                    ->execute([$l['nome'], $l['plano'], $l['status'], $l['usuarios'], $dec($l['custo_direto_centavos']), $met, $l['inicio'], $id, $accountId]);
            } else {
                $pdo->prepare("INSERT INTO projeto_contas (account_id, projeto_id, nome, identificador, plano, status, usuarios, custo_direto,
                                                           inicio, metricas, origem, sincronizado_em)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sync', NOW())")
                    ->execute([$accountId, $projetoId, $l['nome'], $l['identificador'], $l['plano'], $l['status'], $l['usuarios'],
                               $dec($l['custo_direto_centavos']), $l['inicio'], $met]);
            }
        }
        return $log;
    }
}
