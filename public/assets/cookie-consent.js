/**
 * cookie-consent.js — Banner de cookies LGPD (sem dependências).
 *
 * Comportamento:
 *   • Aparece em fixed-bottom se usuário ainda não respondeu
 *   • 3 opções: "Aceitar todos" / "Só essenciais" / "Preferências"
 *   • Persiste em localStorage (`yuris_cookie_consent`) imediatamente
 *   • Se user logado, sincroniza com /api/legal/consent.php (registra
 *     consentimento server-side com IP/UA/timestamp pra evidência LGPD)
 *
 * Categorias:
 *   • essenciais  — sempre ATIVAS (sessão, CSRF, login)
 *   • analiticos  — Google Fonts, jsDelivr CDN (envia IP a 3os)
 *   • funcionais  — preferência de tema
 *   • marketing   — não usados ainda; reservado pra futuro
 *
 * Uso: incluir <script src=".../cookie-consent.js"></script> em todas as
 * páginas (públicas E autenticadas). Auto-inicializa em DOMContentLoaded.
 */
(function () {
  'use strict';

  const STORAGE_KEY = 'yuris_cookie_consent';
  const VERSION     = '2026-05-23';   // bump pra forçar re-prompt em todos os browsers

  const CATEGORIES = {
    essenciais:  { label: 'Essenciais',    desc: 'Sessão, autenticação, CSRF. Sempre ativos.', locked: true,  default: true },
    funcionais:  { label: 'Funcionais',    desc: 'Preferência de tema, idioma, layout.',       locked: false, default: true },
    analiticos:  { label: 'Analíticos',    desc: 'Carregamento de fontes Google e CDN externa (envia seu IP).', locked: false, default: false },
    marketing:   { label: 'Marketing',     desc: 'Não utilizamos hoje. Reservado para o futuro.', locked: false, default: false },
  };

  function loadStored() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      const obj = JSON.parse(raw);
      if (!obj || obj.version !== VERSION) return null;   // versão diferente = pede de novo
      return obj;
    } catch { return null; }
  }

  function save(state) {
    const data = {
      version:    VERSION,
      timestamp:  new Date().toISOString(),
      categories: state,
    };
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(data)); } catch {}
    syncServerSide(state);
    closeBanner();
  }

  function syncServerSide(state) {
    // Se logado, registra cada categoria como consent server-side (LGPD evidência)
    const csrf = window.YURIS_CSRF || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!csrf) return; // não autenticado ou sem CSRF — fica só em localStorage

    Object.entries(state).forEach(([cat, granted]) => {
      if (CATEGORIES[cat]?.locked) return; // essenciais não precisam registrar
      const finalidade = 'cookies_' + cat;
      const method     = granted ? 'POST' : 'DELETE';
      fetch('/api/legal/consent.php', {
        method,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        credentials: 'same-origin',
        body: JSON.stringify({
          csrf_token:  csrf,
          finalidade:  finalidade,
          base_legal:  'consentimento',
          fonte:       'banner_cookie',
          evidencia:   { version: VERSION, ts: new Date().toISOString() },
        }),
      }).catch(() => {});
    });
  }

  /**
   * Cor da marca da página (edição CRM, 17/10/2026). A tela de login de marca define --ff-rgb e as
   * telas internas --ff-marca-rgb; com uma delas, o aviso sai claro como a tela, com botões, links e
   * caixas na cor da marca e a fonte da página. Sem nenhuma (Yuris), fica como sempre foi.
   */
  function corDaMarca() {
    const cs = getComputedStyle(document.documentElement);
    const bruto = (cs.getPropertyValue('--ff-marca-rgb') || cs.getPropertyValue('--ff-rgb') || '').trim();
    const m = bruto.match(/^(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})$/);
    if (!m) return null;
    const rgb = [+m[1], +m[2], +m[3]];
    const mistura = (alvo, p) => rgb.map((v, i) => Math.round(v + (alvo[i] - v) * p));
    const css = c => `rgb(${c.join(',')})`;
    const lum = c => { const l = c.map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2]; };
    return {
      rgb: rgb.join(','),
      marca: css(rgb),
      forte: css(mistura([0, 0, 0], 0.25)),
      tinta: css(mistura([0, 0, 0], 0.6)),
      tintaSuave: css(mistura([60, 60, 60], 0.55)),
      sobre: lum(rgb) > 0.45 ? '#1f1f1f' : '#ffffff',
    };
  }

  // Regras de botão com !important: o tema geral (yuris-theme.css) pinta button.primary de azul com !important.
  function estiloDaMarca(c) {
    return `
      <style>
        #yuris-cookie-banner.ycc-marca {
          background: rgba(255,255,255,.97); color: ${c.tintaSuave};
          border-top: 1px solid rgba(${c.rgb},.18); box-shadow: 0 -10px 30px rgba(${c.rgb},.14);
          font-family: inherit;
        }
        #yuris-cookie-banner.ycc-marca .ycc-text strong { color: ${c.tinta}; }
        #yuris-cookie-banner.ycc-marca a, #yuris-cookie-prefs.ycc-marca a { color: ${c.marca}; }
        #yuris-cookie-banner.ycc-marca button, #yuris-cookie-prefs.ycc-marca button {
          padding: 9px 16px !important; border-radius: 999px !important; border: 1px solid rgba(${c.rgb},.35) !important;
          background: #fff !important; color: ${c.marca} !important; cursor: pointer; font: inherit !important; font-weight: 600 !important;
          box-shadow: none !important; transition: background .12s, box-shadow .12s;
        }
        #yuris-cookie-banner.ycc-marca button:hover, #yuris-cookie-prefs.ycc-marca button:hover { background: rgba(${c.rgb},.07) !important; }
        #yuris-cookie-banner.ycc-marca button.primary, #yuris-cookie-prefs.ycc-marca button.primary {
          background: linear-gradient(135deg, ${c.marca}, ${c.forte}) !important; color: ${c.sobre} !important; border: none !important;
          box-shadow: 0 8px 20px rgba(${c.rgb},.28) !important;
        }
        #yuris-cookie-banner.ycc-marca button.primary:hover, #yuris-cookie-prefs.ycc-marca button.primary:hover { box-shadow: 0 10px 24px rgba(${c.rgb},.38) !important; filter: brightness(1.06); }
        #yuris-cookie-prefs.ycc-marca { background: rgba(${c.rgb},.28); }
        #yuris-cookie-prefs.ycc-marca .ycc-modal {
          background: #fff; color: ${c.tintaSuave}; border: 1px solid rgba(${c.rgb},.18);
          box-shadow: 0 24px 60px rgba(${c.rgb},.25); font-family: inherit; border-radius: 16px;
        }
        #yuris-cookie-prefs.ycc-marca h3, #yuris-cookie-prefs.ycc-marca .ycc-cat-label { color: ${c.tinta}; }
        #yuris-cookie-prefs.ycc-marca p, #yuris-cookie-prefs.ycc-marca .ycc-cat-desc, #yuris-cookie-prefs.ycc-marca .ycc-locked { color: ${c.tintaSuave}; }
        #yuris-cookie-prefs.ycc-marca .ycc-cat { border-color: rgba(${c.rgb},.16); background: rgba(${c.rgb},.03); }
        #yuris-cookie-prefs.ycc-marca input[type="checkbox"] { accent-color: ${c.marca}; }
      </style>`;
  }

  function buildBanner() {
    const marca = corDaMarca();
    const stored = loadStored();
    if (stored) return; // já respondeu nesta versão

    // Estado inicial — defaults
    const state = {};
    Object.entries(CATEGORIES).forEach(([k, c]) => state[k] = c.default);

    const wrap = document.createElement('div');
    wrap.id = 'yuris-cookie-banner';
    if (marca) wrap.className = 'ycc-marca';
    wrap.innerHTML = (marca ? estiloDaMarca(marca) : '') + `
      <style>
        #yuris-cookie-banner {
          position: fixed; bottom: 0; left: 0; right: 0;
          z-index: 99999;
          background: rgba(15, 23, 41, .98);
          color: #e8edf5;
          border-top: 1px solid rgba(96,165,250,.30);
          box-shadow: 0 -8px 24px rgba(0,0,0,.45);
          padding: 18px 22px;
          font-family: Inter, system-ui, sans-serif;
          font-size: 14px;
          backdrop-filter: blur(8px);
        }
        #yuris-cookie-banner .ycc-row {
          max-width: 1180px; margin: 0 auto;
          display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between;
        }
        #yuris-cookie-banner .ycc-text { flex: 1; min-width: 260px; line-height: 1.45; }
        #yuris-cookie-banner .ycc-text strong { color: #fff; }
        #yuris-cookie-banner a { color: #7eb8f7; text-decoration: underline; }
        #yuris-cookie-banner .ycc-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        #yuris-cookie-banner button {
          padding: 9px 14px; border-radius: 7px; border: 1px solid rgba(96,165,250,.30);
          background: transparent; color: #e8edf5; cursor: pointer; font: inherit; font-weight: 600;
          transition: background .12s;
        }
        #yuris-cookie-banner button:hover { background: rgba(96,165,250,.10); }
        #yuris-cookie-banner button.primary {
          background: linear-gradient(135deg, #2563eb, #1d4ed8); border: none;
        }
        #yuris-cookie-banner button.primary:hover { box-shadow: 0 8px 18px rgba(37,99,235,.4); }
        #yuris-cookie-prefs {
          position: fixed; inset: 0; z-index: 100000;
          background: rgba(0,0,0,.6); display: none; align-items: center; justify-content: center;
        }
        #yuris-cookie-prefs.open { display: flex; }
        #yuris-cookie-prefs .ycc-modal {
          width: 100%; max-width: 540px; background: #0f172b; border-radius: 12px;
          border: 1px solid rgba(96,165,250,.25); padding: 24px;
          max-height: 88vh; overflow-y: auto;
          font-family: Inter, system-ui, sans-serif; color: #e8edf5;
        }
        #yuris-cookie-prefs h3 { margin: 0 0 8px; color: #fff; font-size: 18px; }
        #yuris-cookie-prefs p { font-size: 13px; color: #b6c5dd; margin: 0 0 18px; }
        #yuris-cookie-prefs .ycc-cat { padding: 12px; border: 1px solid rgba(96,165,250,.18); border-radius: 8px; margin-bottom: 10px; }
        #yuris-cookie-prefs .ycc-cat-head { display:flex; justify-content:space-between; align-items:center; gap:12px; }
        #yuris-cookie-prefs .ycc-cat-label { font-weight: 600; color: #fff; }
        #yuris-cookie-prefs .ycc-cat-desc { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        #yuris-cookie-prefs .ycc-locked { font-size: 11px; color: #94a3b8; font-style: italic; }
        #yuris-cookie-prefs .ycc-modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 18px; }
        #yuris-cookie-prefs label { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
        #yuris-cookie-prefs input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
      </style>
      <div class="ycc-row">
        <div class="ycc-text">
          <strong>Cookies & Privacidade.</strong>
          Usamos cookies essenciais para o login e CSRF, e carregamos fontes/libs de CDN externa que enviam seu IP a terceiros (Google, jsDelivr).
          Veja nossa <a href="/cookies" target="_blank">Política de Cookies</a>
          e <a href="/privacidade" target="_blank">Política de Privacidade</a>.
        </div>
        <div class="ycc-actions">
          <button type="button" data-action="prefs">Preferências</button>
          <button type="button" data-action="essentials">Só essenciais</button>
          <button type="button" data-action="all" class="primary">Aceitar todos</button>
        </div>
      </div>
    `;
    document.body.appendChild(wrap);

    // Preferences modal
    const prefs = document.createElement('div');
    prefs.id = 'yuris-cookie-prefs';
    if (marca) prefs.classList.add('ycc-marca');
    const catsHtml = Object.entries(CATEGORIES).map(([k, c]) => `
      <div class="ycc-cat">
        <div class="ycc-cat-head">
          <div>
            <div class="ycc-cat-label">${c.label}</div>
            <div class="ycc-cat-desc">${c.desc}</div>
          </div>
          ${c.locked
            ? '<span class="ycc-locked">Obrigatório</span>'
            : `<label><input type="checkbox" data-cat="${k}" ${c.default ? 'checked' : ''}></label>`}
        </div>
      </div>
    `).join('');
    prefs.innerHTML = `
      <div class="ycc-modal">
        <h3>Preferências de Cookies</h3>
        <p>Você pode escolher quais categorias de cookies aceitar. Cookies essenciais não podem ser desativados (necessários para o sistema funcionar).</p>
        ${catsHtml}
        <div class="ycc-modal-actions">
          <button type="button" data-action="cancel-prefs">Cancelar</button>
          <button type="button" data-action="save-prefs" class="primary">Salvar preferências</button>
        </div>
      </div>
    `;
    document.body.appendChild(prefs);

    wrap.addEventListener('click', e => {
      const a = e.target.closest('[data-action]')?.dataset.action;
      if (!a) return;
      if (a === 'all') {
        const allOn = {}; Object.keys(CATEGORIES).forEach(k => allOn[k] = true);
        save(allOn);
      } else if (a === 'essentials') {
        const onlyEss = {}; Object.entries(CATEGORIES).forEach(([k,c]) => onlyEss[k] = !!c.locked);
        save(onlyEss);
      } else if (a === 'prefs') {
        prefs.classList.add('open');
      }
    });

    prefs.addEventListener('click', e => {
      const a = e.target.closest('[data-action]')?.dataset.action;
      if (a === 'cancel-prefs') prefs.classList.remove('open');
      if (a === 'save-prefs') {
        const out = {};
        Object.entries(CATEGORIES).forEach(([k, c]) => {
          if (c.locked) { out[k] = true; return; }
          const cb = prefs.querySelector(`input[data-cat="${k}"]`);
          out[k] = !!(cb && cb.checked);
        });
        prefs.classList.remove('open');
        save(out);
      }
      if (e.target === prefs) prefs.classList.remove('open');
    });
  }

  function closeBanner() {
    const el = document.getElementById('yuris-cookie-banner');
    if (el) el.remove();
    const pr = document.getElementById('yuris-cookie-prefs');
    if (pr) pr.remove();
  }

  // Expõe API pra reabrir preferências (ex: link no footer "Gerenciar cookies")
  window.YurisCookies = {
    open() {
      try { localStorage.removeItem(STORAGE_KEY); } catch {}
      closeBanner();
      buildBanner();
    },
    state() { return loadStored(); },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buildBanner);
  } else {
    buildBanner();
  }
})();
