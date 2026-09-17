# Overview shows sending statistics per app and per worker

## Context

The limit cards in the Overview (ADR 0001) answered "is something stuck right now", but not the other question an admin brings to the panel: *how many emails went out* — today, yesterday, each day of the last week, at specific hours — and *which app and which worker* sent them. A bar like "2 / 300 in the current window" says nothing about that, and the limit cards didn't even show their configured values.

## Decision

Add a **Sending** section to the Overview: range presets, a custom range with time-of-day, app/worker filters, an app × worker table, and a day-by-day or hour-by-hour table. Replace the limit cards with a compact **limits table** showing the configured setting ("300 / 15 min" plus the env variable name), current-window usage, reset time, and state. The ADR 0001 principle — show all limits together, uniformly — is kept.

The worker that actually sent a message is recorded in a new `email_queue.sent_queue` column, instead of being inferred from `priority`: `fallbackTechnicalToStandard()` rewrites a technical message to `normal` before the standard worker sends it, so priority is misleading exactly in the case that matters most. History from before the migration is backfilled from the last `attempt_started` event (which carries `details.queue`), falling back to priority. A single `LimitsConfig` class reads limit values and is used by both the panel and the enforcing code, so the panel can't show a different default than what's actually in effect.

Rejected: a separate "Statistics" tab — ADR 0001 notes the previous one went unused. Rejected: a charting library — horizontal CSS bars in the table are enough and need no build step.

## Consequences

Adds a `GET /admin/stats/sent` endpoint and an additive `limitsConfig` field on `/admin/status`. Day/hour buckets are textual prefixes of `sent_at` (works identically on MySQL and the SQLite test database), so they're counted in the PHP server's local time — `date.timezone` must be set to the timezone the panel is read in. Range is bounded by retention (`EMAIL_DATA_RETENTION_DAYS`), and hourly granularity is available up to 7 days.
