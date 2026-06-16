/* =============================================================================
   demos.js — controller das mini-demonstrações de produto.
   Cada [data-feature-scene] monta uma cena de INTERFACE que roda sozinha:
   ao entrar na viewport ela é executada automaticamente e fica em loop, com
   um respiro entre as repetições. Sem cursor, sem clique, sem botão — é uma
   interface viva, não um tutorial. Mostra a FUNCIONALIDADE que o sistema
   entrega (prazo calculado, tarefa criada, vínculos), nunca o motor interno.
   reduced-motion: nem arma → estado final estático já visível (fallback).
   ========================================================================== */
export function init({ gsap, ScrollTrigger }) {
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;                       // estado final já visível (CSS)

  const builders = {
    juridico: buildJuridico,
    intimacoes: buildIntimacoes,
    comunicacao: buildComunicacao,
    operacao: buildOperacao,
    gestao: buildGestao,
    automacoes: buildAutomacoes,
    vinculos: buildVinculos,
    dashboards: buildDashboards,
    showcase: buildShowcase,
  };

  document.querySelectorAll('[data-feature-scene]').forEach((stage) => {
    const build = builders[stage.getAttribute('data-feature-scene')];
    if (!build) return;

    stage.classList.add('is-armed');        // esconde os .dm-el (CSS)
    let tl;
    try { tl = build(stage, gsap); }
    catch (e) { console.warn('[lp2 demo]', e); stage.classList.remove('is-armed'); return; }

    // Toca sozinha ao entrar na tela; pausa quando sai (poupa CPU) e retoma.
    ScrollTrigger.create({
      trigger: stage, start: 'top 82%', end: 'bottom 18%',
      onEnter:     () => tl.play(),
      onEnterBack: () => tl.play(),
      onLeave:     () => tl.pause(),
      onLeaveBack: () => tl.pause(),
    });
  });

  initTabScenes({ gsap, ScrollTrigger });
}

/* ---- Cena Jurídico: o processo se monta e a intimação é capturada sozinha ----
   processo → linha do tempo → intimação destacada automaticamente → prazo e
   tarefa gerados → vínculos. Em loop, com respiro entre as repetições. */
function buildJuridico(stage, gsap) {
  const q  = (s) => stage.querySelector(s);
  const qa = (s) => stage.querySelectorAll(s);
  const proc   = q('.dm-proc');
  const items  = qa('.dm-timeline .dm-el');
  const hot    = q('[data-hot]');
  const detail = q('[data-detail]');
  const badges = qa('.dm-badges .dm-el');

  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true, repeat: -1, repeatDelay: 2.8 });

  // Estados iniciais no tempo 0 → resetam a cada volta do loop.
  tl.set(proc,   { opacity: 0, y: 16 }, 0)
    .set(items,  { opacity: 0, x: -10 }, 0)
    .set(hot,    { backgroundColor: 'rgba(201,162,75,0)' }, 0)
    .set(detail, { opacity: 0, y: -6 }, 0)
    .set(badges, { opacity: 0, scale: 0.82 }, 0)
    // sequência (tudo automático, sem interação)
    .to(proc,   { opacity: 1, y: 0, duration: 0.55 })
    .to(items,  { opacity: 1, x: 0, duration: 0.4, stagger: 0.16 }, '-=.05')
    .to(hot,    { backgroundColor: 'rgba(201,162,75,.20)', duration: 0.24 }, '-=.02')  // captura
    .to(hot,    { backgroundColor: 'rgba(201,162,75,.07)', duration: 0.5 })            // assenta destacada
    .to(detail, { opacity: 1, y: 0, duration: 0.45 }, '-=.2')
    .to(badges, { opacity: 1, scale: 1, duration: 0.35, stagger: 0.1, ease: 'back.out(1.6)' }, '+=.1');

  return tl;
}

/* ---- Cena Intimações: monitoramento captura intimações e cria tarefas sozinho ---- */
function buildIntimacoes(stage, gsap) {
  const monitor = stage.querySelector('.dm-monitor');
  const cards   = stage.querySelectorAll('.dm-intim');
  const metas   = stage.querySelectorAll('.dm-meta');
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true, repeat: -1, repeatDelay: 2.8 });
  tl.set(monitor, { opacity: 0, y: -8 }, 0)
    .set(cards,   { opacity: 0, y: 16 }, 0)
    .set(metas,   { opacity: 0, scale: 0.82 }, 0)
    .to(monitor,  { opacity: 1, y: 0, duration: 0.4 })
    .to(cards,    { opacity: 1, y: 0, duration: 0.45, stagger: 0.26 })
    .to(metas,    { opacity: 1, scale: 1, duration: 0.32, stagger: 0.26, ease: 'back.out(1.6)' }, '-=.6');
  return tl;
}

/* ---- Cena Comunicação: a conversa se monta e se vincula ao processo sozinha ---- */
function buildComunicacao(stage, gsap) {
  const els = stage.querySelectorAll('.dm-el');
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true, repeat: -1, repeatDelay: 3 });
  tl.set(els, { opacity: 0, y: 12 }, 0)
    .to(els,  { opacity: 1, y: 0, duration: 0.45, stagger: 0.5 });
  return tl;
}

/* ---- Cena Operação: o lead percorre o funil e vira processo, sozinho ---- */
function buildOperacao(stage, gsap) {
  const cols = stage.querySelectorAll('.dm-col');
  const lead = stage.querySelector('.dm-lead');
  const done = stage.querySelector('.dm-funnel-done');
  const r0 = cols[0].getBoundingClientRect();
  const xs = [...cols].map((c) => c.getBoundingClientRect().left - r0.left);

  const tl = gsap.timeline({ defaults: { ease: 'power2.inOut' }, paused: true, repeat: -1, repeatDelay: 2.2 });
  tl.set(lead, { opacity: 0, x: xs[0], y: 12, borderColor: '#5BBCE0' }, 0)
    .set(done, { opacity: 0, y: 6 }, 0)
    .to(lead,  { opacity: 1, y: 0, duration: 0.4, ease: 'power3.out' })
    .to(lead,  { x: xs[1], duration: 0.5 }, '+=.45')
    .to(lead,  { x: xs[2], duration: 0.5 }, '+=.45')
    .to(lead,  { x: xs[3], duration: 0.5 }, '+=.45')
    .to(lead,  { borderColor: '#6EE7A0', duration: 0.3 }, '-=.1')
    .to(done,  { opacity: 1, y: 0, duration: 0.4 }, '+=.05');
  return tl;
}

/* ---- Cena Gestão: o escopo (matriz/filial/advogado) alterna e os números mudam ---- */
function buildGestao(stage, gsap) {
  const q = (s) => stage.querySelector(s);
  const seg   = q('.dm-seg');
  const segs  = stage.querySelectorAll('.dm-seg-i');
  const thumb = q('.dm-seg-thumb');
  const k = { proc: q('[data-k="proc"]'), prazo: q('[data-k="prazo"]'), adv: q('[data-k="adv"]') };
  const data = [ { proc: 248, prazo: 31, adv: 12 }, { proc: 96, prazo: 12, adv: 5 }, { proc: 34, prazo: 4, adv: 1 } ];
  const num = { proc: 248, prazo: 31, adv: 12 };
  const xAt = (i) => i * ((seg.clientWidth - 8) / 3);
  const render = () => { k.proc.textContent = Math.round(num.proc); k.prazo.textContent = Math.round(num.prazo); k.adv.textContent = Math.round(num.adv); };
  const activate = (i) => segs.forEach((s, idx) => s.classList.toggle('is-on', idx === i));

  const tl = gsap.timeline({ paused: true, repeat: -1, repeatDelay: 0.6 });
  tl.call(() => { Object.assign(num, data[0]); render(); activate(0); }, null, 0)
    .set(thumb, { x: xAt(0) }, 0)
    .to(thumb, { x: xAt(1), duration: 0.5, ease: 'power3.inOut', onStart: () => activate(1) }, 1.6)
    .to(num,   { proc: data[1].proc, prazo: data[1].prazo, adv: data[1].adv, duration: 0.6, onUpdate: render }, 1.6)
    .to(thumb, { x: xAt(2), duration: 0.5, ease: 'power3.inOut', onStart: () => activate(2) }, 3.6)
    .to(num,   { proc: data[2].proc, prazo: data[2].prazo, adv: data[2].adv, duration: 0.6, onUpdate: render }, 3.6)
    .to(thumb, { x: xAt(0), duration: 0.5, ease: 'power3.inOut', onStart: () => activate(0) }, 5.6)
    .to(num,   { proc: data[0].proc, prazo: data[0].prazo, adv: data[0].adv, duration: 0.6, onUpdate: render }, 5.6)
    .to({}, { duration: 0.7 }, 6.2);
  return tl;
}

/* ---- Cena Automações: um evento dispara ações nos outros sistemas, sozinho ---- */
function buildAutomacoes(stage, gsap) {
  const event = stage.querySelector('.dm-event');
  const nodes = stage.querySelectorAll('.dm-node');
  const flag  = stage.querySelector('.dm-flag');
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true, repeat: -1, repeatDelay: 2.6 });
  tl.set(event, { opacity: 0, y: -10, scale: 0.96 }, 0)
    .set(nodes, { opacity: 0, y: 12, borderColor: 'rgba(126,184,246,.22)', boxShadow: '0 0 0 rgba(91,188,224,0)' }, 0)
    .set(flag,  { opacity: 0, y: 8 }, 0)
    .to(event,  { opacity: 1, y: 0, scale: 1, duration: 0.45 })
    .to(nodes,  { opacity: 1, y: 0, duration: 0.4, stagger: 0.22 }, '+=.1')
    .to(nodes,  { borderColor: '#5BBCE0', boxShadow: '0 0 18px rgba(91,188,224,.35)', duration: 0.32, stagger: 0.22 }, '-=.45')
    .to(flag,   { opacity: 1, y: 0, duration: 0.4 }, '+=.05');
  return tl;
}

/* ---- Cena Vínculos: a estrutura se monta e o processo é vinculado ao advogado certo ---- */
function buildVinculos(stage, gsap) {
  const matriz   = stage.querySelector('.dm-matriz');
  const branches = stage.querySelectorAll('.dm-branch');
  const filiais  = [...branches[0].querySelectorAll('.dm-onode')];
  const advs     = [...branches[1].querySelectorAll('.dm-onode')];
  const bind     = stage.querySelector('.dm-bind');
  const advFirst = advs[0];
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true, repeat: -1, repeatDelay: 2.8 });
  tl.set(matriz,   { opacity: 0, y: -10, scale: 0.9 }, 0)
    .set(filiais,  { opacity: 0, y: 12 }, 0)
    .set(advs,     { opacity: 0, y: 12 }, 0)
    .set(bind,     { opacity: 0, y: 12 }, 0)
    .set(advFirst, { boxShadow: '0 0 0 rgba(91,188,224,0)' }, 0)
    .to(matriz,   { opacity: 1, y: 0, scale: 1, duration: 0.5 })
    .to(filiais,  { opacity: 1, y: 0, duration: 0.45, stagger: 0.18 }, '+=.1')
    .to(advs,     { opacity: 1, y: 0, duration: 0.45, stagger: 0.18 }, '+=.1')
    .to(bind,     { opacity: 1, y: 0, duration: 0.45 }, '+=.15')
    .to(advFirst, { boxShadow: '0 0 16px rgba(91,188,224,.55)', duration: 0.45 }, '-=.2');
  return tl;
}

/* ---- Cena Dashboards: indicadores sobem contando e o gráfico se desenha sozinho ---- */
function buildDashboards(stage, gsap) {
  const qa = (s) => stage.querySelectorAll(s);
  const stats = qa('.dm-stat');
  const nums = [...stats].map((s) => s.querySelector('b'));
  const chart = stage.querySelector('.dm-chart');
  const bars = qa('.dm-bar > b');
  const targets = nums.map((n) => parseInt(n.getAttribute('data-c'), 10) || 0);
  const prox = targets.map(() => ({ v: 0 }));
  const renderNums = () => nums.forEach((n, i) => { n.textContent = Math.round(prox[i].v); });

  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true, repeat: -1, repeatDelay: 2.8 });
  tl.call(() => { prox.forEach((p) => { p.v = 0; }); renderNums(); }, null, 0)
    .set(stats, { opacity: 0, y: 14 }, 0)
    .set(chart, { opacity: 0, y: 14 }, 0)
    .set(bars,  { scaleY: 0 }, 0)
    .to(stats,  { opacity: 1, y: 0, duration: 0.45, stagger: 0.12 });
  nums.forEach((n, i) => tl.to(prox[i], { v: targets[i], duration: 0.7, onUpdate: renderNums }, '<'));
  tl.to(chart, { opacity: 1, y: 0, duration: 0.4 }, '-=.15')
    .to(bars,  { scaleY: 1, duration: 0.55, stagger: 0.08, ease: 'power2.out' }, '-=.1');
  return tl;
}

/* ---- Cena Showcase: motion graphic que passa por todas as funções, ~3s cada ----
   Cada quadro MONTA seus elementos (sobem, estouram com overshoot, giram de leve) e
   depois SOLTA (sobem e somem), cruzando com o próximo. Legenda + progresso.
   Mostra a função, nunca o motor. */
function buildShowcase(stage, gsap) {
  const frames = [...stage.querySelectorAll('.dm-frame')];
  const labelEl = stage.querySelector('.dm-show-label');
  const dots = [...stage.querySelectorAll('.dm-show-dot')];
  const labels = frames.map((f) => f.getAttribute('data-label') || '');
  const setActive = (i) => {
    if (labelEl) labelEl.textContent = labels[i];
    dots.forEach((d, j) => d.classList.toggle('on', j === i));
  };
  const pick = (f, sel) => [...f.querySelectorAll(sel)];
  const PANEL = '.dm-card, .dm-user, .dm-event';
  const BAR = '.dm-fr-bars > span';
  const POP = '.dm-onode, .dm-stat, .dm-num, .dm-row, .dm-flag, .dm-tag, .dm-msg, .dm-fr-funnel > span, .dm-node, .dm-avatar';
  const IN = 0.6, HOLD = 2.3, OUT = 0.55, STEP = IN + HOLD;

  const tl = gsap.timeline({ paused: true, repeat: -1 });
  tl.set(frames, { opacity: 1 }, 0);
  frames.forEach((f) => {
    tl.set(pick(f, PANEL), { opacity: 0, scale: 0.92, y: 14 }, 0)
      .set(pick(f, BAR), { opacity: 0, scaleY: 0, transformOrigin: 'bottom' }, 0)
      .set(pick(f, POP), { opacity: 0, y: 28, x: (i) => ((i % 3) - 1) * 16, scale: 0.8, rotate: (i) => (i % 2 ? -4 : 4) }, 0);
  });

  frames.forEach((f, i) => {
    const at = i * STEP;
    const panels = pick(f, PANEL), bars = pick(f, BAR), pops = pick(f, POP);
    tl.call(setActive, [i], at)
      .to(panels, { opacity: 1, scale: 1, y: 0, duration: IN, ease: 'power3.out' }, at)
      .to(pops, { opacity: 1, y: 0, x: 0, scale: 1, rotate: 0, duration: IN + 0.15, stagger: 0.07, ease: 'back.out(1.8)' }, at + 0.05)
      .to(bars, { opacity: 1, scaleY: 1, duration: 0.55, stagger: 0.07, ease: 'power2.out' }, at + 0.12);
    const outAt = at + IN + HOLD;
    tl.to([...panels, ...bars], { opacity: 0, duration: OUT, ease: 'power2.in' }, outAt)
      .to(pops, { opacity: 0, y: -18, scale: 0.9, duration: OUT, ease: 'power2.in' }, outAt);
  });
  return tl;
}

/* =============================================================================
   Abas "O Yuris por dentro" — cada aba é uma tela interativa que toca ao abrir.
   Cenas pré-montadas (paused) com elementos escondidos; restart() na ativação.
   ========================================================================== */
function initTabScenes({ gsap, ScrollTrigger }) {
  const tb = {
    dashboard: buildTabDashboard, processos: buildTabList, tarefas: buildTabKanban,
    intimacoes: buildTabList, comunicacao: buildTabChat, automacao: buildTabAuto, matriz: buildTabShare,
  };
  document.querySelectorAll('[data-tabs="mockups"]').forEach((container) => {
    const map = new Map();
    container.querySelectorAll('.lp2-tab-pane').forEach((pane) => {
      const stage = pane.querySelector('[data-tab-scene]');
      if (!stage) return;
      const build = tb[stage.getAttribute('data-tab-scene')];
      if (!build) return;
      try { map.set(pane, build(stage, gsap)); } catch (e) { console.warn('[lp2 tab]', e); }
    });
    if (!map.size) return;
    const playActive = () => {
      const active = container.querySelector('.lp2-tab-pane.active');
      const tl = active && map.get(active);
      if (tl) tl.restart();
    };
    ScrollTrigger.create({ trigger: container, start: 'top 80%', once: true, onEnter: playActive });
    container.querySelectorAll('.lp2-tab').forEach((btn) => {
      btn.addEventListener('click', () => {
        const pane = container.querySelector('.lp2-tab-pane[data-pane="' + btn.getAttribute('data-target') + '"]');
        const tl = pane && map.get(pane);
        if (tl) tl.restart();
      });
    });
  });
}

/* ---- Tela Dashboard: KPIs contando + gráfico de linha desenhando + barras ---- */
function buildTabDashboard(stage, gsap) {
  const qa = (s) => stage.querySelectorAll(s), q = (s) => stage.querySelector(s);
  const stats = qa('.dm-stat'), nums = [...stats].map((s) => s.querySelector('b'));
  const chart = q('.dm-chart'), area = q('.dm-line-area'), path = q('.dm-line-path'), bars = qa('.dm-bar > b');
  const targets = nums.map((n) => parseInt(n.getAttribute('data-c'), 10) || 0);
  const prox = targets.map(() => ({ v: 0 }));
  const render = () => nums.forEach((n, i) => { n.textContent = Math.round(prox[i].v); });
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true });
  tl.set(stats, { opacity: 0, y: 14 }, 0).set(chart, { opacity: 0, y: 14 }, 0)
    .set(area, { opacity: 0 }, 0).set(path, { strokeDashoffset: 400 }, 0)
    .set(bars, { scaleY: 0, transformOrigin: 'bottom' }, 0)
    .call(() => { prox.forEach((p) => { p.v = 0; }); render(); }, null, 0)
    .to(stats, { opacity: 1, y: 0, duration: 0.45, stagger: 0.12 });
  nums.forEach((n, i) => tl.to(prox[i], { v: targets[i], duration: 0.8, onUpdate: render }, '<'));
  tl.to(chart, { opacity: 1, y: 0, duration: 0.4 }, '-=.2')
    .to(area, { opacity: 1, duration: 0.5 }, '-=.1')
    .to(path, { strokeDashoffset: 0, duration: 1.0, ease: 'power2.inOut' }, '<')
    .to(bars, { scaleY: 1, duration: 0.5, stagger: 0.07 }, '-=.4');
  return tl;
}

/* ---- Tela em lista (Processos / Intimações): cabeçalho + linhas em cascata ---- */
function buildTabList(stage, gsap) {
  const head = stage.querySelector('.dm-lhead'), rows = stage.querySelectorAll('.dm-lrow');
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true });
  tl.set(head, { opacity: 0, y: -8 }, 0).set(rows, { opacity: 0, x: -14 }, 0)
    .to(head, { opacity: 1, y: 0, duration: 0.35 })
    .to(rows, { opacity: 1, x: 0, duration: 0.4, stagger: 0.12 }, '-=.1');
  return tl;
}

/* ---- Tela Tarefas (kanban): colunas + cards estourando ---- */
function buildTabKanban(stage, gsap) {
  const cols = stage.querySelectorAll('.dm-kcol'), tasks = stage.querySelectorAll('.dm-task');
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true });
  tl.set(cols, { opacity: 0, y: 12 }, 0).set(tasks, { opacity: 0, scale: 0.85 }, 0)
    .to(cols, { opacity: 1, y: 0, duration: 0.4, stagger: 0.1 })
    .to(tasks, { opacity: 1, scale: 1, duration: 0.35, stagger: 0.1, ease: 'back.out(1.6)' }, '-=.3');
  return tl;
}

/* ---- Tela Comunicação: lista de conversas entrando ---- */
function buildTabChat(stage, gsap) {
  const els = stage.querySelectorAll('.dm-conv, .dm-chip');
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true });
  tl.set(els, { opacity: 0, x: -14 }, 0).to(els, { opacity: 1, x: 0, duration: 0.42, stagger: 0.14 });
  return tl;
}

/* ---- Tela Automação: evento → webhook → integrações acendendo ---- */
function buildTabAuto(stage, gsap) {
  const event = stage.querySelector('.dm-event'), hook = stage.querySelector('.dm-hook');
  const nodes = stage.querySelectorAll('.dm-node'), flag = stage.querySelector('.dm-flag');
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true });
  tl.set(event, { opacity: 0, y: -10 }, 0).set(hook, { opacity: 0, scale: 0.9 }, 0)
    .set(nodes, { opacity: 0, y: 12, borderColor: 'rgba(126,184,246,.22)', boxShadow: '0 0 0 rgba(91,188,224,0)' }, 0)
    .set(flag, { opacity: 0, y: 8 }, 0)
    .to(event, { opacity: 1, y: 0, duration: 0.4 })
    .to(hook, { opacity: 1, scale: 1, duration: 0.4, ease: 'back.out(1.6)' }, '-=.1')
    .to(nodes, { opacity: 1, y: 0, duration: 0.38, stagger: 0.14 }, '-=.05')
    .to(nodes, { borderColor: '#5BBCE0', boxShadow: '0 0 16px rgba(91,188,224,.35)', duration: 0.3, stagger: 0.14 }, '-=.4')
    .to(flag, { opacity: 1, y: 0, duration: 0.4 }, '-=.1');
  return tl;
}

/* ---- Tela Matriz e filiais: matriz compartilha um processo com as filiais ---- */
function buildTabShare(stage, gsap) {
  const top = stage.querySelector('.dm-share-top'), doc = stage.querySelector('.dm-share-doc');
  const fil = stage.querySelectorAll('.dm-share-row .dm-onode'), flag = stage.querySelector('.dm-flag');
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true });
  tl.set(top, { opacity: 0, y: -10, scale: 0.9 }, 0).set(doc, { opacity: 0, scale: 0.9 }, 0)
    .set(fil, { opacity: 0, y: 14 }, 0).set(flag, { opacity: 0, y: 8 }, 0)
    .to(top, { opacity: 1, y: 0, scale: 1, duration: 0.45 })
    .to(doc, { opacity: 1, scale: 1, duration: 0.4, ease: 'back.out(1.6)' }, '-=.1')
    .to(fil, { opacity: 1, y: 0, duration: 0.4, stagger: 0.14 }, '-=.05')
    .to(flag, { opacity: 1, y: 0, duration: 0.4 }, '-=.1');
  return tl;
}
