/**
 * processos_calendario_test.js — nenhum processo some do calendário.
 *
 * O RELATO (23/10/2026): "não tá vindo os processos". A tela mostrava "1
 * processo ativo" nos indicadores e "0 processos" no calendário.
 *
 * Medido em produção: o processo existe, está ativo, foi aberto em 2019 e não
 * tem prazo. O calendário filtra pelo ano visto (2026) usando o prazo ou a data
 * de início, e esse processo não tem nenhum dos dois em 2026. Pior: o seletor de
 * ano só ia de 2023 a 2027, então não havia ano para escolher.
 *
 * O que está sob teste, com as funções REAIS extraídas do arquivo:
 *
 *  1. agruparProcessosPorMes: sem prazo e aberto em outro ano vai para "Sem
 *     prazo", e não é espalhado num mês qualquer.
 *  2. anosDosProcessos: o seletor oferece todo ano que existe nos processos.
 *  3. a coluna "Sem prazo" é a primeira do quadro e usa o mesmo card dos meses.
 *
 * Uso: node scripts/tests/processos_calendario_test.js
 */
const fs = require('fs');
const path = require('path');
const fonte = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'processos.js'), 'utf8').replace(/\r\n/g, '\n'); // CRLF do Windows (autocrlf) nao pode quebrar as expressoes regulares

let passou = 0, falhou = 0;
const ok = (c, m) => { if (c) { passou++; console.log('  [PASS] ' + m); } else { falhou++; console.log('  [FAIL] ' + m); } };
const secao = (t) => console.log('\n== ' + t + ' ==');

function extrai(nome) {
  const ini = fonte.indexOf('function ' + nome + '(');
  if (ini < 0) throw new Error('função não encontrada: ' + nome);
  let i = fonte.indexOf('{', ini), prof = 0;
  for (; i < fonte.length; i++) {
    if (fonte[i] === '{') prof++;
    else if (fonte[i] === '}' && --prof === 0) break;
  }
  return fonte.slice(ini, i + 1);
}
// eslint-disable-next-line no-new-func
const lib = new Function(
  extrai('parseProcessDate') + '\n' + extrai('agruparProcessosPorMes') + '\n' + extrai('anosDosProcessos') +
  '\nreturn { agruparProcessosPorMes, anosDosProcessos };'
)();
const nomes = (arr) => arr.map(p => p.id).sort((a, b) => a - b);

/* ===================================================================== */
secao('1. em que coluna cada processo cai');
/* ===================================================================== */

// O caso real: aberto em 2019, sem prazo.
const caso = { id: 215, numero: '1009347-61.2019.8.26.0006', data_inicio: '2019-08-28', proximo_prazo: null, status: 'ativo' };

let g = lib.agruparProcessosPorMes([caso], 2026);
ok(nomes(g.semPrazo).join() === '215', 'O CASO DO RELATO: aberto em 2019 e sem prazo cai em "Sem prazo" quando se vê 2026');
ok(g.meses.every(m => m.length === 0), 'e NÃO é espalhado em agosto (antes ia para o mês da abertura, sem olhar o ano)');

g = lib.agruparProcessosPorMes([caso], 2019);
ok(g.meses[7].length === 1 && g.semPrazo.length === 0, 'vendo 2019, o mesmo processo aparece no mês da abertura (agosto)');

g = lib.agruparProcessosPorMes([{ id: 1, status: 'ativo' }], 2026);
ok(nomes(g.semPrazo).join() === '1', 'sem prazo e sem data nenhuma também vai para "Sem prazo"');

g = lib.agruparProcessosPorMes([{ id: 2, proximo_prazo: '2026-03-10', data_inicio: '2019-01-01' }], 2026);
ok(g.meses[2].length === 1 && g.semPrazo.length === 0, 'com prazo, vale o mês do prazo');

g = lib.agruparProcessosPorMes([{ id: 3, data_inicio: '2026-11-05', proximo_prazo: null }], 2026);
ok(g.meses[10].length === 1, 'sem prazo e aberto neste ano continua no mês da abertura (comportamento que já existia)');

g = lib.agruparProcessosPorMes(null, 2026);
ok(g.semPrazo.length === 0 && g.meses.length === 12, 'lista vazia ou nula não quebra');

const todos = [caso, { id: 1 }, { id: 2, proximo_prazo: '2026-03-10' }, { id: 3, data_inicio: '2026-11-05' }, { id: 4, proximo_prazo: '2027-02-01' }];
g = lib.agruparProcessosPorMes(todos, 2026);
const soma = g.semPrazo.length + g.meses.reduce((a, m) => a + m.length, 0);
ok(soma === todos.length, 'NENHUM processo se perde: todos caem em algum lugar (' + soma + ' de ' + todos.length + ')');

/* ===================================================================== */
secao('2. os anos do seletor');
/* ===================================================================== */

let anos = lib.anosDosProcessos([], 2026);
ok(anos.join() === '2027,2026,2025,2024,2023', 'sem processos, a janela de sempre: ano que vem e três para trás');

anos = lib.anosDosProcessos([caso], 2026);
ok(anos.includes(2019), 'O CASO DO RELATO: 2019 passa a existir no seletor');
ok(anos.join() === [...anos].sort((a, b) => b - a).join(), 'em ordem decrescente');
ok(new Set(anos).size === anos.length, 'sem repetir ano');

anos = lib.anosDosProcessos([{ proximo_prazo: '2018-05-01' }, { data_inicio: '2031-01-01' }], 2026);
ok(anos.includes(2018) && anos.includes(2031), 'conta o ano do prazo e o da abertura');

anos = lib.anosDosProcessos([{ data_inicio: '1900-01-01' }, { proximo_prazo: '2099-01-01' }, { data_inicio: '0000-00-00' }], 2026);
ok(!anos.includes(1900) && !anos.includes(2099), 'data digitada errada (1900, 2099) não polui a lista');

/* ===================================================================== */
secao('3. a coluna "Sem prazo" na tela');
/* ===================================================================== */

const corpo = extrai('renderColumns');
ok(corpo.indexOf('data-month="nodate"') < corpo.indexOf('for (let m=0;m<12;m++)'),
   '"Sem prazo" é montada ANTES dos meses, ou seja, é a primeira coluna');
ok(/noDateGroup\.forEach\(p => \{ html \+= cardHtml\(p\); \}\)/.test(corpo),
   'usa o mesmo card dos meses (a cópia simplificada tinha uma tag sem fechar)');
ok(!/groups\[d\.getMonth\(\)\]\.push/.test(corpo), 'não agrupa mais por conta própria, sem olhar o ano');
ok(/agruparProcessosPorMes\(processes, parseInt\(yearFilter\.value, 10\)\)/.test(corpo), 'agrupa pelo ano que a pessoa está vendo');

const quantasInsercoes = (fonte.match(/board\.insertBefore\(col, board\.firstChild\)/g) || []).length;
ok(quantasInsercoes === 2, 'ao cadastrar ou mover, a coluna "Sem prazo" também entra primeiro (' + quantasInsercoes + ' lugares)');

const load = extrai('loadUpcoming');
ok(/atualizarAnosDoSeletor\(allData\)/.test(load), 'o seletor de ano é atualizado com os processos carregados');

console.log('\n== RESULTADO ==\n  passou: ' + passou + '\n  falhou: ' + falhou);
process.exit(falhou ? 1 : 0);
