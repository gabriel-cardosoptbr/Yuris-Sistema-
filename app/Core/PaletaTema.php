<?php
namespace App\Core;

/**
 * PALETA DO YURIS (06/11/2026): o escuro do leão e o claro da águia.
 *
 * O PEDIDO. Duas fotos de referência do dono do produto:
 *
 *   escuro  um leão azul-preto de olhos azuis. "Não é um azul, é um azul com
 *           preto." Os botões ganham o azul mais claro dos olhos.
 *   claro   uma águia de penas prateadas e olhos azuis. "O tema claro não tem
 *           que ser branco, tem que ser prateado."
 *
 * A foto do leão foi medida pixel a pixel (TONS_LEAO: a saturação que o pelo tem
 * em cada nível de luz). A da águia não chegou em arquivo, e os tons dela foram
 * lidos da imagem.
 *
 * COMO FUNCIONA. O desenho do sistema tem as cores escritas à mão em dezenas de
 * arquivos. Em vez de reescrever todos, este conversor lê o CSS que já existe e
 * devolve uma folha só com as declarações cuja cor muda, já na cor nova. É o
 * mesmo caminho do App\Master\TemaEscuroCrm, e pelo mesmo motivo: um segundo
 * desenho mantido à mão se desencontra do primeiro a cada mudança.
 *
 * A folha é servida por /api/tema.php e carregada pelo menu lateral SÓ nas contas
 * Yuris. As contas de marca (Fleetiflow, Inovaize, Via Autodoc) têm identidade
 * própria e não recebem nada disto.
 *
 * POR QUE :where(). A folha vem depois das originais. Cada regra gerada tem a
 * MESMA especificidade da original (o prefixo de tema vai dentro de :where(),
 * que vale zero), então ganha dela só por vir depois, e continua perdendo para
 * quem já ganhava. Subir a especificidade pareceria mais simples e quebraria a
 * cascata: `.card` recolorido passaria a vencer `.card.especial`, que não tem
 * cor para recolorir e por isso não é regerado.
 *
 * O QUE NÃO ALCANÇA: cor escrita em atributo style="" e cor posta por JS. Esses
 * pontos ficam no tom antigo até serem tratados um a um.
 */
final class PaletaTema
{
    /** Saturação do pelo do leão em cada nível de luz (HSL), medida na foto. */
    private const TONS_LEAO = [[2.9, 87.0], [6.1, 68.0], [9.0, 56.5], [12.7, 44.6], [18.0, 37.0], [25.1, 31.2], [34.5, 28.4]];

    /** Azul dos olhos: matiz e saturação do botão. */
    private const OLHO_MATIZ = 207.0;
    private const OLHO_SATURACAO = 88.0;

    /** Prata da pena da águia: matiz e saturação das superfícies claras. */
    private const PRATA_MATIZ = 213.0;
    private const PRATA_SATURACAO = 28.0;
    /** Luz do que era branco puro. O resto desce junto, mantendo a ordem. */
    private const PRATA_LUZ_DO_BRANCO = 93.5;

    /** O brilho da pena nos painéis do tema claro, e quem conta como painel. */
    private const BRILHO_DA_PENA = 'linear-gradient(160deg,#F4F6F9 0%,#E9EDF2 46%,#DEE4EC 100%)';
    private const PAINEL = '/card|panel|shell|modal|drawer|sidebar|kpi|popup|-box\b|\bbox\b|-col\b/i';

    /** Folhas de /assets que entram, nesta ordem. As demais são de marca ou do site. */
    private const FOLHAS = [
        'design-system.css', 'yuris-theme.css', 'sidebar.css', 'responsivo.css', 'fog.css', 'avisos.css',
        'tarefas.css', 'relatorios.css', 'crm-fase2.css', 'chat-mobile.css', 'vendedor-em-foco.css',
    ];

    /** Páginas cujo <style> entra. Só telas que a conta Yuris abre com o menu lateral. */
    private const PAGINAS = [
        'includes/sidebar.php', 'dashboard.php', 'planejamento.php', 'prospeccao.php', 'clientes.php', 'tarefas.php',
        'processos.php', 'intimacoes.php', 'juridico.php', 'chat.php', 'chat_interno.php', 'atendimento.php',
        'relatorios.php', 'desempenho.php', 'financas.php', 'usuarios.php', 'escritorios.php', 'agente.php',
        'webhooks.php', 'configuracoes.php',
    ];

    /** Arquivos de origem, na ordem em que entram. Serve também para a versão do cache. */
    public static function fontes(string $publico): array
    {
        $lista = [];
        foreach (self::FOLHAS as $f)  { $lista[] = $publico . '/assets/' . $f; }
        foreach (self::PAGINAS as $p) { $lista[] = $publico . '/' . $p; }
        return array_values(array_filter($lista, 'is_file'));
    }

    /** Muda quando qualquer origem ou o próprio conversor mudam. */
    public static function versao(string $publico): int
    {
        $v = (int) filemtime(__FILE__);
        foreach (self::fontes($publico) as $f) { $v = max($v, (int) filemtime($f)); }
        return $v;
    }

    /**
     * A folha de um tema: 'escuro' (leão) ou 'claro' (águia).
     */
    public static function gerar(string $publico, string $tema): string
    {
        $saida = [];
        $ctxAberto = null;
        foreach (self::fontes($publico) as $arquivo) {
            $css = (string) file_get_contents($arquivo);
            $blocos = str_ends_with($arquivo, '.css') ? [$css] : self::estilosDaPagina($css);
            foreach ($blocos as $bloco) {
                foreach (self::regras($bloco) as $r) {
                    $linha = self::converterRegra($r['sel'], $r['corpo'], $tema);
                    if ($linha === null) continue;
                    $ctx = implode('', array_map(static fn ($c) => $c . '{', $r['ctx']));
                    if ($ctx !== $ctxAberto) {
                        if ($ctxAberto !== null && $ctxAberto !== '') $saida[] = str_repeat('}', substr_count($ctxAberto, '{'));
                        if ($ctx !== '') $saida[] = $ctx;
                        $ctxAberto = $ctx;
                    }
                    $saida[] = $linha;
                }
            }
        }
        if ($ctxAberto !== null && $ctxAberto !== '') $saida[] = str_repeat('}', substr_count($ctxAberto, '{'));
        return implode("\n", $saida) . "\n";
    }

    /* ------------------------------------------------------------- cores -- */

    /**
     * A cor nova, ou null se esta cor não muda.
     *
     * @param int[]  $rgb
     * @param string $tema 'escuro', 'claro' (regra do tema claro) ou 'claro-base'
     *                     (regra sem tema, valendo no claro: só o azul muda)
     * @param string $tipo 'fundo', 'borda', 'texto' ou 'outro'
     * @return int[]|null
     */
    public static function mapear(array $rgb, string $tema, string $tipo): ?array
    {
        // As mesmas cores se repetem centenas de vezes nas folhas.
        static $feitas = [];
        $chave = $tema . $tipo . ($rgb[0] << 16 | $rgb[1] << 8 | $rgb[2]);
        // Guardado dentro de uma lista para o "não muda" (null) também ficar em cache.
        return ($feitas[$chave] ??= [self::calcular($rgb, $tema, $tipo)])[0];
    }

    /** @return int[]|null */
    private static function calcular(array $rgb, string $tema, string $tipo): ?array
    {
        [$h, $s, $l] = self::hsl($rgb);

        if ($tema === 'escuro' && $l < 20 && $s >= 22 && $h >= 198 && $h <= 228) {
            // Azul-marinho vira o azul com preto do pelo: mais escuro, mais para o
            // anil, e com a saturação que a foto tem naquele nível de luz.
            // O fundo desce bem (o preto da juba). O cartão desce menos, senão
            // some no fundo: na foto, o pelo iluminado é que desenha o rosto.
            $luz = max(2.2, $l * (0.78 + max(0.0, min(1.0, ($l - 7) / 10)) * 0.17));
            return self::rgb(233 - min(1.0, $luz / 18) * 5, self::saturacaoDoLeao($luz), $luz);
        }

        if ($tema === 'claro') {
            $neutro = $s <= 14 || ($h >= 195 && $h <= 240 && $s <= 58);
            if ($neutro && $tipo === 'fundo' && $l >= 86) {
                return self::rgb(self::PRATA_MATIZ, self::PRATA_SATURACAO, self::PRATA_LUZ_DO_BRANCO - (100 - $l) * 1.15);
            }
            if ($neutro && $tipo === 'borda' && $l >= 60) {
                return self::rgb(self::PRATA_MATIZ, max($s, 22.0), $l - 6);
            }
            // Texto apagado some no prata: desce um pouco para manter a leitura.
            if ($neutro && $tipo === 'texto' && $l >= 36 && $l <= 72 && $s <= 45) {
                return self::rgb($h, $s, $l - 7);
            }
        }

        // Azul de botão (o aço antigo) vira o azul do olho, mais aceso.
        if ($l >= 20 && $l <= 48 && $s >= 36 && $s < 75 && $h >= 203 && $h <= 221) {
            return self::comLuminancia(self::OLHO_MATIZ, self::OLHO_SATURACAO, self::acender(self::luminancia($rgb), 1.5));
        }

        // Azul de destaque: gira para o azul do olho. Como texto, mantém a
        // luminância (o contraste não muda); como fundo, acende um pouco.
        if ($s >= 70 && $l >= 28 && $l <= 88 && $h > 210 && $h <= 228) {
            $y = self::luminancia($rgb);
            if ($tipo === 'fundo') $y = self::acender($y, 1.25);
            // No prata o texto azul precisa ficar abaixo deste teto para dar contraste 4,5.
            if ($tema !== 'escuro' && $tipo === 'texto') $y = min($y, 0.150);
            return self::comLuminancia(199 + ($h - 210) * (11 / 18), min(100.0, $s + 2), $y);
        }

        return null;
    }

    /**
     * Clareia um fundo azul sem passar do teto em que o texto branco ainda dá
     * contraste 4,5. Quem já está acima do teto fica como está.
     */
    private static function acender(float $y, float $fator): float
    {
        return $y >= 0.175 ? $y : min($y * $fator, 0.175);
    }

    private static function saturacaoDoLeao(float $luz): float
    {
        $t = self::TONS_LEAO;
        if ($luz <= $t[0][0]) return $t[0][1];
        for ($i = 1; $i < count($t); $i++) {
            if ($luz <= $t[$i][0]) {
                $p = ($luz - $t[$i - 1][0]) / ($t[$i][0] - $t[$i - 1][0]);
                return $t[$i - 1][1] + $p * ($t[$i][1] - $t[$i - 1][1]);
            }
        }
        return $t[count($t) - 1][1];
    }

    /** @return float[] matiz 0..360, saturação e luz 0..100 */
    public static function hsl(array $c): array
    {
        [$r, $g, $b] = [$c[0] / 255, $c[1] / 255, $c[2] / 255];
        $mx = max($r, $g, $b); $mn = min($r, $g, $b);
        $l = ($mx + $mn) / 2; $d = $mx - $mn;
        if ($d < 1e-9) return [0.0, 0.0, $l * 100];
        $s = $d / (1 - abs(2 * $l - 1));
        if ($mx === $r)     $h = fmod(($g - $b) / $d, 6);
        elseif ($mx === $g) $h = ($b - $r) / $d + 2;
        else                $h = ($r - $g) / $d + 4;
        $h *= 60; if ($h < 0) $h += 360;
        return [$h, $s * 100, $l * 100];
    }

    /** @return int[] */
    public static function rgb(float $h, float $s, float $l): array
    {
        $s = max(0.0, min(100.0, $s)) / 100; $l = max(0.0, min(100.0, $l)) / 100;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default  => [$c, 0, $x],
        };
        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }

    /** Luminância relativa (WCAG), 0..1. */
    public static function luminancia(array $c): float
    {
        $f = static fn (int $v): float => ($v / 255) <= 0.03928 ? ($v / 255) / 12.92 : (($v / 255 + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
    }

    /** A cor deste matiz e saturação que tem a luminância pedida. @return int[] */
    private static function comLuminancia(float $h, float $s, float $alvo): array
    {
        $min = 0.0; $max = 100.0;
        for ($i = 0; $i < 22; $i++) {
            $meio = ($min + $max) / 2;
            if (self::luminancia(self::rgb($h, $s, $meio)) < $alvo) $min = $meio; else $max = $meio;
        }
        return self::rgb($h, $s, ($min + $max) / 2);
    }

    /* --------------------------------------------------------------- CSS -- */

    private static function converterRegra(string $seletor, string $corpo, string $tema): ?string
    {
        // Regra de marca ou de ambiente: identidade própria, fica como está.
        if (preg_match('/data-ambiente|\.ff-|\.ffc\b|--ff-|--amb-/', $seletor . $corpo)) return null;

        $escuroExplicito = (bool) preg_match('/:not\(\s*\[data-theme=["\']?light["\']?\]\s*\)|\[data-theme=["\']?dark["\']?\]/', $seletor);
        $claro = !$escuroExplicito && (bool) preg_match('/\[data-theme=["\']?light["\']?\]/', $seletor);

        if ($tema === 'escuro') {
            if ($claro) return null;
            $modo = 'escuro';
            $novoSeletor = $escuroExplicito ? $seletor : self::prefixar($seletor, ':not([data-theme="light"])');
        } else {
            if ($escuroExplicito) return null;
            $modo = $claro ? 'claro' : 'claro-base';
            $novoSeletor = $claro ? $seletor : self::prefixar($seletor, '[data-theme="light"]');
        }

        // Painel que era branco liso ganha o brilho da pena: prata mais claro em
        // cima, mais fechado embaixo. Só em painel; linha de lista e campo ficam lisos.
        $painel = $modo === 'claro' && preg_match(self::PAINEL, $seletor);

        $decl = [];
        foreach (self::declaracoes($corpo) as [$prop, $valor]) {
            if (str_contains($valor, '__PHP__')) continue;
            $novo = self::trocarCores($valor, $modo, self::tipoDaPropriedade($prop));
            if ($novo === $valor) continue;
            $p = strtolower($prop);
            if ($painel && ($p === 'background' || $p === 'background-color')
                && preg_match('/^(#fff(?:fff)?|white|rgba?\(\s*255\s*,\s*255\s*,\s*255\s*(?:,\s*1(?:\.0+)?\s*)?\))\s*(!important)?$/i', $valor, $m)) {
                $imp = !empty($m[2]) ? ' !important' : '';
                $decl[] = $prop . ':' . $novo;
                $decl[] = 'background-image:' . self::BRILHO_DA_PENA . $imp;
                continue;
            }
            $decl[] = $prop . ':' . $novo;
        }
        return $decl ? $novoSeletor . '{' . implode(';', $decl) . '}' : null;
    }

    /** Põe a condição do tema em cada seletor da lista, sem mudar a especificidade. */
    private static function prefixar(string $seletores, string $condicao): string
    {
        $saida = [];
        foreach (self::dividir($seletores, ',') as $s) {
            $s = trim($s);
            if ($s === '') continue;
            if (preg_match('/^(html|:root)(?![\w-])/', $s, $m)) {
                $saida[] = $m[1] . ':where(' . $condicao . ')' . substr($s, strlen($m[1]));
            } else {
                $saida[] = ':where(html' . $condicao . ') ' . $s;
            }
        }
        return implode(',', $saida);
    }

    private static function tipoDaPropriedade(string $prop): string
    {
        $p = strtolower($prop);
        if (str_starts_with($p, '--')) {
            if (preg_match('/text|texto|tinta|ink|label|muted|-fg|color$/', $p)) return 'texto';
            return preg_match('/border|borda|linha|line/', $p) ? 'borda' : 'fundo';
        }
        if (str_starts_with($p, 'background')) return 'fundo';
        if (str_starts_with($p, 'border') || str_starts_with($p, 'outline') || $p === 'box-shadow') return 'borda';
        if (in_array($p, ['color', 'fill', 'stroke', '-webkit-text-fill-color', 'caret-color', 'text-shadow'], true)) return 'texto';
        return 'outro';
    }

    public static function trocarCores(string $valor, string $tema, string $tipo): string
    {
        return (string) preg_replace_callback(
            '/#([0-9a-fA-F]{6})([0-9a-fA-F]{2})?(?![0-9a-zA-Z_-])|rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(,\s*[\d.]+%?\s*)?\)/',
            static function (array $m) use ($tema, $tipo): string {
                if (($m[1] ?? '') !== '') {
                    $rgb = [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
                    $novo = self::mapear($rgb, $tema, $tipo);
                    return $novo === null ? $m[0] : sprintf('#%02X%02X%02X', ...$novo) . ($m[2] ?? '');
                }
                $novo = self::mapear([(int) $m[3], (int) $m[4], (int) $m[5]], $tema, $tipo);
                if ($novo === null) return $m[0];
                $alfa = isset($m[6]) ? preg_replace('/\s+/', '', $m[6]) : '';
                return ($alfa !== '' ? 'rgba(' : 'rgb(') . implode(',', $novo) . $alfa . ')';
            },
            $valor
        );
    }

    /** Os <style> de uma página, com o PHP de dentro trocado por um marcador. @return string[] */
    private static function estilosDaPagina(string $php): array
    {
        if (!preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $php, $m)) return [];
        return array_map(static fn (string $css): string => (string) preg_replace('/<\?.*?\?>/s', ' __PHP__ ', $css), $m[1]);
    }

    /**
     * Regras de uma folha, na ordem, com o contexto (@media) de cada uma.
     * Fica de fora o que não se recolore: impressão, @keyframes, @font-face.
     *
     * @return array<int, array{ctx: string[], sel: string, corpo: string}>
     */
    private static function regras(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', ' ', $css);
        $n = strlen($css);
        $regras = [];
        $ctx = [];
        $inicio = 0;
        $i = 0;
        while ($i < $n) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") { $i = self::fimDaString($css, $i); continue; }
            if ($c === ';') { $inicio = $i + 1; $i++; continue; }
            if ($c === '}') { array_pop($ctx); $inicio = $i + 1; $i++; continue; }
            if ($c !== '{') { $i++; continue; }

            $preludio = trim(str_replace('__PHP__', ' ', substr($css, $inicio, $i - $inicio)));
            if (preg_match('/^@(media|supports|container|layer)\b/i', $preludio)) {
                $ctx[] = (string) preg_replace('/\s+/', ' ', $preludio);
                $inicio = $i + 1; $i++;
                continue;
            }
            $fim = self::fimDoBloco($css, $i);
            if ($preludio !== '' && $preludio[0] !== '@' && !preg_grep('/\bprint\b/i', $ctx)) {
                $regras[] = ['ctx' => $ctx, 'sel' => (string) preg_replace('/\s+/', ' ', $preludio), 'corpo' => substr($css, $i + 1, $fim - $i - 1)];
            }
            $i = $fim + 1;
            $inicio = $i;
        }
        return $regras;
    }

    /** @return array<int, array{0: string, 1: string}> propriedade e valor */
    private static function declaracoes(string $corpo): array
    {
        $saida = [];
        foreach (self::dividir($corpo, ';') as $d) {
            $p = strpos($d, ':');
            if ($p === false) continue;
            $prop = trim(substr($d, 0, $p));
            $valor = trim(substr($d, $p + 1));
            if ($prop === '' || $valor === '' || !preg_match('/^-{0,2}[a-zA-Z][\w-]*$/', $prop)) continue;
            $saida[] = [$prop, (string) preg_replace('/\s+/', ' ', $valor)];
        }
        return $saida;
    }

    /** Divide no separador, fora de parênteses e de aspas. @return string[] */
    private static function dividir(string $texto, string $sep): array
    {
        $partes = []; $nivel = 0; $inicio = 0; $n = strlen($texto);
        for ($i = 0; $i < $n; $i++) {
            $c = $texto[$i];
            if ($c === '"' || $c === "'") { $i = self::fimDaString($texto, $i) - 1; continue; }
            if ($c === '(') $nivel++;
            elseif ($c === ')') $nivel = max(0, $nivel - 1);
            elseif ($c === $sep && $nivel === 0) { $partes[] = substr($texto, $inicio, $i - $inicio); $inicio = $i + 1; }
        }
        $partes[] = substr($texto, $inicio);
        return $partes;
    }

    /** Posição logo depois da aspa que fecha a string aberta em $i. */
    private static function fimDaString(string $t, int $i): int
    {
        $aspa = $t[$i]; $n = strlen($t);
        for ($j = $i + 1; $j < $n; $j++) {
            if ($t[$j] === '\\') { $j++; continue; }
            if ($t[$j] === $aspa || $t[$j] === "\n") return $j + 1;
        }
        return $n;
    }

    /** Posição da chave que fecha o bloco aberto em $i. */
    private static function fimDoBloco(string $t, int $i): int
    {
        $nivel = 0; $n = strlen($t);
        for ($j = $i; $j < $n; $j++) {
            $c = $t[$j];
            if ($c === '"' || $c === "'") { $j = self::fimDaString($t, $j) - 1; continue; }
            if ($c === '{') $nivel++;
            elseif ($c === '}' && --$nivel === 0) return $j;
        }
        return $n - 1;
    }
}
