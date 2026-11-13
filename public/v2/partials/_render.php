<?php
/**
 * _render.php, helpers de renderização da home.
 * Incluído por index.php (require_once) antes do loop de seções.
 *
 * Os nomes lp2_* ficaram: a home guarda o texto nos partials e são eles que
 * chamam estes helpers. Só a marcação mudou para o design system ys-.
 */

if (!function_exists('lp2_check')) {
    function lp2_check(): string {
        return ys_check();
    }
}

if (!function_exists('lp2_wa_btn')) {
    /** Botão de WhatsApp. $cls: variante (ex.: 'ys-btn ys-btn-light'). */
    function lp2_wa_btn(string $msg, string $label, string $cls = 'ys-btn'): string {
        return ys_wa_btn($msg, $label, $cls);
    }
}

if (!function_exists('lp2_head')) {
    /**
     * Cabeçalho de seção. $layout: 'split' (título à esquerda, apoio à direita),
     * 'center' ou '' (empilhado à esquerda).
     */
    function lp2_head(string $eyebrow, string $h2, string $sub = '', string $layout = 'split'): void {
        $cls = $layout === '' ? '' : ' is-' . $layout;
        if ($sub === '') $cls = $layout === 'center' ? ' is-center' : '';
        echo '<div class="ys-head' . $cls . ' ys-reveal"><div><h2 class="ys-h2">' . $h2 . '</h2></div>';
        if ($sub !== '') echo '<div><p class="ys-lede">' . $sub . '</p></div>';
        echo '</div>';
    }
}

if (!function_exists('lp2_checks')) {
    /** Lista de verificação a partir de um array de itens. */
    function lp2_checks(array $itens): string {
        $html = '<ul class="ys-checks">';
        foreach ($itens as $b) $html .= '<li>' . ys_check() . '<span>' . $b . '</span></li>';
        return $html . '</ul>';
    }
}

if (!function_exists('lp2_spot')) {
    /**
     * Destaque: texto de um lado, ilustração do recurso do outro (lp2_scene, em _cenas.php).
     * $s: id, fundo ('' | 'ys-bg-2' | 'ys-bg-tint'), flip (tela à esquerda), h2, lede,
     * itens (lista de verificação), wa [mensagem, rótulo], cena (nome da ilustração) e
     * ancoras (ids antigos que continuam valendo como endereço).
     */
    function lp2_spot(array $s): void
    {
        $cls = trim('ys-section ' . ($s['fundo'] ?? ''));
        echo '<section id="' . $s['id'] . '" class="' . $cls . '">';
        foreach (($s['ancoras'] ?? []) as $a) echo '<span id="' . $a . '"></span>';
        echo '<div class="ys-container"><div class="ys-spot' . (!empty($s['flip']) ? ' is-flip' : '') . '">';
        echo '<div class="ys-spot-text ys-reveal"><h2 class="ys-h2">' . $s['h2'] . '</h2><p class="ys-lede">' . $s['lede'] . '</p>';
        echo lp2_checks($s['itens']);
        echo '<div class="ys-cta-row ys-mt">' . lp2_wa_btn($s['wa'][0], $s['wa'][1]) . '</div></div>';
        echo '<div class="ys-spot-art">' . lp2_scene($s['cena']) . '</div>';
        echo '</div></div></section>';
    }
}
