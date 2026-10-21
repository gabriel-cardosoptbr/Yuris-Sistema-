<?php
namespace App\Core;

/**
 * Planilha .xlsx escrita à mão (21/10/2026), em fluxo, sobre o ZipEmFluxo: sem
 * Composer e sem a extensão zip do PHP. Abre no Excel, no Google Planilhas e no
 * LibreOffice. Cada aba:
 *
 *   ['nome' => 'Entregas', 'larguras' => [30, 12, ...], 'cabecalho' => 5 (linha
 *    do cabeçalho: negrito, fundo cinza, filtro e congelada), 'linhas' => [[...], ...]]
 *
 * Célula: texto, número, null (vazia), ['titulo' => '...'] (negrito grande) ou
 * ['link' => 'https://...', 'texto' => '...'] (link clicável, fórmula HYPERLINK).
 */
final class PlanilhaXlsx
{
    private static function x(string $s): string
    {
        // Só caracteres que o XML aceita.
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function coluna(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function celula(string $ref, mixed $v, bool $cabecalho): string
    {
        if ($v === null || $v === '') return '';
        if (is_array($v) && isset($v['link'])) {
            $url = str_replace('"', '""', (string) $v['link']);
            $txt = (string) ($v['texto'] ?? $v['link']);
            if (strlen($url) > 250) return '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::x((string) $v['link']) . '</t></is></c>';
            return '<c r="' . $ref . '" s="2" t="str"><f>' . self::x('HYPERLINK("' . $url . '","' . str_replace('"', '""', $txt) . '")') . '</f><v>' . self::x($txt) . '</v></c>';
        }
        if (is_array($v) && isset($v['titulo'])) return '<c r="' . $ref . '" s="3" t="inlineStr"><is><t>' . self::x((string) $v['titulo']) . '</t></is></c>';
        if (is_int($v) || is_float($v)) return '<c r="' . $ref . '"' . ($cabecalho ? ' s="1"' : '') . '><v>' . $v . '</v></c>';
        return '<c r="' . $ref . '"' . ($cabecalho ? ' s="1"' : '') . ' t="inlineStr"><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
    }

    private static function aba(array $a): string
    {
        $cab = (int) ($a['cabecalho'] ?? 0);
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($cab > 0) $x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $cab . '" topLeftCell="A' . ($cab + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if (!empty($a['larguras'])) {
            $x .= '<cols>';
            foreach (array_values($a['larguras']) as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        $maxCol = 0;
        foreach (array_values($a['linhas']) as $r => $linha) {
            $n = $r + 1;
            $x .= '<row r="' . $n . '">';
            foreach (array_values($linha) as $c => $v) { $x .= self::celula(self::coluna($c) . $n, $v, $n === $cab); $maxCol = max($maxCol, $c + 1); }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        $ultima = count($a['linhas']);
        if ($cab > 0 && $ultima >= $cab && $maxCol > 0) $x .= '<autoFilter ref="A' . $cab . ':' . self::coluna($maxCol - 1) . $ultima . '"/>';
        return $x . '</worksheet>';
    }

    /** @param array<int, array> $abas */
    public static function gerar(array $abas, callable $saida): void
    {
        $zip = new ZipEmFluxo($saida);
        $n = count($abas);
        $tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) $tipos .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $tipos .= '</Types>';
        $arquivos = [
            '[Content_Types].xml' => $tipos,
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        ];
        $folhas = ''; $rels = '';
        $usados = [];
        foreach (array_values($abas) as $i => $a) {
            // Nome de aba: até 31 letras, sem []:*?/\ e sem repetir.
            $nome = mb_substr(trim(preg_replace('#[\[\]:*?/\\\\]#', ' ', (string) ($a['nome'] ?? 'Planilha'))) ?: 'Planilha', 0, 31);
            while (isset($usados[mb_strtolower($nome)])) $nome = mb_substr($nome, 0, 28) . ' ' . ($i + 1);
            $usados[mb_strtolower($nome)] = true;
            $folhas .= '<sheet name="' . self::x($nome) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $arquivos['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $folhas . '</sheets></workbook>';
        $arquivos['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
        // Estilos: 0 normal, 1 cabeçalho (negrito, fundo cinza), 2 link, 3 título.
        $arquivos['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><u/><sz val="11"/><color rgb="FF0563C1"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE7EBF1"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        foreach ($arquivos as $nome => $conteudo) $zip->adicionar($nome, function (callable $e) use ($conteudo) { $e($conteudo); });
        foreach (array_values($abas) as $i => $a) {
            $xml = self::aba($a);
            $zip->adicionar('xl/worksheets/sheet' . ($i + 1) . '.xml', function (callable $e) use ($xml) { $e($xml); });
        }
        $zip->fechar();
    }
}
