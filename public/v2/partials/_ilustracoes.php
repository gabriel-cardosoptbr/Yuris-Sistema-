<?php
/**
 * _ilustracoes.php — ilustrações SVG animadas por módulo (painel da vitrine).
 *
 * lp2_ill($slug) devolve um <svg> decorativo (aria-hidden). As animações são
 * CSS (classes ill-* + delay via --d) e re-disparam sozinhas quando o painel
 * fica visível (o pane sai de display:none → as CSS animations recomeçam).
 * Linguagem visual única; cada módulo conta a sua própria narrativa.
 */
if (!function_exists('lp2_ill')) {
    function lp2_ill(string $slug): string {
        $svg = [

        // PROCESSOS — timeline processual sendo desenhada + documento com histórico
        'v-processos' => '
            <line x1="34" y1="116" x2="266" y2="116" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <line class="ill-draw" style="--d:.25s" x1="34" y1="116" x2="266" y2="116" stroke="var(--lp2-cyan)" stroke-width="2"/>
            <circle class="ill-pop" style="--d:.30s" cx="34"  cy="116" r="7" fill="var(--lp2-bg-1)" stroke="var(--lp2-blue-glow)" stroke-width="2"/>
            <circle class="ill-pop" style="--d:.55s" cx="111" cy="116" r="7" fill="var(--lp2-bg-1)" stroke="var(--lp2-blue-glow)" stroke-width="2"/>
            <circle class="ill-pop" style="--d:.80s" cx="189" cy="116" r="7" fill="var(--lp2-bg-1)" stroke="var(--lp2-blue-glow)" stroke-width="2"/>
            <circle class="ill-pop" style="--d:1.05s" cx="266" cy="116" r="7" fill="var(--lp2-bg-1)" stroke="var(--lp2-gold)" stroke-width="2"/>
            <g class="ill-rise" style="--d:.45s">
              <rect x="116" y="34" width="68" height="50" rx="6" fill="var(--lp2-card)" stroke="var(--lp2-metal)" stroke-width="2"/>
              <line x1="127" y1="50" x2="173" y2="50" stroke="var(--lp2-cyan)" stroke-width="3"/>
              <line x1="127" y1="61" x2="167" y2="61" stroke="var(--lp2-text-mute)" stroke-width="3"/>
              <line x1="127" y1="72" x2="160" y2="72" stroke="var(--lp2-text-mute)" stroke-width="3"/>
            </g>',

        // INTIMAÇÕES — documento oficial chega, é vinculado ao processo, recebe selo
        'v-intimacoes' => '
            <rect x="158" y="58" width="104" height="78" rx="8" fill="var(--lp2-card)" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <text x="210" y="86" fill="var(--lp2-text-mute)" font-family="Inter" font-size="9" text-anchor="middle" letter-spacing="1">PROCESSO</text>
            <line x1="172" y1="104" x2="248" y2="104" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <line x1="172" y1="116" x2="232" y2="116" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <g class="ill-slidein" style="--d:.3s">
              <rect x="46" y="46" width="60" height="76" rx="6" fill="var(--lp2-bg-1)" stroke="var(--lp2-cyan)" stroke-width="2"/>
              <line x1="58" y1="64" x2="94" y2="64" stroke="var(--lp2-cyan)" stroke-width="3"/>
              <line x1="58" y1="76" x2="90" y2="76" stroke="var(--lp2-text-mute)" stroke-width="3"/>
              <line x1="58" y1="88" x2="86" y2="88" stroke="var(--lp2-text-mute)" stroke-width="3"/>
            </g>
            <path class="ill-draw" style="--d:1.05s" d="M108 92 q30 24 56 6" stroke="var(--lp2-gold)" stroke-width="2" fill="none"/>
            <g class="ill-pop" style="--d:1.5s">
              <circle cx="206" cy="58" r="13" fill="var(--lp2-bg-0)" stroke="#4ADE80" stroke-width="2"/>
              <path d="M200 58 l4 4 l8 -9" stroke="#4ADE80" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </g>',

        // TAREFAS E PRAZOS — checklist sendo concluído + arco de prazo
        'v-tarefas' => '
            <g class="ill-rise" style="--d:.2s"><rect x="40" y="40" width="150" height="24" rx="6" fill="var(--lp2-card)" stroke="var(--lp2-border)" stroke-width="1.5"/><rect x="49" y="46" width="12" height="12" rx="3" fill="none" stroke="var(--lp2-cyan)" stroke-width="2"/><path class="ill-draw" style="--d:.6s" d="M51 52 l3 3 l6 -7" stroke="#4ADE80" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/><line x1="72" y1="52" x2="176" y2="52" stroke="var(--lp2-text-mute)" stroke-width="3"/></g>
            <g class="ill-rise" style="--d:.4s"><rect x="40" y="72" width="150" height="24" rx="6" fill="var(--lp2-card)" stroke="var(--lp2-border)" stroke-width="1.5"/><rect x="49" y="78" width="12" height="12" rx="3" fill="none" stroke="var(--lp2-cyan)" stroke-width="2"/><path class="ill-draw" style="--d:.95s" d="M51 84 l3 3 l6 -7" stroke="#4ADE80" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/><line x1="72" y1="84" x2="168" y2="84" stroke="var(--lp2-text-mute)" stroke-width="3"/></g>
            <g class="ill-rise" style="--d:.6s"><rect x="40" y="104" width="150" height="24" rx="6" fill="var(--lp2-card)" stroke="var(--lp2-border)" stroke-width="1.5"/><rect x="49" y="110" width="12" height="12" rx="3" fill="none" stroke="var(--lp2-cyan)" stroke-width="2"/><line x1="72" y1="116" x2="160" y2="116" stroke="var(--lp2-text-mute)" stroke-width="3"/></g>
            <circle cx="238" cy="84" r="24" fill="none" stroke="var(--lp2-border-strong)" stroke-width="4"/>
            <circle class="ill-draw" style="--d:.5s" cx="238" cy="84" r="24" fill="none" stroke="var(--lp2-gold)" stroke-width="4" stroke-linecap="round" transform="rotate(-90 238 84)"/>',

        // PROSPECÇÃO — lead percorrendo o funil até virar oportunidade
        'v-prospeccao' => '
            <path d="M70 44 H230 L196 84 H104 Z" fill="var(--lp2-card)" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <path d="M104 92 H196 L176 126 H124 Z" fill="var(--lp2-card)" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <text x="150" y="69" fill="var(--lp2-text-mute)" font-family="Inter" font-size="9" text-anchor="middle" letter-spacing="1">LEADS</text>
            <text x="150" y="113" fill="var(--lp2-text-mute)" font-family="Inter" font-size="9" text-anchor="middle" letter-spacing="1">CLIENTES</text>
            <circle class="ill-fall" style="--d:.4s" cx="150" cy="40" r="7" fill="var(--lp2-cyan)"/>
            <circle class="ill-pop" style="--d:1.4s" cx="150" cy="144" r="8" fill="none" stroke="var(--lp2-gold)" stroke-width="2.5"/>',

        // COMUNICAÇÃO — mensagem enviada, vinculada ao cliente, confirmada
        'v-comunicacao' => '
            <g class="ill-pop" style="--d:.3s"><path d="M48 50 h96 a8 8 0 0 1 8 8 v26 a8 8 0 0 1 -8 8 h-72 l-16 14 v-14 a8 8 0 0 1 -8 -8 v-26 a8 8 0 0 1 8 -8 z" fill="var(--lp2-card)" stroke="var(--lp2-cyan)" stroke-width="2"/><circle class="ill-breathe" style="--d:.9s" cx="78"  cy="71" r="3.5" fill="var(--lp2-cyan)"/><circle class="ill-breathe" style="--d:1.05s" cx="96" cy="71" r="3.5" fill="var(--lp2-cyan)"/><circle class="ill-breathe" style="--d:1.2s" cx="114" cy="71" r="3.5" fill="var(--lp2-cyan)"/></g>
            <path class="ill-draw" style="--d:1.2s" d="M120 96 q40 26 70 4" stroke="var(--lp2-gold)" stroke-width="2" fill="none"/>
            <g class="ill-rise" style="--d:1.5s"><rect x="180" y="78" width="80" height="44" rx="10" fill="var(--lp2-card)" stroke="var(--lp2-border-strong)" stroke-width="2"/><circle cx="200" cy="100" r="9" fill="none" stroke="var(--lp2-blue-glow)" stroke-width="2"/><line x1="216" y1="95" x2="248" y2="95" stroke="var(--lp2-text-mute)" stroke-width="3"/><line x1="216" y1="106" x2="240" y2="106" stroke="var(--lp2-text-mute)" stroke-width="3"/></g>
            <g class="ill-pop" style="--d:2s"><circle cx="250" cy="64" r="12" fill="var(--lp2-bg-0)" stroke="#4ADE80" stroke-width="2"/><path d="M244 64 l4 4 l8 -9" stroke="#4ADE80" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/></g>',

        // AUTOMAÇÕES — evento nasce no Yuris, percorre conexões, dispara e confirma
        'v-automacoes' => '
            <line x1="64" y1="85" x2="150" y2="48" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <line x1="64" y1="85" x2="150" y2="122" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <line x1="150" y1="48" x2="240" y2="85" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <line x1="150" y1="122" x2="240" y2="85" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <line class="ill-flow" style="--d:.6s" x1="64" y1="85" x2="150" y2="48" stroke="var(--lp2-cyan)" stroke-width="2.5"/>
            <line class="ill-flow" style="--d:1.2s" x1="150" y1="48" x2="240" y2="85" stroke="var(--lp2-gold)" stroke-width="2.5"/>
            <g class="ill-pop" style="--d:.2s"><rect x="40" y="68" width="48" height="34" rx="8" fill="var(--lp2-card-2)" stroke="var(--lp2-blue-glow)" stroke-width="2"/><text x="64" y="89" fill="var(--lp2-blue-glow)" font-family="Inter" font-size="9" font-weight="700" text-anchor="middle">YURIS</text></g>
            <circle class="ill-pop" style="--d:.5s" cx="150" cy="48" r="13" fill="var(--lp2-bg-1)" stroke="var(--lp2-cyan)" stroke-width="2"/>
            <circle class="ill-pop" style="--d:.7s" cx="150" cy="122" r="13" fill="var(--lp2-bg-1)" stroke="var(--lp2-metal)" stroke-width="2"/>
            <g class="ill-pop" style="--d:1.5s"><circle cx="240" cy="85" r="14" fill="var(--lp2-bg-0)" stroke="#4ADE80" stroke-width="2"/><path d="M233 85 l5 5 l9 -10" stroke="#4ADE80" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/></g>',

        // LGPD E AUDITORIA — documento identificado, auditado e protegido por escudo
        'v-lgpd' => '
            <g class="ill-rise" style="--d:.25s"><rect x="60" y="40" width="62" height="80" rx="6" fill="var(--lp2-card)" stroke="var(--lp2-metal)" stroke-width="2"/><line x1="72" y1="58" x2="110" y2="58" stroke="var(--lp2-text-mute)" stroke-width="3"/><line x1="72" y1="70" x2="106" y2="70" stroke="var(--lp2-text-mute)" stroke-width="3"/><line x1="72" y1="82" x2="110" y2="82" stroke="var(--lp2-text-mute)" stroke-width="3"/><line x1="72" y1="94" x2="100" y2="94" stroke="var(--lp2-text-mute)" stroke-width="3"/></g>
            <path class="ill-draw" style="--d:.9s" d="M196 44 l40 14 v26 c0 26 -20 38 -40 46 c-20 -8 -40 -20 -40 -46 v-26 z" fill="rgba(126,184,246,.06)" stroke="var(--lp2-blue-glow)" stroke-width="2.5"/>
            <g class="ill-pop" style="--d:1.6s"><rect x="186" y="84" width="20" height="16" rx="3" fill="var(--lp2-gold)"/><path d="M189 84 v-5 a7 7 0 0 1 14 0 v5" fill="none" stroke="var(--lp2-gold)" stroke-width="2.5"/></g>',

        // MATRIZ E FILIAL — matriz no centro, filiais surgindo e sincronizando
        'v-matriz' => '
            <line x1="150" y1="85" x2="64"  y2="48"  stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <line x1="150" y1="85" x2="64"  y2="122" stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <line x1="150" y1="85" x2="240" y2="85"  stroke="var(--lp2-border-strong)" stroke-width="2"/>
            <line class="ill-flow" style="--d:1s" x1="150" y1="85" x2="64" y2="48" stroke="var(--lp2-cyan)" stroke-width="2.5"/>
            <line class="ill-flow" style="--d:1.2s" x1="150" y1="85" x2="64" y2="122" stroke="var(--lp2-cyan)" stroke-width="2.5"/>
            <line class="ill-flow" style="--d:1.4s" x1="150" y1="85" x2="240" y2="85" stroke="var(--lp2-cyan)" stroke-width="2.5"/>
            <g class="ill-pop" style="--d:.2s"><rect x="128" y="62" width="44" height="46" rx="6" fill="var(--lp2-card-2)" stroke="var(--lp2-gold)" stroke-width="2"/><path d="M150 50 l16 12 H134 Z" fill="var(--lp2-gold)"/></g>
            <rect class="ill-pop" style="--d:.55s" x="44"  y="34"  width="40" height="30" rx="5" fill="var(--lp2-card)" stroke="var(--lp2-blue-glow)" stroke-width="2"/>
            <rect class="ill-pop" style="--d:.75s" x="44"  y="108" width="40" height="30" rx="5" fill="var(--lp2-card)" stroke="var(--lp2-blue-glow)" stroke-width="2"/>
            <rect class="ill-pop" style="--d:.95s" x="220" y="70"  width="40" height="30" rx="5" fill="var(--lp2-card)" stroke="var(--lp2-blue-glow)" stroke-width="2"/>',
        ];
        $inner = $svg[$slug] ?? '';
        if ($inner === '') return '';
        return '<svg class="lp2-ill" viewBox="0 0 300 170" fill="none" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}
