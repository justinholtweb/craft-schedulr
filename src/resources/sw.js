/**
 * Schedulr's service worker.
 *
 * Served from the site root so its scope covers the whole origin — a worker at `/assets/sw.js`
 * can only ever control `/assets/`, which is the single most common reason push "doesn't work".
 *
 * This file is only used when Schedulr owns the registration. On a site running PWA, PWA's worker
 * receives Schedulr's pushes instead and renders them correctly with no changes, because the two
 * payload shapes are the same. See services/Interop.php.
 */

const CONFIG = /*__SCHEDULR_CONFIG__*/ {};

/* ------------------------------------------------------------------------------- lifecycle */

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

/* ------------------------------------------------------------------------------------ push */

self.addEventListener('push', (event) => {
  let payload = {};

  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    // A push service is allowed to deliver a bare string, and some test tools do. Showing it is
    // better than showing nothing, because a notification that never appears has no error to read.
    payload = { title: (event.data && event.data.text()) || '' };
  }

  const title = payload.title || CONFIG.defaultTitle || '';

  if (!title) return;

  event.waitUntil(
    (async () => {
      await self.registration.showNotification(title, {
        body: payload.body || '',
        icon: payload.icon || CONFIG.defaultIcon || undefined,
        badge: payload.badge || CONFIG.defaultBadge || undefined,
        image: payload.image || undefined,
        tag: payload.tag || undefined,
        requireInteraction: !!payload.requireInteraction,
        actions: Array.isArray(payload.actions) ? payload.actions.slice(0, 2) : undefined,
        data: {
          url: payload.url || CONFIG.homeUrl || '/',
          n: payload.n || null,
          v: payload.v || null,
          s: payload.s || null,
          k: payload.k || null,
        },
      });

      report('displayed', payload);
    })()
  );
});

self.addEventListener('notificationclick', (event) => {
  const data = event.notification.data || {};
  const action = event.action;

  event.notification.close();

  // An action button carries its own URL; the notification body falls back to the main one.
  const actions = Array.isArray(event.notification.actions) ? event.notification.actions : [];
  const matched = action ? actions.find((a) => a.action === action) : null;
  const raw = (matched && matched.url) || data.url || '/';
  const target = safeUrl(raw);

  event.waitUntil(
    (async () => {
      const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

      // Focusing an open tab beats opening a second one showing the same thing.
      for (const client of clients) {
        if (client.url === target && 'focus' in client) {
          return client.focus();
        }
      }

      if (self.clients.openWindow) {
        return self.clients.openWindow(target);
      }
    })()
  );
});

self.addEventListener('notificationclose', (event) => {
  const data = event.notification.data || {};

  // A dismissal is the only negative signal push gives you, and it is the one that tells you a
  // campaign was unwelcome rather than merely unclicked.
  event.waitUntil(report('dismissed', data));
});

/* ------------------------------------------------------------ subscription maintenance */

self.addEventListener('pushsubscriptionchange', (event) => {
  // A push service can rotate a subscription with no page open. Re-subscribing here is the
  // difference between a device that keeps working and one that silently stops receiving anything
  // — and there is no other moment at which the browser will tell you.
  event.waitUntil(
    (async () => {
      if (!CONFIG.vapidPublicKey || !CONFIG.subscribeUrl) return;

      try {
        const subscription = await self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: base64ToUint8(CONFIG.vapidPublicKey),
        });

        await fetch(CONFIG.subscribeUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({
            visitorId: CONFIG.visitorId || null,
            subscription: subscription.toJSON(),
            previousEndpoint: (event.oldSubscription && event.oldSubscription.endpoint) || null,
          }),
        });
      } catch (e) {
        // Nothing useful to do here. The next page load re-subscribes.
      }
    })()
  );
});

/* ---------------------------------------------------------------------------------- helpers */

async function report(type, data) {
  if (!CONFIG.eventUrl || !data || !data.n) return;

  try {
    await fetch(CONFIG.eventUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      // `k` is the signature the payload carried for this subscriber. Without it the server cannot
      // tell this report from anybody else's guess at a subscriber ID, and refuses it.
      body: JSON.stringify({ type, n: data.n, v: data.v || null, s: data.s || null, k: data.k || null }),
    });
  } catch (e) {
    // An unreported event is not worth failing a notification over.
  }
}

/**
 * The URL to open, or the site root when it is not an http(s) one.
 *
 * `openWindow()` with a `javascript:` or `data:` URL is refused by current browsers, but "current" is
 * doing the work in that sentence, and the server already promises it never stores one. Belt and braces.
 */
function safeUrl(raw) {
  try {
    const url = new URL(raw, self.location.origin);

    if (url.protocol === 'https:' || url.protocol === 'http:') return url.href;
  } catch (e) {
    // Unparseable. Falls through to the root.
  }

  return new URL('/', self.location.origin).href;
}

function base64ToUint8(value) {
  const padded = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
  const raw = self.atob(padded);
  const bytes = new Uint8Array(raw.length);

  for (let i = 0; i < raw.length; i++) {
    bytes[i] = raw.charCodeAt(i);
  }

  return bytes;
}
