---
title: Web push
slug: web-push
order: 30
summary: VAPID keys, the service worker, what push status codes mean, and running alongside PWA.
---

## How it fits together

1. The runtime on your pages registers a service worker and, once the visitor allows it, asks the
   browser for a push subscription bound to your site's VAPID public key.
2. The subscription (an endpoint URL at the browser vendor's push service, plus two keys) is stored on
   the visitor's subscriber record.
3. When a notification goes out, Schedulr encrypts the payload for each device and posts it to that
   device's endpoint, signed with your VAPID private key.
4. The push service wakes the service worker, which shows the notification.

The payload is encrypted end to end (RFC 8291), so the push service relays a message it cannot read.

## Browser support

Web push needs a secure page (HTTPS, or `localhost` in development) and a browser with service
workers and the Push API: current Chrome, Edge, Firefox and Opera on desktop and Android, and Safari
on macOS. On iPhone and iPad, Safari only offers push to a site that has been added to the home
screen (iOS 16.4 and later).

Everyone else is still reachable on-site, and by email if they have signed in, which is why Schedulr
has three channels.

## Push services

The subscribe endpoint is public, and every endpoint it accepts is a URL your server will later post
to. So Schedulr only accepts subscriptions whose endpoint is on a known push service:

- `fcm.googleapis.com` (Chrome, Edge, Opera, Android)
- `push.services.mozilla.com` (Firefox)
- `notify.windows.com` (Windows)
- `push.apple.com` (Safari)

Each entry also covers its subdomains. The endpoint must be `https`, on port 443, with no user name or
password in it and no IP address for a host. Anything else is refused at subscribe time, and checked
again before every send, so a row that arrived some other way (PWA adoption, a direct database write)
cannot make the server post somewhere else.

Every shipping browser uses one of these. If you need another, for a newer browser or a test harness,
add it with `extraPushHosts` in `config/schedulr.php`:

```php
return [
    'extraPushHosts' => ['push.example-browser.com'],
];
```

An entry needs at least one dot, and covers its subdomains too.

Schedulr does not follow redirects from a push service: a response is read as it is.

## VAPID keys

The keypair identifies your site to push services. Every subscription a browser holds is bound to the
public key it was created with, so **changing the keypair orphans every existing subscription**.

Schedulr takes its keypair from the first of these that applies:

1. **PWA's keypair**, when the PWA plugin is installed and **Keep standing aside for PWA** is on.
2. **The environment**, when both `SCHEDULR_VAPID_PUBLIC_KEY` and `SCHEDULR_VAPID_PRIVATE_KEY` are
   set.
3. **A generated pair**, created on first use and stored in the database.

**Settings → Web push** shows which one is in use and the public key itself. The keypair is never
written to project config: a private key does not belong in a committed file, and a rotation arriving
through a deployment would orphan a list nobody meant to touch.

### Bringing keys from another push stack

If you are moving from another push setup, put its keys in `.env` and the existing subscriptions keep
working:

```sh
SCHEDULR_VAPID_PUBLIC_KEY="BJ…"
SCHEDULR_VAPID_PRIVATE_KEY="…"
```

The public key is the base64url string most libraries print. The private key may be either the raw
base64url scalar (which is what most JavaScript and PHP push libraries store) or a PEM block. Set both
halves: Schedulr needs the public key to rebuild a usable private key from the scalar.

You will still need to bring the subscriptions themselves across; Schedulr only adopts subscribers
automatically from PWA.

### Rotating the keypair

**Settings → Web push → Rotate the keypair** is only offered for a generated pair. It cannot be undone:
every device on your list stops receiving push until the visitor comes back to the site and subscribes
again. Visitor records are kept, so on-site delivery and the record of who declined are not lost.

When PWA owns the keys, rotate them in PWA. When they come from the environment, change the
environment.

### Push subject

**Push subject** (`pushSubject`) is the VAPID `sub` claim, a `mailto:` or `https:` URL identifying
your site to push services. Some push services reject requests without one, so when it is empty
Schedulr uses `mailto:` plus the system email address from **Settings → Email**, or failing that
`mailto:webmaster@` plus your site's host name. Environment variables (`$PUSH_SUBJECT`) work here.

## The service worker

Schedulr serves its worker at `/schedulr-worker.js` (the `serviceWorkerPath` setting) and registers it
with scope `/`. It must be served from the site root: a worker at `/assets/sw.js` can only control
`/assets/`, and push to it silently never arrives. Schedulr sends a `Service-Worker-Allowed: /` header
to go with it.

The worker shows the notification, opens the link on click (focusing an existing tab on the same URL
if there is one), reports *displayed* and *dismissed* events back for [analytics](analytics), and
re-subscribes by itself if the push service rotates a subscription while no page is open.

**Default icon URL** and **Default badge URL** are used by Schedulr's own worker when a notification
does not set its own.

### Using your own worker

Turn **Register Schedulr's service worker** off if your site already has a worker at the root scope.
The runtime then subscribes through `navigator.serviceWorker.ready`, which is your registration. Your
worker needs to handle the `push` event itself; Schedulr's payload is JSON:

```json
{
  "title": "New on the blog",
  "body": "…",
  "url": "https://example.com/schedulr/go?sr_n=…",
  "icon": "…",
  "badge": "…",
  "image": "…",
  "tag": "…",
  "requireInteraction": true,
  "actions": [{ "action": "a0", "title": "Read", "url": "…" }],
  "n": 42,
  "v": 7,
  "s": 1234,
  "k": "…"
}
```

Only `title` and `url` are always present. Open `url` on click to keep click tracking working; each
action's `url` is tracked too. To report *displayed* or *dismissed* events from your own worker, post
`{ type, n, v, s, k }` back to `/actions/schedulr/track/event` with `Content-Type: application/json`:
`k` is the signature that lets the endpoint accept `s`.

## Status codes, and what happens to a device

There is no feedback from push apart from the push service's HTTP response, and a push service answers
201 both for a message it will deliver and for one whose encryption is subtly wrong. Schedulr reads the
codes that are not 201 carefully:

| Response | Meaning | What Schedulr does |
| --- | --- | --- |
| 2xx | Accepted | Recorded as `delivered` |
| 404, 410 | The subscription has been retired | Recorded as `gone`; the push subscription is removed from the subscriber at once. The visitor record is kept |
| 401, 403 | Your VAPID credentials were rejected | Recorded as `failed` and logged as an error. Not counted against the device: every device fails identically, so it is the site that needs fixing, not the list |
| 413 | The payload is too large | Checked before sending, so it fails once, not per device. Not counted against the device |
| 429, 5xx | The push service is busy or failing | Recorded as `failed`. Not counted against the device |
| Timeouts, connection errors | The endpoint did not answer | Recorded as `failed` and counted against the device |
| 3xx | A redirect | Not followed. Recorded as `failed` and counted against the device |
| — | The endpoint is not on a [known push service](#push-services) | Recorded as `failed`, without any request being made, and counted against the device |

A device is dropped after **pushMaxFailures** consecutive failures (5 by default). Consecutive, not
total, so one bad afternoon at a push service retires nobody. A device reached on another channel in
the same send does not have the failure counted.

Messages are held by the push service for up to four weeks for a device that is offline. A
notification with a collapse key also sends a `Topic` header, so an offline device that comes back
receives only the latest message on that key rather than a backlog.

## Checking the chain

**Settings → Web push** has two buttons for the silent failures push is known for:

- **Check openssl and signing** confirms the extension is loaded, P-256 keys can be generated, and a
  VAPID header can be signed. It runs the same code a real send does.
- **Send a test to one device** sends a test notification to the first push subscriber and reports
  exactly what the push service answered.

Allow notifications in your own browser on the live site first, so the test has a device to go to.

## Running alongside PWA

A site running both `justinholtweb/craft-pwa` and Schedulr has one browser, one permission grant and
one service worker registration per scope, and a push subscription belongs to a registration. Two
independent push stacks on one origin would mean two prompts and one worker silently replacing the
other.

So when PWA is installed and **Keep standing aside for PWA** (`deferToPwa`) is on, Schedulr:

- **Uses PWA's keypair**, so the subscriptions PWA already holds keep working.
- **Adopts PWA's push subscribers** when Schedulr is installed, so nobody is asked twice. Running it
  again (**Settings → Web push → Adopt them**, or `php craft schedulr/subscribers/adopt-pwa`) is safe:
  subscribers are matched on their endpoint and nobody is adopted twice.
- **Registers no service worker.** Its runtime subscribes through PWA's registration.

PWA's worker already renders Schedulr's payload (`title`, `body`, `icon`, `badge`, `tag`,
`requireInteraction`, `url`) and opens `url` on click, so this needs no configuration and no changes to
PWA. Click tracking keeps working, because the tracking redirect is the `url` itself.

Two things PWA's worker does not do: it does not report *displayed* or *dismissed* events to
Schedulr, and it does not use Schedulr's default icon and badge settings.

If PWA is installed but its service worker is switched off, the scope is free and Schedulr registers
its own worker as usual.

Turning **Keep standing aside for PWA** off makes Schedulr run its own push stack beside PWA's. That is
supported, but it means two prompts for the visitor and one worker replacing the other.
