# Schedulr — build plan

**`justinholtweb/craft-schedulr`** · handle `schedulr` · namespace `justinholtweb\schedulr` · version
`5.0.0` (family numbering) · **paid, Lite/Pro — $79 one-time + $59/yr renewal**

Scheduled notifications for Craft 5. The reference point is WordPress's
*OneSignal Free Web Push Notifications*, whose six advertised pillars are opt-in customisation,
targeting segments, scheduled notifications (including "in the visitor's own time zone"),
automatic notifications, real-time analytics, and A/B testing. Schedulr covers all six and adds
two channels OneSignal's free tier does not have — email and on-site — so one composed message can
reach someone whether or not they ever granted push permission.

---

## 1. The decisions that shape everything else

### 1.1 Three channels, one composed message

A **Notification** is written once — title, body, image, link, buttons — and fanned out to any
combination of **push**, **email** and **on-site**. This is the single most important structural
call in the plugin, because the naïve alternative (three separate notification types) means the
same announcement gets written three times and drifts.

The consequence to design around: *the three channels do not share a recipient list.* A visitor
who denied push permission can still be reachable on-site; a subscriber with no email address
cannot be emailed. So audience resolution happens **per channel**, from one `Subscriber` row, and
each notification carries a **dedupe policy** — `all channels` / `push, then email if not pushed`
/ `first reachable channel only`. Without that policy, enabling two channels double-notifies
everybody, which is precisely the behaviour that gets a site's push permission revoked.

### 1.2 One Subscriber row, push optional

The obvious model is "a subscriber is a push endpoint". That model cannot express the on-site or
email channel at all, and it throws away the visitor the moment they click *Block*.

So: a **Subscriber is a visitor identity**, and the push endpoint is one optional column set on it.

| state | how it happens | reachable by |
| --- | --- | --- |
| anonymous | first page view, first-party cookie ID | on-site |
| push-subscribed | granted permission | on-site, push |
| identified | logged in, or gave an email | on-site, email (+ push if granted) |

`userId` is filled in when a logged-in Craft user is seen on that browser, which is also how the
same person's several browsers get collapsed for frequency capping. Nothing about a visitor is
stored beyond what a channel needs to reach them; the privacy screen lists every column and offers
a retention sweep.

### 1.3 Standalone push stack, but stand aside for PWA

Schedulr installs and works alone: its own VAPID keypair, its own subscribe endpoint, its own
service worker. `src/push/Encryptor.php` is ported verbatim from PWA — RFC 8291 payload encryption
and RFC 8292 VAPID signing in pure PHP against `ext-openssl`, no dependencies, covered by the RFC
8291 §5 test vector.

**When PWA is installed and enabled, Schedulr defers to it**, because two independent push stacks
on one site is not a degraded experience, it is a broken one:

- **Keys.** Schedulr reads PWA's keypair via `pwa->push->getPublicKey()/getPrivateKey()` instead of
  generating its own. Every subscription a browser already holds is bound to the public key it was
  created with, so generating a second pair would silently orphan the whole existing list.
- **Subscribers.** Schedulr's subscribe endpoint upserts into its own table but keys on the same
  `sha256(endpoint)`, and an adopt-on-install migration copies `pwa_subscribers` across. The
  visitor is never prompted twice.
- **Service worker.** *Only one service worker can own a scope*, and a push subscription belongs to a
  *registration*. Registering Schedulr's worker on a site running PWA's would replace PWA's outright
  — offline, caching, install prompt, all gone, with no error anywhere. So when PWA is present
  Schedulr does not register a worker at all; its runtime subscribes through
  `navigator.serviceWorker.ready`, which *is* PWA's registration.

  **Correction to the original plan.** This was going to be done by asking PWA's generated worker to
  `importScripts()` a Schedulr push handler. That turned out to be unnecessary: PWA's worker already
  renders `{title, body, icon, badge, tag, requireInteraction, url}` and opens `data.url` on click,
  which is exactly Schedulr's payload shape — so it delivers Schedulr's notifications correctly with
  **no changes to PWA whatsoever**. Click tracking survives too, because Schedulr tracks clicks by
  putting its redirect in `url` rather than by asking the worker to report anything.

Detection is `Craft::$app->plugins->isPluginEnabled('pwa')` guarded by `class_exists`, resolved
once per request in `services\Interop`, never by reaching into PWA's classes from ten places.

### 1.4 Materialised occurrences, not a recurrence rule evaluated at send time

A recurring notification (*every Tuesday at 09:00*, *the first of the month*) is expanded ahead of
time into concrete `schedulr_occurrences` rows, exactly as Owl does for event occurrences. The
runner's query is then a single indexed `WHERE dueAt <= NOW() AND status = 'pending'` — no rule
parsing, no scan of every notification, and the CP can *show the next twelve sends* rather than
asserting that they will happen.

The expansion horizon is 90 days, topped up by the runner. Editing the rule discards unsent future
occurrences and re-expands; sent ones are never touched, because the record of what actually went
out is the more valuable of the two.

### 1.5 Per-subscriber time zone delivery

OneSignal's headline scheduling feature, and the reason the occurrence table has a `timezone`
column. "Send at 09:00 local" for a list spanning 30 zones is **30 occurrences** of the same
notification, each with its own `dueAt` in UTC and its own audience slice, fanned out across ~26
hours. The subscriber's zone is captured at subscribe time from
`Intl.DateTimeFormat().resolvedOptions().timeZone` and refreshed on each heartbeat, because people
travel and a stale zone sends a "good morning" at 3am.

Subscribers whose zone is unknown fall into a configurable bucket — the site's own zone by default,
which is the only defensible guess.

### 1.6 The runner: cron first, web fallback, never silently nothing

Craft has no scheduler. The primary runner is `php craft schedulr/run`, meant for a one-minute
cron, and the CP tells you plainly whether it has been seen recently.

Because a great many Craft sites — especially the ones that would buy this — have no cron, there is
a **web fallback**: a request-tail runner behind a mutex and a minimum interval, WP-Cron shaped.
It is opt-in-by-default with a loud caveat in the CP: it can only run when somebody visits, so a
quiet site sends late. What it must never do is *appear* to be scheduled and do nothing, which is
the failure mode of every scheduler that assumes cron.

Sending itself always goes to the **queue**, one job per batch of 200 recipients, so a 50,000-device
send neither blocks the runner nor loses its place if a worker dies.

### 1.7 Plain vocabulary, on purpose

The family leans on themed vocabulary (PWA's flight deck, Nuke's blast radius). Schedulr does not.
Its buyers arrive having used OneSignal, Mailchimp or Firebase, and they search the CP for
*notification*, *segment*, *campaign*, *delivery*. Renaming those costs discoverability and buys
nothing. The identity lives in the colour and the icon instead.

- **Accent `#C7278C`** — a signal magenta, clear of everything in the family palette (nearest
  neighbours are RedPen's crimson `#A80E35` and Stub's rose `#EC628C`, both distinguishable side by
  side).
- **Icon** — a bell whose clapper is a clock hand, on `#2A0B22`. Hand-drawn, single path, mono mask
  variant for the CP nav.

---

## 2. Editions

The line is **Lite composes, schedules and sends. Pro decides who, when *for them*, and tells you
what happened.**

| | Lite | Pro |
| --- | --- | --- |
| Subscribers | unlimited | unlimited |
| Opt-in prompts | native + one soft prompt style | all styles, custom HTML, re-ask rules |
| Channels | push, email, on-site | + per-channel dedupe policy |
| Send now | ✓ | ✓ |
| Schedule for a time | ✓ | ✓ |
| Recurring (daily / weekly / monthly) | ✓ | ✓ + full rule builder, exclusions |
| Audience | whole site, or by topic | condition-builder segments |
| Per-subscriber time zone delivery | — | ✓ |
| Automations (entry published, user registered, win-back) | — | ✓ |
| A/B testing | — | ✓ |
| Click tracking + conversion funnel | delivered/failed counts only | ✓ full ledger + events |
| Frequency caps & quiet hours | — | ✓ |
| CSV export of subscribers & deliveries | — | ✓ |

There is deliberately **no cap on subscribers or on notifications sent**. Charging for the number
that grows with a site's success is the wrong shape for this tool, the same call made in Hire.

Downgrade behaviour: a lapsed licence never deletes a segment, never cancels a schedule, and never
stops a send that is in flight. What Lite refuses is *creating* the next Pro-shaped thing.
`models\Edition` is pure and static, taking `bool $isPro`, so the whole boundary reads in one file.

---

## 3. Data model

Nine tables. Definitions authored by marketers live in the **database, not project config** —
a segment is content, not configuration, and nobody wants "audience: lapsed readers" arriving in a
deployment. Only plugin settings (prompt styling, keys policy, retention, runner mode) are project
config.

| table | holds |
| --- | --- |
| `schedulr_notifications` | element sub-table: title, body, image, url, tag, buttons, channels, dedupe policy, status, audience, counts |
| `schedulr_variants` | A/B variants of a notification, split %, and per-variant counts |
| `schedulr_schedules` | one row per notification: mode (now / at / recurring / trigger), rule, time zone mode, window |
| `schedulr_occurrences` | **the runner's work queue** — `dueAt`, `timezone`, `status`, `notificationId` |
| `schedulr_audiences` | segments: name + serialised condition |
| `schedulr_subscribers` | the visitor identity — cookie ID, endpoint + keys (nullable), email, userId, siteId, language, timezone, platform, visit count, last seen, failure count |
| `schedulr_subscriber_tags` | `(subscriberId, tag, value)` — a real table, not JSON, because segments query it |
| `schedulr_deliveries` | ledger: one row per recipient per channel per send, with status/code/error |
| `schedulr_events` | displayed / clicked / dismissed / converted, from the worker and the click redirect |
| `schedulr_keys` | VAPID pair — **never** project config, and absent entirely when PWA owns the keys |

**Notification is a Craft element**; Subscriber is not. The element index is the notification list
the CP needs anyway — statuses, search, bulk actions, exporters, permissions, all free. Subscribers
run to five and six figures and would swamp the `elements` table for no benefit; they get a plain
record and a hand-built index.

Retention: deliveries and events are pruned on a rolling window (90 days default) from
`Gc::EVENT_RUN`, queued rather than inline.

---

## 4. Build phases

Each phase ends green in the `plugin-testing` harness before the next begins.

**Phase 1 — skeleton.** composer.json, `Plugin.php`, editions, settings model, Install migration
(all nine tables), CP nav, permissions, icon, translations, `.gitignore`, `.ddev` for the test
suite, `docs/`.

**Phase 2 — the push stack.** Port `Encryptor` from PWA with its RFC 8291 §5 vector test. `Keys`
service with generate / import-from-env / rotate. `services\Interop` and PWA detection. Subscribe /
unsubscribe / heartbeat endpoints. Service worker generation *and* the importScripts path.

**Phase 3 — subscribers and prompts.** Subscriber upsert keyed on cookie ID and endpoint hash,
timezone/language/platform capture, visit counting. The opt-in prompt runtime: native, soft
bell, slide-down, custom; re-ask rules and the "never ask again" record. CP subscriber index with
filters. Privacy screen and retention sweep.

**Phase 4 — notifications and the three channels.** The Notification element, its edit screen and
preview. `channels\PushChannel`, `EmailChannel`, `OnSiteChannel` behind one interface, the dedupe
policy, and the send pipeline: resolve audience → batch → queue → ledger. On-site inbox endpoint
and its runtime. Send-now works end to end.

**Phase 5 — scheduling.** Schedules, the recurrence expander, the occurrence table, the console
runner, the web fallback with its mutex, and the CP's "next twelve sends" panel plus the
cron-health banner. Per-subscriber time zone fan-out (Pro).

**Phase 6 — audiences and automations (Pro).** The condition builder over subscriber attributes.
Automations: entry published in section X, user registered, no visit in N days. Frequency caps and
quiet hours enforced at audience resolution, not at send.

**Phase 7 — analytics and A/B (Pro).** Delivery ledger screens, the click redirect with its
`sr_` namespaced parameter, worker-reported display/dismiss events, the conversion funnel, the
dashboard, and A/B splitting with winner selection.

**Phase 8 — hardening and release.** Edition audit against §2, GraphQL and Twig variable, console
commands, `tests/integration/checks.php` (target ≥120 checks), PHPStan level 4, ECS, README with
measured numbers, CHANGELOG, `CLAUDE.md`, first commit, tag `5.0.0`.

---

## 5. Traps already known, to be designed around from the start

Carried in from the family's recorded gotchas — each of these has bitten a sibling plugin:

- Never mark a plugin setting `required`; a fresh install then cannot save *any* setting.
- Craft's colour field posts hex **without** the leading `#`.
- A typed `int` property assigned `''` from a cleared CP number field is a `TypeError`, and Craft's
  date/time fields post **arrays**.
- `token` and `p` are reserved query parameters — the click-tracking redirect must namespace its
  own (`sr_n`, `sr_v`, `sr_s`).
- `tabs` and `queue` are reserved CP template variables.
- `Sites::getSiteByUid()` **throws**; walk `getAllSites(true)`.
- Deleting a subscriber and then inserting a delivery row that references it fails the foreign key
  even with `ON DELETE SET NULL` — re-check surviving IDs before `batchInsert`, or one `410 gone`
  loses the entire batch's ledger.
- Yii's `CompareValidator` stringifies operands, so `dateEnd > dateStart` on two `DateTime`s throws.
- `craft\base\Component` inherits `Model::load()` — no service method may be named `load()`.
- Project config coalesces add+remove within one request, so teardown must be called directly as
  well as from the handler.
- A cross-origin or blocked resource still fires `load`; the worker's readiness must be proven, not
  assumed.

---

## 6. Open questions for Justin

1. **Accent `#C7278C`** (signal magenta) and the bell-with-clock-hand icon — keep or replace?
2. **Web fallback runner on by default?** Plan says yes-with-a-caveat; the alternative is
   cron-only and a hard warning, which is more honest but leaves cron-less sites dead on arrival.
3. **Adopt PWA's subscribers on install** automatically, or offer it as a button on the settings
   screen? Plan says automatic migration, since the alternative is a list that looks empty.


---

## 7. Outcome

All eight phases built. What shipped differs from the plan in three places, each noted above or here:

- **The PWA worker interop is simpler than planned** — no `importScripts`, no changes to PWA. See §1.3.
- **`helpers\Recurrence` was extracted** from `services\Schedules` so the date arithmetic could be
  tested without booting Craft. It is where the DST, month-clamping and interval behaviour is pinned.
- **A `templateId` column was added** to `schedulr_notifications`. Without it, "have we already
  notified about this entry" cannot be answered *per rule*, so two templates watching one section
  would silently share one guard — and a win-back's send-once guard matched nothing at all, because
  the template itself never appears in the ledger.

**Verification**: 30 unit tests (including the RFC 8291 §5 vector) and 232 integration checks against
a real Craft install, repeatable and self-cleaning. PHPStan level 4 clean. Every CP screen and the
front-end runtime confirmed in a browser, including a real on-site delivery: the subscriber who
loaded a page went `queued → delivered` with a `displayed` event, while the two who never did stayed
`queued`.

Bugs the verification caught that inspection had not: JSON columns double-encoded by the query
builder, `Db::upsert()`'s insert half, a heartbeat that 400'd for every real browser over an `Accept`
header, `getUserAgent()` fataling outside a web request, a push reachability predicate that disagreed
with `isPushable()`, a win-back that re-sent every pass, and a send that reported "0 recipients"
after reaching all of them.
