# Central Mailer

A lightweight PHP backend service for centralized, queued email sending from multiple applications. The API stores messages in MySQL/MariaDB. Standard messages use the primary SMTP account, while `technical` messages use a separate FIFO queue and Gmail SMTP account.

Stack: PHP 8.2+, Slim Framework, MySQL/MariaDB, PHPMailer, vlucas/phpdotenv, Monolog.

## Requirements

- PHP 8.2 or newer
- Composer
- MySQL 8.0+ or MariaDB 10.6+
- SMTP access, for example CyberFolks/SeoHost
- A Gmail account with 2-Step Verification and an App Password for technical messages
- PHP extensions: `pdo`, `pdo_mysql`, `json`, `mbstring`, `fileinfo`

## Installation

```bash
composer install
cp .env.example .env
```

Set the correct values in `.env`: database access, API keys, SMTP settings, and sending limits.

## `.env` configuration

Key variables:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_CORS_ORIGIN=*

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=central_mailer
DB_USERNAME=root
DB_PASSWORD=secret

API_KEY_APP_A=change-me-app-a
API_KEY_APP_B=change-me-app-b

SMTP_HOST=h18.seohost.pl
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=kontakt@example.com
SMTP_PASSWORD=secret
SMTP_FROM_EMAIL=kontakt@example.com
SMTP_FROM_NAME="My application"
SMTP_DEBUG_LEVEL=0

GMAIL_SMTP_USER=developer@gmail.com
GMAIL_SMTP_APP_PASSWORD=google-app-password
GMAIL_SMTP_PORT=587
GMAIL_SMTP_SECURE=tls
GMAIL_FROM_EMAIL=developer@gmail.com
GMAIL_FROM_NAME="Developer notifications"

EMAIL_RATE_LIMIT_COUNT=100
EMAIL_RATE_LIMIT_WINDOW_MINUTES=15
EMAIL_RATE_LIMIT_RESERVATION_RETENTION_MINUTES=10080
EMAIL_WORKER_BATCH_SIZE=20
EMAIL_WORKER_SLEEP_SECONDS=10
EMAIL_PRIORITY_AGING_SECONDS=900
EMAIL_BATCH_MAX_RECIPIENTS=1000
EMAIL_ATTACHMENT_MAX_COUNT=5
EMAIL_ATTACHMENT_MAX_TOTAL_BYTES=5000000
EMAIL_ATTACHMENT_ALLOWED_MIME_TYPES=image/png,application/pdf

LOG_LEVEL=info
LOG_DIR=
```

Leave `LOG_DIR` empty to use `storage/logs`. On a deployed server, set it only to an absolute path.

On startup, legacy `.env` keys are inserted into `email_clients` only if the corresponding client does not exist:

- `API_KEY_APP_A` -> `app-a`
- `API_KEY_APP_B` -> `app-b`

The database table `email_clients` is the source of truth for authentication, activation, queue weights, and optional per-client rate limits. The backend does not trust the `sourceApp` field from the request body.

## Database and migration

Create the database:

```bash
mysql -u root -p -e "CREATE DATABASE central_mailer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

For a new database, run the initial migration:

```bash
mysql -u root -p central_mailer < database/migrations/001_create_email_queue.sql
```

For an existing database created before delivery-safety support, run:

```bash
mysql -u root -p central_mailer < database/migrations/002_add_delivery_safety.sql
```

Then run the clients, batch, attachments, and event history migration:

```bash
mysql -u root -p central_mailer < database/migrations/003_add_clients_batches_attachments_events.sql
```

Then enable the technical priority:

```bash
mysql -u root -p central_mailer < database/migrations/004_add_technical_priority.sql
```

The deploy workflow uses the migration runner instead of invoking SQL files manually:

```bash
php scripts/run-migrations.php
php scripts/run-migrations.php --dry-run
php scripts/run-migrations.php --baseline
```

Use `--baseline` once for an existing database that already has the current schema but does not have the
`schema_migrations` table. Use the normal command for a new empty database and for later deployments.

Example client configuration:

```sql
INSERT INTO email_clients
  (source_app, api_key_hash, active, queue_weight, rate_limit_count, rate_limit_window_minutes, created_at, updated_at)
VALUES
  ('billing', SHA2('replace-with-long-random-key', 256), 1, 2, 50, 15, NOW(), NOW());
```

Rotate a key by updating `api_key_hash`. A larger `queue_weight` gives the client a larger share of available sending capacity.

## Local run

API:

```bash
composer serve
```

or:

```bash
php -S 0.0.0.0:8080 -t public public/router.php
```

Worker:

```bash
composer worker
```

or:

```bash
php bin/worker.php
```

The worker runs in a loop and sleeps for `EMAIL_WORKER_SLEEP_SECONDS` seconds between cycles.

Technical Gmail SMTP worker:

```bash
composer technical-worker
```

or:

```bash
php bin/technical-worker.php
```

Run exactly one technical worker to preserve strict FIFO ordering.

## Endpoints

Swagger UI documentation is available after starting the API at:

```text
http://localhost:8080/docs
```

The raw OpenAPI 3.0 specification is available at:

```text
http://localhost:8080/openapi.json
```

Documentation endpoints do not require `X-API-Key`. The API endpoints described below still require authorization with the `X-API-Key` header.

### Add an email

```bash
curl -X POST http://localhost:8080/emails \
  -H "Content-Type: application/json" \
  -H "X-API-Key: change-me-app-a" \
  -H "Idempotency-Key: order-confirmation-12345" \
  -d '{
    "to": "recipient@example.com",
    "subject": "Test",
    "html": "<p>This is a test</p>",
    "text": "This is a test",
    "priority": "normal",
    "metadata": {"userId": 123}
  }'
```

Response:

```json
{
  "id": "uuid",
  "status": "pending"
}
```

`Idempotency-Key` is optional but strongly recommended. It must be unique within the calling application. Repeating the same request with the same key returns the existing message with HTTP `200`. Reusing the key for different content returns HTTP `409`.

The SMTP `Message-ID` is derived from the queue ID and stays stable across retries. This reduces duplicate-delivery risk after an uncertain SMTP result, but SMTP cannot provide a strict exactly-once delivery guarantee.

Set `"priority": "technical"` to route a message to the separate Gmail SMTP FIFO queue. `normal` and `high` messages are never claimed by the technical worker, and the standard SMTP worker never claims `technical` messages.

### Add an attachment

Attachments are optional and intended for exceptional cases such as QR-code PNG files:

```json
{
  "to": "recipient@example.com",
  "subject": "QR code",
  "html": "<p>Your QR code is attached.</p>",
  "attachments": [
    {
      "filename": "qr.png",
      "contentBase64": "iVBORw0KGgo..."
    }
  ]
}
```

The service validates the real MIME type, stores the file under `storage/attachments`, and deletes it after the message reaches `sent` or `failed`. Multiple worker hosts must share this directory. Batch requests do not support attachments.

### Add a batch

`POST /emails/batch` stores the common subject and body once, then creates one queue record per recipient:

```json
{
  "subject": "Newsletter",
  "html": "<p>Hello</p>",
  "priority": "normal",
  "recipients": [
    {"to": "one@example.com", "metadata": {"userId": 1}},
    {"to": "two@example.com", "metadata": {"userId": 2}}
  ]
}
```

Use `Idempotency-Key` for batch requests as well.

### Check status

```bash
curl -X GET http://localhost:8080/emails/{id} \
  -H "X-API-Key: change-me-app-a"
```

An application can read only its own messages. The `app-a` API key will not see emails added by `app-b`.

Status history is available at `GET /emails/{id}/events`. It includes queueing, processing, rate-limit releases, retries, failures, timeouts, and provider acceptance.

### Add a test email

```bash
curl -X POST http://localhost:8080/emails/test \
  -H "Content-Type: application/json" \
  -H "X-API-Key: change-me-app-a" \
  -d '{"to": "recipient@example.com"}'
```

The test endpoint adds the message to the normal queue. It does not send it directly.

## Message statuses

- `pending` - the message is waiting in the queue.
- `processing` - the worker locked the record and is trying to send the message.
- `sent` - the configured SMTP server accepted the message. Final mailbox delivery can still fail later on the recipient side.
- `retry` - sending failed, but it will be retried after `next_attempt_at`.
- `failed` - `max_attempts` was exceeded.

## Worker, retry, and ordering

The worker fetches a batch limited by `EMAIL_WORKER_BATCH_SIZE` and the rate limits. Scheduling uses:

1. effective high priority
2. weighted fairness between clients
3. `created_at ASC`

Normal messages older than `EMAIL_PRIORITY_AGING_SECONDS` receive effective high priority, so a constant stream of high-priority messages cannot starve them.

The technical worker ignores client weights and priority aging. It sends only the oldest non-terminal `technical` message. A retry scheduled for that oldest message blocks later technical messages until it is sent or permanently failed.

For MySQL/MariaDB versions that support `SELECT ... FOR UPDATE SKIP LOCKED`, the worker uses that mechanism. If the database does not support it, the fallback uses transactional `FOR UPDATE`, which is safe but can block parallel workers. Each claimed message receives a processing lease, so a delayed worker cannot overwrite a status written by a newer worker.

Retry uses exponential backoff: 60s, 120s, 240s, 480s, up to a maximum of 3600s.

## Gmail SMTP authorization and delivery feedback

The technical worker uses `smtp.gmail.com` with the full Gmail address in `GMAIL_SMTP_USER` and a Google App Password in `GMAIL_SMTP_APP_PASSWORD`. Do not use the normal Google account password. App Passwords require 2-Step Verification and might be unavailable for some work, school, Advanced Protection, or security-key-only accounts. OAuth 2.0 is not required for this App Password SMTP variant.

A successful SMTP send marks the queue record as `sent` and stores the stable MIME `Message-ID` as `providerMessageId`. This confirms that Gmail accepted the message for sending, not that the recipient mailbox delivered or opened it. Later rejections may arrive as bounce messages in the sender mailbox and require a separate mailbox monitoring feature. Google Workspace administrators can investigate delivery in Email Log Search, but that is an administrative troubleshooting tool and is not integrated into this API.

## Rate limit

The global limit applies to all applications together:

```dotenv
EMAIL_RATE_LIMIT_COUNT=100
EMAIL_RATE_LIMIT_WINDOW_MINUTES=15
```

Before each send attempt, the worker atomically reserves a rate-limit slot in the database. Reservations are serialized across all workers and count for the configured rolling window, including failed or uncertain SMTP attempts. If the limit is reached, the claimed message is returned to the queue without increasing its attempt count.

Each `email_clients` row can also define `rate_limit_count` and `rate_limit_window_minutes`. A client that reaches its own limit does not consume the remaining capacity of other clients.

## Logging

Logs are written to:

- `storage/logs/app.log`
- `storage/logs/worker.log`
- `storage/logs/technical-worker.log`

Technical events are logged: queued messages, idempotent replays, send attempts, success, errors, attempt count, final status, and rate limiting. Persistent event history is stored in `email_events`. SMTP passwords, full HTML bodies, and metadata are not logged. `SMTP_DEBUG_LEVEL` defaults to `0`; enabling SMTP debug can expose message content and must not be used in production.

## CyberFolks/SeoHost SMTP configuration

Example for port 587 and STARTTLS:

```dotenv
SMTP_HOST=h18.seohost.pl
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=kontakt@example.com
SMTP_PASSWORD=mailbox-password
SMTP_FROM_EMAIL=kontakt@example.com
SMTP_FROM_NAME="My application"
```

The login is the full email address, and the password is the mailbox password. `SMTP_FROM_EMAIL` should be the mailbox address or an address allowed by the hosting provider.

Alternatively, port 465 SSL:

```dotenv
SMTP_PORT=465
SMTP_SECURE=ssl
```

## Cron and systemd

The simplest cron variant starts the worker periodically. Because the current worker is a long-running
process, use `timeout` to stop it before the next minute and `flock` to prevent overlapping workers:

```cron
* * * * * cd /path/to/central-mailer-smtp && flock -n storage/worker.lock timeout 55 /usr/bin/php bin/worker.php >> storage/logs/cron.log 2>&1
* * * * * cd /path/to/central-mailer-smtp && flock -n storage/technical-worker.lock timeout 55 /usr/bin/php bin/technical-worker.php >> storage/logs/technical-cron.log 2>&1
```

On shared hosting, replace `/usr/bin/php` with the PHP CLI path provided by the host, for example
`/usr/local/php83/bin/php`. After a deploy, the next cron execution automatically uses the new application
files, so the deploy workflow does not need to restart the worker.

On a server with systemd access, a better production variant is:

```ini
[Unit]
Description=Central Mailer Worker
After=network.target

[Service]
Type=simple
WorkingDirectory=/path/to/central-mailer-smtp
ExecStart=/usr/bin/php bin/worker.php
Restart=always
RestartSec=5
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
```

After saving the file:

```bash
sudo systemctl daemon-reload
sudo systemctl enable central-mailer-worker
sudo systemctl start central-mailer-worker
```

Create a second service with `ExecStart=/usr/bin/php bin/technical-worker.php` and a distinct unit name such as `central-mailer-technical-worker`.

## GitHub Actions deployment

The workflow in `.github/workflows/deploy.yml` deploys the API to the `staging` or `production` GitHub
environment. A push to `main` or `master` deploys to staging with migrations enabled. A production deploy
must be started manually with `confirm_production=DEPLOY_PRODUCTION`.

Configure these secrets separately in both GitHub environments:

- `SSH_HOST`
- `SSH_PORT`
- `SSH_USER`
- `SSH_PRIVATE_KEY`
- `SSH_KNOWN_HOSTS`
- `BACKEND_REMOTE_DIR`
- `BACKEND_ALLOWED_ROOT`
- `BACKEND_BACKUP_DIR`
- `API_URL`

Before the first deployment, create the target directory, its `.env`, an empty
`.central-mailer-api-root` marker file, and a `public_html` directory. `BACKEND_REMOTE_DIR` must be inside
`BACKEND_ALLOWED_ROOT`, while `BACKEND_BACKUP_DIR` must be outside `BACKEND_REMOTE_DIR`.
The server must provide `php`, `realpath`, `rsync`, `unzip`, `mysqldump`, and `gzip`. Set `APP_ENV=staging`
or `APP_ENV=production` in each target `.env` to match its GitHub environment.

The web server document root should be `public_html`. The workflow synchronizes it from `public`, removing
old frontend files while preserving `.well-known` for certificate validation. Application code, `.env`,
logs, attachments, and database backups stay outside the public web directory. Create and enable both
worker processes separately before the first deployment.

## Security

- API keys are stored as SHA-256 hashes in `email_clients`; the SMTP password remains in `.env`.
- A single request adds one recipient, while `/emails/batch` adds multiple recipients with shared content.
- There is no CC/BCC.
- `from` always comes from `.env`.
- The email address, priority, subject length, body size, metadata size, attachment count, attachment size, and attachment MIME type are validated.
- Applications are isolated by `sourceApp`, which is derived from the API key.

## How to switch SMTP to Brevo later

The sending layer is separated through `CentralMailer\Email\EmailProviderInterface`. The queue, retries, rate limiting, and statuses do not know about the specific provider.

Steps:

1. Add `src/Email/BrevoEmailProvider.php`.
2. Make the class implement `EmailProviderInterface`.
3. In `send(EmailMessage $message): EmailSendResult`, call the Brevo API and return `EmailSendResult` with the Brevo message ID if the API returns one.
4. Add `.env` variables, for example `EMAIL_PROVIDER=brevo` and `BREVO_API_KEY=...`.
5. In `public/index.php` and `bin/worker.php`, change the provider factory so that it creates `BrevoEmailProvider` for `EMAIL_PROVIDER=brevo`, and uses `SmtpEmailProvider` by default.

The rest of the application does not require changes because `EmailWorker` depends only on `EmailProviderInterface`. The queue table, retries, statuses, and rate limit stay the same.

## Troubleshooting

- `401 Invalid or missing API key`: check the `X-API-Key` header and the `API_KEY_APP_A` / `API_KEY_APP_B` values.
- `Email not found`: the message does not exist, or the API key belongs to another application.
- No sending: check whether the worker is running and whether records are in `retry` with a future `next_attempt_at`.
- Rate limit blocks sending: check the global `.env` limit, the client limit in `email_clients`, and recent rows in `email_rate_limit_reservations`.
- SMTP error: check host, port, `SMTP_SECURE`, full email login, and mailbox password.
- JSON migration issue in older MariaDB versions: make sure the database version supports the `JSON` type, or change the `metadata JSON NULL` column to `metadata LONGTEXT NULL`.
