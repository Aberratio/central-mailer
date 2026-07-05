CREATE TABLE IF NOT EXISTS email_worker_heartbeats (
  worker_id VARCHAR(191) PRIMARY KEY,
  queue ENUM('standard', 'technical') NOT NULL,
  host VARCHAR(255) NOT NULL,
  process_id INT NULL,
  started_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  last_processed_at DATETIME NULL,
  last_error TEXT NULL,
  processed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  INDEX idx_email_worker_heartbeats_queue_seen (queue, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_queue
  ADD INDEX idx_email_queue_claim_pending_due (status, next_attempt_at, priority, created_at, source_app);
