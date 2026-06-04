# Central Mailer SMTP

A lightweight PHP backend service for centralized, queued email sending from two applications. The API stores messages in MySQL/MariaDB, while a separate CLI worker sends them through SMTP with retries, a global rate limit, and status logging.

Stack: PHP 8.2+, Slim Framework, MySQL/MariaDB, PHPMailer, vlucas/phpdotenv, Monolog.

## Requirements

- PHP 8.2 or newer
- Composer
- MySQL 8.0+ or MariaDB 10.6+
- SMTP access, for example CyberFolks/SeoHost
- PHP extensions: `pdo`, `pdo_mysql`, `json`, `mbstring`

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

EMAIL_RATE_LIMIT_COUNT=100
EMAIL_RATE_LIMIT_WINDOW_MINUTES=15
EMAIL_WORKER_BATCH_SIZE=20
EMAIL_WORKER_SLEEP_SECONDS=10

LOG_LEVEL=info
```

`sourceApp` is mapped from the API key:

- `API_KEY_APP_A` -> `app-a`
- `API_KEY_APP_B` -> `app-b`

The backend does not trust the `sourceApp` field from the request body.

## Database and migration

Create the database:

```bash
mysql -u root -p -e "CREATE DATABASE central_mailer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Run the migration:

```bash
mysql -u root -p central_mailer < database/migrations/001_create_email_queue.sql
```

The project uses a single simple SQL file instead of a heavy migration system.

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

### Check status

```bash
curl -X GET http://localhost:8080/emails/{id} \
  -H "X-API-Key: change-me-app-a"
```

An application can read only its own messages. The `app-a` API key will not see emails added by `app-b`.

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
- `sent` - the sender SMTP server accepted the message. Final mailbox delivery can still fail later on the recipient side.
- `retry` - sending failed, but it will be retried after `next_attempt_at`.
- `failed` - `max_attempts` was exceeded.

## Worker, retry, and ordering

The worker fetches a batch limited by `EMAIL_WORKER_BATCH_SIZE` and the global rate limit. Ordering:

1. `priority = high`
2. `created_at ASC`

For MySQL/MariaDB versions that support `SELECT ... FOR UPDATE SKIP LOCKED`, the worker uses that mechanism. If the database does not support it, the fallback uses transactional `FOR UPDATE`, which is safe but can block parallel workers.

Retry uses exponential backoff: 60s, 120s, 240s, 480s, up to a maximum of 3600s.

## Rate limit

The limit is global for both applications together:

```dotenv
EMAIL_RATE_LIMIT_COUNT=100
EMAIL_RATE_LIMIT_WINDOW_MINUTES=15
```

The worker counts `sent` records from the last `EMAIL_RATE_LIMIT_WINDOW_MINUTES` minutes. If the limit is reached, it does not fetch more emails and waits until the next cycle.

## Logging

Logs are written to:

- `storage/logs/app.log`
- `storage/logs/worker.log`

Technical events are logged: queued messages, send attempts, success, errors, attempt count, final status, and rate limiting. SMTP passwords, full HTML bodies, and metadata are not logged.

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

The simplest cron variant starts the worker periodically. Because the current worker is a long-running process, it is best to use `timeout` in cron:

```cron
* * * * * cd /path/to/central-mailer-smtp && timeout 55 php bin/worker.php >> storage/logs/cron.log 2>&1
```

A better production variant is systemd:

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

## Security

- API keys and the SMTP password are stored only in `.env`.
- One request adds one recipient.
- There is no CC/BCC.
- `from` always comes from `.env`.
- The email address, priority, subject length, and body size are validated.
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
- Rate limit blocks sending: check `EMAIL_RATE_LIMIT_COUNT`, `EMAIL_RATE_LIMIT_WINDOW_MINUTES`, and the number of `sent` records.
- SMTP error: check host, port, `SMTP_SECURE`, full email login, and mailbox password.
- JSON migration issue in older MariaDB versions: make sure the database version supports the `JSON` type, or change the `metadata JSON NULL` column to `metadata LONGTEXT NULL`.
