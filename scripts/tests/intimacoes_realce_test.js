/**
 * intimacoes_realce_test.js — o realce do que a conta monitora, na tela de
 * Intimações.
 *
 * O BUG RELATADO: "tem nome que não ficou em negrito". O mesmo advogado aparecia
 * em negrito num card e apagado no card de baixo, porque o destaque só existia
 * na linha "Adv:" estruturada, e boa parte das publicações não traz essa lista
 * separada: o advogado vem cru no meio do texto.
 *
 * E um segundo furo, que ninguém tinha relatado ainda: o destaque usava SÓ a OAB
 * do perfil pessoal do usuário logado. Quem não tem OAB no perfil (secretária,
 * estagiário, sócio administrador) não via NADA em negrito, olhando exatamente
 * as publicações que a conta monitora.
 *
 * O que está sob teste, então:
 *
 *  - o realce acha a OAB e o nome no meio do texto corrido
 *  - NÃO casa pedaço de palavra nem número maior que o procurado
 *  - roda DEPOIS do escape, e nada do conteúdo vira marcação
 *  - sem destaque configurado, o texto sai intocado
 *
 * Uso: node scripts/tests/intimacoes_realce_test.js
 */

const fs = require('fs');
const path = require('path');

const arquivo = path.join(__dirname, '..', '..', 'public', 'assets', 'intimacoes.js');
const fonte = fs.readFileSync(arquivo, 'utf8');

/*
 * O arquivo real é um objeto gigante ligado ao DOM. Em vez de carregá-lo, recorta
 * as duas funções sob teste e monta um objeto mínimo com elas. Assim o teste
 * exercita o CÓDIGO DE VERDADE, e não uma cópia que envelhece sozinha.
 */
function recortar(nome) {
  const marca = '    ' + nome + '(';
  const ini = fonte.indexOf(marca);
  if (ini < 0) throw new Error('não achei a função ' + nome + ' em intimacoes.js');
  // Fecha no primeiro "\n    }," na mesma indentação do método.
  const fim = fonte.indexOf('\n    },', ini);
  if (fim < 0) throw new Error('não achei o fim de ' + nome);
  return fonte.slice(ini, fim + '\n    }'.length);
}

const alvo = new Function(
  'return {' + recortar('_realcar') + ',' + recortar('_esc') + ',' + recortar('_oabMonitorada') + '};'
)();

let P = 0, F = 0;
const ok = (c, m) => { if (c) { P++; console.log('  [PASS] ' + m); } else { F++; console.log('  [FAIL] ' + m); } };
const eq = (esp, obt, m) => {
  if (esp === obt) { P++; console.log('  [PASS] ' + m); }
  else { F++; console.log('  [FAIL] ' + m + '\n           esperado: ' + esp + '\n           obtido:   ' + obt); }
};
const secao = (t) => console.log('\n== ' + t + ' ==');

/** Roda o realce como a tela roda: escapa primeiro, realça depois. */
function realcar(texto, destaques) {
  alvo.destaques = destaques;
  return alvo._realcar(alvo._esc(texto));
}

const MONITORADO = {
  oabs:  [{ oab: '357838', uf: 'SP' }],
  nomes: ['BRUNO CARREIRA FERREIRA'],
};

/* ===================================================================== */
secao('Acha no meio do texto corrido (o bug relatado)');

// Trecho REAL da publicação do print.
const real = '- ADV: WILLIAM GRESPAN GARCIA (OAB 346592/SP), BRUNO CARREIRA FERREIRA (OAB 357838/SP), VICTOR ZOCARATO (OAB 399918/SP)';
const saida = realcar(real, MONITORADO);

ok(saida.includes('<strong class="int-realce">BRUNO CARREIRA FERREIRA</strong>'),
   'o nome monitorado fica em negrito no meio do texto');
ok(saida.includes('<strong class="int-realce">357838</strong>'),
   'a OAB monitorada também');
ok(!saida.includes('<strong class="int-realce">WILLIAM'),
   'o advogado que NÃO é monitorado continua sem destaque');
ok(!saida.includes('346592</strong>'),
   'e a OAB dele também não');

/* ===================================================================== */
secao('Não casa pedaço de palavra nem número maior');

eq('O processo 13578381 segue',
   realcar('O processo 13578381 segue', MONITORADO),
   '357838 dentro de um número maior NÃO é destacado');

eq('Sobrenome FERREIRAS aqui',
   realcar('Sobrenome FERREIRAS aqui', MONITORADO),
   'nome com sufixo colado não casa');

ok(realcar('OAB 357838/SP', MONITORADO).includes('>357838</strong>'),
   'mas com barra logo depois casa, porque barra não é letra');
ok(realcar('(357838)', MONITORADO).includes('>357838</strong>'),
   'entre parênteses também');
ok(realcar('bruno carreira ferreira assinou', MONITORADO).includes('int-realce'),
   'a busca ignora maiúscula e minúscula');

/* ===================================================================== */
secao('Segurança: realça DEPOIS do escape, e nada vira marcação');

const perigoso = 'Advogado <script>alert(1)</script> BRUNO CARREIRA FERREIRA';
const s1 = realcar(perigoso, MONITORADO);
ok(!s1.includes('<script>'), 'a tag do conteúdo continua escapada');
ok(s1.includes('&lt;script&gt;'), '  e aparece como texto');
ok(s1.includes('int-realce'), '  e o realce legítimo continua funcionando');

// Agulha com metacaractere de regex: não pode virar curinga nem quebrar a busca.
const comMeta = { oabs: [], nomes: ['DR. JOAO (SOCIO)'] };
const s2 = realcar('Assina DR. JOAO (SOCIO) pelo escritorio', comMeta);
ok(s2.includes('<strong class="int-realce">DR. JOAO (SOCIO)</strong>'),
   'nome com ponto e parênteses é encontrado literalmente');
ok(!realcar('Assina DRXJOAO YSOCIOZ pelo escritorio', comMeta).includes('int-realce'),
   '  e o ponto NÃO virou curinga');

// Agulha com aspas: o escape da agulha tem de bater com o escape do texto.
const comAspas = { oabs: [], nomes: ['ESCRITORIO "ALFA" LTDA'] };
ok(realcar('parte ESCRITORIO "ALFA" LTDA representada', comAspas).includes('int-realce'),
   'nome com aspas casa, porque a agulha é escapada igual ao texto');

/*
 * A ORDEM DA CHAMADA, conferida na FONTE.
 *
 * Os testes acima chamam `_realcar(_esc(texto))` por conta própria, então
 * passariam mesmo se a tela invertesse a ordem. E inverter é exatamente a falha
 * grave: realçar ANTES de escapar faria o `<strong>` ser escapado (o realce some)
 * e, pior, qualquer marcação vinda do conteúdo passaria intacta.
 *
 * Isto foi descoberto quebrando o código de propósito: a inversão não derrubou
 * nenhum teste. Só uma asserção sobre a fonte pega.
 */
secao('A tela realça DEPOIS de escapar (conferido na fonte)');

const chamada = /_realcar\(\s*esc\(/.test(fonte);
ok(chamada, 'renderCard chama _realcar(esc(...)), e não o contrário');
ok(!/esc\(\s*this\._realcar\(/.test(fonte),
   '  e não existe esc(this._realcar(...)) em lugar nenhum do arquivo');

/* ===================================================================== */
secao('Sem destaque configurado, não mexe no texto');

eq('Texto qualquer', realcar('Texto qualquer', { oabs: [], nomes: [] }),
   'listas vazias devolvem o texto intocado');
eq('Texto qualquer', realcar('Texto qualquer', {}),
   'objeto sem as chaves não quebra');

// Nome curto é descartado: casaria pedaço de qualquer coisa.
eq('Ana estava presente', realcar('Ana estava presente', { oabs: [], nomes: ['Ana'] }),
   'nome com menos de 8 caracteres é ignorado (casaria meia palavra)');

/* ===================================================================== */
secao('A agulha mais longa ganha da mais curta');

const dois = { oabs: [], nomes: ['BRUNO CARREIRA', 'BRUNO CARREIRA FERREIRA'] };
const s3 = realcar('O advogado BRUNO CARREIRA FERREIRA assinou', dois);
ok(s3.includes('<strong class="int-realce">BRUNO CARREIRA FERREIRA</strong>'),
   'o nome completo vence o pedaço dele');
ok((s3.match(/int-realce/g) || []).length === 1,
   '  e não sai realce dentro de realce');

/* ===================================================================== */
secao('_oabMonitorada: a linha "Adv:" usa os monitores, não só o perfil');

alvo.destaques = { oabs: [{ oab: '357838', uf: 'SP' }, { oab: '219955', uf: '' }], nomes: [] };
ok(alvo._oabMonitorada('357838', 'SP'), 'OAB monitorada com UF igual');
ok(alvo._oabMonitorada('OAB 357838', 'sp'), '  aceita o número sujo e a UF minúscula');
ok(!alvo._oabMonitorada('357838', 'RJ'), 'UF diferente NÃO é a mesma inscrição');
ok(alvo._oabMonitorada('219955', 'SP'), 'monitor sem UF casa com qualquer UF');
ok(alvo._oabMonitorada('219955', ''), '  e com UF ausente também');
ok(!alvo._oabMonitorada('346592', 'SP'), 'OAB que a conta não monitora fica de fora');
ok(!alvo._oabMonitorada('', 'SP'), 'OAB vazia não casa nada');

console.log('\n' + (F === 0 ? 'OK' : 'FALHOU') + '  ' + P + ' PASS, ' + F + ' FAIL');
process.exit(F === 0 ? 0 : 1);
