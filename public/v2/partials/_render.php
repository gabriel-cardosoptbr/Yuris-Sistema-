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
        echo '<div class="ys-head' . $cls . ' ys-reveal"><div><span class="ys-eyebrow">' . $eyebrow . '</span><h2 class="ys-h2">' . $h2 . '</h2></div>';
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

if (!function_exists('lp2_rows')) {
    /** Lista numerada com divisórias. */
    function lp2_rows(array $itens): string {
        $html = '<ol class="ys-rows">';
        foreach ($itens as $b) $html .= '<li><span>' . $b . '</span></li>';
        return $html . '</ol>';
    }
}
