---
title: Installation
slug: installation
order: 10
summary: Requirements, install, editions, the cron runner, and your first notification.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later, with the `openssl` and `json` extensions
- HTTPS on the front end, for web push (browsers only offer push to secure pages; `localhost` counts
  as secure for development)

There is no build step and there are no runtime dependencies beyond Craft's own. Web push encryption
(RFC 8291) and VAPID signing (RFC 8292) are implemented in pure PHP against `ext-openssl`, so the
`openssl` extension is the one hard requirement. Your PHP build's OpenSSL must support the P-256
curve (`prime256v1`), which every mainstream build does. **Schedulr → Settings → Web push → Check
openssl and signing** confirms it.

The email channel sends through Craft's own mailer, so it uses whatever transport you have set up in
**Settings → Email**.

## Install

```sh
composer require justinholtweb/craft-schedulr
php craft plugin/install schedulr
```

Or find **Schedulr** in the Craft Plugin Store and install it from there.

On install, Schedulr generates a VAPID keypair the first time one is needed and stores it in the
database (never in project config). If the [PWA plugin](web-push#running-alongside-pwa) is installed,
it borrows PWA's keypair instead and copies PWA's push subscribers across, so nobody is asked twice.

## Set up the runner

Craft has no scheduler of its own, so add one line to cron:

```sh
* * * * * cd /path/to/site && php craft schedulr/run --quiet
```

Cron is the only mode that sends on time. Without it, Schedulr falls back to checking for due
notifications at the end of front-end requests, which means a quiet site sends late. The control
panel says which of the two is happening on every Schedulr screen, so you never have to guess. See
[Troubleshooting](troubleshooting#the-runner-banner) for the four states it can report.

Running the command more often than once a minute is harmless: a mutex means a second pass finds the
lock held and returns immediately.

### The queue

The runner decides *what* is due. The sending itself happens in Craft's queue, one job per batch of
200 recipients by default, so a large send neither blocks the runner nor loses its place if a worker
dies. If your site runs the queue from a daemon or `php craft queue/listen`, nothing else is needed.
If it relies on Craft running the queue automatically over web requests, that works too, but it has
the same "only when somebody visits" limitation as the web fallback runner.

## Editions

Schedulr is a paid plugin with two editions: **$79 one-time + $59/yr** for updates. See the Plugin
Store listing for each edition's current price.

**Lite composes, schedules and sends. Pro decides who, when *for them*, and what happened.**

| | Lite | Pro |
| --- | --- | --- |
| Subscribers and sends | Unlimited | Unlimited |
| Channels: web push, email, on-site | Yes | Yes |
| Send now, send once at a time, recurring schedules | Yes | Yes |
| Topic targeting (subscriber tags) | Yes | Yes |
| Quiet hours | Yes | Yes |
| Delivered and failed counts, the delivery log | Yes | Yes |
| Opt-in prompt styles | Native dialog, bell button | + slide-down panel, custom markup |
| Per-channel dedupe policy | Every channel to everyone reachable | Choose per notification |
| Saved, condition-built audiences | No | Yes |
| Per-subscriber time zone delivery | No | Yes |
| Automations (entry published, user registered, inactivity) | No | Yes |
| A/B testing | No | Yes |
| Click tracking, display and dismiss events, the funnel | No | Yes |
| Frequency caps | No | Yes |
| CSV export of subscribers and deliveries | No | Yes |

There is **no cap on subscribers or on notifications sent** in either edition. Charging for the
number that grows with your success is the wrong shape of pricing for this tool.

### If a licence lapses or you move to Lite

Every gate is a downgrade, not a wall. Nothing is deleted, no schedule is cancelled, and no send
stops. Pro configuration you saved keeps doing exactly what it did:

| Feature | Lite cannot… | …but what you saved keeps |
| --- | --- | --- |
| Saved audiences | create one, or point a notification at one | filtering every send to its audience |
| Per-subscriber time zones | switch a schedule to them | sending once per zone, including after a re-save |
| Automations | create a trigger or change one | firing |
| A/B testing | add a variant | splitting the audience and picking a winner |
| Dedupe policy | choose a policy (new notifications use the default) | being applied between channels |
| Frequency caps | change the caps | leaving over-cap subscribers out |
| Prompt styles | choose a Pro style | prompting, as the bell button |
| CSV export | export | — |

Saving a notification on Lite leaves its stored Pro values (audience, policy, variants, trigger)
untouched, so renewing changes nothing.

**Click tracking is the one exception.** It is applied to each new send rather than saved, so on Lite
new sends link straight to their destination and are not counted. Links already sent while the site
was Pro keep redirecting and keep counting; Lite stops showing the funnel built on them.

## Your first notification

1. Go to **Schedulr → Notifications** and press **New notification**.
2. Write a **Title** (up to 120 characters) and a **Body** (up to 400), and set a **Link**.
3. Tick the **Channels** to send on. Push is the default.
4. Leave **Send** on *Send immediately* and press **Save and send now**. Saving on its own never
   sends: sending is a separate button, behind its own permission.

Then visit the site in another browser and wait for the prompt (by default after two page views and
eight seconds). Allow notifications, and use **Schedulr → Settings → Web push → Send a test to one
device** to check that push actually arrives. See [Usage](usage) for everything else.
