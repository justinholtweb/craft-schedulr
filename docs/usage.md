---
title: Usage
slug: usage
order: 20
summary: Composing, channels and dedupe, schedules, time zones, audiences, automations, A/B, prompts, on-site, Twig and JavaScript.
---

## Composing a notification

A notification is a Craft element, so **Schedulr → Notifications** is a normal element index:
statuses, search, sorting and bulk actions all work as they do for entries. Each notification is
written once and sent over whichever channels you enable.

| Field | Notes |
| --- | --- |
| Title | Required, up to 120 characters. That is roughly where Chrome on Android stops showing a title on the lock screen, so longer is refused rather than silently cut |
| Body | Up to 400 characters |
| Link | Where a tap lands. An `http(s)` URL or a path on this site; relative paths resolve against the site |
| Channels | Web push, email, on-site |
| When two channels reach the same person | The dedupe policy. Pro |
| Audience | *Everyone reachable*, or a saved audience (Pro). **Count** shows how many people it matches now |
| Topics | Comma separated. Only subscribers tagged with one of them receive it |
| Schedule is live | Off keeps the notification a draft. A draft never sends, whatever its schedule says |
| Send | Immediately, once at a time, repeatedly, or when something happens |
| Icon, badge, image URLs | Push only. The badge is the monochrome icon in the Android status bar |
| Collapse key | Two notifications sharing a key replace each other on the device. Set it for things that supersede themselves, like a score; leave it empty otherwise, or today's second article replaces the first |
| Keep on screen until acted on | Push only. Ignored by most mobile browsers |
| Buttons | Two at most, each a label and a URL. Every browser that supports action buttons shows two, and a third would be invisible |
| Email subject and HTML body | Shown when the email channel is on. Leave them empty to reuse the push copy |

Every URL on a notification (link, image, icon, badge, button and variant links) must be an `http://`
or `https://` URL or a relative path. Anything else, such as `javascript:`, is refused on save.

A notification's state is one of **Draft**, **Scheduled**, **Sending**, **Sent** or **Failed**.

## Channels

| | reaches | needs from the visitor |
| --- | --- | --- |
| **Web push** | the lock screen, with the browser closed | permission |
| **Email** | signed-in Craft users | to have been signed in on that browser |
| **On-site** | everybody who loads a page | nothing at all |

The three do not share a recipient list. Somebody who pressed *Block* is still reachable on-site, and
somebody who never signed in cannot be emailed. So Schedulr works out the audience per channel from
one subscriber record.

**Web push** is covered in detail on [Web push](web-push).

**Email** goes through Craft's mailer, one message per recipient (never a Bcc list), with a signed
per-person unsubscribe link and `List-Unsubscribe` / `List-Unsubscribe-Post` headers so Gmail and
Outlook offer one-click unsubscribe. The address comes from the Craft user account the browser was
signed in to when Schedulr last saw it. Schedulr has no front-end form for collecting addresses.

Opening the unsubscribe link shows a confirmation page with one button; only that button (a POST)
unsubscribes, so mail scanners that fetch every link in a message cannot unsubscribe anybody. Gmail's
and Outlook's one-click unsubscribe (RFC 8058) posts to the same URL and takes effect at once.
Unsubscribing from email is unsubscribing from everything: push, email and on-site. It is not undone
when the same browser later allows push again.

**On-site** shows the notification as a small banner the next time the visitor loads a page. Sending
is not delivering here: the delivery sits in the ledger as `queued`, and becomes `delivered` when the
visitor's browser actually fetches it, which may be minutes later or never.

### When two channels reach the same person (Pro)

Without a policy, enabling push and email notifies everybody with both twice, which is a reliable way
to lose your push permission. Each notification can choose:

| Policy | What happens |
| --- | --- |
| Every channel, to everyone reachable | The default, and what every new notification on Lite uses |
| Email only the people push did not reach | Push is tried first; anyone it reached is not emailed. On-site still goes to everyone |
| One channel per person | Push if they can receive it, otherwise email, otherwise on-site |

Channels are always tried in the order push, email, on-site. "Reached" means the push service
accepted the message, so a device that was unreachable gets the email instead.

## Scheduling

The **Send** field has four modes:

- **Send immediately** — sends when you press **Save and send now**. A plain **Save** never sends
  anything.
- **Send once, at a time** — a date and time.
- **Send repeatedly** — a recurrence rule.
- **Send when something happens** — an [automation](#automations-pro) (Pro).

For the timed modes, turn **Schedule is live** on. Off keeps the notification a draft, and a draft has
no scheduled sends at all.

Sending, arming a schedule and choosing a trigger all need the *Send and schedule notifications*
permission. Somebody without it can still write notifications: if they turn **Schedule is live** on or
pick a trigger, the notification is saved as a draft with no trigger, with a notice saying so, and
somebody who can send arms it. They cannot edit a notification that is already scheduled or automated
at all. On a multi-site install, editing a notification also needs permission to edit its site.

### Recurrence

| Option | Notes |
| --- | --- |
| How often | Daily, weekly, monthly or yearly |
| Every | The interval. 2 means every other day, week, month or year (up to 365) |
| On these days | Weekly: at least one weekday is required |
| On these days of the month | Monthly: comma separated. Use `-1` for the last day. Days past the end of a short month are clamped to its last day, never rolled into the next |
| At | The time of day, `HH:MM`. Defaults to 09:00 |
| Start, End | The window. The end must be after the start |
| Stop after | A number of sends. Empty is no limit |
| Skip these dates | One `YYYY-MM-DD` per line: holidays, a launch week |

Recurrence rules are not evaluated at send time. Schedulr expands them into real rows ahead of time
(90 days by default, topped up by the runner), which is what lets a notification's edit screen and
**Schedulr → Schedule** list the next sends instead of promising them. Editing a rule discards the
unsent future rows and re-expands; rows that already sent are never touched.

A rule is anchored on its **Start** date (or, without one, the day the schedule was created). The
interval and **Stop after** both count from there: "every other week" keeps its weeks however often the
runner expands it, and a rule that stops after ten sends stops after ten. A start date in the past
does not restart the rule from today, and it never backfills the sends it missed: only sends still in
the future are created.

The time of day is applied to each date in the site's time zone, so a 09:00 send stays at 09:00 across
daylight-saving changes.

### Quiet hours

**Settings → Delivery → Quiet hours** sets a window, such as 22:00 to 07:00, in which nothing
recurring is sent. A recurring send that lands inside the window is moved to the end of it, never
dropped. The window is read in the time zone of the send, so with per-subscriber time zones it means
each subscriber's own night.

Quiet hours apply to recurring schedules. A notification sent immediately, or once at a stated time,
goes when you told it to.

## Per-subscriber time zones (Pro)

With **Time zone** set to *That local time, wherever each subscriber is*, "09:00" means 09:00 for each
subscriber. One send becomes one delivery per time zone on your list, each at that zone's local time,
spread across about 26 hours.

The subscriber's zone comes from the browser (`Intl.DateTimeFormat().resolvedOptions().timeZone`) and
is refreshed on every visit, because people travel. Subscribers whose zone is unknown, or one PHP does
not recognise, are sent at the site's own time zone.

**Schedulr → Subscribers** and `php craft schedulr/subscribers/stats` both show how many zones your
list spans, which is how many separate sends a local-time notification becomes.

Works with both *once at a time* and *repeatedly*.

## Topics

Topics work in every edition. A topic is a subscriber tag: give a notification the topics `sport,
weather`, and only subscribers carrying one of those tags receive it.

Tags are set on a subscriber's own screen in **Schedulr → Subscribers**, one per line as `tag` or
`tag=value`, or from your own PHP:

```php
use justinholtweb\schedulr\Plugin;

Plugin::getInstance()->subscribers->setTags($subscriberId, [
    'sport' => null,
    'plan' => 'gold',
]);
```

## Audiences (Pro)

**Schedulr → Audiences** holds saved segments, built from rules and matched on **all** or **any** of
them. Every rule compiles to SQL over the subscriber table, so a segment resolves in one query and the
editor shows how many people it matches before you send anything.

| Group | Rules |
| --- | --- |
| Reachability | Subscribed to push, has an email address, declined push, is a signed-in user |
| Behaviour | Number of visits, last visit, first visit, subscribed, notifications received, last notified, received a particular notification |
| Who and where | Browser language, time zone, platform, site, user group, tag |

Date rules are relative ("within the last 30 days"), so a segment does not quietly stop matching
anyone a month after you saved it. A rule Schedulr no longer recognises is dropped rather than
matching nobody.

Each audience has a **Handle**, used by `--audience` on the [console](console). Audiences are stored
in the database rather than project config: a segment is content a marketer writes, not something to
deploy.

Frequency caps are applied while the audience is resolved: see
[Configuration](configuration#delivery).

## Automations (Pro)

Set **Send** to *Send when something happens* and choose a trigger:

| Trigger | Options | Who receives it |
| --- | --- | --- |
| An entry is published | Sections (none means all). *Send on every save* (off by default) | The notification's audience |
| A user registers | User groups (none means all) | Only that user's own browsers |
| Someone has not visited for a while | Days without a visit (default 30) | Each person once, ever |

The notification becomes a **template**. It is never sent itself. Each firing makes a new,
concrete notification with its own counts and delivery log, so "which article did we push on Tuesday"
has an answer, and the template's list of children is the history of the rule.

For entry and user triggers, the title, body, link and email fields may use Twig against the source
element, as `entry`, `user` or `element`:

```twig
New on the blog: {{ entry.title }}
```

The Twig is rendered in Craft's Twig sandbox, because whoever may edit notifications is not
necessarily somebody who may edit templates. Element properties work, and anything Craft's sandbox
security policy does not allow, such as `craft.app`, is refused. On a Craft version without the sandbox extension, only plain references such as
`{{ entry.title }}` or `{title}` are substituted, and anything else is refused.

If the link is left empty, an entry-published notification links to the entry. A rendered link that is
not `http(s)` or relative is dropped. If the title renders empty, or the Twig fails, nothing is sent
and the problem is logged; a broken template never stops the editor's save.

Firing happens in Craft's queue rather than inside the save that triggered it.

An entry-published automation fires once per entry. Drafts, revisions, re-saves and entries that are
not live never fire it, so an editor fixing a typo does not notify everybody again, unless *Send on
every save* is on. An entry that goes live later on its post date, without being saved again, is
caught on the next runner pass after it goes live.

Only a live automation fires: a template that is disabled, or saved with **Schedule is live** off,
never does.

Inactivity win-backs and scheduled entries are checked on each runner pass, so they need the runner to
be running.

## A/B testing (Pro)

Fill in a second variant on the notification's edit screen to split the audience. Each variant can
change the title, body and link, and fields left empty inherit from the notification. **Share of
audience** sets the split.

Assignment is stable: a person is put in an arm by a hash of the notification and their subscriber ID,
so a retried batch never shows one person both versions.

The winner is the variant with the best click rate, and is declared only once every arm has at least
100 deliveries. Before that, both arms show their counts and no winner. Since the comparison is on
clicks, A/B testing needs click tracking, which is also Pro.

## Opt-in prompts

A visitor who blocks notifications blocks them for good: there is no API to ask again. So every
default here is patient. **Settings → Opt-in prompt**:

| Style | Edition | Behaviour |
| --- | --- | --- |
| Native browser dialog only | Lite | Calls the browser's permission dialog directly when the thresholds are met |
| Bell button | Lite | A small bell in the corner that opens a panel with your heading, body and buttons. The default |
| Slide-down panel | Pro | The same panel, sliding in |
| Custom | Pro | Schedulr renders nothing; your markup does |

The prompt appears only once the visitor has seen **promptAfterViews** pages (default 2) and spent
**promptAfterSeconds** on the current one (default 8). A visitor who says no is not asked again for
**promptReaskDays** (default 30); zero makes a "no" final. Visitors who already allowed, or whose
browser has already blocked, are never prompted.

Some browsers (Safari and Firefox among them) only show the native permission dialog in response to a
click. On those, the bell or slide-down styles work better than *Native browser dialog only*.

### Custom markup

With the custom style, Schedulr reveals any element carrying `data-schedulr-prompt` (by removing its
`hidden` attribute) when the thresholds are met, and treats any element carrying
`data-schedulr-subscribe` as the accept control:

```html
<div data-schedulr-prompt hidden>
    <p>Get a notification when we publish something new.</p>
    <button data-schedulr-subscribe>Notify me</button>
</div>

<button data-schedulr-unsubscribe>Stop notifying me</button>
```

`data-schedulr-subscribe` and `data-schedulr-unsubscribe` work on every page, in every style, whether
or not Schedulr would have prompted, so a permanent "Notify me" link in a footer works too.

### Styling

The prompt and the on-site banner use the accent colour from **Settings → General**, through the CSS
custom property `--schedulr-accent` on `.schedulr-prompt` and `.schedulr-toast`. Override it from your
own stylesheet if you prefer.

## On-site notifications

Turn on the on-site channel and the notification appears as a banner the next time each subscriber
loads a page. **Settings → On-site** chooses the corner and how long it stays (12 seconds by default;
zero waits for a click). A banner that times out is not counted as a dismissal.

To render on-site notifications in your own UI, turn **Render the banner** off and fetch them:

```js
fetch('/schedulr/inbox.json?visitorId=' + encodeURIComponent(Schedulr.visitorId()) + '&limit=5')
  .then((r) => r.json())
  .then(({ items }) => {
    // items: [{ id, n, v, title, body, image, url }]
  });
```

Fetching marks the returned items as delivered, so each one is handed over once. Render what you
receive. `limit` is 5 by default, up to 20. The `url` is already the tracked link (Pro).

## Templates and Twig

Schedulr injects its runtime into every front-end HTML page automatically. To place it yourself, turn
**Add Schedulr's runtime to front-end pages** off in **Settings → General** and output it in your
layout, once:

```twig
{{ craft.schedulr.runtime() }}
```

The rest of `craft.schedulr` is read-only. Nothing on it can send a notification, because a template
that could would send one on every page load the first time it was cached.

| Method | Returns |
| --- | --- |
| `runtime()` | The runtime's inline `<style>` and `<script>`. Calling it twice outputs it twice |
| `notifications(criteria)` | A notification element query |
| `audiences()` | The current site's saved audiences |
| `stats()` | `total`, `pushable`, `emailable`, `declined` and `unsubscribed` counts for the current site |
| `subscriberCount()` | Push subscribers on the current site |
| `publicKey()` | The VAPID public key, for subscribing from your own JavaScript |
| `isPro()` | Whether Pro is active |
| `subscriber(visitorId)` | The subscriber for a visitor ID, or null. The ID lives in the browser, so templates rarely have one |

The notification query supports Craft's usual parameters, `status` (the five states) and these:
`channel`, `audienceId`, `triggerType`, `tag`, `pending`, `sentAfter`, `sentBefore`.

```twig
{% set recent = craft.schedulr.notifications()
    .status('sent')
    .channel('onsite')
    .limit(5)
    .all() %}

{% for notification in recent %}
    <a href="{{ notification.url }}">{{ notification.title }}</a>
{% endfor %}
```

Notifications also have a reference tag handle, `notification`.

## JavaScript API

The runtime exposes `window.Schedulr`:

| Method | Notes |
| --- | --- |
| `Schedulr.subscribe()` | Asks for permission and subscribes. Resolves `true` or `false` |
| `Schedulr.unsubscribe()` | Removes this browser's push subscription |
| `Schedulr.prompt()` | Shows the configured prompt, if the thresholds and re-ask rules allow |
| `Schedulr.isSubscribed()` | Whether this browser receives push. Accurate once the page's first request to Schedulr has returned |
| `Schedulr.visitorId()` | This browser's random visitor ID |

And it dispatches events on `window`: `schedulr:prompt`, `schedulr:subscribed`,
`schedulr:unsubscribed`.

```js
window.addEventListener('schedulr:subscribed', () => {
  document.querySelector('.notify-me')?.remove();
});
```

## Front-end endpoints

For a site building its own front end. None of them use CSRF tokens or cookies, so full-page caching
keeps working. In their place:

- The POST endpoints only accept requests sent as `Content-Type: application/json`, or that the browser
  marks `Sec-Fetch-Site: same-origin` or `none`. A cross-site page cannot send either without a CORS
  preflight, which nothing here answers. Anything else gets a 400. The signed email unsubscribe is the
  exception, since mail providers post it as a form.
- The heartbeat, subscribe and event endpoints are rate-limited per IP address, generously (a whole office is often one address), and
  answers `429` when the limit is reached. The IP is Craft's `getUserIP()`, so set `trustedHosts` and
  `ipHeaders` if the site is behind a proxy.
- Subscriptions are only accepted for [known push services](web-push#push-services).

| Route | Method | Purpose |
| --- | --- | --- |
| `/schedulr/heartbeat` | POST | Records a visit, answers whether to prompt, and returns the on-site inbox |
| `/schedulr/subscribe` | POST | Records a push subscription |
| `/schedulr/unsubscribe` | POST | Removes a push subscription. With the signed `sr_u` token from an email, unsubscribes from everything; a GET with the token only shows the confirmation page |
| `/schedulr/inbox.json` | GET | On-site notifications for a visitor |
| `/schedulr/go` | GET | The [click-tracking](analytics#click-tracking) redirect. An unsigned link 404s unless the notification has been sent |
| `/actions/schedulr/track/event` | POST | `displayed`, `dismissed` and `converted` events. See [Events](analytics#events) |
| `/schedulr-worker.js` | GET | The service worker, at `serviceWorkerPath` |
