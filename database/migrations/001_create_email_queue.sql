CREATE TABLE IF NOT EXISTS email_queue (
  id CHAR(36) PRIMARY KEY,
  source_app VARCHAR(100) NOT NULL,
  idempotency_key VARCHAR(255) NULL,
  request_hash CHAR(64) NULL,
  message_id CHAR(36) NULL,
  batch_id CHAR(36) NULL,
  recipient_email VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NULL,
  html_body MEDIUMTEXT NULL,
  text_body MEDIUMTEXT NULL,
  priority ENUM('normal', 'high') NOT NULL DEFAULT 'normal',
  metadata JSON NULL,
  status ENUM('pending', 'processing', 'sent', 'failed', 'retry') NOT NULL DEFAULT 'pending',
  lease_id CHAR(36) NULL,
  lease_expires_at DATETIME NULL,
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 5,
  next_attempt_at DATETIME NULL,
  last_error TEXT NULL,
  provider_message_id VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  INDEX idx_email_queue_status_next_attempt (status, next_attempt_at),
  INDEX idx_email_queue_processing_lease (status, lease_expires_at),
  INDEX idx_email_queue_status_sent_at (status, sent_at),
  INDEX idx_email_queue_claim (status, priority, next_attempt_at, created_at),
  INDEX idx_email_queue_claim_pending (status, priority, created_at, source_app),
  INDEX idx_email_queue_claim_retry (status, next_attempt_at, priority, created_at, source_app),
  INDEX idx_email_queue_source_app (source_app),
  INDEX idx_email_queue_message_id (message_id),
  INDEX idx_email_queue_batch_id (batch_id),
  INDEX idx_email_queue_created_at (created_at),
  INDEX idx_email_queue_priority_created_at (priority, created_at),
  UNIQUE INDEX uq_email_queue_source_idempotency (source_app, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_rate_limit_lock (
  id TINYINT UNSIGNED PRIMARY KEY,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO email_rate_limit_lock (id, updated_at)
VALUES (1, CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE updated_at = updated_at;

CREATE TABLE IF NOT EXISTS email_rate_limit_reservations (
  id CHAR(36) PRIMARY KEY,
  source_app VARCHAR(100) NOT NULL,
  reserved_at DATETIME NOT NULL,
  INDEX idx_email_rate_limit_reserved_at (reserved_at),
  INDEX idx_email_rate_limit_source_reserved (source_app, reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_clients (
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

CREATE TABLE IF NOT EXISTS email_messages (
  id CHAR(36) PRIMARY KEY,
  source_app VARCHAR(100) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  html_body MEDIUMTEXT NOT NULL,
  text_body MEDIUMTEXT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_email_messages_source_app (source_app)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_batches (
  id CHAR(36) PRIMARY KEY,
  source_app VARCHAR(100) NOT NULL,
  idempotency_key VARCHAR(255) NULL,
  request_hash CHAR(64) NULL,
  message_id CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE INDEX uq_email_batches_source_idempotency (source_app, idempotency_key),
  INDEX idx_email_batches_message_id (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_attachments (
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

CREATE TABLE IF NOT EXISTS email_events (
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
