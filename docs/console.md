---
title: Console commands
slug: console
order: 60
summary: Running the scheduler, sending from a shell or a deploy script, reports, and subscriber housekeeping.
---

## The runner

```sh
php craft schedulr/run                      # one pass of the scheduler; put this on cron
php craft schedulr/run --quiet              # print nothing unless something happened
php craft schedulr/run --limit=50           # due sends to dispatch in one pass (default 25)
php craft schedulr/run --dry-run            # list upcoming sends without sending anything
php craft schedulr/run/health               # what the control-panel banner says, at a shell
```

The cron line:

```sh
* * * * * cd /path/to/site && php craft schedulr/run --quiet
```

Each pass, in order: takes back any send that stalled (a worker killed partway through), tops up
recurring schedules to the expansion horizon, checks inactivity win-backs (Pro), then dispatches what
is due. Dispatching resolves the audience and pushes batches onto Craft's queue; the queue does the
sending.

`--quiet` exists for cron: a job that prints a line every minute is a job whose output nobody reads.
With it, the command prints only when it dispatched, expanded, reclaimed or raised something.

A pass that finds another one already running returns immediately, so overlapping cron runs are
harmless.

`--dry-run` lists the next sends with their UTC time, time zone and title, marking anything due now.

`run/health` prints the runner state (`cron`, `web`, `stalled` or `manual`), when cron and the web
fallback last ran, and how many sends are pending and overdue. It exits non-zero when the state is
`stalled`, so it can drive a monitoring check:

```sh
php craft schedulr/run/health || notify-ops "Schedulr is not running"
```

See [Troubleshooting](troubleshooting#the-runner-banner) for what each state means.

## Notifications

```sh
php craft schedulr/notifications/list                   # the 25 most recent
php craft schedulr/notifications/list 100
php craft schedulr/notifications/send "Title" --body="…" --url=/news --channels=push,email
php craft schedulr/notifications/send "Title" --audience=lapsed-readers
php craft schedulr/notifications/send "Title" --dry-run    # count the audience only
php craft schedulr/notifications/resend 42
php craft schedulr/notifications/resend 42 --dry-run
php craft schedulr/notifications/report 42
```

`send` composes a notification on the primary site and sends it immediately. Its options:

| Option | Default | |
| --- | --- | --- |
| `--body` | | The body text |
| `--url` | | Where a tap lands |
| `--channels` | `push` | Comma separated: `push`, `email`, `onsite` |
| `--audience` | | An audience handle (Pro). Omitted sends to everyone reachable |
| `--tag` | | A collapse key |
| `--dry-run` | | Validate and count the audience without saving or sending anything |

It is there so "tell everyone the release shipped" can live in the release script:

```sh
php craft schedulr/notifications/send "Version 2.4 is out" \
    --body="Faster search and a new reading mode." \
    --url=/changelog \
    --channels=push,onsite
```

`send` and `resend` queue the work and return; the queue delivers it. On a server whose queue only runs
over web requests, run `php craft queue/run` afterwards.

`resend` resets an existing notification's counters and sends it again to its current audience.

`report` prints the notification's funnel (targeted, delivered, failed, displayed, clicked, dismissed
and the rates) and its failures grouped by channel, status and status code, with an example error.

## Subscribers

```sh
php craft schedulr/subscribers/stats                    # totals and time zones
php craft schedulr/subscribers/adopt-pwa                # copy PWA's push subscribers across
php craft schedulr/subscribers/prune                    # forget subscribers past the retention setting
php craft schedulr/subscribers/prune --days=365
php craft schedulr/subscribers/prune --dry-run
```

`stats` prints the totals (known browsers, push, email, declined, unsubscribed) and every time zone on
the list with a count. The number of zones is the number of separate sends a "09:00 local" notification
becomes.

`adopt-pwa` is run automatically when Schedulr is installed alongside PWA. Running it again is safe:
subscribers are matched on their push endpoint and nobody is adopted twice.

`prune` forgets every subscriber not seen for `--days`, or for the `subscriberRetentionDays` setting
when `--days` is not given. It asks for confirmation; pass `--interactive=0` to run it unattended. With
`--dry-run` it shows the retention period and the size of the list without deleting anything.
