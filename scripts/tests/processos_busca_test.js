/**
 * processos_busca_test.js — a pesquisa da Gestão Processual.
 *
 * O BUG RELATADO (28/09/2026): "essa pesquisa não está funcionando". Digitava
 * "0087" e o Calendário Processual continuava mostrando todos os processos.
 *
 * A causa não era a regra de busca, era ORDEM DE EXECUÇÃO. O cache que o filtro
 * usa só era preenchido por uma versão substituta de `loadColumnsForYear`,
 * atribuída por cima da original numa seção do arquivo que roda DEPOIS da carga
 * inicial da tela. A carga inicial chamava a original, o cache ficava vazio e o
 * filtro saía na primeira linha. A pesquisa só passava a funcionar depois de
 * trocar o ano ou salvar um processo.
 *
 * O que está sob teste:
 *
 *  1. ESTRUTURA: `loadColumnsForYear` não é mais reatribuída, e é ela mesma quem
 *     guarda o cache. Teste de comportamento sozinho não pegaria isso, porque o
 *     defeito era de ordem, não de regra.
 *  2. REGRA: o filtro de texto acha pelo número (com e sem pontuação), cliente,
 *     área, parte contrária e vara; sem acento; e não casa quase tudo com 1 ou 2
 *     dígitos soltos.
 *
 * Uso: node scripts/tests/processos_busca_test.js
 */

const fs = require('fs');
const path = require('path');

const arquivo = path.join(__dirname, '..', '..', 'public', 'assets', 'processos.js');
const fonte = fs.readFileSync(arquivo, 'utf8');

let passou = 0, falhou = 0;
function ok(cond, msg) {
  if (cond) { passou++; console.log('  [PASS] ' + msg); }
  else      { falhou++; console.log('  [FAIL] ' + msg); }
}
function secao(t) { console.log('\n== ' + t + ' =='); }

/* ===================================================================== */
secao('1. estrutura: o cache não depende de ordem de execução');
/* ===================================================================== */

ok(!/loadColumnsForYear\s*=\s*async/.test(fonte),
   'loadColumnsForYear não é mais reatribuída por um intercepto');

const corpoLoad = (fonte.match(/async function loadColumnsForYear\(year\)\{[\s\S]*?\n  \}/) || [''])[0];
ok(/_allProcessesCache\s*=\s*j\.data/.test(corpoLoad),
   'loadColumnsForYear guarda os processos no cache da busca');
ok(/renderColumns\(\s*filtered\s*\)/.test(corpoLoad),
   'loadColumnsForYear renderiza JÁ filtrado (respeita o que está digitado)');

const posDecl  = fonte.indexOf('let _allProcessesCache');
const posCarga = fonte.indexOf('// ── Initial load');
ok(posDecl > -1 && posCarga > -1 && posDecl < posCarga,
   'o cache é declarado antes da carga inicial da tela');

/* ===================================================================== */
secao('2. a regra de busca');
/* ===================================================================== */

/*
 * Extrai `_norm` e `_applyFilters` do arquivo real e roda com um document de
 * mentira. Assim o teste exercita o código que vai para produção, não uma cópia.
 */
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

const campos = {};
const document = { getElementById: (id) => (id in campos ? { value: campos[id] } : null) };
// eslint-disable-next-line no-new-func
const fabrica = new Function('document', extrai('_norm') + '\n' + extrai('_applyFilters') + '\nreturn _applyFilters;');
const aplicar = fabrica(document);

const procs = [
  { id: 1, numero: '00087123-4/2026', cliente_nome: 'Amanda Rodrigues Lima', setor_nome: 'Previdenciário', vara_comarca: 'JEF Rio de Janeiro', parte_contraria: 'INSS' },
  { id: 2, numero: '0001234-56.2026.8.26.0100', cliente_nome: 'Clínica São Lucas LTDA', setor_nome: 'Civil', vara_comarca: '1ª Vara Empresarial de São Paulo/SP', parte_contraria: 'Banco X' },
  { id: 3, numero: '00067890-7/2025', cliente_nome: 'Tatiana Cristina Lopes', setor_nome: 'Trabalhista', vara_comarca: '10ª Vara Cível', parte_contraria: '' },
];
function busca(txt) { campos.procSearchText = txt; return aplicar(procs).map(p => p.id); }
function igual(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

ok(igual(busca(''), [1, 2, 3]), 'sem termo, nada é escondido');
ok(igual(busca('0087'), [1]), '"0087" acha só o processo que tem 0087 no número (o caso do print)');
ok(igual(busca('00012345620268260100'), [2]), 'número CNJ digitado SEM pontuação acha o processo');
ok(igual(busca('0001234-56'), [2]), 'número digitado COM pontuação continua achando');
ok(igual(busca('clinica sao lucas'), [2]), 'nome do cliente sem acento acha');
ok(igual(busca('TRABALHISTA'), [3]), 'área, em maiúscula, acha');
ok(igual(busca('empresarial'), [2]), 'vara/comarca acha (aparece no card)');
ok(igual(busca('inss'), [1]), 'parte contrária acha');
ok(igual(busca('xyz-inexistente'), []), 'termo que não existe esvazia a lista');
/*
 * "4 5" não aparece como texto em número nenhum, mas os dígitos "45" existem
 * dentro do CNJ do processo 2. Com só 2 dígitos a comparação por dígitos NÃO
 * pode entrar, senão qualquer par de dígitos casaria com quase todo processo.
 * Com 3 ("4 5 6") ela entra e acha.
 */
ok(igual(busca('4 5'), []), 'com 2 dígitos a comparação só por dígitos não entra (evita casar tudo)');
ok(igual(busca('4 5 6'), [2]), 'com 3 dígitos ela entra e acha pelo número');

console.log('\n== RESULTADO ==\n  passou: ' + passou + '\n  falhou: ' + falhou);
process.exit(falhou ? 1 : 0);
