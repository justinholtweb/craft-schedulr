# Schedulr — Craft CMS 5 Plugin

## Project Overview

Schedulr is scheduled notifications for Craft: one message, composed once, delivered over **web
push, email and on-site**, on a schedule, to a segment, in each subscriber's own time zone.
Distributed as `justinholtweb/craft-schedulr`. **Paid, Lite/Pro ($79 one-time + $59/yr).**

The reference point is WordPress's *OneSignal Free Web Push Notifications*, whose six advertised
pillars are opt-in customisation, targeting segments, scheduled notifications (including "in the
visitor's own time zone"), automatic notifications, real-time analytics and A/B testing. Schedulr
covers all six and adds two channels OneSignal's free tier does not have.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- **No build step and no runtime dependencies.** Web push encryption is pure PHP against
  `ext-openssl`; the front-end runtime is one hand-written IIFE injected into the page.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\schedulr`
- Package: `justinholtweb/craft-schedulr`
- Handle: `schedulr`
- Accent `#C7278C` (signal magenta), icon is a bell whose clapper is a clock hand.

### The five decisions everything else follows from

**1. One message, three channels, and a dedupe policy.** A `Notification` is written once and fanned
out to whichever channels are enabled. The three channels do **not** share a recipient list —
somebody who denied push is still reachable on-site — so the audience is resolved *per channel*, and
every notification carries a dedupe policy. Without it, enabling two channels double-notifies
everybody, which is how a site loses its push permission.

**2. A Subscriber is a visitor identity, not a push endpoint.** The endpoint columns are nullable.
That is what makes the on-site and email channels expressible at all, and it means the visitor is
kept when they press *Block* — which is most visitors on every site that has measured it.

**3. Occurrences are materialised, never evaluated at send time.** `services\Schedules` expands a
recurrence rule into concrete `schedulr_occurrences` rows ahead of time (90-day horizon, topped up by
the runner). The runner's query is then one indexed `status = 'pending' AND dueAt <= now`, the CP can
*show* the next twelve sends rather than assert them, and "09:00 in each subscriber's own time zone"
is simply thirty rows with thirty UTC instants.

**4. The runner is cron-first with a WP-Cron-shaped web fallback.** Craft has no scheduler. What the
plugin must never do is look scheduled and be inert, so the CP states which of four states it is in
and the fallback stands down whenever cron is being seen.

**5. Schedulr stands aside for PWA.** See below.

### Interop with PWA

A site running both plugins has one browser, one permission grant, and — the part that decides
everything — **one service worker registration per scope**, with push subscriptions belonging to a
*registration*. Two independent push stacks on one origin is not degraded, it is broken.

So when `justinholtweb/craft-pwa` is installed and `deferToPwa` is on, Schedulr borrows PWA's VAPID
keypair, adopts its subscribers on install, and **does not register a worker at all** — its runtime
subscribes through `navigator.serviceWorker.ready`, which is PWA's registration.

That third part needs **no changes to PWA whatsoever**, because PWA's worker renders
`{title, body, icon, badge, tag, requireInteraction, url}` and opens `data.url` on click, which is
exactly Schedulr's payload shape. Click tracking survives too, because Schedulr tracks clicks by
putting its redirect in `url` rather than by asking the worker to report anything.

All of it goes through `services\Interop`, wrapped, because PWA can be uninstalled between one
request and the next.

### Click tracking

`/schedulr/go?sr_n=…&sr_v=…&sr_s=…&sr_k=…` records the click and redirects. It carries **only IDs**,
never a destination: a redirect endpoint that takes its target from a query parameter is an open
redirect and therefore a phishing gadget on the customer's own domain, and a URL carrying another URL
is what pushes a payload over the ~4KB post-encryption ceiling. The IDs are HMAC-signed (12 hex
chars) or anyone who received one notification could inflate every campaign's click count.

Every parameter is namespaced `sr_`. `token` and `p` are both reserved by Craft.

### Data model

Ten tables. `schedulr_notifications` is an **element** sub-table — the CP screen a notification list
needs *is* Craft's element index. Subscribers are deliberately **not** elements: five and six figures
of them would swamp `elements` for no benefit.

Audiences live in the **database, not project config**: a segment is content a marketer authors, and
nobody wants "audience: lapsed readers" arriving in a pull request.

Every foreign key into `schedulr_deliveries` is `ON DELETE SET NULL`. The ledger outliving its
subjects is the point — "what went out last March" must stay answerable.

### Editions

**Lite composes, schedules and sends. Pro decides who, when *for them*, and what happened.** All
three channels and recurrence are in Lite; Pro adds segments, per-subscriber time zones, automations,
A/B, click analytics and frequency caps. No cap on subscribers or sends in either edition.
`models\Edition` is pure and static so the whole boundary reads in one file. Every gate is a
**downgrade, not a wall**: a lapsed licence keeps every segment and keeps sending to them.

## Testing

    # unit — no Craft, no database
    composer install && vendor/bin/phpunit          # 30 tests

    # integration — inside the plugin-testing harness, from the site root
    ddev exec php /var/www/craft-schedulr/tests/integration/checks.php   # 232 checks

The integration suite is idempotent and self-cleaning, and asserts its own cleanup. Nothing in it
reaches a real push service: the one send that exercises the push path aims at a `.invalid` host, so
the assertion is about how Schedulr classifies an unreachable device.

`tests/unit/EncryptorTest.php` pins the encryptor to the **RFC 8291 §5 test vector**. This is the one
test that cannot be replaced by inspection: if any step of the derivation is off by a byte, the push
service still returns 201 and the notification simply never appears. There is nothing to read in a
log.

## Traps found building this

- **Yii's query builder already encodes an array for a `json` column.** Pre-encoding it yourself
  stores the JSON of a JSON string, and every `is_array()`-guarded getter then silently returns
  nothing — no error, just a topic filter that evaporated and a notification that went to everybody.
  Pass arrays; `helpers\Data` decodes tolerantly on the way back.
- **`Db::upsert()`'s first argument is the insert half.** A value passed only to the third (update)
  argument is null on every *fresh* row, so the bug appears only for keys nobody had set before.
- **`fetch` defaults to `Accept: */*`, and `requireAcceptsJson()` answers 400 to that.** The heartbeat
  worked from curl and failed from every real browser. Send the header *and* do not require it.
- **`Craft::$app->getRequest()` is a `craft\console\Request` outside a web request** and has no
  `getUserAgent()`. Subscribing from a console command or a queue job fatals.
- **Every column an element query selects needs a property**, including `dateUpdated` on any model a
  service hydrates from a whole row. `UnknownPropertyException`, thrown only on the read path.
- **A Twig tag inside a JS or Twig comment is still parsed.** `{% js %}` content is a Twig template,
  so a `{% block content %}` written in a `//` comment is a syntax error.
- **`_elements/element-index` does not exist in Craft 5** — extend `_layouts/elementindex`, which
  derives everything from `elementType` via the `cp.layouts.elementindex` hook.
- **Craft's select `toggle` builds its selector as `'#' + prefix + value`**, so a value containing a
  dot (`entry.published`) is parsed by jQuery as an id plus a class and nothing ever toggles.
- **Yii skips inline validators when the attribute is empty**, and an empty array counts as empty —
  which is exactly the case a "you must choose a weekday" rule exists for. `skipOnEmpty => false`.
- **A shutdown-registered cleanup is not enough.** By the time PHP runs shutdown functions Craft is
  partway through its own teardown, so `getElements()` can fail and a try/catch swallows it.
- **An automation's "already sent" guard must key on the template**, not on the concrete notification
  it raised — and it needs a second guard for the window before the queue has drained, or the sweep
  re-sends every pass.
- **`sendNow()` must re-read its occurrence.** `dispatch()` writes counts to the row, not the model,
  so returning the pre-dispatch object makes a successful send announce "queued to 0 recipients".
- **PHP's `setDate(2026, 2, 31)` is the 3rd of March**, silently. Monthly rules clamp; they never roll
  over. And recurrence walks *dates*, applying the time of day last, or a 09:00 send becomes 08:00 for
  half the year.

## Still to do

No git commit yet, no marketing site (`craft-schedulr-website`), not in the plugin registry's
`plugins.json`, no GraphQL support.
