CREATE TABLE email_enqueue_rate_limit_lock (
  id TINYINT UNSIGNED PRIMARY KEY,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO email_enqueue_rate_limit_lock (id, updated_at)
VALUES (1, CURRENT_TIMESTAMP);

CREATE TABLE email_enqueue_rate_limit_reservations (
  id CHAR(36) PRIMARY KEY,
  source_app VARCHAR(100) NOT NULL,
  reserved_at DATETIME NOT NULL,
  INDEX idx_email_enqueue_rate_source_reserved (source_app, reserved_at),
  INDEX idx_email_enqueue_rate_reserved_at (reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
