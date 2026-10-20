# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow [calendar versioning](https://calver.org/)
(`YYYY.MM.PATCH`). See [RELEASING.md](RELEASING.md) for how a version is cut
and deployed.

## [Unreleased]

### Added

- Optional per-message `replyTo` on `POST /emails`. It overrides the global
  reply-to for every transport, is stored with the message so retries keep
  it, and is part of the idempotency hash. Batches do not support it.
- `log` mail transport (`MAIL_TRANSPORT=log`) for local end-to-end runs; it
  also accepts reserved example domains.
- `/health` reports the deployed `version` and `commit`.

## [2026.09.0] - 2026-09-20

First tagged release. It marks the code running in production on 2026-09-20;
earlier production deploys were not versioned.

### Added

- Queued e-mail sending for multiple applications over a REST API
  authenticated with `X-API-Key`: single messages, batches, status and event
  history, paginated listings and per-client diagnostics.
- Idempotent enqueueing with `Idempotency-Key`.
- Attachments validated by real MIME type, including inline images.
- `transactional` and `marketing` categories, with `List-Unsubscribe`
  headers for marketing mail.
- Background workers with weighted fairness between clients, priority aging,
  exponential backoff with jitter, and quarantine of messages whose send
  outcome is unknown.
- A separate FIFO queue and Gmail account for `technical` messages, with
  optional fallback to the standard queue.
- Suppression list fed by hard bounces and opt-outs, checked at enqueue and
  send time.
- Admin endpoints for requeueing, suppressions, worker status and sent-mail
  statistics.
- `/health` liveness check, with a strict mode that fails on stale worker
  heartbeats.
- Swagger UI (`/docs`) and OpenAPI spec (`/openapi.json`).

[Unreleased]: https://github.com/Aberratio/central-mailer/compare/v2026.09.0...HEAD
[2026.09.0]: https://github.com/Aberratio/central-mailer/releases/tag/v2026.09.0
