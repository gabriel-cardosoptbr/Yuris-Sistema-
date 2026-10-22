<?php
declare(strict_types=1);

namespace App\Core;

/**
 * IconeApp: o ícone do app instalado no celular, feito do ícone da marca (22/10/2026).
 *
 * O ícone enviado no Painel Master tem o tamanho que a pessoa mandou (256, 512)
 * e às vezes fundo transparente. No celular isso não serve: o iPhone pinta o
 * transparente de PRETO (o azul escuro da Inovaize sumiria) e o Android pede
 * 192 e 512 px. O PHP de produção não tem GD nem Imagick, então a conta é feita
 * aqui à mão, como o ZipEmFluxo faz com o .zip:
 *
 *   · lê o PNG (8 bits por canal, sem entrelaçamento: cinza, RGB, paleta, com
 *     ou sem alfa), desfazendo os cinco filtros de linha;
 *   · escolhe o fundo: se o meio da borda de cima é opaco, o ícone já vem
 *     "cheio" (um quadrado com cantos arredondados, por exemplo) e o fundo é
 *     essa cor, sem margem; se é transparente, o fundo é branco e o desenho
 *     ganha 12% de margem;
 *   · pinta sobre o fundo e redimensiona (média de várias amostras ao reduzir,
 *     bilinear ao ampliar);
 *   · grava um PNG RGB opaco.
 *
 * Formato fora disso (16 bits, entrelaçado, JPEG, WebP) devolve null, e quem
 * chamou entrega o ícone original.
 */
final class IconeApp
{
    public const LADOS = [180, 192, 512];

    private const MARGEM_TRANSPARENTE = 0.12;

    /**
     * Endereço do ícone do app de $lado px para a conta: o da marca própria
     * (convertido por /api/app_icone.php), ou o pronto do Fleetiflow ou do Yuris,
     * gerados uma vez e guardados em public/assets/app-*.png. Usado pelo
     * manifesto e pela meta apple-touch-icon do sidebar.php.
     */
    public static function url(string $produto, ?array $marca, int $lado): string
    {
        if ($produto !== 'fleetiflow') return '/assets/app-yuris-' . $lado . '.png?v=1';
        if ($marca && !empty($marca['personalizada'])
            && preg_match('/[?&]h=([0-9a-f]{40})(?![0-9a-f])/', (string) ($marca['icone_url'] ?? ''), $m)) {
            return '/api/app_icone.php?h=' . $m[1] . '&l=' . $lado;
        }
        return '/assets/app-fleetiflow-' . $lado . '.png?v=1';
    }

    /** PNG opaco e quadrado de $lado px a partir do PNG da marca, ou null. */
    public static function gerar(string $png, int $lado): ?string
    {
        $img = self::ler($png);
        if ($img === null || $lado < 16 || $lado > 1024) return null;
        [$w, $h, $rgba] = $img;

        // Fundo e margem pelo meio da borda de cima (segunda linha, para fugir do
        // serrilhado da primeira).
        $p = (min(1, $h - 1) * $w + intdiv($w, 2)) * 4;
        if (ord($rgba[$p + 3]) >= 230) {
            $fundo  = [ord($rgba[$p]), ord($rgba[$p + 1]), ord($rgba[$p + 2])];
            $margem = 0.0;
        } else {
            $fundo  = [255, 255, 255];
            $margem = self::MARGEM_TRANSPARENTE;
        }

        // Pinta sobre o fundo: RGB, 3 bytes por pixel.
        $rgb = '';
        for ($i = 0, $n = $w * $h * 4; $i < $n; $i += 4) {
            $a = ord($rgba[$i + 3]);
            if ($a === 255) { $rgb .= $rgba[$i] . $rgba[$i + 1] . $rgba[$i + 2]; continue; }
            $rgb .= chr(intdiv(ord($rgba[$i]) * $a + $fundo[0] * (255 - $a), 255))
                  . chr(intdiv(ord($rgba[$i + 1]) * $a + $fundo[1] * (255 - $a), 255))
                  . chr(intdiv(ord($rgba[$i + 2]) * $a + $fundo[2] * (255 - $a), 255));
        }
        unset($rgba);

        // Caixa do desenho dentro do quadrado, mantendo a proporção.
        $util = $lado - 2 * (int) round($lado * $margem);
        $esc  = min($util / $w, $util / $h);
        $dw   = $w * $esc; $dh = $h * $esc;
        $ox   = ($lado - $dw) / 2; $oy = ($lado - $dh) / 2;
        $k    = max(1, (int) ceil(1 / $esc));     // amostras por eixo ao reduzir
        $fundoBin = chr($fundo[0]) . chr($fundo[1]) . chr($fundo[2]);

        $linhas = '';
        for ($y = 0; $y < $lado; $y++) {
            $linhas .= "\0";                      // filtro "nenhum"
            for ($x = 0; $x < $lado; $x++) {
                if ($x < $ox - 0.5 || $x >= $ox + $dw - 0.5 || $y < $oy - 0.5 || $y >= $oy + $dh - 0.5) {
                    $linhas .= $fundoBin;
                    continue;
                }
                $r = $g = $b = 0;
                for ($sy = 0; $sy < $k; $sy++) {
                    $fy = ($y + ($sy + 0.5) / $k - 0.5 - $oy) / $esc;
                    for ($sx = 0; $sx < $k; $sx++) {
                        $fx = ($x + ($sx + 0.5) / $k - 0.5 - $ox) / $esc;
                        [$cr, $cg, $cb] = self::bilinear($rgb, $w, $h, $fx, $fy);
                        $r += $cr; $g += $cg; $b += $cb;
                    }
                }
                $q = $k * $k;
                $linhas .= chr((int) round($r / $q)) . chr((int) round($g / $q)) . chr((int) round($b / $q));
            }
        }

        return "\x89PNG\r\n\x1a\n"
            . self::bloco('IHDR', pack('NNCCCCC', $lado, $lado, 8, 2, 0, 0, 0))
            . self::bloco('IDAT', (string) gzcompress($linhas, 9))
            . self::bloco('IEND', '');
    }

    /** @return array{0:float,1:float,2:float} */
    private static function bilinear(string $rgb, int $w, int $h, float $fx, float $fy): array
    {
        $fx = max(0.0, min($w - 1.0, $fx));
        $fy = max(0.0, min($h - 1.0, $fy));
        $x0 = (int) $fx; $y0 = (int) $fy;
        $x1 = min($x0 + 1, $w - 1); $y1 = min($y0 + 1, $h - 1);
        $tx = $fx - $x0; $ty = $fy - $y0;
        $a = ($y0 * $w + $x0) * 3; $b = ($y0 * $w + $x1) * 3;
        $c = ($y1 * $w + $x0) * 3; $d = ($y1 * $w + $x1) * 3;
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $cima  = ord($rgb[$a + $i]) * (1 - $tx) + ord($rgb[$b + $i]) * $tx;
            $baixo = ord($rgb[$c + $i]) * (1 - $tx) + ord($rgb[$d + $i]) * $tx;
            $out[] = $cima * (1 - $ty) + $baixo * $ty;
        }
        return $out;
    }

    /**
     * Lê um PNG de 8 bits sem entrelaçamento.
     * @return array{0:int,1:int,2:string}|null largura, altura e RGBA (4 bytes por pixel)
     */
    private static function ler(string $png): ?array
    {
        if (strncmp($png, "\x89PNG\r\n\x1a\n", 8) !== 0) return null;
        $pos = 8; $len = strlen($png);
        $w = $h = $tipo = 0; $plte = ''; $trns = ''; $idat = '';
        while ($pos + 8 <= $len) {
            $n     = unpack('N', substr($png, $pos, 4))[1];
            $nome  = substr($png, $pos + 4, 4);
            $dados = substr($png, $pos + 8, $n);
            $pos  += 12 + $n;
            if ($nome === 'IHDR') {
                $ih = unpack('Nw/Nh/Cprof/Ctipo/Ccomp/Cfiltro/Center', $dados);
                if ($ih['prof'] !== 8 || $ih['enter'] !== 0) return null;
                [$w, $h, $tipo] = [$ih['w'], $ih['h'], $ih['tipo']];
            } elseif ($nome === 'PLTE') { $plte = $dados; }
            elseif ($nome === 'tRNS')   { $trns = $dados; }
            elseif ($nome === 'IDAT')   { $idat .= $dados; }
            elseif ($nome === 'IEND')   { break; }
        }
        $canais = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$tipo] ?? 0;
        if ($canais === 0 || $w < 1 || $h < 1 || $w > 2048 || $h > 2048) return null;
        if ($tipo === 3 && $plte === '') return null;
        $bruto = @gzuncompress($idat);
        $passo = $w * $canais;
        if ($bruto === false || strlen($bruto) < ($passo + 1) * $h) return null;

        $rgba = '';
        $ant  = array_fill(0, $passo, 0);
        for ($y = 0, $o = 0; $y < $h; $y++, $o += $passo + 1) {
            $filtro = ord($bruto[$o]);
            $lin    = array_values(unpack('C*', substr($bruto, $o + 1, $passo)));
            for ($i = 0; $i < $passo; $i++) {
                $esq = $i >= $canais ? $lin[$i - $canais] : 0;
                $sup = $ant[$i];
                switch ($filtro) {
                    case 1: $lin[$i] = ($lin[$i] + $esq) & 255; break;
                    case 2: $lin[$i] = ($lin[$i] + $sup) & 255; break;
                    case 3: $lin[$i] = ($lin[$i] + (($esq + $sup) >> 1)) & 255; break;
                    case 4:
                        $diag = $i >= $canais ? $ant[$i - $canais] : 0;
                        $pp = $esq + $sup - $diag;
                        $pa = abs($pp - $esq); $pb = abs($pp - $sup); $pc = abs($pp - $diag);
                        $pred = ($pa <= $pb && $pa <= $pc) ? $esq : ($pb <= $pc ? $sup : $diag);
                        $lin[$i] = ($lin[$i] + $pred) & 255;
                        break;
                }
            }
            $ant = $lin;
            for ($x = 0; $x < $w; $x++) {
                $j = $x * $canais;
                switch ($tipo) {
                    case 6: $rgba .= chr($lin[$j]) . chr($lin[$j + 1]) . chr($lin[$j + 2]) . chr($lin[$j + 3]); break;
                    case 2: $rgba .= chr($lin[$j]) . chr($lin[$j + 1]) . chr($lin[$j + 2]) . "\xFF"; break;
                    case 4: $rgba .= str_repeat(chr($lin[$j]), 3) . chr($lin[$j + 1]); break;
                    case 0: $rgba .= str_repeat(chr($lin[$j]), 3) . "\xFF"; break;
                    case 3:
                        $ix = $lin[$j];
                        $cor = substr($plte, $ix * 3, 3);
                        $rgba .= (strlen($cor) === 3 ? $cor : "\0\0\0") . ($ix < strlen($trns) ? $trns[$ix] : "\xFF");
                        break;
                }
            }
        }
        return [$w, $h, $rgba];
    }

    private static function bloco(string $nome, string $dados): string
    {
        return pack('N', strlen($dados)) . $nome . $dados . pack('N', crc32($nome . $dados));
    }
}
