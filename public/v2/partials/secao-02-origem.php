<?php
/**
 * secao-02-origem.php, #origem
 * Seção NOVA: traduz o manual de marca em storytelling (IUS/IURIS → IS,
 * as três pontas do Y). É a "alma romana" que a v1 não contava.
 *
 * Narrativa "O nascimento do Yuris" animada por sections/origem.js (GSAP +
 * ScrollTrigger). Os elementos controlados pela timeline NÃO usam .lp2-reveal
 * (para não competir com o scroll-reveal). Fallback: tudo visível e no estado
 * final por padrão (a animação é melhoria progressiva).
 */
?>
<section id="origem" class="lp2-section lp2-origem" data-origem>
  <div class="lp2-container">
    <div class="lp2-section-head">
      <span class="lp2-eyebrow">A Origem</span>
      <h2>Yuris nasce da raiz do próprio <span class="lp2-orig-direito">Direito</span>.</h2>
    </div>

    <p class="lp2-origem-manifesto">
      Do latim <strong>IUS / IURIS</strong>, Direito, justiça, ordem. A mesma raiz que deu origem a
      <em>jurisprudência</em>, <em>jurisdição</em> e <em>jurista</em>. Assim como o Direito Romano precisou de
      <strong>estrutura</strong> para evoluir, o escritório moderno também precisa.
    </p>

    <!-- Palco conceitual: IUS → IURIS → IS → YURIS (decorativo; conceitos reais nos cards abaixo) -->
    <div class="lp2-orig-stage" data-orig-stage aria-hidden="true">
      <svg class="lp2-orig-glyph" viewBox="0 0 200 200" fill="none" aria-hidden="true">
        <circle cx="100" cy="100" r="80" stroke="currentColor" stroke-width="0.6" opacity=".7"/>
        <circle cx="100" cy="100" r="58" stroke="currentColor" stroke-width="0.4" opacity=".4"/>
        <path d="M100 12 V42 M100 158 V188 M12 100 H42 M158 100 H188" stroke="currentColor" stroke-width="0.7"/>
        <path d="M100 28 L104 96 L100 100 L96 96 Z" fill="currentColor" opacity=".55"/>
      </svg>
      <div class="lp2-orig-word" data-orig-word>
        <span class="ow-l ow-first">Y</span><span class="ow-l">U</span><span class="ow-l ow-ins">R</span><span class="ow-l ow-ins">I</span><span class="ow-l">S</span>
      </div>
      <div class="lp2-orig-sub" data-orig-sub>Direito estruturado por inteligência</div>
    </div>

    <div class="lp2-etimo">
      <div class="lp2-etimo-card" data-etimo="0">
        <span class="lp2-etimo-tag">A raiz</span>
        <div class="lp2-etimo-word">IUS</div>
        <p>Direito, justiça e ordem jurídica, a relação legítima entre as pessoas e o Estado.</p>
      </div>
      <div class="lp2-etimo-card" data-etimo="1">
        <span class="lp2-etimo-tag">A estrutura</span>
        <div class="lp2-etimo-word">IURIS</div>
        <p>A forma como o Direito é organizado e sustentado. Dela nasce a <em>Iuris Prudentia</em>, a sabedoria do Direito.</p>
      </div>
      <div class="lp2-etimo-card" data-etimo="2">
        <span class="lp2-etimo-tag">A inteligência</span>
        <div class="lp2-etimo-word">IS</div>
        <p><strong>Intelligent System.</strong> Tecnologia que deixa de ser ferramenta e passa a ser fundamento.</p>
      </div>
    </div>

    <div class="lp2-pontas">
      <div class="lp2-pontas-title">Um Y. Três fundamentos.</div>

      <!-- Estrela / Y: dois fundamentos no topo (pontas do Y), um embaixo (haste)
           e Yuris no centro (junção). Linhas desenhadas por origem.js (recalc no resize). -->
      <div class="lp2-ystage" data-orig-ystage>
        <svg class="lp2-orig-ylines" preserveAspectRatio="none" aria-hidden="true">
          <path class="oy-line"></path><path class="oy-line"></path><path class="oy-line"></path>
        </svg>
        <div class="lp2-ponta lp2-ponta-tec" data-ponta="0"><h4>Tecnologia</h4><p>Inovação e a construção estrutural da plataforma.</p></div>
        <div class="lp2-ponta lp2-ponta-est" data-ponta="1"><h4>Estratégia</h4><p>Inteligência empresarial e visão de expansão.</p></div>
        <div class="lp2-orig-ymark" data-orig-ymark>
          <svg class="lp2-orig-nucleo" data-orig-nucleo viewBox="0 0 240 240" fill="none" aria-hidden="true">
            <defs>
              <radialGradient id="origHalo" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="#C9A24B" stop-opacity=".42"/>
                <stop offset="40%" stop-color="#5BBCE0" stop-opacity=".15"/>
                <stop offset="100%" stop-color="#5BBCE0" stop-opacity="0"/>
              </radialGradient>
            </defs>
            <circle class="on-halo" cx="120" cy="120" r="104" fill="url(#origHalo)"/>
            <circle class="on-ring on-ring-out" cx="120" cy="120" r="66"/>
            <circle class="on-ring on-ring-in" cx="120" cy="120" r="44"/>
            <circle class="on-core" cx="120" cy="120" r="5"/>
          </svg>
          <svg class="lp2-orig-ymono" viewBox="0 0 40 40" fill="none" aria-hidden="true"><path d="M8 8 L20 22 L32 8 M20 22 V34" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>Yuris</span>
        </div>
        <div class="lp2-ponta lp2-ponta-dir" data-ponta="2"><h4>Auditoria jurídica</h4><p>Legitimidade técnica e a vivência real da advocacia.</p></div>
      </div>

      <div class="lp2-orig-equation" data-orig-eq>
        <span>Tecnologia</span><i>+</i><span>Estratégia</span><i>+</i><span>Direito</span><i>=</i><b>Yuris</b>
      </div>
    </div>
  </div>
  <!-- Luz dourada/azul que conduz a narrativa (revela cards, desce, forma o núcleo). -->
  <div class="lp2-orig-light" data-orig-light aria-hidden="true"></div>
</section>
