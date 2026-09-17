# Admin panel shows queue state, not raw data

## Context

The `/admin.html` panel was built around what the system *has in the database* — a queue table as the default view, with operational state (workers, limits, backlog) hidden in a "Statistics" tab that users didn't open. Users reported the panel as hard to understand: it didn't answer the one question they came for — *is something stuck, why, and when will it recover?*

## Decision

Rebuild the panel so the default view is an **Overview** with a one-sentence verdict ("Queue is waiting for the rate limit to reset. 340 messages will go out at 14:32, in 6 min") and, only when something is actually blocking, a card explaining the cause, the effect, when it self-resolves, and any action the admin can take. Raw tables remain available for drilling down, but are no longer the entry point.

Three further decisions follow:

1. **All limits are shown together, uniformly** — global, provider daily cap (Gmail), per-client, and intake rate limit — each with a worded state and reset time, since an exhausted limit is the most common non-obvious cause of a stall. Until now the Gmail cap and per-client limits weren't returned by `/admin/status` at all, even though `EmailWorker` enforces them.
2. **An alert means "needs attention", not "exists"**: the previous `issues()` fired `active_backlog` on any pending message, and `retry`/`failed` on any `count > 0`, so the issues section was never empty and stopped meaning anything. Backlog becomes a plain metric, alert thresholds are raised, and every issue gets a `blocking` flag so the UI can pick a single banner instead of a list.
3. **Jargon stays out of the UI** — "heartbeat", "cron", "FIFO", "processing lease" are replaced with observable-behaviour descriptions ("last run 40s ago, next in 20s"); worker/lease identifiers move into collapsible details.

Rejected: rewriting the panel on a framework with a build step — it ships as a single static file next to the API, and components don't justify adding a toolchain to a project that doesn't otherwise have one. Also rejected: renaming existing `/admin/status` fields — the response is extended additively only, so nothing that already consumes it breaks.

## Consequences

`AdminController::status()` gains new branches (`rateLimit.provider`, `rateLimit.byClient`, `throughput`, oldest-message age), requiring two new repository methods: `RateLimitRepository::scopeUsage()` and `EmailQueueRepository::sentCountSince()`. The threshold change in `issues()` is intentionally backward-incompatible: some situations stop being reported as issues.

Two bugs that were misrepresenting queue state are fixed along the way: the `retryAfter` query in `globalUsage()` didn't filter out `provider:%` reservations (so the panel could show the wrong limit-reset time), and `oldestUnsentGlobal()` counted `status <> 'sent'`, so a single permanently-failed message could permanently pin the "oldest in queue" figure to itself.
