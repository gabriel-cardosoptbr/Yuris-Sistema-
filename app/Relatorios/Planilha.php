<?php

namespace App\Relatorios;

/**
 * Planilha — o relatorio como CSV, para abrir no Excel.
 *
 * ---------------------------------------------------------------------------
 * TRES DECISOES QUE PARECEM DETALHE E NAO SAO
 * ---------------------------------------------------------------------------
 *
 * 1. BOM UTF-8 no comeco. Sem ele o Excel do Windows le o arquivo como ANSI e
 *    "Prospecção" vira "ProspecÃ§Ã£o". O Excel so respeita UTF-8 num CSV quando
 *    encontra o BOM.
 *
 * 2. Separador PONTO E VIRGULA. No Windows em portugues a virgula e o separador
 *    DECIMAL, entao o Excel espera `;` entre colunas. Um CSV com virgula abre
 *    tudo espremido numa coluna so, e a advogada conclui, com razao, que o
 *    relatorio veio quebrado.
 *
 * 3. Neutralizacao de formula. Uma celula que comeca com `=`, `+`, `-`, `@` ou
 *    TAB e interpretada como FORMULA pelo Excel. Como o conteudo aqui vem do que
 *    as pessoas digitaram no CRM, um nome como `=cmd|...` viraria execucao na
 *    maquina de quem abre o arquivo. Isso tem nome, CSV injection, e a defesa e
 *    prefixar com apostrofo: o Excel mostra o texto e nao avalia nada.
 *
 * ---------------------------------------------------------------------------
 * POR QUE CSV E NAO XLSX
 * ---------------------------------------------------------------------------
 * O projeto nao usa Composer e nao tem `vendor/`. Gerar XLSX de verdade exigiria
 * embutir uma biblioteca inteira para resolver o que o CSV ja resolve: o Excel
 * abre CSV com dois cliques. Quando houver necessidade real de formatacao
 * (varias abas, formula, grafico), ai sim vale o custo.
 */
final class Planilha
{
    public const SEPARADOR = ';';

    /**
     * Monta o conteudo do arquivo.
     *
     * @param string[]        $colunas
     * @param array<int,array> $linhas   cada linha e um array de celulas na ordem das colunas
     * @param string[]        $cabecalho linhas soltas antes da tabela (titulo, filtros, data)
     */
    public static function conteudo(array $colunas, array $linhas, array $cabecalho = []): string
    {
        $out = "\xEF\xBB\xBF"; // BOM

        foreach ($cabecalho as $texto) {
            $out .= self::celula($texto) . "\r\n";
        }
        if ($cabecalho !== []) {
            $out .= "\r\n";
        }

        $out .= self::linha($colunas);
        foreach ($linhas as $l) {
            $out .= self::linha(is_array($l) ? $l : [$l]);
        }
        return $out;
    }

    /**
     * Envia o arquivo como download.
     *
     * `Content-Length` entra porque sem ele o navegador nao mostra progresso e o
     * download de um relatorio grande parece travado.
     */
    public static function baixar(string $conteudo, string $nomeArquivo): void
    {
        $nome = self::nomeSeguro($nomeArquivo);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome . '"');
        header('Content-Length: ' . strlen($conteudo));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $conteudo;
    }

    /** CRLF de proposito: e o que o Excel do Windows espera. */
    private static function linha(array $celulas): string
    {
        $partes = [];
        foreach ($celulas as $c) {
            $partes[] = self::celula($c);
        }
        return implode(self::SEPARADOR, $partes) . "\r\n";
    }

    private static function celula($valor): string
    {
        $s = (string) ($valor ?? '');

        // Quebra de linha DENTRO da celula (o campo Conteúdo de uma anotação tem)
        // vira espaco: o CSV até suporta, entre aspas, mas leitor nenhum concorda
        // sobre como, e o arquivo abre bagunçado em metade deles.
        $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);

        // CSV injection: ver o cabecalho da classe.
        if ($s !== '' && str_contains("=+-@\t", $s[0])) {
            $s = "'" . $s;
        }

        // Aspas duplas dobradas, e a celula inteira entre aspas. Sempre, e nao
        // so quando ha separador: uniforme e mais facil de conferir a olho.
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /**
     * Nome de arquivo sem nada que o navegador ou o sistema de arquivos possa
     * interpretar. Barra, dois-pontos e aspas sairiam do lugar no
     * `Content-Disposition` e permitiriam forjar o nome.
     */
    public static function nomeSeguro(string $nome): string
    {
        $nome = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nome) ?? 'relatorio.csv';
        $nome = trim($nome, '_.');
        if ($nome === '' || !str_ends_with(strtolower($nome), '.csv')) {
            $nome = ($nome === '' ? 'relatorio' : $nome) . '.csv';
        }
        return mb_substr($nome, 0, 120);
    }

    /**
     * O dossie inteiro como planilha: um bloco por vez, com o titulo do bloco
     * como separador.
     *
     * Nao e tao bonito quanto a versao impressa, e nao substitui ela. Serve para
     * quem quer o dado para cruzar, filtrar ou juntar com outro relatorio.
     */
    public static function doDossie(array $doc): string
    {
        $out = "\xEF\xBB\xBF";
        $out .= self::linha([$doc['titulo'] ?? '']);
        $out .= self::linha([$doc['subtitulo'] ?? '']);
        $out .= self::linha(['Gerado em', $doc['gerado_em'] ?? '']);
        $out .= "\r\n";

        $out .= self::linha(['IDENTIFICAÇÃO']);
        foreach (($doc['identificacao'] ?? []) as $p) {
            $out .= self::linha([$p['rotulo'] ?? '', $p['valor'] ?? '']);
        }
        $out .= "\r\n";

        foreach (($doc['blocos'] ?? []) as $b) {
            $out .= self::linha([mb_strtoupper((string) ($b['titulo'] ?? ''))]);

            if ((int) ($b['total'] ?? 0) === 0) {
                $out .= self::linha([$b['vazio'] ?? 'Sem registros.']);
                $out .= "\r\n";
                continue;
            }

            switch ($b['tipo'] ?? '') {
                case 'tabela':
                    $out .= self::linha($b['colunas'] ?? []);
                    foreach (($b['linhas'] ?? []) as $l) {
                        $out .= self::linha($l);
                    }
                    break;

                case 'pares':
                    foreach (($b['itens'] ?? []) as $p) {
                        $out .= self::linha([$p['rotulo'] ?? '', $p['valor'] ?? '']);
                    }
                    break;

                case 'chips':
                    $nomes = [];
                    foreach (($b['itens'] ?? []) as $t) {
                        $nomes[] = (string) ($t['nome'] ?? '');
                    }
                    $out .= self::linha([implode(', ', $nomes)]);
                    break;

                case 'timeline':
                    $out .= self::linha(['Quando', 'Quem', 'O que aconteceu', 'Campo', 'De', 'Para', 'Fase']);
                    foreach (($b['itens'] ?? []) as $e) {
                        $out .= self::linha([
                            $e['quando'] ?? '', $e['usuario'] ?? '', $e['acao'] ?? '',
                            $e['campo'] ?? '', $e['de'] ?? '', $e['para'] ?? '', $e['fase'] ?? '',
                        ]);
                    }
                    break;
            }
            $out .= "\r\n";
        }

        return $out;
    }
}
