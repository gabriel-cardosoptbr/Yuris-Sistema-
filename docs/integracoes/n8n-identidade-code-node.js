/**
 * n8n · Code Node · "Quem mandou essa mensagem"
 * =============================================================================
 *
 * Cole isto num Code Node em modo "Run Once for All Items", logo DEPOIS do nó
 * que recebe o webhook da Evolution.
 *
 * O QUE ELE RESOLVE
 * -----------------------------------------------------------------------------
 * O WhatsApp passou a endereçar conversa por `@lid`, um identificador de
 * privacidade que NÃO é o telefone. Quem lê só `remoteJid` recebe
 * `232366454870257@lid` e não tem como ligar para ninguém, nem saber quem é.
 *
 * O telefone real vem junto, em `remoteJidAlt` (e em `participantAlt` quando a
 * mensagem é de grupo). Medido nesta instalação, em 1.094 payloads reais:
 * 376 trazem o Alt, e nas mensagens RECEBIDAS são 249 de 329 `@lid`, ou 76%.
 *
 * ESTE CÓDIGO FOI ESCRITO CONTRA O PAYLOAD REAL, não contra documentação. Esta é
 * uma `key` de verdade, copiada da instalação:
 *
 *   {
 *     "id": "A52192CCAD2B10047AE08111317416A1",
 *     "fromMe": true,
 *     "remoteJid": "232366454870257@lid",
 *     "participant": "",                              <- STRING VAZIA, não ausente
 *     "remoteJidAlt": "5511997529604@s.whatsapp.net",
 *     "addressingMode": "lid"
 *   }
 *
 * Repare no `participant: ""`. Testar `if (key.participant)` funciona, testar
 * `if ('participant' in key)` não. Por isso tudo aqui passa por `limpar()`.
 *
 * O QUE ELE DEVOLVE, POR ITEM
 * -----------------------------------------------------------------------------
 *   phone             telefone só com dígitos, ou null
 *   jid               NNNN@s.whatsapp.net, ou null
 *   lid               NNNN@lid, ou null
 *   push_name         o apelido do WhatsApp, cru, só quando é do CONTATO
 *   name              o melhor nome (preenchido pelo Yuris, se consultado)
 *   name_source       de onde o nome veio
 *   contact_resolved  false = não sabemos o nome, a automação deve perguntar
 *   is_group / group_jid / from_me / message_id / instance / text
 *
 * DUAS FORMAS DE USAR
 * -----------------------------------------------------------------------------
 * 1. SÓ ESTE NÓ. Resolve telefone e LID sozinho, sem chamar ninguém. Já basta
 *    para deixar de tratar `@lid` como telefone.
 *
 * 2. ESTE NÓ + O YURIS (recomendado). Ligue CONSULTAR_YURIS abaixo. Ele chama
 *    `/api/whatsapp/identidade_resolve.php`, que devolve o nome consolidado (o
 *    do CRM ganha do apelido do WhatsApp) e ENSINA o vínculo de volta. O Yuris é
 *    a fonte de verdade porque é ele que guarda a ponte `@lid` <-> telefone: a
 *    Evolution não guarda, a tabela `Contact` dela não tem essa coluna.
 *
 * CONFIGURAR
 * -----------------------------------------------------------------------------
 * Não escreva segredo aqui. Crie as variáveis no n8n (Settings > Variables) e o
 * código lê sozinho:
 *
 *   YURIS_BASE_URL     ex.: https://app.yuris.com.br
 *   YURIS_APIKEY       a evolution_api_key do escritório (identifica o tenant)
 *   YURIS_WEBHOOK_TOKEN  o webhook_token do escritório (2o fator; obrigatório
 *                        quando o canal está em modo estrito)
 */

const CONSULTAR_YURIS = true;

// Falhar a consulta NUNCA pode derrubar o fluxo: sem o Yuris o item ainda sai
// com telefone e LID, que é a parte que este nó resolve sozinho.
const TIMEOUT_MS = 5000;

/* ========================================================================== */
/* helpers                                                                     */
/* ========================================================================== */

/** String de verdade, ou null. Cobre undefined, null, "" e "   ". */
function limpar(v) {
  if (typeof v !== 'string') return null;
  const s = v.trim();
  return s === '' ? null : s;
}

/** Só os dígitos da parte antes do `@`. */
function digitos(jid) {
  const s = limpar(jid);
  if (!s) return '';
  return s.split('@')[0].replace(/[^0-9]/g, '');
}

/**
 * Telefone discável? 10 a 13 dígitos cobre com e sem DDI, com e sem o nono
 * dígito. Um LID tem 15 ou mais, e é justamente o que este teto exclui.
 */
function telefoneValido(d) {
  return typeof d === 'string' && d.length >= 10 && d.length <= 13;
}

/**
 * Classifica um endereço sem adivinhar. O `tipo` é o que decide tudo depois.
 */
function analisar(jid) {
  const s = limpar(jid);
  if (!s) return { tipo: 'desconhecido', digitos: '', jid: null };

  const d = digitos(s);

  if (s.includes('@g.us'))       return { tipo: 'grupo',      digitos: d, jid: s };
  if (s.includes('@broadcast'))  return { tipo: 'broadcast',  digitos: d, jid: s };
  if (s.includes('@newsletter')) return { tipo: 'newsletter', digitos: d, jid: s };
  if (s.endsWith('@lid'))        return { tipo: 'lid',        digitos: d, jid: s };
  if (s.endsWith('@s.whatsapp.net') || s.endsWith('@c.us')) {
    return { tipo: 'telefone', digitos: d, jid: d + '@s.whatsapp.net' };
  }

  // Sem sufixo conhecido: só é telefone se PARECER telefone.
  if (telefoneValido(d)) return { tipo: 'telefone', digitos: d, jid: d + '@s.whatsapp.net' };
  return { tipo: 'desconhecido', digitos: d, jid: s };
}

/**
 * Extrai os endereços da `key`.
 *
 * A parte que mais erra na mão: EM GRUPO, a pessoa é o `participant`, e o
 * `remoteJid` é o grupo. Ler `remoteJid` em grupo faz o número DO GRUPO virar o
 * "telefone do contato", e a automação passa a responder para um endereço que
 * não é de ninguém.
 */
function enderecosDaKey(key) {
  const k = key && typeof key === 'object' ? key : {};

  const remoto    = limpar(k.remoteJid);
  const remotoAlt = limpar(k.remoteJidAlt);
  const part      = limpar(k.participant);
  const partAlt   = limpar(k.participantAlt);

  const ehGrupo = !!(remoto && remoto.includes('@g.us'));

  const principal = ehGrupo ? part    : remoto;
  const alt       = ehGrupo ? partAlt : remotoAlt;

  const a = analisar(principal);
  const b = analisar(alt);

  let lid = null, jid = null, phone = null;
  for (const x of [a, b]) {
    if (x.tipo === 'lid')      lid = x.jid;
    if (x.tipo === 'telefone') { jid = x.jid; phone = x.digitos; }
  }

  // O LID NUNCA É O TELEFONE. Caso real: um LID de 13 dígitos passou na
  // validação de tamanho e virou "telefone" 7623902498956, que não disca.
  // Mostrar um número desses é pior que não mostrar nada.
  if (phone && lid && digitos(lid) === phone) {
    phone = null;
    jid = null;
  }

  return {
    jid,
    lid,
    phone,
    is_group: ehGrupo,
    group_jid: ehGrupo ? remoto : null,
  };
}

/** O texto da mensagem, nos formatos que a Evolution manda. */
function textoDaMensagem(m) {
  const msg = (m && m.message) || {};
  return (
    limpar(msg.conversation) ||
    limpar(msg.extendedTextMessage && msg.extendedTextMessage.text) ||
    limpar(msg.imageMessage && msg.imageMessage.caption) ||
    limpar(msg.videoMessage && msg.videoMessage.caption) ||
    limpar(msg.documentMessage && msg.documentMessage.caption) ||
    limpar(msg.buttonsResponseMessage && msg.buttonsResponseMessage.selectedDisplayText) ||
    limpar(msg.listResponseMessage && msg.listResponseMessage.title) ||
    null
  );
}

/**
 * Desembrulha o item até achar as mensagens.
 *
 * A Evolution manda `{event, instance, data}`, e `data` às vezes é UM objeto com
 * `key`, às vezes um ARRAY deles. Dependendo de como o webhook do n8n está
 * configurado, tudo isso pode ainda vir dentro de `body`. Aceitamos as formas
 * todas em vez de exigir uma: exigir é o que quebra quando a Evolution muda.
 */
function mensagensDoItem(json) {
  const raiz = (json && json.body) ? json.body : (json || {});
  const dados = raiz.data !== undefined ? raiz.data : raiz;

  let lista;
  if (Array.isArray(dados))          lista = dados;
  else if (dados && dados.key)       lista = [dados];
  else if (dados && Array.isArray(dados.messages)) lista = dados.messages;
  else                               lista = [];

  return {
    evento: limpar(raiz.event) || null,
    instancia: limpar(raiz.instance) || limpar(dados && dados.instance) || null,
    mensagens: lista.filter((m) => m && m.key),
  };
}

/* ========================================================================== */
/* execução                                                                    */
/* ========================================================================== */

const base  = limpar($vars && $vars.YURIS_BASE_URL);
const chave = limpar($vars && $vars.YURIS_APIKEY);
const wtok  = limpar($vars && $vars.YURIS_WEBHOOK_TOKEN);
const podeConsultar = CONSULTAR_YURIS && !!base && !!chave;

const saida = [];

for (const item of $input.all()) {
  const { evento, instancia, mensagens } = mensagensDoItem(item.json);

  for (const m of mensagens) {
    const end = enderecosDaKey(m.key);

    // pushName é de quem ESCREVEU. Em mensagem própria ele é o nome do DONO da
    // conta, não do contato: usá-lo ali renomearia o cliente com o nome do
    // escritório.
    const fromMe = !!(m.key && m.key.fromMe);
    const pushName = fromMe ? null : limpar(m.pushName);

    const linha = {
      evento,
      instance: instancia,
      message_id: limpar(m.key && m.key.id),
      from_me: fromMe,
      addressing_mode: limpar(m.key && m.key.addressingMode),

      phone: end.phone,
      jid: end.jid,
      lid: end.lid,
      is_group: end.is_group,
      group_jid: end.group_jid,

      push_name: pushName,
      name: null,
      name_source: null,
      contact_resolved: false,

      text: textoDaMensagem(m),
      timestamp: (m && m.messageTimestamp) || null,
    };

    /*
     * Consulta ao Yuris. Melhora o nome e, ao mandar a `key` inteira, ENSINA o
     * vínculo de volta: o Alt que veio nesta mensagem fica gravado e vale para
     * todas as próximas, inclusive as que vierem sem ele.
     */
    if (podeConsultar && instancia && (end.lid || end.phone)) {
      try {
        const headers = { 'Content-Type': 'application/json', apikey: chave };
        if (wtok) headers['X-Webhook-Token'] = wtok;

        const r = await this.helpers.httpRequest({
          method: 'POST',
          url: base.replace(/\/+$/, '') + '/api/whatsapp/identidade_resolve.php',
          headers,
          body: { instance: instancia, key: m.key, pushName: pushName || '' },
          json: true,
          timeout: TIMEOUT_MS,
        });

        const id = r && r.identidade;
        if (id) {
          linha.phone            = id.phone || linha.phone;
          linha.jid              = id.jid   || linha.jid;
          linha.lid              = id.lid   || linha.lid;
          linha.name             = id.name || null;
          linha.name_source      = id.name_source || null;
          linha.push_name        = id.push_name || linha.push_name;
          linha.contact_resolved = !!id.contact_resolved;
        }
      } catch (e) {
        // Silencioso de propósito: o item continua útil sem o nome, e derrubar o
        // fluxo por causa do enriquecimento seria trocar um problema pequeno por
        // um grande. O motivo fica no item, para dar para auditar depois.
        linha.lookup_error = String((e && e.message) || e);
      }
    }

    /*
     * Último recurso para exibição. NÃO é nome: é rótulo. Fica num campo
     * separado para ninguém gravar isso como se fosse o nome da pessoa.
     */
    linha.display = linha.name
      || linha.push_name
      || (linha.phone ? '+' + linha.phone : null)
      || (linha.lid ? 'Contato ' + digitos(linha.lid).slice(-4) : 'Contato');

    saida.push({ json: linha });
  }
}

return saida;
