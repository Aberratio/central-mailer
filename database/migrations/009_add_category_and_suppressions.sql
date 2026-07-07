ALTER TABLE email_queue
  ADD COLUMN category ENUM('transactional', 'marketing') NOT NULL DEFAULT 'transactional' AFTER priority;

-- source_app = '' means the suppression applies to every client (global scope).
CREATE TABLE IF NOT EXISTS email_suppressions (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  source_app VARCHAR(100) NOT NULL DEFAULT '',
  reason ENUM('bounce', 'complaint', 'unsubscribe', 'manual') NOT NULL,
  applies_to ENUM('all', 'marketing') NOT NULL DEFAULT 'all',
  origin_email_id CHAR(36) NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_email_suppressions_scope (email, source_app, applies_to),
  INDEX idx_email_suppressions_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
