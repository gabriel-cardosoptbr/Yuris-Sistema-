/* =============================================================================
   comparativo.js — "Antes × Com o Yuris".

   • Slider navegável em todos os tamanhos (setas, dots, teclado, swipe).
   • Cada cena tem uma timeline COMPOSTA, em LOOP contínuo:
       1) o "Antes" ganha vida peça por peça;
       2) uma LUZ VERMELHA (o problema) percorre cada ícone do "Antes" e é puxada
          ao núcleo (rosa dos ventos), onde VIRA AZUL; o "Antes" vira cinza;
       3) a solução (Com o Yuris) se monta;
       4) a LUZ AZUL percorre cada ícone da solução.
     Processos no desktop = coreografia rica dedicada (.cmp-scn-h). As demais 6 +
     mobile (incl. Processos vertical .cmp-scn-v) usam a coreografia genérica.
   • Só a cena ATIVA toca; troca de cena reinicia; fora de tela / aba oculta pausa
     (IntersectionObserver + Page Visibility). prefers-reduced-motion: estático.
   ========================================================================== */
export function init(ctx) {
  const gsap = ctx && ctx.gsap;
  const root = document.querySelector('[data-cmp-mg]');
  if (!root || root.dataset.cmpInit) return;
  root.dataset.cmpInit = '1';
  const slides = [...root.querySelectorAll('.cmp-slide')];
  const n = slides.length;
  if (!n) return;
  root.classList.add('is-mg');

  // ── dots ──
  const dotsWrap = root.querySelector('[data-cmp-dots]');
  const dots = [];
  if (dotsWrap) slides.forEach((s, i) => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'cmp-dot';
    b.setAttribute('aria-label', 'Cena ' + (i + 1) + ' de ' + n + ': ' + (s.getAttribute('data-title') || ''));
    b.addEventListener('click', () => go(i));
    dotsWrap.appendChild(b); dots.push(b);
  });

  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const bar = root.querySelector('[data-cmp-bar]');
  const titleEl = root.querySelector('[data-cmp-title]');
  const NS = 'http://www.w3.org/2000/svg';
  const mkEl = (svg, tag, attrs) => { const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); svg.appendChild(e); return e; };

  let mobile = window.matchMedia('(max-width: 720px)').matches;
  let cur = -1, visible = false;
  const sceneTL = new Array(n).fill(null);
  const setProgress = () => { const t = sceneTL[cur]; if (bar) bar.style.transform = 'scaleX(' + (t ? t.progress() : 0) + ')'; };

  const coreCenter = (svg) => {
    const inner = svg.querySelector('.s-core-inner');
    const m = inner && (inner.getAttribute('transform') || '').match(/translate\(\s*([-\d.]+)[ ,]+([-\d.]+)/);
    return m ? { x: parseFloat(m[1]), y: parseFloat(m[2]) } : { x: 480, y: 170 };
  };

  // ── a luz viajante (glow radial, mix-blend screen): vermelha → azul ──
  let lightN = 0;
  function makeLight(svg, c, rid0) {
    let defs = svg.querySelector('defs'); if (!defs) defs = mkEl(svg, 'defs', {});
    const id = lightN++, rid = 'lpr' + id, bid = 'lpb' + id;
    const grad = (gid, col) => {
      const rg = document.createElementNS(NS, 'radialGradient'); rg.setAttribute('id', gid);
      [['0%', col, '0.95'], ['42%', col, '0.34'], ['100%', col, '0']].forEach((s) => {
        const st = document.createElementNS(NS, 'stop'); st.setAttribute('offset', s[0]); st.setAttribute('stop-color', s[1]); st.setAttribute('stop-opacity', s[2]); rg.appendChild(st);
      });
      defs.appendChild(rg);
    };
    grad(rid, '#ec6f63'); grad(bid, '#79c8f0');
    const glow = mkEl(svg, 'circle', { class: 's-fx-light', cx: '0', cy: '0', r: '54', fill: 'url(#' + rid + ')' });
    return { glow, rid, bid };
  }
  // âncoras = principais ícones (descarta a moldura/maior; pode forçar 1 seletor)
  function anchorsOf(parent, count, forceSel) {
    const kids = [...parent.children].filter((e) => { const t = e.tagName.toLowerCase(); return t !== 'defs' && !e.classList.contains('s-fx-light'); });
    const scored = [];
    kids.forEach((e) => { let b; try { b = e.getBBox(); } catch (_) { return; } if (b.width || b.height) scored.push({ el: e, area: b.width * b.height, cx: b.x + b.width / 2, cy: b.y + b.height / 2 }); });
    scored.sort((a, b) => b.area - a.area);
    const pool = scored.slice(1, 1 + count);
    if (forceSel) { const f = parent.querySelector(forceSel); if (f && !pool.some((p) => p.el === f)) { let b; try { b = f.getBBox(); } catch (_) { b = null; } if (b) pool.push({ el: f, area: b.width * b.height, cx: b.x + b.width / 2, cy: b.y + b.height / 2 }); } }
    pool.sort((a, b) => a.cx - b.cx);
    return pool;
  }
  // move a luz de ícone em ícone, acendendo cada um (pulso). Devolve o tempo final.
  function hopThrough(tl, glow, anchors, startT, pulse) {
    const HOP = 0.16; let t = startT;
    anchors.forEach((a, i) => {
      const d = i === 0 ? 0.12 : HOP;
      tl.to(glow, { x: a.cx, y: a.cy, duration: d, ease: i === 0 ? 'power2.out' : 'power1.inOut' }, t);
      tl.to(a.el, { scale: pulse, duration: 0.15, yoyo: true, repeat: 1, transformOrigin: '50% 50%', ease: 'power1.inOut' }, t + 0.02);
      t += d;
    });
    return t;
  }

  /* ═══════════════ Processos (desktop) — coreografia rica ═══════════════ */
  function buildRich(svg) {
    const q = (s) => svg.querySelector(s), qa = (s) => [...svg.querySelectorAll(s)];
    const before = q('.scn-before'), core = q('.scn-core'), after = q('.scn-after');
    if (!before || !core || !after) return null;
    const lChrome = [q('.s-sheet'), q('.s-sheet-head')].filter(Boolean);
    const lWinDots = qa('.scn-before .s-dim2'), lFormulas = qa('.scn-before .s-formula');
    const lGrids = qa('.scn-before .s-grid'), lLbls = qa('.scn-before .s-lbl'), lCells = qa('.scn-before .s-cell');
    const lHL = q('.s-hl'), lSel = q('.s-sel'), lTabs = qa('.scn-before .s-tab');
    const lFolder = q('.s-folder'), lDocs = qa('.scn-before .s-doc'), lLabel = q('.s-label');
    const lDetails = qa('.scn-before .s-doc-fold, .scn-before .s-docline');
    const lAlertAll = [q('.s-alert'), q('.s-alert-i'), q('.s-alert-d')].filter(Boolean);
    const rWin = q('.s-win'), rHead = q('.s-win-head'), rDot3 = qa('.s-dot3');
    const rCards = qa('.s-card'), rTag = q('.s-tag'), rMeta = q('.s-meta');
    const rRects = qa('.scn-after .s-blue, .scn-after .s-bluefaint').filter((e) => e.tagName.toLowerCase() === 'rect');
    const rTLDots = qa('.scn-after circle.s-blue');
    const rAvatar = q('.s-avatar'), rStatus = q('.s-status');
    const rDocMini = q('.s-doc-mini'), rFoldB = q('.s-doc-foldb');
    const rTimeline = q('.s-timeline'), rLink = q('.s-link');
    const rCheckBg = q('.s-check-bg'), rCheck = q('.s-check');
    const cLen = (rCheck && rCheck.getTotalLength) ? rCheck.getTotalLength() : 0;
    const tLen = (rTimeline && rTimeline.getTotalLength) ? rTimeline.getTotalLength() : 0;
    const cs = '645 245', c = { x: 645, y: 245 };
    const leftA = anchorsOf(before, 5, '.s-alert');
    const rightA = anchorsOf(after, 6, '.s-check-bg');
    const { glow, rid, bid } = makeLight(svg);

    const tl = gsap.timeline({ paused: true, repeat: -1, repeatDelay: 0.9, defaults: { ease: 'power2.out' }, onUpdate: setProgress });

    // ── ARMADO ──
    tl.set([before, after], { opacity: 1 }, 0)
      .set(lChrome, { opacity: 0, scale: 0.92, transformOrigin: '50% 60%' }, 0)
      .set(lWinDots, { opacity: 0, scale: 0, transformOrigin: '50% 50%' }, 0)
      .set(lFormulas, { opacity: 0, scaleX: 0, transformOrigin: '0% 50%' }, 0)
      .set(lGrids, { opacity: 0, scaleX: 0, scaleY: 0, transformOrigin: '0% 0%' }, 0)
      .set(lLbls, { opacity: 0, y: -4 }, 0)
      .set(lCells, { opacity: 0, scaleX: 0, transformOrigin: '0% 50%' }, 0)
      .set(lFolder, { opacity: 0, x: 16, rotation: 6, scale: 0.9, transformOrigin: '50% 50%' }, 0)
      .set(lDocs, { opacity: 0, y: 22, scale: 0.9, transformOrigin: '50% 50%' }, 0)
      .set(lLabel, { opacity: 0, scaleX: 0, transformOrigin: '0% 50%' }, 0)
      .set(lDetails, { opacity: 0 }, 0)
      .set(lTabs, { opacity: 0, y: 6 }, 0)
      .set(lSel, { opacity: 0, scale: 0.4, transformOrigin: '50% 50%' }, 0)
      .set(core, { opacity: 0, scale: 0.5, rotation: -45, svgOrigin: cs }, 0)
      .set(glow, { opacity: 0, x: c.x, y: c.y, attr: { fill: 'url(#' + rid + ')' } }, 0)
      .set(rWin, { opacity: 0, scale: 0.94, transformOrigin: '50% 50%' }, 0)
      .set(rHead, { opacity: 0, scaleX: 0, transformOrigin: '0% 50%' }, 0)
      .set(rDot3, { opacity: 0, scale: 0, transformOrigin: '50% 50%' }, 0)
      .set(rCards, { opacity: 0, scale: 0.95, transformOrigin: '50% 50%' }, 0)
      .set(rTag, { opacity: 0, scaleX: 0, transformOrigin: '0% 50%' }, 0)
      .set(rRects, { opacity: 0, x: -12 }, 0)
      .set(rMeta, { opacity: 0, x: 28 }, 0)
      .set(rAvatar, { opacity: 0, scale: 0, transformOrigin: '50% 50%' }, 0)
      .set(rStatus, { opacity: 0, scale: 0.5, transformOrigin: '50% 50%' }, 0)
      .set(rDocMini, { opacity: 0, y: 10 }, 0)
      .set(rFoldB, { opacity: 0 }, 0)
      .set(rLink, { opacity: 0, scaleX: 0, transformOrigin: '0% 50%' }, 0)
      .set(rTLDots, { opacity: 0, scale: 0, transformOrigin: '50% 50%' }, 0);
    if (lHL) tl.set(lHL, { opacity: 0, scaleX: 0, fill: 'rgba(201,120,120,.12)', stroke: 'rgba(201,120,120,.42)', transformOrigin: '0% 50%' }, 0);
    if (lAlertAll.length) tl.set(lAlertAll, { opacity: 0, scale: 0, svgOrigin: '404 246' }, 0);
    if (rCheckBg) tl.set(rCheckBg, { opacity: 0, scale: 0, transformOrigin: '50% 50%' }, 0);
    if (cLen) tl.set(rCheck, { strokeDasharray: cLen, strokeDashoffset: cLen }, 0);
    if (tLen) tl.set(rTimeline, { strokeDasharray: tLen, strokeDashoffset: tLen }, 0);

    /* FASE 1 — a planilha ganha vida, peça por peça */
    tl.to(lChrome, { opacity: 1, scale: 1, duration: 0.3, transformOrigin: '50% 60%' }, 0)
      .to(lWinDots, { opacity: 1, scale: 1, duration: 0.18, stagger: 0.04, transformOrigin: '50% 50%' }, 0.12)
      .to(lFormulas, { opacity: 1, scaleX: 1, duration: 0.26, stagger: 0.06, transformOrigin: '0% 50%' }, 0.16)
      .to(lGrids, { opacity: 1, scaleX: 1, scaleY: 1, duration: 0.26, stagger: 0.02, transformOrigin: '0% 0%' }, 0.2)
      .to(lLbls, { opacity: 1, y: 0, duration: 0.18, stagger: 0.015 }, 0.3)
      .to(lCells, { opacity: 1, scaleX: 1, duration: 0.22, stagger: 0.025, transformOrigin: '0% 50%' }, 0.32)
      .to(lTabs, { opacity: 1, y: 0, duration: 0.2, stagger: 0.04 }, 0.46)
      .to(lFolder, { opacity: 1, x: 0, rotation: 0, scale: 1, duration: 0.4, ease: 'back.out(1.5)', transformOrigin: '50% 50%' }, 0.44)
      .to(lDocs, { opacity: 1, y: 0, scale: 1, duration: 0.36, stagger: 0.12, ease: 'back.out(1.6)', transformOrigin: '50% 50%' }, 0.52)
      .to(lLabel, { opacity: 1, scaleX: 1, duration: 0.28, ease: 'power3.out', transformOrigin: '0% 50%' }, 0.66)
      .to(lDetails, { opacity: 1, duration: 0.18, stagger: 0.012 }, 0.72)
      .to(lSel, { opacity: 1, scale: 1, duration: 0.22, transformOrigin: '50% 50%' }, 0.58)
      .to(lSel, { opacity: 0.35, duration: 0.16, yoyo: true, repeat: 3, ease: 'power1.inOut' }, 0.82);
    if (lHL) tl.to(lHL, { opacity: 1, scaleX: 1, duration: 0.28, transformOrigin: '0% 50%' }, 0.62);
    if (lAlertAll.length) tl.to(lAlertAll, { opacity: 1, scale: 1, duration: 0.3, ease: 'back.out(2.2)', svgOrigin: '404 246' }, 0.7);

    /* FASE 2 — núcleo surge; a LUZ VERMELHA percorre os ícones do Antes */
    const T2 = 1.3;
    tl.to(core, { opacity: 1, scale: 1, rotation: 0, duration: 0.55, ease: 'back.out(1.5)', svgOrigin: cs }, T2);
    let t = T2 + 0.4;
    tl.set(glow, { x: leftA.length ? leftA[0].cx : 445, y: leftA.length ? leftA[0].cy : 250, attr: { fill: 'url(#' + rid + ')' } }, t)
      .to(glow, { opacity: 0.95, duration: 0.16 }, t);
    t = hopThrough(tl, glow, leftA, t, 1.12);
    tl.to(glow, { x: c.x, y: c.y, duration: 0.28, ease: 'power2.in' }, t); t += 0.28;

    /* morph vermelho→azul no núcleo + Antes vira cinza */
    tl.to(core, { scale: 1.22, duration: 0.24, yoyo: true, repeat: 1, svgOrigin: cs, ease: 'power2.inOut' }, t)
      .to(qa('.scn-core .s-core-ring'), { stroke: '#D98A8A', duration: 0.18, yoyo: true, repeat: 1 }, t)
      .set(glow, { attr: { fill: 'url(#' + bid + ')' } }, t + 0.22);
    if (lHL) tl.to(lHL, { fill: 'rgba(150,165,188,.08)', stroke: 'rgba(150,165,188,.34)', duration: 0.5 }, t);
    if (lAlertAll.length) tl.to(lAlertAll, { opacity: 0, scale: 0.4, duration: 0.34, ease: 'power2.in', svgOrigin: '404 246' }, t);
    tl.to(before, { opacity: 0.5, duration: 0.5, ease: 'power2.inOut' }, t + 0.1);
    t += 0.42;

    /* FASE 3 — a interface Yuris se monta, cada peça viva */
    const T4 = t;
    tl.to(rWin, { opacity: 1, scale: 1, duration: 0.32, transformOrigin: '50% 50%' }, T4)
      .to(rHead, { opacity: 1, scaleX: 1, duration: 0.28, transformOrigin: '0% 50%' }, T4 + 0.08)
      .to(rDot3, { opacity: 1, scale: 1, duration: 0.18, stagger: 0.04, transformOrigin: '50% 50%' }, T4 + 0.1)
      .to(rCards, { opacity: 1, scale: 1, duration: 0.3, stagger: 0.1, transformOrigin: '50% 50%' }, T4 + 0.14)
      .to(rTag, { opacity: 1, scaleX: 1, duration: 0.22, transformOrigin: '0% 50%' }, T4 + 0.24)
      .to(rRects, { opacity: 1, x: 0, duration: 0.24, stagger: 0.025 }, T4 + 0.26)
      .to(rMeta, { opacity: 1, x: 0, duration: 0.32, ease: 'power3.out' }, T4 + 0.3)
      .to(rAvatar, { opacity: 1, scale: 1, duration: 0.28, ease: 'back.out(2)', transformOrigin: '50% 50%' }, T4 + 0.46)
      .to(rStatus, { opacity: 1, scale: 1, duration: 0.32, ease: 'back.out(1.8)', transformOrigin: '50% 50%' }, T4 + 0.56)
      .to(rDocMini, { opacity: 1, y: 0, duration: 0.26 }, T4 + 0.54)
      .to(rFoldB, { opacity: 1, duration: 0.18 }, T4 + 0.64)
      .to(rLink, { opacity: 1, scaleX: 1, duration: 0.2, transformOrigin: '0% 50%' }, T4 + 0.4);
    if (tLen) tl.to(rTimeline, { strokeDashoffset: 0, duration: 0.5, ease: 'power2.out' }, T4 + 0.64);
    tl.to(rTLDots, { opacity: 1, scale: 1, duration: 0.2, stagger: 0.1, ease: 'back.out(2)', transformOrigin: '50% 50%' }, T4 + 0.86);
    if (rCheckBg) tl.to(rCheckBg, { opacity: 1, scale: 1, duration: 0.32, ease: 'back.out(2)', transformOrigin: '50% 50%' }, T4 + 1.0);
    if (cLen) tl.to(rCheck, { strokeDashoffset: 0, duration: 0.32, ease: 'power2.out' }, T4 + 1.1);

    /* FASE 4 — a LUZ AZUL percorre cada ícone da solução */
    let tb = T4 + 1.3;
    tb = hopThrough(tl, glow, rightA, tb, 1.1);
    tl.to(glow, { opacity: 0, duration: 0.34, ease: 'sine.in' }, tb); tb += 0.34;

    // dissolve (loop suave)
    tl.to([before, after, core], { opacity: 0, duration: 0.55, ease: 'power2.inOut' }, tb + 0.55);
    return tl;
  }

  /* ═══════════════ Demais cenas e mobile — coreografia genérica ═══════════════ */
  function buildGeneric(svg) {
    const before = svg.querySelector('.scn-before'), core = svg.querySelector('.scn-core'), after = svg.querySelector('.scn-after');
    if (!before || !core || !after) return null;
    const beforeEls = [...before.children];
    const afterAll = [...after.children];
    const afterShapes = afterAll.filter((e) => !e.classList.contains('scn-draw'));
    const hl = before.querySelector('.s-hl');
    const alertEls = [...before.querySelectorAll('.s-alert, .s-alert-i, .s-alert-d')];
    const c = coreCenter(svg);
    const cs = c.x + ' ' + c.y;
    const leftA = anchorsOf(before, 5, '.s-alert');
    const rightA = anchorsOf(after, 6, '.s-check-bg');
    const { glow, rid, bid } = makeLight(svg);

    const nB = beforeEls.length, nA = afterShapes.length;
    const bStag = Math.min(0.05, 0.7 / Math.max(1, nB - 1));
    const aStag = Math.min(0.045, 0.7 / Math.max(1, nA - 1));

    const tl = gsap.timeline({ paused: true, repeat: -1, repeatDelay: 0.8, defaults: { ease: 'power2.out' }, onUpdate: setProgress });

    // ── ARMADO ──
    tl.set([before, after], { opacity: 1 }, 0)
      .set(beforeEls, { opacity: 0, scale: 0.82, transformOrigin: '50% 50%' }, 0)
      .set(afterShapes, { opacity: 0, scale: 0.86, transformOrigin: '50% 50%' }, 0)
      .set(core, { opacity: 0, scale: 0.5, rotation: -40, svgOrigin: cs }, 0)
      .set(glow, { opacity: 0, x: c.x, y: c.y, attr: { fill: 'url(#' + rid + ')' } }, 0);
    if (hl) tl.set(hl, { fill: 'rgba(201,120,120,.12)', stroke: 'rgba(201,120,120,.42)' }, 0);

    /* FASE 1 — o "Antes" ganha vida (cada elemento entra) */
    const f1 = 0.1;
    tl.to(beforeEls, { opacity: 1, scale: 1, duration: 0.4, stagger: bStag, ease: 'back.out(1.3)', transformOrigin: '50% 50%' }, f1);
    const bEnd = f1 + bStag * Math.max(0, nB - 1) + 0.4;

    /* FASE 2 — núcleo surge; a LUZ VERMELHA percorre os ícones do Antes */
    const T2 = bEnd + 0.05;
    tl.to(core, { opacity: 1, scale: 1, rotation: 0, duration: 0.5, ease: 'back.out(1.5)', svgOrigin: cs }, T2);
    let t = T2 + 0.3;
    tl.set(glow, { x: leftA.length ? leftA[0].cx : c.x - 200, y: leftA.length ? leftA[0].cy : c.y, attr: { fill: 'url(#' + rid + ')' } }, t)
      .to(glow, { opacity: 0.95, duration: 0.16 }, t);
    t = hopThrough(tl, glow, leftA, t, 1.12);
    tl.to(glow, { x: c.x, y: c.y, duration: 0.28, ease: 'power2.in' }, t); t += 0.28;

    /* morph vermelho→azul + Antes vira cinza */
    tl.to(core, { scale: 1.22, duration: 0.24, yoyo: true, repeat: 1, svgOrigin: cs, ease: 'power2.inOut' }, t)
      .to(svg.querySelectorAll('.scn-core .s-core-ring'), { stroke: '#D98A8A', duration: 0.18, yoyo: true, repeat: 1 }, t)
      .set(glow, { attr: { fill: 'url(#' + bid + ')' } }, t + 0.22);
    if (hl) tl.to(hl, { fill: 'rgba(150,165,188,.08)', stroke: 'rgba(150,165,188,.34)', duration: 0.5 }, t);
    if (alertEls.length) tl.to(alertEls, { opacity: 0, duration: 0.34, ease: 'power2.in' }, t);
    tl.to(before, { opacity: 0.5, duration: 0.5, ease: 'power2.inOut' }, t + 0.1);
    t += 0.42;

    /* FASE 3 — a solução se monta */
    const aRev = t;
    tl.to(afterShapes, { opacity: 1, scale: 1, duration: 0.4, stagger: aStag, ease: 'back.out(1.2)', transformOrigin: '50% 50%' }, aRev);
    const aEnd = aRev + aStag * Math.max(0, nA - 1) + 0.4;

    /* FASE 4 — a LUZ AZUL percorre cada ícone da solução */
    let tb = aEnd - 0.15;
    tb = hopThrough(tl, glow, rightA, tb, 1.1);
    tl.to(glow, { opacity: 0, duration: 0.32, ease: 'sine.in' }, tb); tb += 0.32;

    // dissolve (loop suave)
    tl.to([before, after, core], { opacity: 0, duration: 0.55, ease: 'power2.inOut' }, tb + 0.5);
    return tl;
  }

  /* ─────────────────── construção / destruição ─────────────────── */
  function pickSvg(slide) { return slide.querySelector(mobile ? '.cmp-scn-v' : '.cmp-scn-h'); }
  function buildAll() {
    if (!gsap || reduce) return;
    slides.forEach((slide, i) => {
      const svg = pickSvg(slide);
      if (!svg) return;
      sceneTL[i] = (i === 0 && !mobile) ? buildRich(svg) : buildGeneric(svg);
    });
  }
  function killAll() {
    sceneTL.forEach((t, i) => { if (t) { t.kill(); sceneTL[i] = null; } });
    slides.forEach((slide) => {
      slide.querySelectorAll('.s-fx-light, .s-fx-red, .scn-fx-token, .s-fx-sweep, defs').forEach((e) => e.remove());
      slide.querySelectorAll('.scn-before *, .scn-after *, .scn-core').forEach((e) => e.removeAttribute('style'));
    });
  }
  buildAll();

  /* ─────────────────── navegação ─────────────────── */
  function go(i) {
    cur = ((i % n) + n) % n;
    if (titleEl) titleEl.textContent = slides[cur].getAttribute('data-title') || '';
    slides.forEach((s, idx) => { const on = idx === cur; s.classList.toggle('is-active', on); s.style.opacity = on ? '1' : '0'; });
    dots.forEach((d, idx) => { const on = idx === cur; d.classList.toggle('is-active', on); if (on) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current'); });
    sceneTL.forEach((t, idx) => { if (t && idx !== cur) t.pause(); });
    const t = sceneTL[cur];
    root.classList.toggle('is-anim', !!t);
    if (t) { t.pause(); t.progress(0); if (visible) t.play(); }
    setProgress();
  }
  const navBtn = (sel, dir) => { const b = root.querySelector(sel); if (b) b.addEventListener('click', () => go(cur + dir)); };
  navBtn('[data-cmp-prev]', -1);
  navBtn('[data-cmp-next]', 1);

  root.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') { go(cur - 1); e.preventDefault(); }
    else if (e.key === 'ArrowRight') { go(cur + 1); e.preventDefault(); }
  });
  let sx = null;
  root.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; }, { passive: true });
  root.addEventListener('touchend', (e) => { if (sx == null) return; const dx = e.changedTouches[0].clientX - sx; sx = null; if (Math.abs(dx) > 50) go(cur + (dx < 0 ? 1 : -1)); }, { passive: true });

  /* ─────────────────── disparo (entra na tela / aba) ─────────────────── */
  if (sceneTL.some(Boolean)) {
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((es) => {
        es.forEach((e) => { visible = e.isIntersecting; const t = sceneTL[cur]; if (visible) { if (t) t.play(); } else if (t) t.pause(); });
      }, { threshold: 0.3 });
      io.observe(root);
    } else { visible = true; }
    document.addEventListener('visibilitychange', () => {
      const t = sceneTL[cur];
      if (document.hidden) { if (t) t.pause(); } else if (visible && t) t.play();
    });
    let rz;
    window.addEventListener('resize', () => {
      clearTimeout(rz);
      rz = setTimeout(() => {
        const nowMobile = window.matchMedia('(max-width: 720px)').matches;
        if (nowMobile !== mobile) { mobile = nowMobile; killAll(); buildAll(); go(cur < 0 ? 0 : cur); }
      }, 220);
    }, { passive: true });
  }

  go(0);
}
