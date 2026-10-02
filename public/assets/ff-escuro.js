/* Tema escuro da edição CRM: cores dos gráficos (Chart.js).
 *
 * Os gráficos do painel passam a grade e os rótulos em cores fixas pensadas
 * para fundo claro (cinza-ardósia quase transparente), que somem no fundo
 * escuro. Este arquivo ajusta a CONFIGURAÇÃO de cada gráfico antes de ele
 * nascer, só quando o tema é escuro. No claro, não faz nada.
 *
 * Por que não um plugin: no Chart.js 4 as opções de um gráfico vivo são
 * objetos reativos; alterá-las num gancho de desenho redesenha de novo e entra
 * em recursão (foi o primeiro teste). Envolver o construtor mexe só no objeto
 * comum que a tela passa, antes de virar gráfico.
 *
 * Carregado pelo menu lateral nas contas da edição CRM.
 */
(function () {
  var escuro = function () { return document.documentElement.getAttribute('data-theme') !== 'light'; };
  var GRADE = 'rgba(255,255,255,0.08)', TICK = '#8C97A6', ROTULO = '#A3ADBA';

  function ajustar(cfg) {
    if (!escuro() || !cfg || typeof cfg !== 'object') return cfg;
    var o = cfg.options = cfg.options || {};
    var scales = o.scales || {};
    Object.keys(scales).forEach(function (k) {
      var s = scales[k] = scales[k] || {};
      if (!s.grid || s.grid.display !== false) { s.grid = s.grid || {}; s.grid.color = GRADE; }
      if (s.angleLines) s.angleLines.color = GRADE;
      s.ticks = s.ticks || {}; s.ticks.color = TICK;
      if (s.pointLabels) { s.pointLabels.color = ROTULO; s.ticks.backdropColor = 'transparent'; }
    });
    o.plugins = o.plugins || {};
    o.plugins.legend = o.plugins.legend || {};
    o.plugins.legend.labels = o.plugins.legend.labels || {};
    o.plugins.legend.labels.color = ROTULO;
    return cfg;
  }

  function envolver() {
    var C = window.Chart;
    if (!C) return false;
    if (C.__ffEscuro) return true;
    var Escuro = function (item, cfg) { return new C(item, ajustar(cfg)); };
    Escuro.prototype = C.prototype;
    Object.getOwnPropertyNames(C).forEach(function (p) {
      if (['length', 'name', 'prototype', 'caller', 'arguments'].indexOf(p) >= 0) return;
      try { Object.defineProperty(Escuro, p, Object.getOwnPropertyDescriptor(C, p)); } catch (e) {}
    });
    Escuro.__ffEscuro = true;
    window.Chart = Escuro;
    return true;
  }

  // O Chart.js pode chegar depois deste arquivo: tenta de novo por alguns segundos.
  if (!envolver()) {
    var n = 0, t = setInterval(function () { if (envolver() || ++n > 60) clearInterval(t); }, 100);
    document.addEventListener('DOMContentLoaded', envolver);
  }
})();
