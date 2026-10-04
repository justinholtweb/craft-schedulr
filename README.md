# Schedulr

Scheduled notifications for Craft CMS 5. One message, composed once, delivered over **web push,
email and on-site** — on a schedule, to a segment, in each subscriber's own time zone.

If you have used OneSignal's free WordPress plugin, Schedulr covers all six of its advertised
pillars — opt-in customisation, targeting segments, scheduled notifications, automatic
notifications, real-time analytics and A/B testing — and adds the two channels its free tier does
not have.

---

## Why three channels

Most visitors will never grant push permission. A push-only plugin writes off everybody who pressed
*Block*, everybody on a browser without push, and everybody reading on an iPhone that has not added
the site to its home screen.

So a Schedulr notification is written **once** and delivered over whichever channels you enable:

| | reaches | needs from the visitor |
| --- | --- | --- |
| **Web push** | the lock screen, with the browser closed | permission |
| **Email** | signed-in Craft users | to have signed in on that browser |
| **On-site** | everybody who loads a page | nothing at all |

The three do not share a recipient list, so Schedulr resolves the audience *per channel* and lets
you say what should happen when two of them reach the same person: send both, email only the people
push did not reach, or one channel per person.

## Install

    composer require justinholtweb/craft-schedulr
    php craft plugin/install schedulr

Then add one line to cron:

    * * * * * cd /path/to/site && php craft schedulr/run --quiet

Cron is the only mode that sends on time. Without it Schedulr falls back to sending when somebody
visits the site, and says so plainly in the control panel rather than quietly sending late.

## What you get

**Scheduling that you can see.** Recurrence rules are expanded into real rows ahead of time, so the
Schedule screen lists the next twelve sends instead of promising them. Daily, weekly, monthly and
yearly, with intervals, windows, occurrence caps and skipped dates.

**"09:00" meaning 09:00 wherever they are.** A per-subscriber-time-zone send fans out into one
delivery per zone on your list, each at that local time, spread across about 26 hours. The
subscriber screen tells you how many zones that is before you schedule it.

**Segments that count themselves.** Audiences are built from rules over subscriber attributes —
reachability, visits, last seen, platform, language, time zone, user group, tags, and whether they
already received a particular notification. Every rule compiles to SQL over one table, so a segment
resolves in a single query and the editor can tell you it matches 4,812 people *before* you send.

**Automations.** Notify when an entry is published (with Twig against the entry), when a user
registers, or when somebody has not visited for N days. Each firing produces its own notification
with its own record of who it reached, so "which article did we push on Tuesday" has an answer.

**An honest ledger.** Every recipient, every channel, every send, with the distinction that matters:
a device the push service has *retired* is dropped, a device that was merely unreachable is not.
Failure reasons are grouped by status code, and the ledger outlives the notifications and the
subscribers it refers to.

**A/B testing** with stable assignment — the same person is always in the same arm, so a retried
batch never shows one person both — and a winner declared only once both arms have enough data.

**Quiet hours and frequency caps.** Quiet hours *defer* a send to the end of the window; they never
drop it silently.

## Privacy

Schedulr stores a random ID the browser generates, kept in `localStorage` rather than a cookie so
that setting it cannot poison a full-page cache. Alongside it: the push endpoint and its keys when
permission is granted, the Craft user ID when they are signed in (which is where an email address
comes from), browser language and time zone, a coarse platform (no version), a visit count, and any
tags you set.

No page URLs, no dwell time, no cross-site identifiers, and nothing sent to any third party. Push
payloads are encrypted end to end, so the push service relays a message it cannot read. The privacy
settings screen lists every column, and every one of them is visible on any subscriber's own screen
— so the list can be checked rather than taken on trust.

## Already using the PWA plugin?

Schedulr detects it and stands aside: it borrows PWA's VAPID keypair, adopts its subscribers on
install, and does not register a service worker of its own. Your visitors are asked once, your
existing subscriptions keep working, and PWA's offline behaviour is untouched.

This needs no configuration and no changes to PWA.

## Templates

Automatic injection puts the runtime on every page. If you would rather place it yourself, turn
injection off and use:

    {{ craft.schedulr.runtime() }}

Read-only helpers are available on `craft.schedulr` — `notifications()`, `audiences()`, `stats()`,
`publicKey()`. Nothing on the Twig variable can send a notification, because a template that could
would send one on every page load the first time somebody cached it.

For your own opt-in UI, set the prompt style to *Custom* and mark up your own controls:

    <button data-schedulr-subscribe>Notify me</button>
    <button data-schedulr-unsubscribe>Stop notifying me</button>

## Console

    php craft schedulr/run                      # the scheduler; put this on cron
    php craft schedulr/run/health               # what the CP banner says, at a shell
    php craft schedulr/run --dry-run            # what is due, without sending
    php craft schedulr/notifications/list
    php craft schedulr/notifications/send "Title" --body="…" --channels=push,email
    php craft schedulr/notifications/send "Title" --dry-run       # count the audience only
    php craft schedulr/notifications/report 42
    php craft schedulr/subscribers/stats
    php craft schedulr/subscribers/adopt-pwa
    php craft schedulr/subscribers/prune --days=365

## Editions

**Lite composes, schedules and sends. Pro decides who, when *for them*, and what happened.**

All three channels, send-now, scheduled sends and recurrence are in Lite. Pro adds saved audiences,
per-subscriber time zones, automations, A/B testing, click analytics, frequency caps, the per-channel
dedupe policy, and CSV export.

There is **no cap on subscribers or on notifications sent** in either edition. Charging for the
number that grows with your success is the wrong shape of pricing for this tool.

If a licence lapses, nothing is deleted, no schedule is cancelled, and no send stops. Lite only
refuses to create the *next* Pro-shaped thing.

## Requirements

Craft CMS 5.3+, PHP 8.2+, and `ext-openssl`. No other runtime dependencies: the web push encryption
(RFC 8291) and VAPID signing (RFC 8292) are implemented in pure PHP and pinned to the RFC's own test
vector, because a push payload encrypted wrongly is still accepted by the push service — it simply
never appears, with nothing in any log to say so.

## Licence

Commercial. See `LICENSE.md`.
