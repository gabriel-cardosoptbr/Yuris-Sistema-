/* =============================================================================
   origem.js — narrativa "O nascimento do Yuris" (#origem), motion premium.

   Uma jornada de LUZ, disparada uma vez ao entrar no viewport (ScrollTrigger):
     A) cabeçalho + manifesto + o nome YURIS surge (a luz dourada nasce dele);
     B) a luz percorre os três cards (A RAIZ/IUS, A ESTRUTURA/IURIS, A INTELIGÊNCIA/IS);
     C) a luz desce e converge ao centro da composição inferior;
     D) forma um NÚCLEO circular (anel + halo), a luz dourada vira azul;
     E) três feixes saem do núcleo e ATIVAM os três fundamentos (com bloom);
     F) o centro estabiliza com o Y e "Yuris"; a fórmula reforça;
     G) estado vivo: o halo respira (pausa fora de tela / aba oculta).
   Decorativa: estado final = padrão (fallback sem-JS / reduced-motion).
   ========================================================================== */
export function init({ gsap, ScrollTrigger }) {
  const sec = document.querySelector('[data-origem]');
  if (!sec || sec.dataset.origInit) return;
  sec.dataset.origInit = '1';

  const q = (s) => sec.querySelector(s), qa = (s) => [...sec.querySelectorAll(s)];
  const eyebrow = q('.lp2-eyebrow'), title = q('h2'), direito = q('.lp2-orig-direito');
  const manifesto = q('.lp2-origem-manifesto'), strongs = qa('.lp2-origem-manifesto strong');
  const stage = q('[data-orig-stage]'), glyph = q('.lp2-orig-glyph'), word = q('[data-orig-word]'), sub = q('[data-orig-sub]');
  const ins = qa('.ow-ins');
  const etimo = qa('.lp2-etimo-card');
  const pontasTitle = q('.lp2-pontas-title'), pontas = qa('.lp2-ponta');
  const ysvg = q('.lp2-orig-ylines'), ylines = qa('.oy-line');
  const ymark = q('[data-orig-ymark]'), ymono = q('.lp2-orig-ymono'), yname = ymark && ymark.querySelector('span');
  const halo = q('.on-halo'), rings = qa('.on-ring'), core = q('.on-core');
  const equation = q('[data-orig-eq]'), light = q('[data-orig-light]');
  if (!etimo.length) return;

  const GOLD = '#C9A24B';
  const NS = 'http://www.w3.org/2000/svg';
  const wideMQ = window.matchMedia('(min-width: 821px)');
  ins.forEach((el) => { el.style.width = 'auto'; });   // palavra YURIS inteira (sem morph)

  // ── geometria dos feixes (centro do ymark → borda de cada fundamento) + blooms ──
  let drawn = false;
  const blooms = [];
  function edgePoint(jx, jy, rect, sl, st) {
    const cx = rect.left + rect.width / 2 - sl, cy = rect.top + rect.height / 2 - st;
    const dx = cx - jx, dy = cy - jy;
    const hw = rect.width / 2 + 4, hh = rect.height / 2 + 4;
    const tx = dx ? hw / Math.abs(dx) : Infinity, ty = dy ? hh / Math.abs(dy) : Infinity;
    const t = Math.min(tx, ty);
    return { x: cx - dx * t, y: cy - dy * t };
  }
  function layoutY() {
    if (!ysvg || !ymark || !wideMQ.matches) return;
    const r = ysvg.getBoundingClientRect();
    if (!r.width) return;
    ysvg.setAttribute('viewBox', '0 0 ' + r.width + ' ' + r.height);
    const ym = ymark.getBoundingClientRect();
    const jx = ym.left + ym.width / 2 - r.left, jy = ym.top + ym.height / 2 - r.top;
    pontas.forEach((card, i) => {
      const p = ylines[i]; if (!p) return;
      const e = edgePoint(jx, jy, card.getBoundingClientRect(), r.left, r.top);
      p.setAttribute('d', 'M ' + jx.toFixed(1) + ' ' + jy.toFixed(1) + ' L ' + e.x.toFixed(1) + ' ' + e.y.toFixed(1));
      const len = p.getTotalLength();
      p.style.strokeDasharray = len;
      p.style.strokeDashoffset = drawn ? 0 : len;
      if (blooms[i]) { blooms[i].setAttribute('cx', e.x.toFixed(1)); blooms[i].setAttribute('cy', e.y.toFixed(1)); blooms[i]._o = e.x.toFixed(1) + ' ' + e.y.toFixed(1); }
    });
  }
  if (ysvg) pontas.forEach(() => { const b = document.createElementNS(NS, 'circle'); b.setAttribute('class', 'oy-bloom'); b.setAttribute('r', '12'); b.setAttribute('opacity', '0'); ysvg.appendChild(b); blooms.push(b); });
  layoutY();
  let rz; window.addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(layoutY, 160); }, { passive: true });

  // ponto-centro de um elemento, relativo à seção (a luz é filha posicionada da seção)
  const ptOf = (el) => { const r = el.getBoundingClientRect(), s = sec.getBoundingClientRect(); return { x: r.left + r.width / 2 - s.left, y: r.top + r.height / 2 - s.top }; };

  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) { drawn = true; layoutY(); etimo.forEach((c) => c.classList.add('is-lit')); if (light) gsap.set(light, { opacity: 0 }); return; }

  const wide = wideMQ.matches;
  const tl = gsap.timeline({ paused: true, defaults: { ease: 'power3.out' } });

  /* ── ARMADO (tempo 0) ── */
  tl.set(eyebrow, { '--ln': 0, opacity: 0, y: 8 }, 0)
    .set(title, { opacity: 0, y: 18 }, 0)
    .set(manifesto, { opacity: 0, y: 16 }, 0)
    .set(stage, { opacity: 0 }, 0)
    .set(glyph, { opacity: 0, scale: 0.92, rotate: -6 }, 0)
    .set(word, { opacity: 0, y: 10 }, 0)
    .set(sub, { opacity: 0 }, 0)
    .set(etimo, { opacity: 0.5, y: 0 }, 0)
    .set(light, { opacity: 0, xPercent: -50, yPercent: -50, scale: 1 }, 0)
    .set(pontasTitle, { opacity: 0, y: 10 }, 0)
    .set(pontas, { opacity: 0, y: 16, scale: 0.97, transformOrigin: '50% 50%' }, 0)
    .set([ymono, yname].filter(Boolean), { opacity: 0, scale: 0.7, transformOrigin: '50% 50%' }, 0)
    .set(rings, { opacity: 0, scale: 0, svgOrigin: '120 120' }, 0)
    .set(core, { opacity: 0, scale: 0, svgOrigin: '120 120' }, 0)
    .set(halo, { opacity: 0, scale: 0.5, svgOrigin: '120 120' }, 0)
    .set(equation, { opacity: 0, y: 12 }, 0)
    .call(() => etimo.forEach((c) => c.classList.remove('is-lit')));

  /* ── A) cabeçalho + manifesto + o nome surge (a luz nasce dele) ── */
  tl.to(eyebrow, { '--ln': 1, opacity: 1, y: 0, duration: 0.7 }, 0.1)
    .to(title, { opacity: 1, y: 0, duration: 0.7 }, '-=.35')
    .to(direito, { color: GOLD, duration: 0.5, yoyo: true, repeat: 1 }, '-=.2')
    .to(manifesto, { opacity: 1, y: 0, duration: 0.7 }, '-=.4')
    .to(strongs, { textShadow: '0 0 14px rgba(201,162,75,.55)', duration: 0.45, yoyo: true, repeat: 1, stagger: 0.08 }, '-=.3')
    .to(stage, { opacity: 1, duration: 0.5 }, '-=.2')
    .to(glyph, { opacity: 1, scale: 1, rotate: 0, duration: 1.0, ease: 'power2.out' }, '<')
    .to(word, { opacity: 1, y: 0, duration: 0.6 }, '<')
    .to(sub, { opacity: 1, duration: 0.5 }, '<+.2')
    .call(() => word.classList.add('is-sweeping'), null, '>-.1')
    .fromTo(word, { '--sweep': '-60%' }, { '--sweep': '160%', duration: 0.9, ease: 'power1.inOut' }, '<')
    .call(() => word.classList.remove('is-sweeping'));

  /* ── B) a luz dourada percorre os três cards ── */
  tl.set(light, { x: () => ptOf(word).x, y: () => ptOf(word).y }, '>-.2')
    .to(light, { opacity: 0.95, duration: 0.4 }, '<');
  const litCard = (i, finalOp) => {
    tl.to(light, { x: () => ptOf(etimo[i]).x, y: () => ptOf(etimo[i]).y, duration: 0.55, ease: 'power1.inOut' });
    tl.to(etimo[i], { opacity: finalOp, duration: 0.45 }, '<+.12')
      .call(() => etimo[i].classList.add('is-lit'), null, '<');
  };
  litCard(0, 0.82);
  litCard(1, 0.9);
  litCard(2, 1);

  /* ── C) a luz desce e converge ao centro ── */
  tl.to(light, { x: () => ptOf(ymark).x, y: () => ptOf(ymark).y, scale: 0.6, duration: 0.95, ease: 'power2.inOut' }, '>+.1');

  /* ── D) o núcleo se forma; a luz dourada vira azul ── */
  tl.to(halo, { opacity: 1, scale: 1, duration: 0.6, ease: 'power2.out' }, '>-.4')
    .to(light, { opacity: 0, scale: 0.35, duration: 0.5 }, '<')                         // dissolve no núcleo
    .to(rings, { opacity: (i) => (i === 0 ? 0.5 : 0.4), scale: 1, duration: 0.7, stagger: 0.12, ease: 'back.out(1.5)' }, '<+.05')
    .to(core, { opacity: 1, scale: 1, duration: 0.4, ease: 'back.out(2)' }, '<+.25')
    .to([ymono, yname].filter(Boolean), { opacity: 1, scale: 1, duration: 0.5, stagger: 0.08, ease: 'back.out(1.6)' }, '<')
    .to(pontasTitle, { opacity: 1, y: 0, duration: 0.5 }, '<-.35');

  /* ── E) três feixes saem do núcleo e ativam os fundamentos (com bloom) ── */
  if (wide) drawn = true;
  const fireBeam = (i, pos) => {
    if (wide && ylines[i]) tl.to(ylines[i], { strokeDashoffset: 0, duration: 0.55, ease: 'power2.out' }, pos);
    if (wide && blooms[i] && blooms[i]._o) tl.fromTo(blooms[i], { opacity: 0.85, scale: 0.2, svgOrigin: blooms[i]._o }, { opacity: 0, scale: 2.3, duration: 0.55, ease: 'power2.out' }, '>-.12');
    tl.to(pontas[i], { opacity: 1, y: 0, scale: 1, duration: 0.5, ease: 'back.out(1.5)' }, wide ? '>-.34' : (i === 0 ? pos : '>-.3'));
  };
  fireBeam(0, '>-.05');
  fireBeam(1, '>-.12');
  fireBeam(2, '>-.12');

  /* ── F) a fórmula reforça ── */
  tl.to(equation, { opacity: 1, y: 0, duration: 0.6 }, '>-.05');

  /* ── G) estado vivo: o halo respira (pausável) ── */
  const idle = gsap.timeline({ repeat: -1, yoyo: true, paused: true });
  if (halo) idle.to(halo, { opacity: 0.7, scale: 1.06, duration: 2.4, ease: 'sine.inOut' }, 0);
  if (core) idle.to(core, { opacity: 0.6, duration: 2.4, ease: 'sine.inOut' }, 0);

  let inView = false;
  tl.eventCallback('onComplete', () => { if (inView) idle.play(); });
  ScrollTrigger.create({ trigger: sec, start: 'top 72%', once: true, onEnter: () => tl.play() });
  ScrollTrigger.create({
    trigger: sec, start: 'top bottom', end: 'bottom top',
    onToggle: (self) => { inView = self.isActive; if (inView) { if (tl.progress() === 1) idle.play(); } else idle.pause(); },
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { tl.pause(); idle.pause(); }
    else if (inView) { if (tl.progress() < 1) tl.play(); else idle.play(); }
  });
}
