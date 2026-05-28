/**
 * Yuris.populateUserSelect — popula um <select> de usuários com agrupamento
 * por conta (matriz/filial), espelhando o helper PHP App\Helpers\UserOptions.
 *
 * Espera receber:
 *   sel     — elemento <select>
 *   users   — array no formato: [{id, nome, account_id, account_nome, account_tipo}, ...]
 *   opts    — { placeholder: string|null, selected: id|null, allowEmpty: bool }
 *
 * Se a lista tiver apenas 1 conta, renderiza <option> chapados (UI mais limpa).
 * Se tiver múltiplas, agrupa em <optgroup label="Matriz: X" / "Filial: Y">.
 */
(function (root) {
  root.Yuris = root.Yuris || {};

  root.Yuris.populateUserSelect = function (sel, users, opts) {
    if (!sel) return;
    opts = opts || {};
    const placeholder = (opts.placeholder !== undefined) ? opts.placeholder : '— Selecionar —';
    const allowEmpty  = opts.allowEmpty !== false;
    const selectedId  = opts.selected !== undefined && opts.selected !== null ? String(opts.selected) : null;

    // Preserva qualquer <option> com data-keep (ex.: "Todos") antes de limpar
    const keep = Array.from(sel.querySelectorAll('option[data-keep], optgroup[data-keep]'))
                      .map(n => n.cloneNode(true));
    sel.innerHTML = '';
    keep.forEach(n => sel.appendChild(n));

    if (allowEmpty && placeholder !== null && !sel.querySelector('option[value=""]')) {
      const o = document.createElement('option');
      o.value = ''; o.textContent = placeholder;
      sel.appendChild(o);
    }

    if (!Array.isArray(users) || users.length === 0) return;

    // Agrupa por account_id
    const groups = new Map();
    users.forEach(u => {
      const key = Number(u.account_id || 0);
      if (!groups.has(key)) {
        groups.set(key, {
          label: String(u.account_nome || 'Outros'),
          tipo:  String(u.account_tipo || ''),
          users: []
        });
      }
      groups.get(key).users.push(u);
    });

    const makeOpt = (u) => {
      const o = document.createElement('option');
      o.value = String(u.id);
      o.textContent = String(u.nome || '');
      if (selectedId !== null && String(u.id) === selectedId) o.selected = true;
      return o;
    };

    // 1 conta → sem optgroup
    if (groups.size <= 1) {
      users.forEach(u => sel.appendChild(makeOpt(u)));
      return;
    }

    // Múltiplas contas → optgroup, matriz primeiro
    const sorted = Array.from(groups.values()).sort((a, b) => {
      const ta = a.tipo === 'matriz' ? 0 : 1;
      const tb = b.tipo === 'matriz' ? 0 : 1;
      if (ta !== tb) return ta - tb;
      return a.label.localeCompare(b.label, 'pt-BR', { sensitivity: 'base' });
    });

    sorted.forEach(g => {
      const og = document.createElement('optgroup');
      const prefix = g.tipo === 'matriz' ? 'Matriz: ' : (g.tipo === 'filial' ? 'Filial: ' : '');
      og.label = prefix + g.label;
      g.users.forEach(u => og.appendChild(makeOpt(u)));
      sel.appendChild(og);
    });
  };

  /**
   * Helper para extrair só os usuários de um determinado account_id
   * (útil quando o consumidor quer filtrar antes de renderizar).
   */
  root.Yuris.filterUsersByAccount = function (users, accountId) {
    if (!Array.isArray(users) || !accountId) return users || [];
    const aid = Number(accountId);
    return users.filter(u => Number(u.account_id) === aid);
  };

  /**
   * Yuris.buildGroupedOptionsByName — igual ao populateUserSelect, mas retorna
   * uma STRING de HTML (<option>/<optgroup>) usando o NOME do usuário como value
   * em vez do id. Necessário para selects de "responsável" que persistem texto
   * livre (não FK user_id): tarefas processuais e edição de tarefa de processo.
   *
   *   users — [{nome, account_id, account_nome, account_tipo}, ...]
   *   opts  — { placeholder: string, selectedName: string|null }
   *
   * Agrupa em <optgroup label="Matriz: X"/"Filial: Y"> quando há +1 conta.
   */
  root.Yuris.buildGroupedOptionsByName = function (users, opts) {
    opts = opts || {};
    const placeholder  = (opts.placeholder !== undefined) ? opts.placeholder : '— Selecionar —';
    const selectedName = opts.selectedName || null;
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
    ));

    const ph = `<option value="">${esc(placeholder)}</option>`;
    if (!Array.isArray(users) || users.length === 0) return ph;

    const groups = new Map();
    users.forEach((u) => {
      const key = Number(u.account_id || 0);
      if (!groups.has(key)) {
        groups.set(key, {
          label: String(u.account_nome || 'Outros'),
          tipo:  String(u.account_tipo || ''),
          list:  []
        });
      }
      groups.get(key).list.push(u);
    });

    const optFor = (u) => {
      const sel = (selectedName && u.nome === selectedName) ? ' selected' : '';
      return `<option value="${esc(u.nome)}"${sel}>${esc(u.nome)}</option>`;
    };

    // 1 conta → sem optgroup (UI mais limpa)
    if (groups.size <= 1) return ph + users.map(optFor).join('');

    // Múltiplas → matriz primeiro, filiais alfabéticas
    const sorted = Array.from(groups.values()).sort((a, b) => {
      const ta = a.tipo === 'matriz' ? 0 : 1;
      const tb = b.tipo === 'matriz' ? 0 : 1;
      if (ta !== tb) return ta - tb;
      return a.label.localeCompare(b.label, 'pt-BR', { sensitivity: 'base' });
    });
    return ph + sorted.map((g) => {
      const prefix = g.tipo === 'matriz' ? 'Matriz: ' : (g.tipo === 'filial' ? 'Filial: ' : '');
      return `<optgroup label="${esc(prefix + g.label)}">${g.list.map(optFor).join('')}</optgroup>`;
    }).join('');
  };
})(window);
