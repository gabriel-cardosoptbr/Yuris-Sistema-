/**
 * tarefas_link_test.js — o link do aviso abre a tarefa.
 *
 * O sino manda /tarefas.php?tarefa=ID no aviso "você é o responsável". Até
 * 28/09/2026 a tela ignorava o parâmetro: abria no primeiro quadro e a pessoa
 * procurava a tarefa sozinha, quando enxergava o quadro.
 *
 * Roda `abrirTarefaDoLink` extraída do arquivo real, com a tela simulada.
 *
 * Uso: node scripts/tests/tarefas_link_test.js
 */
const fs = require('fs');
const path = require('path');
const fonte = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'tarefas.js'), 'utf8');

let passou = 0, falhou = 0;
const ok = (c, m) => { if (c) { passou++; console.log('  [PASS] ' + m); } else { falhou++; console.log('  [FAIL] ' + m); } };

function extrai(nome) {
  const ini = fonte.indexOf('async function ' + nome + '(');
  if (ini < 0) throw new Error('função não encontrada: ' + nome);
  let i = fonte.indexOf('{', ini), prof = 0;
  for (; i < fonte.length; i++) {
    if (fonte[i] === '{') prof++;
    else if (fonte[i] === '}' && --prof === 0) break;
  }
  return fonte.slice(ini, i + 1);
}

async function cenario({ busca, tarefa, quadros, atual }) {
  const log = [];
  const env = {
    location: { search: busca, pathname: '/tarefas.php', hash: '' },
    history: { replaceState: (a, b, url) => log.push(['url', url]) },
    GET: async (p) => { log.push(['GET', p]); if (!tarefa) throw new Error('404'); return { data: tarefa }; },
    boards: quadros,
    currentBoard: atual,
    selectBoard: async (id) => { log.push(['selectBoard', id]); },
    renderBoardSelect: () => {},
    openDrawer: async (id) => { log.push(['openDrawer', id]); },
    showToast: (m) => log.push(['toast', m]),
    URLSearchParams,
  };
  const nomes = Object.keys(env);
  // eslint-disable-next-line no-new-func
  const fn = new Function(...nomes, extrai('abrirTarefaDoLink') + '\nreturn abrirTarefaDoLink;')(...nomes.map(n => env[n]));
  await fn();
  return log;
}
const tem = (log, tipo, v) => log.some(e => e[0] === tipo && (v === undefined || e[1] === v));

(async () => {
  console.log('\n== o link direto ==');

  let log = await cenario({ busca: '', tarefa: null, quadros: [], atual: null });
  ok(log.length === 0, 'sem ?tarefa= não faz nada');

  log = await cenario({ busca: '?tarefa=42', tarefa: { id: 42, board_id: 7 }, quadros: [{ id: 3 }, { id: 7 }], atual: { id: 3 } });
  ok(tem(log, 'selectBoard', 7), 'troca para o quadro da tarefa quando ele está na lista');
  ok(tem(log, 'openDrawer', 42), 'abre o painel da tarefa');
  ok(log.findIndex(e => e[0] === 'selectBoard') < log.findIndex(e => e[0] === 'openDrawer'),
     'troca o quadro ANTES de abrir o painel (o painel usa as colunas do quadro aberto)');
  ok(tem(log, 'url', '/tarefas.php'), 'tira o ?tarefa= da barra (recarregar não reabre)');

  log = await cenario({ busca: '?tarefa=42', tarefa: { id: 42, board_id: 9 }, quadros: [{ id: 3 }], atual: { id: 3 } });
  ok(!tem(log, 'selectBoard') && tem(log, 'openDrawer', 42),
     'quadro fora da lista (pessoal de outra pessoa): abre o painel direto, sem trocar de quadro');

  log = await cenario({ busca: '?tarefa=42', tarefa: { id: 42, board_id: 3 }, quadros: [{ id: 3 }], atual: { id: 3 } });
  ok(!tem(log, 'selectBoard') && tem(log, 'openDrawer', 42), 'já no quadro certo: não recarrega à toa');

  log = await cenario({ busca: '?tarefa=999', tarefa: null, quadros: [{ id: 3 }], atual: { id: 3 } });
  ok(tem(log, 'toast') && !tem(log, 'openDrawer'), 'tarefa inexistente ou sem acesso: avisa e não abre painel vazio');

  log = await cenario({ busca: '?tarefa=abc', tarefa: null, quadros: [], atual: null });
  ok(!tem(log, 'GET'), 'id que não é número nem consulta o servidor');

  log = await cenario({ busca: '?view=lista&tarefa=42', tarefa: { id: 42, board_id: 3 }, quadros: [{ id: 3 }], atual: { id: 3 } });
  ok(tem(log, 'url', '/tarefas.php?view=lista'), 'preserva os outros parâmetros da barra');

  console.log('\n== está ligado no boot ==');
  ok(/setView\('kanban'\);\s*\n(?:\s*\/\/.*\n)*\s*try \{ await abrirTarefaDoLink\(\); \}/.test(fonte),
     'o boot chama abrirTarefaDoLink depois de montar a tela');
  ok(/Number\(t\.board_id\) !== Number\(currentBoard\.id\)/.test(fonte),
     'o painel mostra a coluna real quando a tarefa é de outro quadro');

  console.log('\n== RESULTADO ==\n  passou: ' + passou + '\n  falhou: ' + falhou);
  process.exit(falhou ? 1 : 0);
})();
