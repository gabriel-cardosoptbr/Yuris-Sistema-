/* ═══════════════════════════════════════════════════════════════════════
   sw.js: o service worker do app instalável (PWA, 22/10/2026).

   Fica na raiz de public/ porque o alcance de um service worker é a pasta de
   onde ele vem: daqui ele cobre o site inteiro.

   Faz duas coisas:
   1. quando a internet cai no meio de uma navegação, mostra a página
      /offline.html em vez do dinossauro do navegador;
   2. (desde 22/10/2026) recebe os avisos de tempo real (lead novo, menção)
      mandados por App\Notificacoes\Push e mostra a notificação no aparelho.
      Com o sistema aberto e em foco, em vez da notificação, passa o aviso
      para a página (assets/avisos.js toca o "bling"). Tocar na notificação
      abre o lead.

   NÃO guarda página,
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

/* ── Avisos (22/10/2026) ───────────────────────────────────────────────── */
self.addEventListener('push', function (e) {
  let a = {};
  try { a = e.data ? e.data.json() : {}; } catch (_) {}
  e.waitUntil((async function () {
    const janelas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const emFoco = janelas.filter(function (c) { return c.focused && c.visibilityState === 'visible'; });
    if (emFoco.length) {
      emFoco.forEach(function (c) { c.postMessage({ tipo: 'yuris-aviso', aviso: a }); });
      return;
    }
    await self.registration.showNotification(a.titulo || 'Aviso', {
      body: a.corpo || '',
      icon: a.icone || '/assets/app-fleetiflow-192.png?v=1',
      tag: a.id ? 'aviso-' + a.id : 'aviso',
      renotify: true,
      data: { url: a.url || '/dashboard' }
    });
  })());
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  let url = self.location.origin + '/dashboard';
  try {
    const u = new URL((e.notification.data && e.notification.data.url) || '/dashboard', self.location.origin);
    if (u.origin === self.location.origin) url = u.href;
  } catch (_) {}
  e.waitUntil((async function () {
    const janelas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of janelas) {
      try {
        await c.focus();
        if ('navigate' in c) { await c.navigate(url); return; }
      } catch (_) {}
    }
    await self.clients.openWindow(url);
  })());
});
