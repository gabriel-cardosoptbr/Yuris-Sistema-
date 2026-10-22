/* ═══════════════════════════════════════════════════════════════════════
   sw.js: o service worker do app instalável (PWA, 22/10/2026).

   Fica na raiz de public/ porque o alcance de um service worker é a pasta de
   onde ele vem: daqui ele cobre o site inteiro.

   Faz UMA coisa: quando a internet cai no meio de uma navegação, mostra a
   página /offline.html em vez do dinossauro do navegador. NÃO guarda página,
   resposta de API nem dado de cliente: tudo continua vindo do servidor, sempre
   (o sistema é multi-conta e cache de página é como um dado vaza de uma conta
   para outra). O que fica guardado é só o offline.html.

   Só toca em navegação GET. POST de formulário, API, imagem e script passam
   direto, como se o service worker não existisse.

   Para desligar em produção: troque este arquivo pelo bloco "DESLIGAR" do
   public/README.md (o navegador busca a versão nova sozinho e se desregistra).
   ═══════════════════════════════════════════════════════════════════════ */
const CACHE = 'yuris-offline-v1';
const OFFLINE = '/offline.html';

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.add(new Request(OFFLINE, { cache: 'reload' })); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil((async function () {
    const nomes = await caches.keys();
    await Promise.all(nomes.filter(function (n) { return n !== CACHE; }).map(function (n) { return caches.delete(n); }));
    // Pré-carregamento da navegação: a página começa a vir do servidor enquanto o
    // service worker acorda, então ligar o app não deixa nada mais lento.
    if (self.registration.navigationPreload) await self.registration.navigationPreload.enable();
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', function (e) {
  const req = e.request;
  if (req.mode !== 'navigate' || req.method !== 'GET') return;
  e.respondWith((async function () {
    try {
      const pre = await e.preloadResponse;
      if (pre) return pre;
      return await fetch(req);
    } catch (_) {
      const c = await caches.open(CACHE);
      return (await c.match(OFFLINE)) || new Response('Sem conexão.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    }
  })());
});
