<?php
namespace App\Orcamentos;

/**
 * De onde vem e para onde vai cada real de um orçamento. É a mesma conta da
 * plataforma Fleetiflow (backend/src/modules/financeiro/decomposicao-da-venda.ts),
 * linha por linha: quem mexer numa, mexe na outra.
 *
 * O exemplo do despachante (11/10/2026), uma transferência de R$ 495,91:
 *
 *   para o escritório                    para o cliente
 *   Valor recebido      R$ 495,91        Detran      R$ 295,91
 *   Detran (imediato)  −R$ 295,91        Honorário   R$ 200,00
 *   SERPRO (mensal)    −R$   8,61        Orçamento   R$ 495,91
 *   Honorário           R$ 191,39
 *
 * REPASSE visível ao cliente (Detran) sai em linha própria no orçamento; o que
 * não é visível (SERPRO) fica dentro do honorário que o cliente lê. DÉBITO do
 * veículo (IPVA, multas, dívida ativa) é repasse integral, nunca honorário.
 *
 * Funções PURAS: nada aqui lê banco, para o teste conferir a conta sozinha.
 */
final class Decomposicao
{
    /**
     * @param array{id?:mixed, natureza:string, descricao:string, quantidade:float, venda:float,
     *              componentes?:array<int,array{categoria:string, descricao:string, valor:float, prazo:string, visivel_cliente:bool}>} $linha
     * @return array{venda:float, repasses:float, custos:float, honorario:float, saidas:array, para_o_cliente:array}
     */
    public static function linha(array $linha): array
    {
        $venda = self::r((float) $linha['venda']);

        if (($linha['natureza'] ?? 'SERVICO') === 'DEBITO') {
            return [
                'venda'          => $venda,
                'repasses'       => $venda,
                'custos'         => 0.0,
                'honorario'      => 0.0,
                'saidas'         => [[
                    'categoria' => 'REPASSE', 'descricao' => $linha['descricao'], 'valor' => $venda,
                    'prazo' => 'IMEDIATO', 'visivel_cliente' => true, 'debito' => true,
                ]],
                'para_o_cliente' => [['descricao' => $linha['descricao'], 'valor' => $venda, 'repasse' => true]],
            ];
        }

        $qtd = (float) ($linha['quantidade'] ?? 1);
        if ($qtd <= 0) $qtd = 1;
        $saidas = [];
        foreach ($linha['componentes'] ?? [] as $c) {
            $repasse = $c['categoria'] === 'REPASSE';
            $saidas[] = [
                'categoria'       => $repasse ? 'REPASSE' : 'CUSTO',
                'descricao'       => (string) $c['descricao'],
                'valor'           => self::r((float) $c['valor'] * $qtd),
                'prazo'           => $repasse ? ($c['prazo'] === 'MENSAL' ? 'MENSAL' : 'IMEDIATO') : 'IMEDIATO',
                'visivel_cliente' => $repasse && !empty($c['visivel_cliente']),
                'debito'          => false,
            ];
        }
        $repasses = self::r(array_sum(array_map(fn($s) => $s['categoria'] === 'REPASSE' ? $s['valor'] : 0, $saidas)));
        $custos   = self::r(array_sum(array_map(fn($s) => $s['categoria'] === 'CUSTO' ? $s['valor'] : 0, $saidas)));

        $visiveis = array_values(array_filter($saidas, fn($s) => $s['visivel_cliente']));
        if (!$visiveis) {
            $cliente = [['descricao' => $linha['descricao'], 'valor' => $venda, 'repasse' => false]];
        } else {
            $cliente = array_map(fn($s) => ['descricao' => $s['descricao'], 'valor' => $s['valor'], 'repasse' => true], $visiveis);
            $cliente[] = [
                'descricao' => 'Honorário',
                'valor'     => self::r($venda - array_sum(array_column($visiveis, 'valor'))),
                'repasse'   => false,
            ];
        }

        return [
            'venda'          => $venda,
            'repasses'       => $repasses,
            'custos'         => $custos,
            'honorario'      => self::r($venda - $repasses - $custos),
            'saidas'         => $saidas,
            'para_o_cliente' => $cliente,
        ];
    }

    /**
     * O orçamento inteiro. As linhas do cliente são somadas pela descrição
     * (dois serviços com Detran viram uma linha "Detran"), taxas e débitos
     * primeiro, honorário no fim.
     *
     * @return array{linhas:array, venda:float, repasses:float, repasses_imediatos:float, repasses_mensais:float,
     *               custos:float, honorario:float, para_o_cliente:array}
     */
    public static function orcamento(array $linhas): array
    {
        $decompostas = array_map(fn($l) => ['linha' => $l] + self::linha($l), $linhas);

        $grupos = [];
        foreach ($decompostas as $d) {
            foreach ($d['para_o_cliente'] as $l) {
                $chave = ($l['repasse'] ? 'r:' : 'h:') . mb_strtolower(trim($l['descricao']));
                if (isset($grupos[$chave])) $grupos[$chave]['valor'] = self::r($grupos[$chave]['valor'] + $l['valor']);
                else $grupos[$chave] = $l;
            }
        }
        $cliente = array_values($grupos);
        usort($cliente, fn($a, $b) => (int) $b['repasse'] <=> (int) $a['repasse']);

        $saidas = array_merge(...array_map(fn($d) => $d['saidas'], $decompostas ?: [['saidas' => []]]));
        $soma = fn(string $k) => self::r(array_sum(array_column($decompostas, $k)));
        $repassePor = fn(string $prazo) => self::r(array_sum(array_map(
            fn($s) => $s['categoria'] === 'REPASSE' && $s['prazo'] === $prazo ? $s['valor'] : 0, $saidas)));

        return [
            'linhas'             => $decompostas,
            'venda'              => $soma('venda'),
            'repasses'           => $soma('repasses'),
            'repasses_imediatos' => $repassePor('IMEDIATO'),
            'repasses_mensais'   => $repassePor('MENSAL'),
            'custos'             => $soma('custos'),
            'honorario'          => $soma('honorario'),
            'para_o_cliente'     => $cliente,
        ];
    }

    public static function r(float $n): float
    {
        return round($n, 2);
    }
}
