/* =============================================================================
   cursor-glow.js — iluminação ambiente que acompanha o cursor (cursor spotlight).
   Melhoria progressiva, 100% decorativa. Só liga com mouse fino + hover e fora
   de prefers-reduced-motion; sem JS nada é criado (fallback limpo, o brilho
   estático de body.lp2::before permanece). Camadas em z-index:-1 (atrás do
   conteúdo, sobre o fundo), pointer-events:none — nunca bloqueia nada.

   Movimento via gsap.quickTo (transform/GPU, uma atualização por frame).
   Partículas num <canvas> leve (cap absoluto, sem crescimento de DOM).
   ========================================================================== */
export function initCursorGlow({ gsap }) {
  const fine = window.matchMedia('(hover: hover) and (pointer: fine)');
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (!fine.matches || reduce.matches) return;          // só desktop com mouse
  if (document.querySelector('.lp2-glow-layer')) return; // evita init duplicado

  let w = window.innerWidth, h = window.innerHeight;

  /* ── camadas (criadas via JS → fallback sem-JS limpo) ── */
  const layer = document.createElement('div');
  layer.className = 'lp2-glow-layer';
  layer.setAttribute('aria-hidden', 'true');
  const fog = document.createElement('div'); fog.className = 'lp2-glow-fog';
  const main = document.createElement('div'); main.className = 'lp2-glow-main';
  const canvas = document.createElement('canvas'); canvas.className = 'lp2-glow-particles';
  layer.append(fog, main, canvas);
  document.body.appendChild(layer);

  gsap.set([main, fog], { xPercent: -50, yPercent: -50, x: w / 2, y: h / 2 });

  /* ── perseguição suave: luz rápida, névoa lenta (inércia) ── */
  const mainX = gsap.quickTo(main, 'x', { duration: 0.45, ease: 'power3' });
  const mainY = gsap.quickTo(main, 'y', { duration: 0.45, ease: 'power3' });
  const fogX = gsap.quickTo(fog, 'x', { duration: 1.1, ease: 'power3' });
  const fogY = gsap.quickTo(fog, 'y', { duration: 1.1, ease: 'power3' });

  /* ── rosa dos ventos: reação discreta (parallax + leve rotação) ── */
  const aura = document.querySelector('.lp2-hero-aura');
  let auraX, auraY, auraR;
  if (aura) {
    gsap.set(aura, { yPercent: -50 });                  // preserva o centramento
    auraX = gsap.quickTo(aura, 'x', { duration: 1.3, ease: 'power2' });
    auraY = gsap.quickTo(aura, 'y', { duration: 1.3, ease: 'power2' });
    auraR = gsap.quickTo(aura, 'rotation', { duration: 1.6, ease: 'power2' });
  }

  /* ── partículas (canvas leve) ── */
  const ctx = canvas.getContext('2d');
  let dpr = 1;
  function sizeCanvas() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  sizeCanvas();

  const MAX = 12;
  const COLORS = ['126,184,246', '91,188,224', '201,162,75']; // azul, ciano, dourado
  const parts = [];
  let raf = 0, running = false, nowMs = 0;
  let lastSX = w / 2, lastSY = h / 2, lastST = -1e9;

  function spawn(x, y) {
    if (parts.length >= MAX) return;
    const ang = Math.random() * Math.PI * 2;
    const spd = 0.06 + Math.random() * 0.11;            // px/ms, lento
    parts.push({
      x: x + (Math.random() - 0.5) * 28, y: y + (Math.random() - 0.5) * 28,
      vx: Math.cos(ang) * spd, vy: Math.sin(ang) * spd - 0.02, // leve subida
      r: 1 + Math.random() * 3, life: 0, ttl: 1100 + Math.random() * 900,
      c: COLORS[(Math.random() * COLORS.length) | 0],
    });
    ensureLoop();
  }
  function maybeSpawn(x, y, t) {
    const dx = x - lastSX, dy = y - lastSY;
    if (dx * dx + dy * dy < 52 * 52) return;             // distância mínima
    if (t - lastST < 150) return;                        // limite temporal
    lastSX = x; lastSY = y; lastST = t;
    if (Math.random() < 0.8) spawn(x, y);
  }
  function frame(t) {
    raf = 0;
    if (!nowMs) nowMs = t;
    const dt = Math.min(t - nowMs, 50); nowMs = t;
    ctx.clearRect(0, 0, w, h);
    for (let i = parts.length - 1; i >= 0; i--) {
      const p = parts[i];
      p.life += dt;
      if (p.life >= p.ttl) { parts.splice(i, 1); continue; }
      p.x += p.vx * dt; p.y += p.vy * dt;
      const a = Math.sin((p.life / p.ttl) * Math.PI) * 0.5; // fade in/out, sutil
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(' + p.c + ',' + a.toFixed(3) + ')';
      ctx.fill();
    }
    if (parts.length && document.visibilityState === 'visible') raf = requestAnimationFrame(frame);
    else running = false;
  }
  function ensureLoop() {
    if (!running && document.visibilityState === 'visible') {
      running = true; nowMs = 0; raf = requestAnimationFrame(frame);
    }
  }

  /* ── glow local nos cards/demos: injeta uma camada de luz (sem pseudo) ── */
  document.querySelectorAll('.lp2-card, .lp2-demo, .lp2-etimo-card, .lp2-ponta').forEach((el) => {
    const g = document.createElement('i');
    g.className = 'lp2-litglow';
    g.setAttribute('aria-hidden', 'true');
    el.insertBefore(g, el.firstChild);
  });
  let cardEl = null, cardRaf = 0, cpx = 0, cpy = 0;
  function applyCardVars() {
    cardRaf = 0;
    if (!cardEl) return;
    const r = cardEl.getBoundingClientRect();
    if (!r.width || !r.height) return;
    cardEl.style.setProperty('--px', (((cpx - r.left) / r.width) * 100).toFixed(1) + '%');
    cardEl.style.setProperty('--py', (((cpy - r.top) / r.height) * 100).toFixed(1) + '%');
  }

  /* ── visibilidade do glow ── */
  let on = false;
  const show = () => { if (!on) { on = true; layer.classList.add('is-on'); } };
  const hide = () => { if (on) { on = false; layer.classList.remove('is-on'); } };

  /* ── handler único de movimento ── */
  function onMove(e) {
    const x = e.clientX, y = e.clientY;
    show();
    mainX(x); mainY(y);
    fogX(x + 12); fogY(y + 18);
    if (aura) {
      const nx = x / w - 0.5, ny = y / h - 0.5;
      auraX(nx * 10); auraY(ny * 10); auraR(nx * 2.4);
    }
    maybeSpawn(x, y, e.timeStamp);
    const c = e.target && e.target.closest ? e.target.closest('.lp2-card, .lp2-demo, .lp2-etimo-card, .lp2-ponta') : null;
    cardEl = c;
    if (cardEl) { cpx = x; cpy = y; if (!cardRaf) cardRaf = requestAnimationFrame(applyCardVars); }
  }

  /* ── eventos ── */
  window.addEventListener('pointermove', onMove, { passive: true });
  document.addEventListener('mouseleave', hide);
  window.addEventListener('blur', hide);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') { if (parts.length) ensureLoop(); }
    else if (raf) { cancelAnimationFrame(raf); raf = 0; running = false; }
  });
  let rz = 0;
  window.addEventListener('resize', () => {
    if (rz) cancelAnimationFrame(rz);
    rz = requestAnimationFrame(() => { w = window.innerWidth; h = window.innerHeight; sizeCanvas(); });
  }, { passive: true });
}
