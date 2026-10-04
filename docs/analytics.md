---
title: Reports & analytics
slug: analytics
order: 40
summary: The delivery ledger, click tracking, the funnel, A/B results and CSV export.
---

## The ledger

Every send writes one row per recipient per channel: who, which channel, which variant, the outcome,
the push service's status code and its error text. **Schedulr → Reports** and each notification's
**Full delivery log** read from it. Both editions have it.

| Status | Meaning |
| --- | --- |
| `queued` | On-site only: waiting for the visitor to load a page |
| `delivered` | Push: the push service accepted it. Email: the mailer accepted it. On-site: the visitor's browser fetched it |
| `failed` | Not sent. The status code and error say why |
| `gone` | Push: the push service says the subscription no longer exists. The device has been dropped |
| `skipped` | Not reachable on that channel |

"Delivered" for push means the push service took it. Whether it appeared is what the *displayed*
event is for.

The ledger outlives what it describes. Deleting a notification or forgetting a subscriber leaves their
delivery rows in place, so "what went out last March" stays answerable. It is pruned on a rolling
window instead: see `ledgerRetentionDays` in [Configuration](configuration#privacy).

## Reports

**Schedulr → Reports** shows the known browsers on your list (how many accept push, how many have an
address), a daily chart of delivered and failed sends over 7, 30, 90 or 365 days, and, in Pro, the
best-performing notifications by click rate (once they have at least 20 deliveries).

Each notification's delivery log shows the summary by channel and status, failure reasons grouped by
status code with an example error for each, the recent sends of a recurring schedule, the A/B
variants, and every ledger row, filterable to failures only.

From a shell, `php craft schedulr/notifications/report <id>` prints the same funnel and failure
summary.

## Click tracking (Pro)

In Pro, a notification's link is replaced by a tracked link on Schedulr's own domain:

```
https://example.com/schedulr/go?sr_n=42&sr_c=push&sr_v=7&sr_s=1234&sr_k=3f9a0c51b2e4
```

It records the click and redirects to the notification's link. Three properties are deliberate:

- **It carries only IDs, never the destination.** A redirect that takes its target from a query
  parameter is an open redirect, and so a phishing tool on your own domain. A URL carrying another URL
  is also what pushes a payload over the roughly 4KB limit push services allow after encryption.
- **It is signed.** `sr_k` is an HMAC of the other parameters made with your site's security key, so
  somebody who received one notification cannot inflate every campaign's clicks. A link with a bad
  signature to a notification that has been sent still redirects (so a rotated security key does not
  strand readers); it just is not counted. An unsigned link to a notification that has never gone out
  is a 404, so the redirect cannot be used to preview a draft's destination.
- **Every parameter starts with `sr_`.** Craft reserves `token` and `p`.

Because tracking rides on the link rather than on the service worker reporting anything, it works the
same through Schedulr's worker, PWA's worker, an email client and the on-site banner.

A person clicking twice counts once towards the click rate, though every click is recorded.

Action buttons on push notifications are tracked the same way, through their own signed links.

In Lite, new sends link straight to their destination and are not counted. Links that went out while
the site was Pro keep redirecting and counting.

## Events

Besides clicks, Schedulr records:

| Event | Reported by |
| --- | --- |
| `displayed` | Schedulr's service worker when it shows a push notification; the on-site runtime when it shows the banner |
| `dismissed` | Schedulr's service worker when a notification is closed; the on-site runtime when the close button is pressed (not when the banner times out) |
| `unsubscribed` | The email unsubscribe link, once the visitor confirms (or their mail client sends a one-click unsubscribe) |
| `converted` | Your own code, if you report it |

Displayed and dismissed push events come from Schedulr's own worker. On a site where
[PWA's worker](web-push#running-alongside-pwa) handles push, clicks are still tracked but displays and
dismissals are not reported.

The event endpoint only accepts events it can attribute to somebody: either the visitor ID the runtime
keeps in the browser, or a subscriber ID together with the signature (`k`) the push payload carried
for it. Each type of event is written once per person per notification, so repeating a request adds
nothing. Requests must be sent as JSON, and are rate-limited per IP.

To record a conversion, post the notification ID and the visitor's ID:

```js
fetch('/actions/schedulr/track/event', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ type: 'converted', n: 42, s: Schedulr.visitorId() }),
});
```

Clicks are never accepted on this endpoint: they come only from the signed redirect.

## The funnel

Each notification's results (Pro) show:

| | |
| --- | --- |
| targeted | People the send was addressed to |
| delivered | Ledger rows that were delivered |
| failed | Ledger rows that failed |
| displayed, clicked, dismissed | Distinct people with that event |
| clicks | Every click, including repeats |
| deliveryRate | Delivered as a percentage of targeted |
| clickRate | Distinct clickers as a percentage of delivered |
| dismissRate | Dismissals as a percentage of *displayed*, since only something shown can be dismissed |

A rate with nothing to divide by is shown as a dash rather than 0%, so a notification that has not
been sent yet does not look like one that failed.

## A/B results (Pro)

Each variant shows its deliveries, clicks and click rate. The winner is marked once every arm has at
least 100 deliveries, as the arm with the highest click rate. See [Usage](usage#ab-testing-pro).

## CSV export (Pro)

- **Subscribers** — **Schedulr → Subscribers → Export CSV**, optionally filtered to push subscribers,
  people with an address, or unsubscribed. Columns: `id`, `state`, `email`, `userId`, `language`,
  `timezone`, `platform`, `visits`, `notified`, `firstSeen`, `lastSeen`, `subscribed`. Needs the
  *Export subscribers* permission.
- **Deliveries** — **Export CSV** on a notification's delivery log. Columns: `id`, `subscriberId`, `channel`,
  `status`, `statusCode`, `error`, `sentAt`.
