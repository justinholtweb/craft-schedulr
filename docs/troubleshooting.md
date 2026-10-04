---
title: Troubleshooting
slug: troubleshooting
order: 70
summary: The runner banner, notifications that never appear, permission, HTTPS, and PWA interop.
---

## The runner banner

Craft has no scheduler, and the failure Schedulr is built to avoid is looking scheduled while doing
nothing. So every Schedulr screen says which state the runner is in. `php craft schedulr/run/health`
reports the same thing at a shell.

| State | Banner | What it means | What to do |
| --- | --- | --- | --- |
| `cron` | None | Cron has run `schedulr/run` in the last five minutes. Sends go out on time, and the web fallback stands down | Nothing |
| `web` | *Sending by web requests* | Runner mode is *Cron if available, web requests if not*, and cron has not been seen. Due sends go out at the end of somebody's page request, at most once every `runnerMinInterval` seconds | Add the cron line to send on time |
| `stalled` | *Schedulr has not heard from cron* | Runner mode is *Cron only*, and cron has not run in the last five minutes. **Nothing is sending scheduled notifications** | Fix the cron job, or switch the runner mode to *Cron if available* |
| `manual` | *Nothing is sending scheduled notifications* | Runner mode is *Never automatically*. Schedules fire only when something runs `craft schedulr/run` | Intended for sites driving Schedulr from their own code |

The cron line:

```sh
* * * * * cd /path/to/site && php craft schedulr/run --quiet
```

"Cron has been seen" is kept in Craft's cache. If the cache is cleared, the banner shows `web` (or
`stalled`) until the next cron tick, a minute later at most.

A site in the `web` state sends late when it is quiet. A 09:00 send on a site whose first visitor of the
day arrives at 09:40 goes at 09:40. Lowering `runnerMinInterval` does not help a site with no visitors.

## A notification was sent, and nothing arrived

Work down this list:

1. **Did it go out at all?** Open the notification and its **Full delivery log**. A recent send with
   status `skipped` and *Nobody matched the audience* means exactly that: check the channels, the
   audience and the topics.
2. **Is the queue running?** The runner only queues batches. If **Utilities → Queue Manager** has
   Schedulr jobs waiting, nothing will be delivered until the queue runs.
3. **What does the ledger say?** `failed` with a status code is the push service or mailer saying no.
   See below for the codes.
4. **Is it in quiet hours, or past a frequency cap?** A recurring send in quiet hours is moved to the
   end of the window. Somebody over the frequency cap (Pro) is left out of the audience entirely.
5. **Is it a draft?** With **Schedule is live** off, a notification has no scheduled sends.

## Push says delivered, but nothing appears

"Delivered" means the push service accepted the message. A push service accepts a message whose
encryption is wrong just as happily as a good one, so:

- **Settings → Web push → Check openssl and signing**, then **Send a test to one device**, on your own
  browser. If the test appears, push works and the problem is elsewhere.
- **Check the operating system.** macOS Focus, Windows Do Not Disturb and Android's per-site notification
  settings all hide notifications the browser received.
- **Check the worker.** Open `/schedulr-worker.js` (or your `serviceWorkerPath`). It should return
  JavaScript, not a 404 or your site's HTML 404 page. In the browser's developer tools, under
  Application → Service workers, it should be registered with scope `/`.
- **Another service worker owns the scope.** Only one worker can control `/`. If your site, a theme, or
  another plugin registers one, either turn **Register Schedulr's service worker** off and handle
  push in that worker (see [Web push](web-push#using-your-own-worker)), or remove the other one.
- **The worker is served from a subdirectory.** A worker at `/assets/sw.js` controls only `/assets/`.
  Keep `serviceWorkerPath` at the root.

## Push failure codes

| Code | Meaning | Fix |
| --- | --- | --- |
| 401, 403 | The push service rejected your VAPID credentials | Set **Push subject** to a real `mailto:` address. If the keys come from the environment, check both halves are from the same pair. Devices are not dropped for this, but nothing is delivered until it is fixed |
| 404, 410 | The subscription no longer exists (the visitor revoked permission, cleared site data, or reinstalled) | Nothing. The device is dropped automatically, and the visitor record is kept |
| 413 | The payload is too large | Shorten the title, body or links. Push allows about 4KB after encryption |
| 429, 5xx | The push service is busy or failing | Nothing, usually. These are not counted against the device |
| — (*not on a recognised push service*) | The stored endpoint is not on the [push-service allowlist](web-push#push-services) | Add the host to `extraPushHosts` if it is a genuine push service |

## A browser allows notifications but never becomes a subscriber

- **Its push service is not on the allowlist.** Schedulr only accepts subscriptions to
  [known push services](web-push#push-services) over `https` on port 443, and refuses the rest at
  subscribe time. Every current browser uses a listed one; for anything else, add the host to
  `extraPushHosts`.
- **The visitor unsubscribed from everything** through an email link. That opt-out is not undone by
  allowing push again in the browser.
- **A proxy is stripping the request.** The subscribe endpoint only accepts `application/json`
  requests (or ones the browser marks same-origin) and answers 400 otherwise.
- **Too many requests from one address.** The endpoints are rate-limited per IP and answer `429` when
  the limit is reached. Behind a load balancer or CDN, set Craft's `trustedHosts` and `ipHeaders` so
  each visitor is not counted as the proxy's single address.

## The prompt never appears

- **The visitor already answered.** The prompt is never shown to a browser that already allowed or
  blocked notifications, or to somebody who said no within `promptReaskDays`. Test in a private window,
  or reset the site's permissions in the browser.
- **The thresholds have not been met.** By default the prompt waits for the second page view and eight
  seconds on the page.
- **The page is not on HTTPS.** Browsers only offer push to secure pages. Without it the runtime does
  not prompt at all. `localhost` counts as secure.
- **The browser has no push.** iPhone and iPad Safari only offer push to sites added to the home screen.
  Those visitors are still reachable on-site.
- **The native style and a browser that wants a click.** Safari and Firefox ignore a permission
  request that does not come from a click. Use the bell or slide-down style.
- **The runtime is not on the page.** View source and look for `window.__SCHEDULR__`. It is not
  injected when `injectRuntime` is off, on URIs in `excludedUris`, on responses that are not
  `text/html`, or on pages without a closing `</body>` tag.
- **The custom style, with nothing marked up.** Custom renders nothing of its own. Your page needs an
  element with `data-schedulr-prompt` (and `hidden`) for it to reveal, and a `data-schedulr-subscribe`
  control inside it.
- **The style is Pro on a Lite install.** Slide-down and custom fall back to the bell on Lite.

## Visitors were blocked or declined

A browser-level block is permanent: there is no API to ask again. Those visitors are kept as
subscribers and are still reachable on-site, and by email if they sign in. The **Declined push**
audience rule (Pro) finds them.

## On-site notifications do not show

- The runtime must be on the page (see above), and **Render the banner** must be on. With it off, your
  own code has to fetch `/schedulr/inbox.json`.
- An on-site notification is shown on the visitor's next page load after it was sent, not on pages
  that were already open.
- Each item is handed over once. If your own code fetches the inbox and does not render what it gets,
  those items are gone.

## Email is not sending, or reaches fewer people than expected

- The email channel reaches subscribers whose browser was signed in to a Craft account when Schedulr
  last saw it. Anonymous visitors are not emailable, however many there are.
- Test Craft's own mail settings in **Settings → Email** first; Schedulr uses that transport.
- `failed` with *The mailer refused the message* comes from Craft's mailer, so its log has the detail.

## Per-subscriber time zone sends

- One notification becomes one send per time zone on your list, spread across about 26 hours. That is
  expected. `php craft schedulr/subscribers/stats` shows how many zones there are.
- Subscribers whose zone is unknown are sent at the site's own time zone.
- On Lite, a schedule cannot be switched to per-subscriber time zones. One that already uses them
  keeps them, including when it is saved again.

## PWA interop

- **Visitors are asked twice.** **Keep standing aside for PWA** is off. Turn it back on.
- **Push stopped working after installing PWA, or after removing it.** The keypair changed hands.
  Subscriptions are bound to the public key they were created with, so devices subscribed under the
  other plugin's key must subscribe again. While both plugins are installed and Schedulr stands aside,
  there is one keypair and this does not happen.
- **Schedulr's subscriber list is empty on a site with PWA subscribers.** Run
  `php craft schedulr/subscribers/adopt-pwa`, or press **Adopt them** on the Web push pane.
- **PWA's offline page or install prompt disappeared.** Schedulr registered its own worker over PWA's,
  which happens only when standing aside is off. Turn it back on.
- **No displayed or dismissed events.** Expected when PWA's worker is handling push: it does not report
  them. Clicks are still tracked.

## After a deploy, Pro screens are locked

Plugin editions live in project config. If `config/project/project.yaml` is re-applied with Schedulr
set to Lite, you can no longer create or change Pro configuration, and new sends are not
click-tracked. Everything already saved keeps working: see
[what Lite does with Pro data](installation#if-a-licence-lapses-or-you-move-to-lite). Check the edition
on **Settings → General**.

## A notification was saved as a draft when I scheduled it

Arming a schedule or choosing a trigger needs the *Send and schedule notifications* permission. Without
it, the notification is saved as a draft with no trigger, and somebody who can send has to arm it.
Editing a notification that is already scheduled or automated needs that permission too, and is
refused (403) without it.

## Getting help

Email [justin@justinholt.com](mailto:justin@justinholt.com) with the output of
`php craft schedulr/run/health` and, for a particular notification,
`php craft schedulr/notifications/report <id>`. Schedulr logs to Craft's log under the `schedulr`
category.
