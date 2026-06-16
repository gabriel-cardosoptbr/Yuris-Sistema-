<?php
/**
 * _render.php, helpers de renderização da landing v2.
 * Incluído por index.php (require_once) antes do loop de seções.
 * Mantém os partials enxutos e o visual coeso entre as seções.
 */

if (!function_exists('lp2_check')) {
    function lp2_check(): string {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    }
}

if (!function_exists('lp2_wa_btn')) {
    function lp2_wa_btn(string $msg, string $label): string {
        global $waSvg;
        return '<a href="' . wa($msg) . '" target="_blank" rel="noopener" class="lp2-btn lp2-btn-primary lp2-btn-wa">'
            . $waSvg . ' ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}

if (!function_exists('lp2_head')) {
    function lp2_head(string $eyebrow, string $h2, string $sub = ''): void {
        echo '<div class="lp2-section-head lp2-reveal"><span class="lp2-eyebrow">' . $eyebrow . '</span><h2>' . $h2 . '</h2>';
        if ($sub !== '') echo '<p>' . $sub . '</p>';
        echo '</div>';
    }
}

if (!function_exists('lp2_split')) {
    /**
     * Seção "split": texto (eyebrow, h2, sub, bullets, CTA) + visual com glyph.
     * $s = ['id','eyebrow','h2','sub','bullets'=>[],'glyph'=>svgInner,'wa','cta','reverse'=>bool]
     */
    function lp2_split(array $s): void {
        $rev   = !empty($s['reverse']) ? ' reverse' : '';
        $check = lp2_check();
        echo '<section id="' . $s['id'] . '" class="lp2-section"><div class="lp2-container">';
        echo '<div class="lp2-split' . $rev . ' lp2-reveal"><div class="lp2-split-text">';
        echo '<span class="lp2-eyebrow">' . $s['eyebrow'] . '</span><h2>' . $s['h2'] . '</h2><p>' . $s['sub'] . '</p>';
        echo '<ul class="lp2-split-bullets">';
        foreach ($s['bullets'] as $b) echo '<li>' . $check . '<span>' . $b . '</span></li>';
        echo '</ul>' . lp2_wa_btn($s['wa'], $s['cta']) . '</div>';
        if (!empty($s['scene']) && function_exists('lp2_scene')) {
            // painel = mini-demonstração de produto (cena animada) no lugar do glyph
            echo '<div class="lp2-split-visual lp2-demo-wrap">' . lp2_scene($s['scene']) . '</div>';
        } else {
            echo '<div class="lp2-split-visual"><div class="lp2-glyph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">' . $s['glyph'] . '</svg></div></div>';
        }
        echo '</div></div></section>';
    }
}
