/**
 * n8n_code_node_test.js — o Code Node do n8n, contra os payloads REAIS.
 *
 * O Code Node vive colado no n8n, longe de qualquer suite. Este teste carrega o
 * arquivo de verdade (docs/integracoes/n8n-identidade-code-node.js), recorta os
 * helpers e exercita os casos que ja mordram em producao:
 *
 *   - a `key` real, com `participant: ""` (string VAZIA, nao ausente)
 *   - grupo, onde a pessoa e o `participant` e nao o `remoteJid`
 *   - o LID de 13 digitos que passava por telefone e nao discava
 *   - os tres formatos de envelope que a Evolution manda
 *
 * Se alguem editar o Code Node e quebrar um desses, aqui aparece.
 *
 * Uso: node scripts/tests/n8n_code_node_test.js
 */

const fs = require('fs');
let src = fs.readFileSync(require('path').join(__dirname, '..', '..', 'docs', 'integracoes', 'n8n-identidade-code-node.js'),'utf8');
// Recorta so os helpers (ate a secao de execucao), que e o que da para testar fora do n8n.
src = src.split('/* ========================================================================== */\n/* execução')[0];
const mod = new Function(src + '\nreturn {limpar, digitos, telefoneValido, analisar, enderecosDaKey, textoDaMensagem, mensagensDoItem};')();

let P=0,F=0;
const eq=(a,b,m)=>{ if(JSON.stringify(a)===JSON.stringify(b)){P++;console.log('  [PASS] '+m);} else {F++;console.log('  [FAIL] '+m+'  esperado '+JSON.stringify(a)+' obtido '+JSON.stringify(b));} };

console.log('== a key REAL de producao ==');
const keyReal = {id:"A52192CCAD2B10047AE08111317416A1",fromMe:true,remoteJid:"232366454870257@lid",participant:"",remoteJidAlt:"5511997529604@s.whatsapp.net",addressingMode:"lid"};
const r1 = mod.enderecosDaKey(keyReal);
eq('5511997529604', r1.phone, 'tira o telefone real do remoteJidAlt');
eq('232366454870257@lid', r1.lid, 'e guarda o LID');
eq(false, r1.is_group, 'participant vazio NAO faz virar grupo');

console.log('== grupo ==');
const r2 = mod.enderecosDaKey({remoteJid:"120363000000000000@g.us",participant:"232366454870257@lid",participantAlt:"5511997529604@s.whatsapp.net"});
eq(true, r2.is_group, 'reconhece grupo');
eq('5511997529604', r2.phone, 'em grupo o telefone vem do participantAlt');
eq('120363000000000000@g.us', r2.group_jid, 'o grupo fica em campo proprio');

const r3 = mod.enderecosDaKey({remoteJid:"120363000000000000@g.us"});
eq(null, r3.phone, 'o numero do GRUPO nunca vira telefone de pessoa');

console.log('== sem o Alt, nao inventa ==');
const r4 = mod.enderecosDaKey({remoteJid:"232366454870257@lid"});
eq(null, r4.phone, 'sem Alt nao ha telefone');
eq('232366454870257@lid', r4.lid, 'so o LID');

console.log('== o LID que parece telefone ==');
const r5 = mod.enderecosDaKey({remoteJid:"7623902498956@lid",remoteJidAlt:"7623902498956@s.whatsapp.net"});
eq(null, r5.phone, 'LID de 13 digitos NAO vira telefone');

console.log('== key faltando / lixo ==');
eq(null, mod.enderecosDaKey({}).phone, 'key vazia nao explode');
eq(null, mod.enderecosDaKey(null).phone, 'key null nao explode');
eq(null, mod.enderecosDaKey({remoteJid:null}).lid, 'remoteJid null nao explode');
eq(null, mod.enderecosDaKey({remoteJid:"status@broadcast"}).phone, 'broadcast nao vira contato');

console.log('== envelopes ==');
const env1 = mod.mensagensDoItem({event:"messages.upsert",instance:"mariafernanda-83",data:{key:keyReal,pushName:"Fe"}});
eq(1, env1.mensagens.length, 'data como objeto unico');
eq('mariafernanda-83', env1.instancia, 'pega o nome da instancia');
const env2 = mod.mensagensDoItem({body:{event:"messages.upsert",instance:"x",data:[{key:keyReal},{key:keyReal}]}});
eq(2, env2.mensagens.length, 'data como array, dentro de body');
eq(0, mod.mensagensDoItem({}).mensagens.length, 'item vazio devolve zero, sem explodir');
eq(0, mod.mensagensDoItem({data:{semKey:1}}).mensagens.length, 'data sem key e descartado');

console.log('== texto ==');
eq('oi', mod.textoDaMensagem({message:{conversation:"oi"}}), 'conversation');
eq('oi', mod.textoDaMensagem({message:{extendedTextMessage:{text:"oi"}}}), 'extendedTextMessage');
eq(null, mod.textoDaMensagem({message:{audioMessage:{}}}), 'audio nao tem texto, devolve null');
eq(null, mod.textoDaMensagem({}), 'sem message nao explode');

console.log('\n' + (F===0?'OK':'FALHOU') + '  ' + P + ' PASS, ' + F + ' FAIL');
process.exit(F===0?0:1);
