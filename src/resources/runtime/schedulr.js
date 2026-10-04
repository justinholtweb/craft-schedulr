/**
 * Schedulr's front-end runtime.
 *
 * Three jobs: keep a visitor identity, ask for push permission at a moment that isn't rude, and
 * render on-site notifications for the people who said no to push.
 *
 * **The visitor identity lives in localStorage, not a cookie**, and is posted with every call. A
 * `Set-Cookie` on an HTML response poisons every full-page cache in front of the site — the first
 * visitor's identity gets served to everybody after them, which is both a broken feature and a
 * privacy incident. Nothing here ever asks the server to set a cookie.
 */
(function () {
  'use strict';

  var CONFIG = window.__SCHEDULR__ || {};

  if (!CONFIG.heartbeatUrl) return;

  var KEY_ID = 'schedulr.visitor';
  var KEY_VIEWS = 'schedulr.views';
  var KEY_DECLINED = 'schedulr.declined';
  var KEY_SEEN = 'schedulr.seen';
  var KEY_PROMPTED = 'schedulr.prompted';

  /* -------------------------------------------------------------------------- storage */

  function get(key, fallback) {
    try {
      var value = window.localStorage.getItem(key);
      return value === null ? fallback : value;
    } catch (e) {
      // Private browsing, or storage disabled entirely. Everything below degrades to "this is a
      // brand new visitor on every page", which is the correct behaviour when we cannot remember
      // them — not an error worth logging on every page load.
      return fallback;
    }
  }

  function set(key, value) {
    try {
      window.localStorage.setItem(key, String(value));
    } catch (e) {
      /* as above */
    }
  }

  // The visitor ID is the only credential the on-site inbox and event endpoints have, so it must be
  // unguessable. `Math.random()` is not: its state can be recovered from a handful of outputs. A
  // browser with neither `randomUUID` nor `getRandomValues` is old enough that it has no push either,
  // and the runtime simply does not run there.
  function uuid() {
    var c = window.crypto;

    if (c && c.randomUUID) {
      return c.randomUUID();
    }

    if (!c || !c.getRandomValues) return null;

    var bytes = c.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    var hex = '';

    for (var i = 0; i < 16; i++) {
      hex += (bytes[i] + 0x100).toString(16).slice(1);
    }

    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
  }

  var visitorId = get(KEY_ID, null);

  if (!visitorId) {
    visitorId = uuid();

    if (!visitorId) return;

    set(KEY_ID, visitorId);
  }

  var views = parseInt(get(KEY_VIEWS, '0'), 10) + 1;
  set(KEY_VIEWS, views);

  /* ----------------------------------------------------------------------------- state */

  var state = { subscribed: false, registration: null, inbox: [] };

  function supported() {
    return (
      'serviceWorker' in navigator &&
      'PushManager' in window &&
      'Notification' in window
    );
  }

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        // Sent explicitly. `fetch` defaults to `Accept: */*`, and a Craft controller calling
        // `requireAcceptsJson()` answers **400** to that — so without this header every heartbeat from
        // every real browser fails while the same request from curl succeeds.
        Accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(body || {}),
    })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .catch(function () {
        // Offline, blocked, or a server error. The next page load tries again; there is nothing useful
        // to do here and an unhandled rejection in every visitor's console is not it.
        return null;
      });
  }

  /* -------------------------------------------------------------------- service worker */

  function registration() {
    if (state.registration) return Promise.resolve(state.registration);

    // When Schedulr does not own the worker, PWA's registration is the one holding the scope —
    // and a push subscription belongs to a *registration*, so subscribing through anything else
    // would produce a subscription whose events nobody receives.
    var promise = CONFIG.ownsWorker && CONFIG.workerUrl
      ? navigator.serviceWorker.register(CONFIG.workerUrl, { scope: '/' })
      : navigator.serviceWorker.ready;

    return promise.then(function (reg) {
      state.registration = reg;
      return reg;
    });
  }

  function base64ToUint8(value) {
    var padded = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    var raw = window.atob(padded);
    var bytes = new Uint8Array(raw.length);

    for (var i = 0; i < raw.length; i++) {
      bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
  }

  /* ------------------------------------------------------------------------- subscribe */

  function subscribe() {
    if (!supported() || !CONFIG.vapidPublicKey) return Promise.resolve(false);

    return Notification.requestPermission()
      .then(function (permission) {
        if (permission !== 'granted') {
          set(KEY_DECLINED, Date.now());
          post(CONFIG.heartbeatUrl, { visitorId: visitorId, declined: true });
          return false;
        }

        return registration()
          .then(function (reg) {
            return reg.pushManager.getSubscription().then(function (existing) {
              if (existing) return existing;

              return reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64ToUint8(CONFIG.vapidPublicKey),
              });
            });
          })
          .then(function (subscription) {
            return post(CONFIG.subscribeUrl, {
              visitorId: visitorId,
              subscription: subscription.toJSON(),
              timezone: timezone(),
              language: navigator.language || null,
            });
          })
          .then(function () {
            state.subscribed = true;
            hidePrompt();
            emit('schedulr:subscribed');
            return true;
          });
      })
      .catch(function () {
        return false;
      });
  }

  function unsubscribe() {
    return registration()
      .then(function (reg) {
        return reg.pushManager.getSubscription();
      })
      .then(function (subscription) {
        if (!subscription) return false;

        var endpoint = subscription.endpoint;

        return subscription.unsubscribe().then(function () {
          return post(CONFIG.unsubscribeUrl, { visitorId: visitorId, endpoint: endpoint });
        });
      })
      .then(function () {
        state.subscribed = false;
        emit('schedulr:unsubscribed');
        return true;
      })
      .catch(function () {
        return false;
      });
  }

  function timezone() {
    try {
      return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
    } catch (e) {
      return null;
    }
  }

  function emit(name, detail) {
    window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
  }

  /* ---------------------------------------------------------------------------- prompt */

  var promptEl = null;

  function mayPrompt() {
    if (!supported() || state.subscribed) return false;
    if (Notification.permission !== 'default') return false;
    if (views < (CONFIG.promptAfterViews || 0)) return false;

    var declined = parseInt(get(KEY_DECLINED, '0'), 10);

    if (declined) {
      // Zero re-ask days means the visitor's "no" is permanent. Re-asking on every page load is
      // how a site earns a browser-level block it can never recover from.
      if (!CONFIG.promptReaskDays) return false;

      var elapsed = (Date.now() - declined) / 86400000;

      if (elapsed < CONFIG.promptReaskDays) return false;
    }

    return true;
  }

  function showPrompt() {
    if (!mayPrompt()) return;

    set(KEY_PROMPTED, Date.now());

    if (CONFIG.promptStyle === 'native') {
      subscribe();
      return;
    }

    if (CONFIG.promptStyle === 'custom') {
      // The site owns the markup. Anything carrying `data-schedulr-subscribe` becomes the accept
      // control; anything carrying `data-schedulr-prompt` is revealed.
      document.querySelectorAll('[data-schedulr-prompt]').forEach(function (el) {
        el.hidden = false;
      });
      emit('schedulr:prompt');
      return;
    }

    promptEl = build(CONFIG.promptStyle === 'slide' ? 'slide' : 'bell');
    document.body.appendChild(promptEl);

    // Two frames, not one: appending and adding the class in the same frame gives the browser
    // nothing to transition *from*, so the panel appears instead of sliding.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        promptEl.setAttribute('data-open', '');
      });
    });

    emit('schedulr:prompt');
  }

  function hidePrompt() {
    document.querySelectorAll('[data-schedulr-prompt]').forEach(function (el) {
      el.hidden = true;
    });

    if (!promptEl) return;

    promptEl.removeAttribute('data-open');
    var el = promptEl;
    promptEl = null;
    window.setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, 350);
  }

  function decline() {
    set(KEY_DECLINED, Date.now());
    hidePrompt();
    post(CONFIG.heartbeatUrl, { visitorId: visitorId, declined: true });
  }

  function build(style) {
    var root = document.createElement('div');
    root.className = 'schedulr-prompt schedulr-prompt--' + style;
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-live', 'polite');
    root.setAttribute('aria-label', CONFIG.promptHeading || 'Notifications');

    if (style === 'bell') {
      var bell = document.createElement('button');
      bell.type = 'button';
      bell.className = 'schedulr-prompt__bell';
      bell.setAttribute('aria-label', CONFIG.promptAccept || 'Allow notifications');
      bell.innerHTML = bellSvg();
      bell.addEventListener('click', function () {
        root.setAttribute('data-expanded', '');
      });
      root.appendChild(bell);
    }

    var panel = document.createElement('div');
    panel.className = 'schedulr-prompt__panel';

    var heading = document.createElement('p');
    heading.className = 'schedulr-prompt__heading';
    heading.textContent = CONFIG.promptHeading || '';
    panel.appendChild(heading);

    if (CONFIG.promptBody) {
      var body = document.createElement('p');
      body.className = 'schedulr-prompt__body';
      body.textContent = CONFIG.promptBody;
      panel.appendChild(body);
    }

    var actions = document.createElement('div');
    actions.className = 'schedulr-prompt__actions';

    var accept = document.createElement('button');
    accept.type = 'button';
    accept.className = 'schedulr-prompt__accept';
    accept.textContent = CONFIG.promptAccept || 'Allow';
    accept.addEventListener('click', subscribe);
    actions.appendChild(accept);

    var no = document.createElement('button');
    no.type = 'button';
    no.className = 'schedulr-prompt__decline';
    no.textContent = CONFIG.promptDecline || 'No thanks';
    no.addEventListener('click', decline);
    actions.appendChild(no);

    panel.appendChild(actions);
    root.appendChild(panel);

    return root;
  }

  function bellSvg() {
    return (
      '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">' +
      '<path fill="currentColor" d="M12 2a2 2 0 0 1 2 2v.3c2.9.9 5 3.6 5 6.7v3.2l1.4 2.1A1 1 0 0 1 19.6 18H4.4a1 1 0 0 1-.8-1.7L5 14.2V11c0-3.1 2.1-5.8 5-6.7V4a2 2 0 0 1 2-2Zm3 17a3 3 0 0 1-6 0Z"/>' +
      '</svg>'
    );
  }

  /* --------------------------------------------------------------------------- on-site */

  function seenIds() {
    try {
      return JSON.parse(get(KEY_SEEN, '[]')) || [];
    } catch (e) {
      return [];
    }
  }

  function renderInbox(items) {
    if (!CONFIG.onSiteRender || !items || !items.length) return;

    var seen = seenIds();
    var fresh = items.filter(function (item) {
      return seen.indexOf(item.id) === -1;
    });

    if (!fresh.length) return;

    // One at a time. A stack of four banners is not a notification, it is a wall.
    var item = fresh[0];

    seen.push(item.id);
    set(KEY_SEEN, JSON.stringify(seen.slice(-100)));

    var root = document.createElement('div');
    root.className = 'schedulr-toast schedulr-toast--' + (CONFIG.onSitePosition || 'bottom-right');
    root.setAttribute('role', 'status');

    var href = safeUrl(item.url);
    var inner = document.createElement(href ? 'a' : 'div');
    inner.className = 'schedulr-toast__inner';

    if (href) {
      inner.href = href;
    }

    var src = safeUrl(item.image);

    if (src) {
      var img = document.createElement('img');
      img.className = 'schedulr-toast__image';
      img.src = src;
      img.alt = '';
      inner.appendChild(img);
    }

    var text = document.createElement('div');
    text.className = 'schedulr-toast__text';

    var title = document.createElement('span');
    title.className = 'schedulr-toast__title';
    title.textContent = item.title || '';
    text.appendChild(title);

    if (item.body) {
      var bodyEl = document.createElement('span');
      bodyEl.className = 'schedulr-toast__body';
      bodyEl.textContent = item.body;
      text.appendChild(bodyEl);
    }

    inner.appendChild(text);
    root.appendChild(inner);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'schedulr-toast__close';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '&times;';
    close.addEventListener('click', function (event) {
      event.preventDefault();
      dismissToast(root, item);
    });
    root.appendChild(close);

    document.body.appendChild(root);

    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        root.setAttribute('data-open', '');
      });
    });

    report('displayed', item);

    if (CONFIG.onSiteAutoDismiss > 0) {
      window.setTimeout(function () {
        dismissToast(root, item, true);
      }, CONFIG.onSiteAutoDismiss * 1000);
    }
  }

  /**
   * The URL, resolved against the page, if it is http(s); otherwise null.
   *
   * The server validates every URL a notification stores, and this is the second lock on the same
   * door: an `href` is the one place a `javascript:` URL turns straight into script on this origin.
   */
  function safeUrl(value) {
    if (!value || typeof value !== 'string') return null;

    try {
      var url = new URL(value, window.location.href);

      return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null;
    } catch (e) {
      return null;
    }
  }

  function dismissToast(root, item, auto) {
    if (!root.parentNode) return;

    root.removeAttribute('data-open');
    window.setTimeout(function () {
      if (root.parentNode) root.parentNode.removeChild(root);
    }, 300);

    // Timing out is not dismissing. Recording it as a dismissal would make every unattended tab
    // look like a rejection and turn the one honest negative signal into noise.
    if (!auto) {
      report('dismissed', item);
    }
  }

  function report(type, item) {
    if (!CONFIG.eventUrl || !item || !item.n) return;

    post(CONFIG.eventUrl, {
      type: type,
      n: item.n,
      v: item.v || null,
      s: visitorId,
      channel: 'onsite',
    });
  }

  /* ------------------------------------------------------------------------------ boot */

  function heartbeat() {
    return post(CONFIG.heartbeatUrl, {
      visitorId: visitorId,
      timezone: timezone(),
      language: navigator.language || null,
      views: views,
      path: window.location.pathname,
      permission: 'Notification' in window ? Notification.permission : 'unsupported',
    }).then(function (data) {
      if (!data) return;

      state.subscribed = !!data.subscribed;
      state.inbox = data.inbox || [];

      // The server's word beats the browser's memory: a device dropped as `410 gone` still has a
      // local subscription object, and would otherwise never re-subscribe.
      if (data.resubscribe && Notification.permission === 'granted') {
        subscribe();
      }

      renderInbox(state.inbox);

      if (data.mayPrompt !== false) {
        schedulePrompt();
      }
    });
  }

  function schedulePrompt() {
    var delay = (CONFIG.promptAfterSeconds || 0) * 1000;

    if (delay <= 0) {
      showPrompt();
      return;
    }

    window.setTimeout(showPrompt, delay);
  }

  function boot() {
    // Bind the custom prompt's controls whether or not we are going to prompt, so a site that
    // renders its own bell in the header has a working button on every page.
    document.addEventListener('click', function (event) {
      var target = event.target.closest ? event.target.closest('[data-schedulr-subscribe]') : null;

      if (target) {
        event.preventDefault();
        subscribe();
        return;
      }

      var off = event.target.closest ? event.target.closest('[data-schedulr-unsubscribe]') : null;

      if (off) {
        event.preventDefault();
        unsubscribe();
      }
    });

    if (supported() && CONFIG.ownsWorker && CONFIG.workerUrl) {
      // Registered eagerly rather than at prompt time: the registration must already exist when
      // the permission is granted, or the subscribe call races the install and intermittently
      // fails on the very first grant — the one that matters most.
      registration().catch(function () {});
    }

    heartbeat();
  }

  window.Schedulr = {
    subscribe: subscribe,
    unsubscribe: unsubscribe,
    prompt: showPrompt,
    isSubscribed: function () {
      return state.subscribed;
    },
    visitorId: function () {
      return visitorId;
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
