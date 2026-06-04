CREATE TABLE IF NOT EXISTS email_queue (
  id CHAR(36) PRIMARY KEY,
  source_app VARCHAR(100) NOT NULL,
  idempotency_key VARCHAR(255) NULL,
  request_hash CHAR(64) NULL,
  recipient_email VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  html_body MEDIUMTEXT NOT NULL,
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
  INDEX idx_email_queue_source_app (source_app),
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
  reserved_at DATETIME NOT NULL,
  INDEX idx_email_rate_limit_reserved_at (reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
