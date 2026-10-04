---
title: Configuration
slug: configuration
order: 50
summary: Every setting and its default, config/schedulr.php, environment variables and permissions.
---

## Settings

**Schedulr → Settings**, admins only, in seven panes. Settings are stored in project config, so they
deploy with the rest of your site's configuration. Notifications, audiences, subscribers and the VAPID
keypair are not settings and are never in project config.

No setting is required: a fresh install can save any pane without filling anything in first.

With `allowAdminChanges` off (as it usually is in production), the panes stay viewable but every field
is read-only and there is nothing to save. Change settings in development and deploy them, or use
`config/schedulr.php`.

### General

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Add Schedulr's runtime to front-end pages | `injectRuntime` | `true` | Injects the runtime before `</body>` on every front-end HTML response. Off to place it yourself with `{{ craft.schedulr.runtime() }}`. Every endpoint keeps working either way |
| Except on these URIs | `excludedUris` | `[]` | Pages the runtime is never injected into. Matched against the path without a leading slash; `*` matches anything and `?` one character: `checkout/*` |
| Accent colour | `accentColor` | `#C7278C` | The prompt and on-site banner colour, set as `--schedulr-accent` |

### Opt-in prompt

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Prompt style | `promptStyle` | `bell` | `native`, `bell`, `slide` (Pro) or `custom` (Pro). See [Usage](usage#opt-in-prompts) |
| After this many page views | `promptAfterViews` | `2` | Zero prompts on the first page, which is the most reliable way to be blocked |
| And this many seconds on the page | `promptAfterSeconds` | `8` | |
| Ask again after | `promptReaskDays` | `30` | Days before somebody who said no is asked again. Zero makes a "no" final |
| Heading | `promptHeading` | `Stay in the loop` | |
| Body | `promptBody` | `Get a notification when we publish something new.` | |
| Accept label | `promptAccept` | `Allow` | |
| Decline label | `promptDecline` | `No thanks` | |

### Web push

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Keep standing aside for PWA | `deferToPwa` | `true` | Shown when PWA is installed. See [Web push](web-push#running-alongside-pwa) |
| Push subject | `pushSubject` | `''` | The VAPID `sub` claim, `mailto:` or `https:`. Empty derives one from the system email address. Accepts `$ENV_VAR` |
| Drop a device after this many consecutive failures | `pushMaxFailures` | `5` | Minimum 1 |
| Default icon URL | `defaultIcon` | `''` | Used by Schedulr's worker when a notification has no icon. An `http(s)` URL or a path on this site; relative paths resolve against the site. Accepts `$ENV_VAR` |
| Default badge URL | `defaultBadge` | `''` | The same, for the monochrome Android badge |
| Register Schedulr's service worker | `registerServiceWorker` | `true` | Off leaves push to your own worker, or PWA's |
| Service worker path | `serviceWorkerPath` | `/schedulr-worker.js` | Must start with `/` and end in `.js`, and should sit at the site root |
| — | `extraPushHosts` | `[]` | Push-service hosts to accept subscriptions from, on top of the built-in list. Each covers its subdomains and needs at least one dot. Config file only. See [Web push](web-push#push-services) |

The keypair is shown on this pane but is not a setting. See [Web push](web-push#vapid-keys).

### Email

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| From name | `emailFromName` | `''` | Empty uses the system setting. Accepts `$ENV_VAR` |
| From address | `emailFromEmail` | `''` | Empty uses the system setting. Accepts `$ENV_VAR` |
| Template | `emailTemplate` | `''` | A site template rendered for each email, such as `_emails/notification`. Empty uses the bundled one |

Your own email template receives:

| Variable | |
| --- | --- |
| `notification` | The notification element |
| `subscriber` | Who it is going to |
| `title` | The title, with any A/B variant applied |
| `body` | The HTML body (the notification's email body, or its body text), already marked safe |
| `url` | The tracked link. Use this rather than `notification.url`, or clicks go uncounted |
| `unsubscribeUrl` | The signed, per-person unsubscribe link. Your template must include it |
| `siteName` | The current site's name |

```twig
<h1>{{ title }}</h1>
{{ body }}
{% if url %}<p><a href="{{ url }}">Read more</a></p>{% endif %}
<p><a href="{{ unsubscribeUrl }}">Unsubscribe</a></p>
```

The subject is the notification's email subject, or its title.

### On-site

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Render the banner | `onSiteRender` | `true` | Off leaves `/schedulr/inbox.json` for your own UI. See [Usage](usage#on-site-notifications) |
| Position | `onSitePosition` | `bottom-right` | `top-left`, `top-right`, `bottom-left`, `bottom-right` |
| Dismiss itself after | `onSiteAutoDismiss` | `12` | Seconds. Zero waits for a click |

### Delivery

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| How due notifications get sent | `runnerMode` | `auto` | `auto` (cron if it is running, web requests if not), `cron` (cron only) or `manual` (never automatically) |
| Minimum seconds between web-fallback runs | `runnerMinInterval` | `60` | Minimum 10. Lowering it does not make a site with no visitors punctual |
| Recipients per queue job | `batchSize` | `200` | 1 to 1000. Larger is fewer jobs and more memory each |
| Materialise this many days of future sends | `expandHorizonDays` | `90` | 1 to 730. How far ahead recurring schedules are expanded |
| At most this many notifications per person | `frequencyCapCount` | `0` | Pro. Zero is no cap |
| Counted over this many days | `frequencyCapDays` | `7` | Pro. The window the cap counts over |
| Quiet hours from | `quietHoursStart` | `''` | `HH:MM`. Empty is no quiet hours |
| Quiet hours until | `quietHoursEnd` | `''` | `HH:MM`. A window that wraps midnight, such as 22:00 to 07:00, is normal |

People who have reached the frequency cap are left out when the audience is worked out, rather than
skipped at send time, so the cap does not fill the ledger with thousands of skipped rows. Queued and
delivered rows on any channel count towards it.

Quiet hours move a recurring send that lands inside the window to the end of it; see
[Usage](usage#quiet-hours). Both ends must be set.

### Privacy

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Store the user agent | `storeUserAgent` | `true` | Off still records the coarse platform, which is what segments use |
| Keep delivery and event records for | `ledgerRetentionDays` | `90` | Days. Zero keeps them forever |
| Forget browsers not seen for | `subscriberRetentionDays` | `0` | Days. Zero never forgets. This deletes subscribers; the setting above only deletes records of sends |

Both run with Craft's garbage collection, as a queued job rather than inside a page request.
`php craft schedulr/subscribers/prune` forgets subscribers by hand.

The Privacy pane lists everything Schedulr stores about a visitor:

- a random ID the browser generates, kept in `localStorage` rather than a cookie, so it cannot break
  full-page caching
- the push endpoint and its two keys, only when push is allowed
- the Craft user ID, when the visitor is signed in
- browser language and time zone
- a coarse platform (iOS, Android, macOS, Windows, Linux), with no version
- visit count and first and last seen dates
- the user agent, if **Store the user agent** is on
- any tags you set

No page URLs, no dwell time, no cross-site identifiers, and nothing sent to any third party apart from
the encrypted payloads push services relay.

## config/schedulr.php

Like any Craft plugin's settings, these can be set in a config file, which takes precedence over the
settings screen. Create `config/schedulr.php` with any of the config keys above:

```php
<?php

use craft\helpers\App;

return [
    'runnerMode' => 'cron',
    'pushSubject' => 'mailto:notifications@example.com',
    'excludedUris' => ['checkout/*', 'account/*'],
    'quietHoursStart' => '22:00',
    'quietHoursEnd' => '07:00',
    'ledgerRetentionDays' => 180,
    'injectRuntime' => App::parseBooleanEnv('$SCHEDULR_INJECT') ?? true,
];
```

Multi-environment config works as usual:

```php
<?php

return [
    '*' => [
        'runnerMode' => 'auto',
    ],
    'production' => [
        'runnerMode' => 'cron',
    ],
];
```

A setting fixed in the config file can still be shown on the settings screen, but changing it there has
no effect.

## Environment variables

| Variable | |
| --- | --- |
| `SCHEDULR_VAPID_PUBLIC_KEY` | The VAPID public key, base64url. Used when PWA is not supplying the keys |
| `SCHEDULR_VAPID_PRIVATE_KEY` | The VAPID private key, as a base64url scalar or PEM. Both must be set |

`pushSubject`, `emailFromName`, `emailFromEmail`, `defaultIcon` and `defaultBadge` also accept a
`$VARIABLE` reference, so they can be set from `.env` through the settings screen without a config
file. Anything else can come from the environment through `App::env()` in `config/schedulr.php`.

## Permissions

| Permission | Allows |
| --- | --- |
| View notifications | The Notifications and Schedule screens |
| — Create and edit notifications | Saving notifications as drafts |
| —— Send and schedule notifications | **Save and send now**, sending an existing notification, turning **Schedule is live** on, choosing a trigger, and editing a notification that is already scheduled or automated |
| — Delete notifications | |
| — View reports | The Reports screen and delivery logs |
| View subscribers | The Subscribers screen |
| — Edit and remove subscribers | Editing tags, deleting subscribers |
| — Export subscribers | The subscriber CSV export (Pro) |
| — Manage audiences | The Audiences screen (Pro) |

Writing a notification and sending it are separate permissions on purpose: a draft can be reviewed,
and a send cannot be recalled. Somebody with only *Create and edit notifications* who turns a schedule
on or picks a trigger has the notification saved as a draft with no trigger, and is told so.

On a multi-site install, editing a notification also needs Craft's permission to edit its site. Subscribers are not nested under notifications, because the subscriber
list is a list of people's browsers and habits, and writing an announcement does not need it.

The settings screens are for admins only.
