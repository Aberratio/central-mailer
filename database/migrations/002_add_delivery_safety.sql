ALTER TABLE email_queue
  ADD COLUMN idempotency_key VARCHAR(255) NULL AFTER source_app,
  ADD COLUMN request_hash CHAR(64) NULL AFTER idempotency_key,
  ADD COLUMN lease_id CHAR(36) NULL AFTER status,
  ADD COLUMN lease_expires_at DATETIME NULL AFTER lease_id,
  ADD UNIQUE INDEX uq_email_queue_source_idempotency (source_app, idempotency_key),
  ADD INDEX idx_email_queue_processing_lease (status, lease_expires_at),
  ADD INDEX idx_email_queue_status_sent_at (status, sent_at);

CREATE TABLE email_rate_limit_lock (
  id TINYINT UNSIGNED PRIMARY KEY,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO email_rate_limit_lock (id, updated_at)
VALUES (1, CURRENT_TIMESTAMP);

CREATE TABLE email_rate_limit_reservations (
  id CHAR(36) PRIMARY KEY,
  reserved_at DATETIME NOT NULL,
  INDEX idx_email_rate_limit_reserved_at (reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
