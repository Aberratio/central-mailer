CREATE TABLE email_clients (
  source_app VARCHAR(100) PRIMARY KEY,
  api_key_hash CHAR(64) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  queue_weight INT UNSIGNED NOT NULL DEFAULT 1,
  queue_credit BIGINT NOT NULL DEFAULT 0,
  rate_limit_count INT UNSIGNED NULL,
  rate_limit_window_minutes INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE INDEX uq_email_clients_api_key_hash (api_key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_messages (
  id CHAR(36) PRIMARY KEY,
  source_app VARCHAR(100) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  html_body MEDIUMTEXT NOT NULL,
  text_body MEDIUMTEXT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_email_messages_source_app (source_app)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_batches (
  id CHAR(36) PRIMARY KEY,
  source_app VARCHAR(100) NOT NULL,
  idempotency_key VARCHAR(255) NULL,
  request_hash CHAR(64) NULL,
  message_id CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE INDEX uq_email_batches_source_idempotency (source_app, idempotency_key),
  INDEX idx_email_batches_message_id (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_queue
  MODIFY COLUMN subject VARCHAR(255) NULL,
  MODIFY COLUMN html_body MEDIUMTEXT NULL,
  ADD COLUMN message_id CHAR(36) NULL AFTER request_hash,
  ADD COLUMN batch_id CHAR(36) NULL AFTER message_id,
  ADD INDEX idx_email_queue_message_id (message_id),
  ADD INDEX idx_email_queue_batch_id (batch_id),
  ADD INDEX idx_email_queue_claim (status, priority, next_attempt_at, created_at),
  ADD INDEX idx_email_queue_claim_pending (status, priority, created_at, source_app),
  ADD INDEX idx_email_queue_claim_retry (status, next_attempt_at, priority, created_at, source_app);

ALTER TABLE email_rate_limit_reservations
  ADD COLUMN source_app VARCHAR(100) NOT NULL DEFAULT '' AFTER id,
  ADD INDEX idx_email_rate_limit_source_reserved (source_app, reserved_at);

CREATE TABLE email_attachments (
  id CHAR(36) PRIMARY KEY,
  email_id CHAR(36) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  content_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_email_attachments_email_id (email_id),
  INDEX idx_email_attachments_terminal_cleanup (deleted_at, email_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_id CHAR(36) NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  status VARCHAR(20) NOT NULL,
  attempt INT UNSIGNED NOT NULL DEFAULT 0,
  error_code VARCHAR(255) NULL,
  error_message TEXT NULL,
  provider_message_id VARCHAR(255) NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_email_events_email_created (email_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
