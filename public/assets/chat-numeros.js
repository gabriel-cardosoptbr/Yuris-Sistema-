/**
 * chat-numeros.js — vários números de WhatsApp na mesma conta (edição Fleetiflow).
 *
 * Desenha, no topo do chat, o resumo (quantos números, quantos conectados,
 * caídos e nunca conectados) e uma aba por número. Clicar numa aba troca o
 * número que a tela está vendo (ChatApp.trocarCanal). Quem gerencia a conexão
 * (owner/admin) também renomeia o número e adiciona um novo.
 *
 * O número escolhido fica guardado no navegador, por conta. O servidor valida
 * tudo de novo: número que a conta não enxerga é recusado lá.
 */
(function () {
  const raiz = document.getElementById('ffNumeros');
  if (!raiz || typeof ChatApp === 'undefined') return;

  const API   = '/api/whatsapp/instances.php';
  const CSRF  = window.CSRF_CHAT || '';
  const CHAVE = 'ff_chat_numero_' + (window.CHAT_CONTA_ID || '0');

  let numeros = [];
  let resumo  = { total: 0, conectados: 0, caidos: 0, nunca_conectados: 0 };
  let podeGerenciar = false;

  // Número escolhido ANTES do init do chat (que roda no DOMContentLoaded): a
  // primeira consulta de status já sai para o número certo.
  const daUrl = new URLSearchParams(location.search).get('numero');
  let guardado = null;
  try { guardado = daUrl || localStorage.getItem(CHAVE); } catch (_) {}
  if (guardado) ChatApp.definirCanal(Number(guardado));

  function salvar(id) { try { localStorage.setItem(CHAVE, String(id)); } catch (_) {} }

  function esc(t) {
    return String(t == null ? '' : t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function telefone(p) {
    const d = String(p || '').replace(/\D/g, '');
    if (d.length === 13) return '+' + d.slice(0, 2) + ' (' + d.slice(2, 4) + ') ' + d.slice(4, 9) + '-' + d.slice(9);
    if (d.length === 12) return '+' + d.slice(0, 2) + ' (' + d.slice(2, 4) + ') ' + d.slice(4, 8) + '-' + d.slice(8);
    return d ? '+' + d : '';
  }

  const ROTULO = { conectado: 'Conectado', caido: 'Caído', nunca_conectado: 'Nunca conectado' };

  async function api(url, corpo) {
    const opts = { credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json' } };
    if (corpo) { opts.method = 'POST'; opts.body = JSON.stringify(Object.assign({ _csrf: CSRF }, corpo)); }
    const r = await fetch(url, opts);
    const d = await r.json().catch(() => ({}));
    if (!r.ok || d.ok === false) throw new Error(d.error || 'Não foi possível concluir.');
    return d;
  }

  async function carregar(atualizar) {
    try {
      const d = await api(API + '?action=numeros' + (atualizar ? '&atualizar=1' : ''));
      numeros = d.numeros || [];
      resumo  = d.resumo || resumo;
      podeGerenciar = !!d.pode_gerenciar;
      // Número guardado que não existe mais (ou de outra conta): volta ao padrão.
      const atual = ChatApp.canalAtual();
      if (atual && !numeros.some((n) => n.id === atual)) {
        const padrao = numeros.find((n) => n.padrao) || numeros[0];
        await ChatApp.trocarCanal(padrao ? padrao.id : null);
      } else if (!atual && numeros.length > 1) {
        const padrao = numeros.find((n) => n.padrao) || numeros[0];
        if (padrao) ChatApp.definirCanal(padrao.id);
      }
      desenhar();
    } catch (e) {
      raiz.innerHTML = '';
    }
  }

  function desenhar() {
    const atual = ChatApp.canalAtual() || (numeros.find((n) => n.padrao) || {}).id;
    const chips = [
      ['', resumo.total + (resumo.total === 1 ? ' número' : ' números')],
      ['ok', resumo.conectados + (resumo.conectados === 1 ? ' conectado' : ' conectados')],
      ['ruim', resumo.caidos + (resumo.caidos === 1 ? ' caído' : ' caídos')],
      ['neutro', resumo.nunca_conectados + ' nunca ' + (resumo.nunca_conectados === 1 ? 'conectado' : 'conectados')],
    ];

    raiz.innerHTML =
      '<div class="ffn-topo">' +
        '<div class="ffn-resumo">' +
          chips.map(([tom, txt], i) =>
            '<span class="ffn-chip' + (tom ? ' ffn-' + tom : '') + (i > 0 && txt.startsWith('0 ') ? ' ffn-zero' : '') + '">' +
            (tom ? '<i></i>' : '') + esc(txt) + '</span>').join('') +
        '</div>' +
        (podeGerenciar ? '<button type="button" class="ffn-add" data-acao="adicionar">+ Adicionar número</button>' : '') +
      '</div>' +
      '<div class="ffn-abas" role="tablist" aria-label="Números de WhatsApp">' +
        numeros.map((n) => {
          const ativo = n.id === atual;
          return '<div class="ffn-aba' + (ativo ? ' ativa' : '') + ' ffn-' + n.situacao + '" role="tab" tabindex="0" aria-selected="' + ativo + '" data-id="' + n.id + '" title="' + esc(ROTULO[n.situacao]) + '">' +
            '<i class="ffn-ponto"></i>' +
            '<span class="ffn-nome">' + esc(n.nome) + '</span>' +
            '<span class="ffn-fone">' + esc(telefone(n.phone) || ROTULO[n.situacao]) + '</span>' +
            (n.nao_lidas > 0 ? '<span class="ffn-badge">' + (n.nao_lidas > 99 ? '99+' : n.nao_lidas) + '</span>' : '') +
            (ativo && podeGerenciar && n.is_own ? '<button type="button" class="ffn-renomear" data-acao="renomear" data-id="' + n.id + '" title="Renomear número" aria-label="Renomear número">' +
              '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>' : '') +
          '</div>';
        }).join('') +
      '</div>';
  }

  async function escolher(id) {
    id = Number(id);
    if (!id || id === ChatApp.canalAtual()) return;
    salvar(id);
    ChatApp.definirCanal(null); // força trocarCanal a reconhecer a troca
    await ChatApp.trocarCanal(id);
    desenhar();
  }

  // ── Janelinha de nome (renomear / adicionar) ──────────────────────────────
  function pedirNome(titulo, texto, valor, botao) {
    return new Promise((resolver) => {
      const fundo = document.createElement('div');
      fundo.className = 'ffn-modal-fundo';
      fundo.innerHTML =
        '<form class="ffn-modal" role="dialog" aria-modal="true">' +
          '<h3>' + esc(titulo) + '</h3>' +
          '<p>' + esc(texto) + '</p>' +
          '<input type="text" maxlength="150" required placeholder="Ex.: Comercial, Suporte, Número 2" value="' + esc(valor || '') + '">' +
          '<div class="ffn-erro" hidden></div>' +
          '<div class="ffn-acoes"><button type="button" class="ffn-sec">Cancelar</button><button type="submit" class="ffn-pri">' + esc(botao) + '</button></div>' +
        '</form>';
      document.body.appendChild(fundo);
      const form = fundo.querySelector('form');
      const input = form.querySelector('input');
      const erro = form.querySelector('.ffn-erro');
      const fechar = (v) => { fundo.remove(); resolver(v); };
      input.focus(); input.select();
      form.querySelector('.ffn-sec').onclick = () => fechar(null);
      fundo.addEventListener('mousedown', (e) => { if (e.target === fundo) fechar(null); });
      fundo.addEventListener('keydown', (e) => { if (e.key === 'Escape') fechar(null); });
      form.onsubmit = (e) => {
        e.preventDefault();
        const v = input.value.trim();
        if (!v) { erro.textContent = 'Dê um nome ao número.'; erro.hidden = false; return; }
        fechar({ valor: v });
      };
    });
  }

  async function renomear(id) {
    const n = numeros.find((x) => x.id === Number(id));
    if (!n) return;
    const r = await pedirNome('Renomear número', 'O nome aparece só aqui no CRM; o WhatsApp não muda.', n.nome, 'Salvar');
    if (!r) return;
    try {
      await api(API, { action: 'renomear', channel_id: n.id, nome: r.valor });
      await carregar(false);
    } catch (e) { alert(e.message); }
  }

  async function adicionar() {
    const r = await pedirNome('Adicionar número', 'Cria o número aqui e abre o QR Code para você escanear com o celular do novo WhatsApp.', '', 'Criar e gerar QR');
    if (!r) return;
    try {
      const d = await api(API, { action: 'adicionar_numero', nome: r.valor });
      await carregar(false);
      await escolher(d.channel_id);
      ChatApp.connectWhatsApp(); // já mostra o QR do número novo
    } catch (e) { alert(e.message); }
  }

  raiz.addEventListener('click', (e) => {
    const alvo = e.target.closest('[data-acao], .ffn-aba');
    if (!alvo) return;
    if (alvo.dataset.acao === 'adicionar') return void adicionar();
    if (alvo.dataset.acao === 'renomear') { e.stopPropagation(); return void renomear(alvo.dataset.id); }
    if (alvo.classList.contains('ffn-aba')) escolher(alvo.dataset.id);
  });
  raiz.addEventListener('keydown', (e) => {
    const aba = e.target.closest('.ffn-aba');
    if (aba && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); escolher(aba.dataset.id); }
  });

  // Estado real na abertura (pergunta à Evolution) e depois a cada 2 min;
  // entre um e outro, o gravado a cada 20 s (conta não lidas e quedas pelo webhook).
  carregar(true);
  setInterval(() => { if (!document.hidden) carregar(false); }, 20000);
  setInterval(() => { if (!document.hidden) carregar(true); }, 120000);
})();
