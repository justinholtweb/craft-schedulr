---
title: FAQ
slug: faq
order: 80
summary: Common questions about scheduled web push, email and on-site notifications in Craft CMS.
---

## Which Craft and PHP versions are supported?

Craft CMS 5.3+ and PHP 8.2+, with the `openssl` extension.

## What does it cost?

$79 one-time + $59/yr for updates. There are two editions, Lite and Pro; see
[Installation](installation#editions) for what each includes.

## Is there a limit on subscribers or sends?

No, in either edition. Charging for the number that grows with your success is the wrong shape of
pricing for this tool.

## Do I need a OneSignal, Firebase or other third-party account?

No. Schedulr talks to the browsers' push services directly with its own VAPID keypair, and sends email
through Craft's mailer. Nothing about your visitors is sent to any third party, and push payloads are
encrypted so the push service cannot read them.

## Do I need cron?

To send on time, yes. Without it, Schedulr sends due notifications at the end of front-end requests,
which works but sends late on a quiet site. The control panel tells you which is happening. See
[Installation](installation#set-up-the-runner).

## Why three channels?

Most visitors never grant push permission. A push-only plugin writes off everybody who pressed
*Block*, everybody on a browser without push, and everybody on an iPhone that has not added the site to
the home screen. On-site reaches everyone who loads a page, and email reaches signed-in users, from the
same notification.

## Will people with push and email get everything twice?

On Lite, yes, if you enable both channels. On Pro, each notification has a dedupe policy: email only
the people push did not reach, or one channel per person. See [Usage](usage#when-two-channels-reach-the-same-person-pro).

## Does it work on iPhone?

Push works on iOS and iPadOS 16.4 and later, but only for a site the visitor has added to the home
screen. On-site notifications work in every browser.

## Does Schedulr work with full-page caching?

Yes. The runtime is the same on every page, the visitor ID is generated in the browser and kept in
`localStorage` rather than a cookie, and the endpoints use no CSRF token, so nothing in a cached page
varies by visitor.

## Does it slow my pages down?

The runtime is a few kilobytes of inline script and CSS, so there are no extra file requests. Each page
makes one small request to Schedulr after it loads, which also brings back any on-site notifications.
The web fallback runner, if you use it, runs at the end of a front-end request at most once every
`runnerMinInterval` seconds, and only queues work rather than sending it. With cron running, it never
runs at all.

## I already use the PWA plugin. Will they fight?

No. Schedulr detects PWA, uses its keypair, adopts its subscribers and does not register a service
worker of its own. Your visitors are asked once and PWA's offline behaviour is untouched. See
[Web push](web-push#running-alongside-pwa).

## Can I keep my subscribers if I move from another push service?

You can keep the keys: put them in `SCHEDULR_VAPID_PUBLIC_KEY` and `SCHEDULR_VAPID_PRIVATE_KEY` and
existing subscriptions stay valid. The subscriptions themselves need importing, which Schedulr does
automatically only from PWA. See [Web push](web-push#bringing-keys-from-another-push-stack).

## Can I send a notification from my own code?

From a shell or a deploy script, yes: `php craft schedulr/notifications/send`. See [Console](console).
There is deliberately no way to send from Twig, because a template that could would send on every page
load the first time somebody cached it.

## Can I design my own opt-in prompt?

Yes, with the custom style (Pro): mark up your own controls with `data-schedulr-prompt` and
`data-schedulr-subscribe`. Lite has the native dialog and the bell button. `data-schedulr-subscribe`
and `data-schedulr-unsubscribe` work in every edition and every style. See
[Usage](usage#opt-in-prompts).

## What does "09:00 in each subscriber's own time zone" actually do?

It turns one send into one per time zone on your list, each at 09:00 local, spread across about 26
hours. Subscribers whose zone is unknown get it at 09:00 in the site's zone. Pro.

## Why can't I see who clicked on Lite?

Click tracking, the display and dismiss events and the funnel are Pro. Lite records every delivery and
failure, per recipient and per channel, in the ledger.

## How is a click counted?

Through a signed redirect on your own domain that carries only IDs, never the destination, so it cannot
be used as an open redirect or to inflate somebody else's numbers. Each person counts once towards the
click rate. See [Reports & analytics](analytics#click-tracking).

## What happens if my licence lapses?

Nothing is deleted, no schedule is cancelled and no send stops. Everything you saved while on Pro
(audiences, variants, automations, dedupe policies, frequency caps, per-subscriber time zones) keeps
working exactly as before. Lite only stops you creating or changing Pro configuration. The one
exception is click tracking: new sends on Lite are not tracked. See
[Installation](installation#if-a-licence-lapses-or-you-move-to-lite).

## How does somebody unsubscribe?

From push, by blocking notifications in the browser, or with a `data-schedulr-unsubscribe` control on
your site. From everything, with the link in any notification email: it opens a confirmation page, and
the button on it (or the one-click unsubscribe in Gmail and Outlook) unsubscribes them from push, email
and on-site. That opt-out stays in place even if the same browser allows push again later.

## What does Schedulr store about visitors?

A random browser-generated ID, the push endpoint and keys if they allowed push, the Craft user ID if
they were signed in, browser language and time zone, a coarse platform, visit counts and dates, the
user agent (optional) and any tags you set. No page URLs, no dwell time, no cross-site identifiers. The
Privacy settings pane lists it all, and every column is visible on each subscriber's own screen. See
[Configuration](configuration#privacy).

## Does it support GraphQL?

Not yet.
