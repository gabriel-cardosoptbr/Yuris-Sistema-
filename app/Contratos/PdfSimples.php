<?php
namespace App\Contratos;

/**
 * Gerador mínimo de PDF (texto e linhas, A4, Helvetica), sem dependência: o
 * servidor não tem biblioteca de PDF nem Composer. Serve ao comprovante de
 * assinaturas, que é só texto. Acentos via WinAnsiEncoding (Windows-1252);
 * caractere fora dela vira "?".
 */
final class PdfSimples
{
    private const W = 595.28;
    private const H = 841.89;
    private const M = 50.0;
    /** Larguras da Helvetica (AFM), 1/1000 do tamanho, para 32..126. */
    private const LARG = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];

    /** @var string[] */
    private array $paginas = [];
    private string $atual = '';
    private float $y = 0;
    private string $titulo;

    public function __construct(string $titulo = 'Documento')
    {
        $this->titulo = $titulo;
        $this->novaPagina();
    }

    public function novaPagina(): void
    {
        if ($this->atual !== '') $this->paginas[] = $this->atual;
        $this->atual = ' ';
        $this->y = self::H - self::M;
    }

    public function espaco(float $pt): void
    {
        $this->y -= $pt;
        if ($this->y < self::M) $this->novaPagina();
    }

    public function linha(float $cinza = 0.8): void
    {
        $this->espaco(6);
        $c = number_format($cinza, 2, '.', '');
        $this->atual .= sprintf("%s G 0.6 w %.2F %.2F m %.2F %.2F l S\n", $c, self::M, $this->y, self::W - self::M, $this->y);
        $this->espaco(10);
    }

    /** Texto com quebra automática. $cor em [r,g,b] de 0 a 1. */
    public function texto(string $txt, float $tam = 10, bool $negrito = false, array $cor = [0.1, 0.1, 0.1], float $recuo = 0): void
    {
        $larguraMax = self::W - 2 * self::M - $recuo;
        foreach (explode("\n", $txt) as $par) {
            $linhas = $this->quebrar($this->cp1252($par), $tam, $negrito, $larguraMax);
            foreach ($linhas as $l) {
                if ($this->y - $tam < self::M) $this->novaPagina();
                $this->y -= $tam * 1.25;
                $this->atual .= sprintf("BT /%s %.1F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
                    $negrito ? 'F2' : 'F1', $tam, $cor[0], $cor[1], $cor[2], self::M + $recuo, $this->y, $this->escapar($l));
            }
        }
    }

    /** Par rótulo: valor numa linha (o valor quebra embaixo se não couber). */
    public function campo(string $rotulo, string $valor, float $tam = 9.5): void
    {
        $this->texto($rotulo, $tam - 1.5, true, [0.42, 0.42, 0.45]);
        $this->texto($valor === '' ? '-' : $valor, $tam, false, [0.1, 0.1, 0.1]);
        $this->espaco(3);
    }

    public function render(): string
    {
        $paginas = $this->paginas;
        $paginas[] = $this->atual;
        $obj = [];
        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $n = 5;
        $paginaObjs = [];
        foreach ($paginas as $conteudo) {
            $pid = $n++;
            $cid = $n++;
            $kids[] = "$pid 0 R";
            $paginaObjs[$pid] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $cid);
            $paginaObjs[$cid] = '<< /Length ' . strlen($conteudo) . " >>\nstream\n" . $conteudo . "\nendstream";
        }
        $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $obj += $paginaObjs;
        $info = $n;
        $obj[$info] = '<< /Title (' . $this->escapar($this->cp1252($this->titulo)) . ') /Producer (Yuris Contratos) /CreationDate (D:' . gmdate('YmdHis') . "Z) >>";
        ksort($obj);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($obj as $i => $o) {
            $offsets[$i] = strlen($out);
            $out .= "$i 0 obj\n$o\nendobj\n";
        }
        $xref = strlen($out);
        $total = max(array_keys($obj)) + 1;
        $out .= "xref\n0 $total\n0000000000 65535 f \n";
        for ($i = 1; $i < $total; $i++) $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        $out .= "trailer\n<< /Size $total /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    private function cp1252(string $s): string
    {
        $c = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return $c === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $c;
    }

    private function escapar(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
    }

    private function largura(string $s, float $tam, bool $negrito): float
    {
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($s[$i]);
            $w += ($o >= 32 && $o <= 126) ? self::LARG[$o - 32] : 556;
        }
        return $w * $tam / 1000 * ($negrito ? 1.08 : 1.0);
    }

    /** @return string[] */
    private function quebrar(string $par, float $tam, bool $negrito, float $max): array
    {
        if ($par === '') return [''];
        $linhas = [];
        $atual = '';
        foreach (preg_split('/(\s+)/', $par) as $palavra) {
            $tentativa = $atual === '' ? $palavra : $atual . ' ' . $palavra;
            if ($this->largura($tentativa, $tam, $negrito) <= $max) { $atual = $tentativa; continue; }
            if ($atual !== '') $linhas[] = $atual;
            // Palavra maior que a linha (hash, UUID): corta em pedaços.
            while ($this->largura($palavra, $tam, $negrito) > $max) {
                $k = strlen($palavra);
                while ($k > 1 && $this->largura(substr($palavra, 0, $k), $tam, $negrito) > $max) $k--;
                $linhas[] = substr($palavra, 0, $k);
                $palavra = substr($palavra, $k);
            }
            $atual = $palavra;
        }
        if ($atual !== '') $linhas[] = $atual;
        return $linhas;
    }
}
