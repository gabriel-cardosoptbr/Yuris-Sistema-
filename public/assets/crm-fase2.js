/**
 * crm-fase2.js — os quatro blocos da Fase 2 do CRM, iguais nas duas telas.
 *
 * ---------------------------------------------------------------------------
 * POR QUE UM MODULO COMPARTILHADO
 * ---------------------------------------------------------------------------
 * Etiqueta, campo personalizado, documento e interacao existem dos DOIS lados:
 * na ficha do cliente (clientes.php, 1.9 mil linhas) e no card de prospeccao
 * (prospeccao.php, 3.4 mil linhas). Escrever a mesma coisa duas vezes dentro de
 * dois arquivos que ja sao grandes garantiria duas versoes divergindo: a correcao
 * feita num lado esquecida no outro.
 *
 * O modulo monta os quatro blocos DENTRO de um container so. Cada tela precisa
 * de uma linha de HTML (`<div id="...Crm"></div>`) e uma chamada:
 *
 *     CrmFase2.montar({ entidade: 'cliente', id: 12, host: '#cliCrm', csrf: token });
 *
 * ---------------------------------------------------------------------------
 * O QUE O MODULO NAO FAZ
 * ---------------------------------------------------------------------------
 * Nao usa confirm(), alert() nem prompt() nativos: REGRA ETERNA do projeto.
 * Confirmacao e Yuris.confirm (Promise), aviso e Yuris.toast, os dois da
 * identidade visual e ja carregados por yuris-ui.js nas duas telas.
 *
 * Nao monta URL de download a partir de caminho de arquivo. A API devolve
 * `download_url` apontando para o endpoint autenticado, e e so isso que ele usa:
 * montar URL direta foi exatamente o furo P0 que a auditoria LGPD fechou.
 */
(function (root) {
  'use strict';

  if (root.CrmFase2) return;

  /* ===================================================================== */
  /* helpers                                                               */
  /* ===================================================================== */

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function avisar(msg, tipo) {
    if (root.Yuris && root.Yuris.toast) root.Yuris.toast(msg, tipo || 'info');
  }

  async function confirmar(msg, opts) {
    if (root.Yuris && root.Yuris.confirm) return root.Yuris.confirm(msg, opts || {});
    return false; // sem a UI da identidade carregada, NAO cai em confirm() nativo
  }

  /** Data e hora legiveis em pt-BR. Aceita 'Y-m-d H:i:s', que e o que a API manda. */
  function fmtDataHora(s) {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return String(s);
    return d.toLocaleDateString('pt-BR') + ' ' +
           d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  }

  function fmtTamanho(bytes) {
    const n = Number(bytes || 0);
    if (n <= 0) return '';
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  /** Data e hora de agora no formato que o input datetime-local espera. */
  function agoraLocal() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
           'T' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  /*
   * O ESTILO VEM DE crm-fase2.css, E NAO DE CLASSE EMPRESTADA DA TELA.
   *
   * Aqui havia quatro constantes de estilo inline (CSS_TITULO, CSS_LINK,
   * CSS_VAZIO, CSS_ITEM) e, pior, o modulo emitia as classes `btn`,
   * `btn-ghost` e `form-input` torcendo para a tela hospedeira as ter.
   *
   * As duas telas onde ele roda TEM, e com significados diferentes:
   *
   *   clientes.php     .btn = azul solido     variante: .btn-ghost  (hifen)
   *   prospeccao.php   .btn = TRANSPARENTE    variante: .btn.ghost  (espaco)
   *
   * Efeito na tela: o botao "Registrar" virava texto sem fundo na Prospeccao, e
   * "Aplicar"/"Anexar" pediam uma classe que ali nao existe. O mesmo bloco, dois
   * visuais, nenhum igual ao resto do sistema.
   *
   * Agora as classes sao do proprio modulo (crm-titulo, crm-link, crm-vazio,
   * crm-item, crm-btn, crm-input), todas escopadas em `.crm-bloco`. Ele fica
   * igual nas duas telas de hoje, e igual numa terceira amanha.
   */

  /* ===================================================================== */
  /* a instancia                                                           */
  /* ===================================================================== */

  /**
   * @param {object} o
   *   entidade 'cliente' | 'card'
   *   id       int
   *   host     seletor ou elemento onde os blocos sao montados
   *   csrf     token da sessao
   *   aoMudar  callback opcional, chamado quando algo grava (para a tela
   *            recarregar a timeline: os eventos novos aparecem la)
   */
  function Painel(o) {
    this.entidade = o.entidade;
    this.id       = parseInt(o.id, 10) || 0;
    this.csrf     = o.csrf || '';
    this.aoMudar  = typeof o.aoMudar === 'function' ? o.aoMudar : function () {};
    this.host     = typeof o.host === 'string' ? document.querySelector(o.host) : o.host;
    this.podeGerenciar = false;
  }

  Painel.prototype.cabecalhos = function (extra) {
    return Object.assign({ 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf }, extra || {});
  };

  /**
   * Toda chamada passa por aqui. Erro de rede e erro da API caem no mesmo lugar,
   * e o 403 de permissao ganha a mensagem que a API escreveu, que e especifica.
   */
  Painel.prototype.chamar = async function (url, opcoes) {
    try {
      const r = await fetch(url, opcoes || {});
      let dados = null;
      try { dados = await r.json(); } catch (e) { dados = null; }
      if (!r.ok) {
        avisar((dados && dados.error) || 'Não foi possível concluir a operação.', 'error');
        return null;
      }
      return dados;
    } catch (e) {
      avisar('Falha de conexão.', 'error');
      return null;
    }
  };

  /** Monta o esqueleto e carrega os quatro blocos em paralelo. */
  Painel.prototype.montar = function () {
    if (!this.host || this.id <= 0) return;

    this.host.innerHTML =
      bloco('Etiquetas',              'tags',       '') +
      bloco('Campos personalizados',  'campos',     '') +
      bloco('Documentos',             'anexos',     '') +
      bloco('Contatos e notas',       'interacoes', '');

    // Em paralelo: os quatro sao independentes e serializar deixaria a ficha
    // visivelmente lenta em conexao ruim.
    this.carregarTags();
    this.carregarCampos();
    this.carregarAnexos();
    this.carregarInteracoes();

    function bloco(titulo, chave) {
      return '<div class="crm-bloco" data-bloco="' + chave + '" style="margin-top:16px;">' +
             '  <div style="display:flex;align-items:center;justify-content:space-between;margin:0 0 8px;">' +
             '    <h3 class="crm-titulo">' + esc(titulo) + '</h3>' +
             '    <div data-acoes="' + chave + '" style="display:flex;gap:12px;align-items:center;"></div>' +
             '  </div>' +
             '  <div data-corpo="' + chave + '"></div>' +
             '</div>';
    }
  };

  Painel.prototype.corpo  = function (chave) { return this.host.querySelector('[data-corpo="' + chave + '"]'); };
  Painel.prototype.acoes  = function (chave) { return this.host.querySelector('[data-acoes="' + chave + '"]'); };

  /* ===================================================================== */
  /* BLOCO B — etiquetas                                                   */
  /* ===================================================================== */

  Painel.prototype.carregarTags = async function () {
    const d = await this.chamar('/api/crm_tags.php?entidade=' + this.entidade + '&id=' + this.id);
    if (!d) return;
    this.podeGerenciar = !!d.pode_gerenciar;
    this.desenharTags(d.tags || [], d.catalogo || []);
  };

  Painel.prototype.desenharTags = function (tags, catalogo) {
    const corpo = this.corpo('tags');
    if (!corpo) return;
    const self = this;

    const chips = (tags || []).map(function (t) {
      const cor = t.cor || '#64748b';
      return '<span data-tag="' + t.id + '" style="display:inline-flex;align-items:center;gap:6px;' +
             'padding:3px 9px;border-radius:999px;font-size:.76rem;font-weight:600;' +
             'background:' + esc(cor) + '22;border:1px solid ' + esc(cor) + '55;color:' + esc(cor) + ';">' +
             esc(t.nome) +
             '<a href="#" data-tirar="' + t.id + '" title="Remover etiqueta" ' +
             'style="text-decoration:none;color:inherit;opacity:.6;font-weight:700;">&times;</a></span>';
    }).join(' ');

    // O datalist oferece o catálogo da conta dona, e o input aceita nome novo:
    // é o "digite e crie" descrito em /api/crm_tags.php.
    const listaId = 'crmTagsCat_' + this.entidade + '_' + this.id;
    corpo.innerHTML =
      '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px;">' +
        (chips || '<span class="crm-vazio">Nenhuma etiqueta.</span>') +
      '</div>' +
      '<div style="display:flex;gap:6px;align-items:center;">' +
        '<input list="' + listaId + '" data-nova-tag class="crm-input" placeholder="Etiqueta…" ' +
               'maxlength="60" style="max-width:230px;">' +
        '<datalist id="' + listaId + '">' +
          (catalogo || []).map(function (c) { return '<option value="' + esc(c.nome) + '">'; }).join('') +
        '</datalist>' +
        '<button type="button" data-add-tag class="crm-btn crm-btn-ghost">Aplicar</button>' +
      '</div>';

    corpo.querySelectorAll('[data-tirar]').forEach(function (a) {
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        self.tirarTag(parseInt(a.getAttribute('data-tirar'), 10));
      });
    });

    const campo = corpo.querySelector('[data-nova-tag]');
    const botao = corpo.querySelector('[data-add-tag]');
    const aplicar = function () {
      const nome = (campo.value || '').trim();
      if (!nome) return;
      // Se o nome bate com uma do catálogo, manda o id: assim nem passa pela
      // checagem de permissão de criar, porque não está criando nada.
      const achada = (catalogo || []).find(function (c) {
        return String(c.nome).toLowerCase() === nome.toLowerCase();
      });
      self.aplicarTag(achada ? { tag_id: achada.id } : { nome: nome });
      campo.value = '';
    };
    botao.addEventListener('click', aplicar);
    campo.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); aplicar(); }
    });

    // "Gerenciar" só aparece para quem pode: oferecer e depois recusar com 403
    // é pior que não oferecer.
    const acoes = this.acoes('tags');
    if (acoes) {
      acoes.innerHTML = this.podeGerenciar
        ? '<a href="#" data-gerenciar-tags class="crm-link">Gerenciar etiquetas</a>'
        : '';
      const link = acoes.querySelector('[data-gerenciar-tags]');
      if (link) {
        link.addEventListener('click', function (ev) { ev.preventDefault(); self.gerenciarTags(); });
      }
    }
  };

  Painel.prototype.aplicarTag = async function (extra) {
    const corpo = Object.assign(
      { acao: 'aplicar', entidade: this.entidade, id: this.id, csrf_token: this.csrf },
      extra
    );
    const d = await this.chamar('/api/crm_tags.php', {
      method: 'POST', headers: this.cabecalhos(), body: JSON.stringify(corpo)
    });
    if (!d) return;
    if (d.aplicou === false) avisar('Essa etiqueta já estava aplicada.', 'info');
    await this.carregarTags();
    this.aoMudar();
  };

  Painel.prototype.tirarTag = async function (tagId) {
    if (!tagId) return;
    const d = await this.chamar('/api/crm_tags.php', {
      method: 'DELETE',
      headers: this.cabecalhos(),
      body: JSON.stringify({ entidade: this.entidade, id: this.id, tag_id: tagId, csrf_token: this.csrf })
    });
    if (!d) return;
    await this.carregarTags();
    this.aoMudar();
  };

  /** Painel embutido do catálogo: renomear, trocar cor e arquivar. */
  Painel.prototype.gerenciarTags = async function () {
    const corpo = this.corpo('tags');
    if (!corpo) return;
    const existente = corpo.querySelector('[data-cat-tags]');
    if (existente) { existente.remove(); return; }  // segundo clique fecha

    const d = await this.chamar('/api/crm_tags.php?catalogo=1');
    if (!d) return;
    const self = this;

    const cx = document.createElement('div');
    cx.setAttribute('data-cat-tags', '1');
    cx.style.cssText = 'margin-top:10px;padding:10px;border:1px dashed var(--border);border-radius:8px;';
    cx.innerHTML =
      '<div class="crm-vazio" style="margin-bottom:8px;">' +
        'Etiqueta arquivada sai do seletor e continua aparecendo onde já estava: ' +
        'apagar o vínculo reescreveria o passado.' +
      '</div>' +
      ((d.catalogo || []).length
        ? (d.catalogo || []).map(function (c) {
            return '<div class="crm-item">' +
              '<span style="display:flex;gap:8px;align-items:center;">' +
                '<input type="color" data-cor="' + c.id + '" value="' + esc(c.cor || '#64748b') + '" ' +
                       'style="width:26px;height:26px;border:none;background:none;padding:0;cursor:pointer;">' +
                '<input type="text" data-nome="' + c.id + '" value="' + esc(c.nome) + '" maxlength="60" ' +
                       'class="crm-input" style="max-width:200px;">' +
              '</span>' +
              '<span style="display:flex;gap:12px;align-items:center;">' +
                '<span class="crm-vazio">' + (parseInt(c.usos, 10) || 0) + ' uso(s)</span>' +
                '<a href="#" data-salvar-tag="' + c.id + '" class="crm-link">Salvar</a>' +
                '<a href="#" data-arquivar-tag="' + c.id + '" class="crm-link crm-link-perigo">Arquivar</a>' +
              '</span></div>';
          }).join('')
        : '<div class="crm-vazio">Nenhuma etiqueta no catálogo ainda. ' +
          'Digite um nome no campo acima para criar a primeira.</div>');

    corpo.appendChild(cx);

    cx.querySelectorAll('[data-salvar-tag]').forEach(function (a) {
      a.addEventListener('click', async function (ev) {
        ev.preventDefault();
        const tagId = parseInt(a.getAttribute('data-salvar-tag'), 10);
        const r = await self.chamar('/api/crm_tags.php', {
          method: 'POST', headers: self.cabecalhos(),
          body: JSON.stringify({
            acao: 'renomear', tag_id: tagId, csrf_token: self.csrf,
            nome: cx.querySelector('[data-nome="' + tagId + '"]').value,
            cor:  cx.querySelector('[data-cor="' + tagId + '"]').value
          })
        });
        if (r) { avisar('Etiqueta atualizada.', 'success'); cx.remove(); self.carregarTags(); }
      });
    });

    cx.querySelectorAll('[data-arquivar-tag]').forEach(function (a) {
      a.addEventListener('click', async function (ev) {
        ev.preventDefault();
        if (!(await confirmar('Arquivar esta etiqueta? Ela sai do seletor e continua nas fichas onde já está.',
                              { okLabel: 'Arquivar', danger: true }))) return;
        const r = await self.chamar('/api/crm_tags.php', {
          method: 'DELETE', headers: self.cabecalhos(),
          body: JSON.stringify({ tag_id: parseInt(a.getAttribute('data-arquivar-tag'), 10), arquivar: true, csrf_token: self.csrf })
        });
        if (r) { cx.remove(); self.carregarTags(); }
      });
    });
  };

  /* ===================================================================== */
  /* BLOCO C — campos personalizados                                       */
  /* ===================================================================== */

  Painel.prototype.carregarCampos = async function () {
    const d = await this.chamar('/api/crm_campos.php?entidade=' + this.entidade + '&id=' + this.id);
    if (!d) return;
    this.podeGerenciar = this.podeGerenciar || !!d.pode_gerenciar;
    this.desenharCampos(d.campos || []);
  };

  Painel.prototype.desenharCampos = function (campos) {
    const corpo = this.corpo('campos');
    if (!corpo) return;
    const self = this;

    if (!campos.length) {
      corpo.innerHTML = '<div class="crm-vazio">' +
        'Nenhum campo personalizado configurado' +
        (this.podeGerenciar ? '. Use “Gerenciar campos” para criar o primeiro.' : '.') +
        '</div>';
    } else {
      corpo.innerHTML =
        '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">' +
          campos.map(function (c) { return entrada(c); }).join('') +
        '</div>' +
        '<div style="margin-top:8px;"><button type="button" data-salvar-campos class="crm-btn crm-btn-ghost" ' +
        'style="font-size:.78rem;padding:6px 12px;">Salvar campos</button></div>';

      corpo.querySelector('[data-salvar-campos]').addEventListener('click', function () { self.salvarCampos(campos); });
    }

    const acoes = this.acoes('campos');
    if (acoes) {
      acoes.innerHTML = this.podeGerenciar
        ? '<a href="#" data-gerenciar-campos class="crm-link">Gerenciar campos</a>'
        : '';
      const link = acoes.querySelector('[data-gerenciar-campos]');
      if (link) link.addEventListener('click', function (ev) { ev.preventDefault(); self.gerenciarCampos(); });
    }

    /** Um controle por tipo. O `data-campo` guarda a chave, que é o que a API espera. */
    function entrada(c) {
      const k     = esc(c.chave);
      const rot   = esc(c.rotulo) + (parseInt(c.obrigatorio, 10) ? ' <span style="color:#fca5a5">*</span>' : '');
      const v     = c.valor == null ? '' : String(c.valor);
      const base  = 'class="crm-input" data-campo="' + k + '"';
      let controle;

      switch (c.tipo) {
        case 'texto_longo':
          controle = '<textarea ' + base + ' rows="3" maxlength="20000">' + esc(v) + '</textarea>';
          break;
        case 'data':
          controle = '<input type="date" ' + base + ' value="' + esc(v) + '">';
          break;
        case 'numero':
        case 'moeda':
          controle = '<input type="text" inputmode="decimal" ' + base + ' value="' + esc(v) + '" ' +
                     'placeholder="' + (c.tipo === 'moeda' ? '0,00' : '0') + '">';
          break;
        case 'sim_nao':
          controle = '<select ' + base + '>' +
            '<option value=""' + (v === '' ? ' selected' : '') + '>—</option>' +
            '<option value="1"' + (v === '1' ? ' selected' : '') + '>Sim</option>' +
            '<option value="0"' + (v === '0' ? ' selected' : '') + '>Não</option>' +
            '</select>';
          break;
        case 'selecao':
          controle = '<select ' + base + '><option value="">—</option>' +
            (c.opcoes || []).map(function (op) {
              return '<option value="' + esc(op) + '"' + (v === op ? ' selected' : '') + '>' + esc(op) + '</option>';
            }).join('') + '</select>';
          break;
        case 'multi_selecao': {
          // O valor vem como JSON: é assim que crm_campo_valores.valor guarda
          // multi-seleção, para caber numa coluna TEXT como todos os outros tipos.
          let sel = [];
          try { sel = JSON.parse(v) || []; } catch (e) { sel = v ? [v] : []; }
          controle = '<select multiple ' + base + ' size="' + Math.min(4, Math.max(2, (c.opcoes || []).length)) + '">' +
            (c.opcoes || []).map(function (op) {
              return '<option value="' + esc(op) + '"' + (sel.indexOf(op) >= 0 ? ' selected' : '') + '>' + esc(op) + '</option>';
            }).join('') + '</select>';
          break;
        }
        default:
          controle = '<input type="text" ' + base + ' value="' + esc(v) + '" maxlength="500">';
      }

      const quem = c.atualizado_por_nome
        ? '<div class="crm-vazio" style="margin-top:2px;">' + esc(c.atualizado_por_nome) +
          ' • ' + esc(fmtDataHora(c.updated_at)) + '</div>'
        : '';

      return '<div class="field"><label style="font-size:.78rem;">' + rot + '</label>' + controle + quem + '</div>';
    }
  };

  Painel.prototype.salvarCampos = async function (campos) {
    const corpo = this.corpo('campos');
    const valores = {};
    (campos || []).forEach(function (c) {
      const el = corpo.querySelector('[data-campo="' + c.chave + '"]');
      if (!el) return;
      if (c.tipo === 'multi_selecao') {
        valores[c.chave] = Array.prototype.slice.call(el.selectedOptions || []).map(function (o) { return o.value; });
      } else {
        valores[c.chave] = el.value;
      }
    });

    const d = await this.chamar('/api/crm_campos.php', {
      method: 'POST', headers: this.cabecalhos(),
      body: JSON.stringify({ acao: 'salvar', entidade: this.entidade, id: this.id, valores: valores, csrf_token: this.csrf })
    });
    if (!d) return;

    // Erro parcial: grava o que deu e diz qual campo não passou. Recusar o lote
    // inteiro faria a pessoa perder o que digitou nos outros.
    const chavesComErro = Object.keys(d.erros || {});
    if (chavesComErro.length) {
      avisar('Não gravou: ' + chavesComErro.map(function (k) { return k + ' (' + d.erros[k] + ')'; }).join('; '), 'error');
    } else if (d.gravados > 0) {
      avisar(d.gravados === 1 ? 'Campo gravado.' : d.gravados + ' campos gravados.', 'success');
    } else {
      avisar('Nada mudou.', 'info');
    }

    this.desenharCampos(d.campos || []);
    if (d.gravados > 0) this.aoMudar();
  };

  /** Painel embutido das definições: criar e arquivar campo. */
  Painel.prototype.gerenciarCampos = async function () {
    const corpo = this.corpo('campos');
    if (!corpo) return;
    const existente = corpo.querySelector('[data-cat-campos]');
    if (existente) { existente.remove(); return; }

    const d = await this.chamar('/api/crm_campos.php?definicoes=1');
    if (!d) return;
    const self = this;

    const ROTULO_TIPO = {
      texto: 'Texto', texto_longo: 'Texto longo', numero: 'Número', moeda: 'Moeda',
      data: 'Data', selecao: 'Seleção', multi_selecao: 'Múltipla escolha', sim_nao: 'Sim/Não'
    };

    const cx = document.createElement('div');
    cx.setAttribute('data-cat-campos', '1');
    cx.style.cssText = 'margin-top:10px;padding:10px;border:1px dashed var(--border);border-radius:8px;';
    cx.innerHTML =
      '<div class="crm-vazio" style="margin-bottom:8px;">' +
        'O tipo não muda depois de criado: trocar o tipo de um campo já preenchido deixaria ' +
        'valor inválido para trás. Quem precisa de outro tipo arquiva e cria outro campo.' +
      '</div>' +
      ((d.definicoes || []).length
        ? (d.definicoes || []).map(function (c) {
            return '<div class="crm-item">' +
              '<span><strong>' + esc(c.rotulo) + '</strong> ' +
                '<span class="crm-vazio">' + esc(ROTULO_TIPO[c.tipo] || c.tipo) +
                ' • ' + esc(c.aplica_em === 'ambos' ? 'cliente e prospecção' : c.aplica_em) +
                (parseInt(c.obrigatorio, 10) ? ' • obrigatório' : '') + '</span></span>' +
              '<a href="#" data-arquivar-campo="' + c.id + '" class="crm-link crm-link-perigo">Arquivar</a>' +
              '</div>';
          }).join('')
        : '<div class="crm-vazio">Nenhum campo personalizado ainda.</div>') +
      '<div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--border);' +
           'display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end;">' +
        '<div class="field"><label class="crm-rotulo">Nome do campo</label>' +
          '<input type="text" data-novo-rotulo class="crm-input" maxlength="120" placeholder="NIT, Data de admissão…"></div>' +
        '<div class="field"><label class="crm-rotulo">Tipo</label><select data-novo-tipo class="crm-input">' +
          Object.keys(ROTULO_TIPO).map(function (t) { return '<option value="' + t + '">' + esc(ROTULO_TIPO[t]) + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="field"><label class="crm-rotulo">Aparece em</label><select data-novo-aplica class="crm-input">' +
          '<option value="ambos">Cliente e prospecção</option>' +
          '<option value="cliente">Só cliente</option>' +
          '<option value="card">Só prospecção</option>' +
        '</select></div>' +
        '<div class="field" data-wrap-opcoes style="display:none;"><label class="crm-rotulo">Opções (uma por linha)</label>' +
          '<textarea data-novo-opcoes class="crm-input" rows="3"></textarea></div>' +
        '<div><button type="button" data-criar-campo class="crm-btn">Criar campo</button></div>' +
      '</div>';

    corpo.appendChild(cx);

    // As opções só existem para seleção e múltipla escolha, e são obrigatórias
    // nelas: a API recusa seleção sem opção.
    const selTipo    = cx.querySelector('[data-novo-tipo]');
    const wrapOpcoes = cx.querySelector('[data-wrap-opcoes]');
    const sincronizar = function () {
      wrapOpcoes.style.display = (selTipo.value === 'selecao' || selTipo.value === 'multi_selecao') ? '' : 'none';
    };
    selTipo.addEventListener('change', sincronizar);
    sincronizar();

    cx.querySelector('[data-criar-campo]').addEventListener('click', async function () {
      const rotulo = (cx.querySelector('[data-novo-rotulo]').value || '').trim();
      if (!rotulo) { avisar('Dê um nome ao campo.', 'error'); return; }
      const opcoes = (cx.querySelector('[data-novo-opcoes]').value || '')
        .split('\n').map(function (s) { return s.trim(); }).filter(Boolean);

      const r = await self.chamar('/api/crm_campos.php', {
        method: 'POST', headers: self.cabecalhos(),
        body: JSON.stringify({
          acao: 'definir', rotulo: rotulo, tipo: selTipo.value,
          aplica_em: cx.querySelector('[data-novo-aplica]').value,
          opcoes: opcoes, csrf_token: self.csrf
        })
      });
      if (r) { avisar('Campo criado.', 'success'); cx.remove(); self.carregarCampos(); }
    });

    cx.querySelectorAll('[data-arquivar-campo]').forEach(function (a) {
      a.addEventListener('click', async function (ev) {
        ev.preventDefault();
        if (!(await confirmar('Arquivar este campo? Os valores já preenchidos continuam gravados, o campo só sai das fichas.',
                              { okLabel: 'Arquivar', danger: true }))) return;
        const r = await self.chamar('/api/crm_campos.php', {
          method: 'DELETE', headers: self.cabecalhos(),
          body: JSON.stringify({ campo_id: parseInt(a.getAttribute('data-arquivar-campo'), 10), csrf_token: self.csrf })
        });
        if (r) { cx.remove(); self.carregarCampos(); }
      });
    });
  };

  /* ===================================================================== */
  /* BLOCO A — documentos                                                  */
  /* ===================================================================== */

  Painel.prototype.carregarAnexos = async function () {
    const d = await this.chamar('/api/crm_anexos.php?entidade=' + this.entidade + '&id=' + this.id);
    if (!d) return;
    this.desenharAnexos(d.anexos || [], d.limites || {});
  };

  Painel.prototype.desenharAnexos = function (anexos, limites) {
    const corpo = this.corpo('anexos');
    if (!corpo) return;
    const self = this;
    const maxMB = Math.round((limites.tamanho_maximo || 20971520) / 1048576);

    corpo.innerHTML =
      (anexos.length
        ? anexos.map(function (a) {
            // O selo "da prospecção" existe porque a ficha do cliente mostra os
            // documentos das prospecções de origem sem copiar nada. Sem o selo,
            // ninguém saberia de onde veio o arquivo.
            const selo = a.fase === 'prospeccao'
              ? '<span style="font-size:.7rem;padding:1px 6px;border-radius:999px;' +
                'background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.28);color:#fbbf24;">da prospecção</span>'
              : '';
            const meta = [a.enviado_por_nome, fmtDataHora(a.created_at), fmtTamanho(a.file_size)]
              .filter(Boolean).map(esc).join(' • ');
            return '<div class="crm-item">' +
              '<span style="min-width:0;">' +
                '<a href="' + esc(a.download_url) + '" style="font-weight:600;font-size:.84rem;color:#93c5fd;text-decoration:none;">' +
                  esc(a.file_name) + '</a> ' + selo +
                (a.descricao ? '<div class="crm-vazio">' + esc(a.descricao) + '</div>' : '') +
                '<div class="crm-vazio">' + meta + '</div>' +
              '</span>' +
              '<a href="#" data-tirar-anexo="' + a.id + '" class="crm-link crm-link-perigo">Remover</a>' +
              '</div>';
          }).join('')
        : '<div class="crm-vazio">Nenhum documento.</div>') +
      /*
       * O `<input type="file">` fica INVISÍVEL por cima de um rótulo que parece
       * botão. O widget nativo ("Escolher arquivo | Nenhum arquivo escolhido",
       * cinza) não é estilizável: nem cor, nem borda, nem o texto, que vem
       * travado do sistema operacional. Era o elemento mais destoante do bloco.
       *
       * O input continua existindo e recebendo o clique, então teclado e leitor
       * de tela seguem funcionando: some o visual nativo, não o controle.
       */
      '<div class="crm-linha" style="margin-top:8px;">' +
        '<label class="crm-arquivo">' +
          '<span class="crm-btn crm-btn-ghost">Escolher arquivo</span>' +
          '<input type="file" data-arquivo>' +
        '</label>' +
        '<span class="crm-arquivo-nome" data-arquivo-nome>Nenhum arquivo escolhido</span>' +
        '<input type="text" data-descricao class="crm-input" placeholder="Descrição (opcional)" ' +
               'maxlength="255" style="max-width:210px;">' +
        '<button type="button" data-subir class="crm-btn">Anexar</button>' +
        '<span class="crm-vazio">até ' + maxMB + ' MB</span>' +
      '</div>';

    // O nome do arquivo escolhido é escrito aqui, porque o rótulo nativo que
    // fazia isso foi escondido junto com o resto do widget.
    const campoArq = corpo.querySelector('[data-arquivo]');
    const nomeArq  = corpo.querySelector('[data-arquivo-nome]');
    if (campoArq && nomeArq) {
      campoArq.addEventListener('change', function () {
        const f = campoArq.files && campoArq.files[0];
        nomeArq.textContent = f ? f.name : 'Nenhum arquivo escolhido';
        nomeArq.title = f ? f.name : '';
      });
    }

    corpo.querySelectorAll('[data-tirar-anexo]').forEach(function (a) {
      a.addEventListener('click', async function (ev) {
        ev.preventDefault();
        if (!(await confirmar('Remover este documento? O arquivo sai do servidor e o registro fica no histórico.',
                              { okLabel: 'Remover', danger: true }))) return;
        const r = await self.chamar('/api/crm_anexos.php', {
          method: 'DELETE', headers: self.cabecalhos(),
          body: JSON.stringify({ id: parseInt(a.getAttribute('data-tirar-anexo'), 10), csrf_token: self.csrf })
        });
        if (r) { self.carregarAnexos(); self.aoMudar(); }
      });
    });

    corpo.querySelector('[data-subir]').addEventListener('click', function () { self.subirAnexo(); });
  };

  Painel.prototype.subirAnexo = async function () {
    const corpo = this.corpo('anexos');
    const input = corpo.querySelector('[data-arquivo]');
    const arq   = input && input.files && input.files[0];
    if (!arq) { avisar('Escolha um arquivo.', 'error'); return; }

    // multipart, então NÃO usa this.cabecalhos(): definir Content-Type à mão
    // quebraria o boundary que o navegador monta. O CSRF vai no header.
    const fd = new FormData();
    fd.append('entidade', this.entidade);
    fd.append('id', String(this.id));
    fd.append('file', arq);
    fd.append('descricao', (corpo.querySelector('[data-descricao]').value || '').trim());
    fd.append('csrf_token', this.csrf);

    const botao = corpo.querySelector('[data-subir]');
    botao.disabled = true;
    botao.textContent = 'Enviando…';

    const d = await this.chamar('/api/crm_anexos.php', {
      method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf }, body: fd
    });

    botao.disabled = false;
    botao.textContent = 'Anexar';
    if (!d) return;
    avisar('Documento anexado.', 'success');
    await this.carregarAnexos();
    this.aoMudar();
  };

  /* ===================================================================== */
  /* BLOCO D — contatos registrados e notas internas                       */
  /* ===================================================================== */

  Painel.prototype.carregarInteracoes = async function () {
    const d = await this.chamar('/api/crm_interacoes.php?entidade=' + this.entidade + '&id=' + this.id);
    if (!d) return;
    this.tiposInteracao = d.tipos || {};
    this.desenharInteracoes(d.interacoes || []);
  };

  Painel.prototype.desenharInteracoes = function (lista) {
    const corpo = this.corpo('interacoes');
    if (!corpo) return;
    const self  = this;
    const tipos = this.tiposInteracao || {};

    corpo.innerHTML =
      '<div class="crm-grade" style="padding:12px;border:1px dashed var(--border);' +
           'border-radius:10px;margin-bottom:10px;">' +
        '<div class="field"><label class="crm-rotulo">Tipo</label><select data-i-tipo class="crm-input">' +
          Object.keys(tipos).map(function (t) { return '<option value="' + esc(t) + '">' + esc(tipos[t]) + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="field" data-wrap-direcao><label class="crm-rotulo">Direção</label><select data-i-direcao class="crm-input">' +
          '<option value="">—</option><option value="entrada">Recebemos</option>' +
          '<option value="saida">Nós procuramos</option><option value="interna">Interna</option>' +
        '</select></div>' +
        '<div class="field"><label class="crm-rotulo">Quando aconteceu</label>' +
          '<input type="datetime-local" data-i-quando class="crm-input" value="' + agoraLocal() + '"></div>' +
        '<div class="field" data-wrap-duracao><label class="crm-rotulo">Duração (min)</label>' +
          '<input type="number" min="0" data-i-duracao class="crm-input" placeholder="30"></div>' +
        '<div class="field crm-grade-larga"><label class="crm-rotulo">Assunto</label>' +
          '<input type="text" data-i-assunto class="crm-input" maxlength="180"></div>' +
        '<div class="field crm-grade-larga"><label class="crm-rotulo">O que foi tratado</label>' +
          '<textarea data-i-conteudo class="crm-input" rows="3" maxlength="20000"></textarea></div>' +
        '<div class="crm-grade-larga"><button type="button" data-i-salvar class="crm-btn">Registrar</button></div>' +
      '</div>' +
      (lista.length
        ? lista.map(function (i) { return item(i); }).join('')
        : '<div class="crm-vazio">Nenhum contato registrado.</div>');

    // Nota interna não tem direção nem duração: ninguém ligou para ninguém.
    const selTipo = corpo.querySelector('[data-i-tipo]');
    const wrapDir = corpo.querySelector('[data-wrap-direcao]');
    const wrapDur = corpo.querySelector('[data-wrap-duracao]');
    const sincronizar = function () {
      const nota = selTipo.value === 'nota';
      wrapDir.style.display = nota ? 'none' : '';
      wrapDur.style.display = nota ? 'none' : '';
    };
    selTipo.addEventListener('change', sincronizar);
    sincronizar();

    corpo.querySelector('[data-i-salvar]').addEventListener('click', function () { self.registrarInteracao(); });

    corpo.querySelectorAll('[data-tirar-interacao]').forEach(function (a) {
      a.addEventListener('click', async function (ev) {
        ev.preventDefault();
        if (!(await confirmar('Remover este registro? Quem removeu e quando fica no histórico.',
                              { okLabel: 'Remover', danger: true }))) return;
        const r = await self.chamar('/api/crm_interacoes.php', {
          method: 'DELETE', headers: self.cabecalhos(),
          body: JSON.stringify({ id: parseInt(a.getAttribute('data-tirar-interacao'), 10), csrf_token: self.csrf })
        });
        if (r) { self.carregarInteracoes(); self.aoMudar(); }
      });
    });

    function item(i) {
      const selo = i.fase === 'prospeccao'
        ? '<span style="font-size:.7rem;padding:1px 6px;border-radius:999px;' +
          'background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.28);color:#fbbf24;">da prospecção</span>'
        : '';
      // "anotado depois" é honesto: o registro atrasado aparece na data do fato,
      // e sem esse aviso pareceria que foi digitado na hora.
      const atraso = i.retroativo
        ? '<span class="crm-vazio" title="Registrado em ' + esc(fmtDataHora(i.created_at)) + '">anotado depois</span>'
        : '';
      const dir = { entrada: 'recebemos', saida: 'nós procuramos', interna: 'interna' }[i.direcao] || '';
      const meta = [i.registrado_por_nome, fmtDataHora(i.ocorrido_em), dir,
                    i.duracao_min ? i.duracao_min + ' min' : '']
        .filter(Boolean).map(esc).join(' • ');

      return '<div style="padding:9px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;">' +
        '<div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;">' +
          '<span style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">' +
            '<strong>' + esc(i.tipo_rotulo) + '</strong>' + selo + atraso +
            (i.assunto ? '<span>' + esc(i.assunto) + '</span>' : '') +
          '</span>' +
          '<a href="#" data-tirar-interacao="' + i.id + '" class="crm-link crm-link-perigo">Remover</a>' +
        '</div>' +
        (i.conteudo
          ? '<div style="font-size:.84rem;white-space:pre-wrap;margin-top:4px;">' + esc(i.conteudo) + '</div>'
          : '') +
        '<div class="crm-vazio" style="margin-top:4px;">' + meta + '</div>' +
      '</div>';
    }
  };

  Painel.prototype.registrarInteracao = async function () {
    const corpo = this.corpo('interacoes');
    const pegar = function (sel) { const el = corpo.querySelector(sel); return el ? el.value : ''; };

    const assunto  = (pegar('[data-i-assunto]') || '').trim();
    const conteudo = (pegar('[data-i-conteudo]') || '').trim();
    if (!assunto && !conteudo) { avisar('Escreva o assunto ou o que foi tratado.', 'error'); return; }

    const d = await this.chamar('/api/crm_interacoes.php', {
      method: 'POST', headers: this.cabecalhos(),
      body: JSON.stringify({
        entidade: this.entidade, id: this.id,
        tipo:        pegar('[data-i-tipo]'),
        direcao:     pegar('[data-i-direcao]'),
        ocorrido_em: pegar('[data-i-quando]'),
        duracao_min: pegar('[data-i-duracao]'),
        assunto: assunto, conteudo: conteudo,
        csrf_token: this.csrf
      })
    });
    if (!d) return;
    avisar('Registrado.', 'success');
    this.desenharInteracoes(d.interacoes || []);
    this.aoMudar();
  };

  /* ===================================================================== */
  /* fachada                                                               */
  /* ===================================================================== */

  root.CrmFase2 = {
    /** Monta os quatro blocos e devolve o painel, para a tela poder recarregar. */
    montar: function (opcoes) {
      const p = new Painel(opcoes);
      p.montar();
      return p;
    },
    /** Limpa o container. Usado ao abrir a ficha em modo de criação. */
    limpar: function (host) {
      const el = typeof host === 'string' ? document.querySelector(host) : host;
      if (el) el.innerHTML = '';
    }
  };
})(window);
