# Release Notes for Schedulr

## 5.0.0

Initial release.

Schedulr starts at 5.0.0 to match the Craft version it targets, in line with the rest of this
plugin family.

### Added

- **Three delivery channels** — web push, email and on-site — for one notification composed once,
  with a per-channel dedupe policy so enabling two of them does not notify everybody twice.
- **Subscribers as visitor identities**, so somebody who declines push is still reachable on-site and
  is not thrown away.
- **Materialised scheduling**: daily, weekly, monthly and yearly recurrence with intervals, windows,
  occurrence caps and skipped dates, expanded into real rows so the control panel can list the next
  twelve sends.
- **Per-subscriber time zone delivery** — "09:00" delivered at 09:00 wherever each subscriber is.
- **Audiences**: condition-built segments over reachability, behaviour, platform, language, time
  zone, user group, tags and prior deliveries, each compiling to a single SQL query and counting
  itself in the editor.
- **Automations** for entry published, user registered and inactivity win-backs, with Twig rendered
  against the source element and one concrete notification per firing.
- **A/B testing** with stable per-subscriber assignment and a winner declared only on sufficient data.
- **Click tracking** through a signed redirect that carries IDs rather than a destination, plus
  displayed and dismissed events reported by the service worker and the on-site runtime.
- **Reports**: a delivery chart, a per-notification funnel, failure reasons grouped by status code,
  and a full delivery log with CSV export.
- **Opt-in prompts** in four styles with view, time and re-ask thresholds.
- **Quiet hours and frequency caps**, deferring rather than dropping.
- **A cron runner with a web fallback**, and a control-panel banner that says which is running.
- **PWA interoperation**: when `justinholtweb/craft-pwa` is installed, Schedulr borrows its keypair,
  adopts its subscribers and leaves the service worker alone.
- Console commands for running the scheduler, sending, reporting and subscriber housekeeping.
- Retention sweeps for the ledger and for subscribers, hooked to Craft's garbage collection.

### Fixed

- The reports and subscriber-detail screens glued a notification's title to its ID. Twig's `??` binds tighter than `~`, so `row.title ?? '#' ~ row.id` parsed as `(row.title ?? '#') ~ row.id`.
- The notifications index returned HTTP 500 whenever the status column was shown — Craft 5 expects `statuses()` to return `craft\enums\Color` cases rather than colour strings.

