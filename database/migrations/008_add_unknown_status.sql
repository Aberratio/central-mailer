ALTER TABLE email_queue
  MODIFY COLUMN status ENUM('pending', 'processing', 'sent', 'failed', 'retry', 'unknown') NOT NULL DEFAULT 'pending';
