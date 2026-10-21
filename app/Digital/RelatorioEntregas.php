<?php
namespace App\Digital;

use App\Core\PlanilhaXlsx;

/**
 * O relatório de entregas do cliente em Excel (21/10/2026), para a pessoa
 * conferir: o que foi contratado e entregue no período, quando cada coisa foi
 * aprovada, entregue e publicada, o link do post e o arquivo do Drive de cada
 * entrega. Duas abas: "Entregas" (uma linha por entrega) e "Resumo por mês"
 * (contratado x entregue x publicado, por mês e tipo).
 */
final class RelatorioEntregas
{
    private static function dataHora(?string $v): string { return $v ? date('d/m/Y H:i', strtotime($v)) : ''; }
    private static function data(?string $v): string { return $v ? date('d/m/Y', strtotime($v)) : ''; }
    private static function mes(string $v): string { return substr($v, 5, 2) . '/' . substr($v, 0, 4); }

    /** Meses de $de a $ate (AAAA-MM), no máximo 24. */
    public static function meses(string $de, string $ate): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $de) || !preg_match('/^\d{4}-\d{2}$/', $ate)) throw new \InvalidArgumentException('Período inválido.');
        if ($de > $ate) [$de, $ate] = [$ate, $de];
        $out = [];
        for ($m = $de; $m <= $ate && count($out) < 24; $m = date('Y-m', strtotime($m . '-01 +1 month'))) $out[] = $m;
        if ($m <= $ate) throw new \InvalidArgumentException('Período longo demais: até 24 meses por relatório.');
        return $out;
    }

    /**
     * As abas da planilha.
     * @param array $entregas FichaDoCliente::producao
     */
    public static function abas(string $cliente, string $de, string $ate, array $entregas, array $resumoPorMes, string $quem): array
    {
        $tot = ['contratadas' => 0, 'entregues' => 0, 'publicadas' => 0];
        foreach ($resumoPorMes as $r) { $tot['contratadas'] += (int) ($r['contratadas'] ?? 0); $tot['entregues'] += (int) ($r['concluidas'] ?? 0); $tot['publicadas'] += (int) ($r['publicadas'] ?? 0); }
        $linhas = [
            [['titulo' => 'Relatório de entregas · ' . $cliente]],
            ['Período: ' . self::mes($de) . ($ate !== $de ? ' a ' . self::mes($ate) : '') . ' · gerado em ' . date('d/m/Y H:i') . ($quem ? ' por ' . $quem : '')],
            ['Contratadas: ' . $tot['contratadas'] . ' · Entregues: ' . $tot['entregues'] . ' · Publicadas: ' . $tot['publicadas'] . ' · Cadastradas: ' . count($entregas)],
            [],
            ['Mês', 'Entrega', 'Tipo', 'Prazo', 'Produção', 'Aprovação do cliente', 'Aprovado em', 'Entrega', 'Entregue em', 'Publicação', 'Publicado em', 'Link do post', 'Arquivo do Drive', 'Outros arquivos', 'Responsável', 'Quadro'],
        ];
        foreach ($entregas as $e) {
            $arqs = $e['arquivos'] ?? [];
            $primeiro = $arqs[0] ?? null;
            $linhas[] = [
                self::mes((string) $e['competencia']), (string) $e['titulo'], (string) ($e['tipo_rotulo'] ?? ''), self::data($e['prazo'] ?? null),
                (string) ($e['producao_rotulo'] ?? ''), (string) ($e['aprovacao_rotulo'] ?? ''), self::dataHora($e['aprovacao'] === 'aprovado' ? ($e['aprovacao_em'] ?? null) : null),
                (string) ($e['entrega_rotulo'] ?? '') . (!empty($e['atrasada']) ? ' (atrasada)' : ''), self::dataHora($e['entregue_em'] ?? null),
                (string) ($e['publicacao_rotulo'] ?? ''), self::dataHora($e['publicado_em'] ?? null),
                !empty($e['link_publicacao']) ? ['link' => $e['link_publicacao'], 'texto' => 'Abrir post'] : null,
                $primeiro ? ($primeiro['link'] ? ['link' => $primeiro['link'], 'texto' => $primeiro['nome']] : $primeiro['nome']) : null,
                count($arqs) > 1 ? implode(' | ', array_map(fn($a) => $a['nome'] . ($a['link'] ? ' (' . $a['link'] . ')' : ''), array_slice($arqs, 1))) : null,
                (string) ($e['responsavel'] ?? ''), (string) ($e['quadro'] ?? ''),
            ];
        }
        if (!$entregas) $linhas[] = ['Nenhuma entrega cadastrada no período.'];
        $res = [[['titulo' => 'Resumo por mês · ' . $cliente]], [], ['Mês', 'Tipo', 'Contratado', 'Cadastradas', 'Entregues', 'Publicadas', 'Pendentes']];
        foreach ($resumoPorMes as $mes => $r) {
            $tipos = $r['por_tipo'] ?? [];
            if (!$tipos) { $res[] = [self::mes($mes), '-', 0, 0, 0, 0, 0]; continue; }
            foreach ($tipos as $t) $res[] = [self::mes($mes), (string) ($t['rotulo'] ?? ''), (int) $t['previsto'], (int) $t['cadastradas'], (int) $t['entregues'], (int) $t['publicados'], (int) ($t['pendentes'] ?? 0)];
        }
        return [
            ['nome' => 'Entregas', 'cabecalho' => 5, 'larguras' => [9, 36, 14, 11, 13, 22, 16, 14, 16, 13, 16, 14, 36, 30, 18, 24], 'linhas' => $linhas],
            ['nome' => 'Resumo por mês', 'cabecalho' => 3, 'larguras' => [10, 18, 12, 12, 12, 12, 12], 'linhas' => $res],
        ];
    }

    public static function gerar(array $abas, callable $saida): void { PlanilhaXlsx::gerar($abas, $saida); }
}
