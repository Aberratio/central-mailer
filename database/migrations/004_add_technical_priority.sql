ALTER TABLE email_queue
  MODIFY COLUMN priority ENUM('normal', 'high', 'technical') NOT NULL DEFAULT 'normal',
  ADD INDEX idx_email_queue_technical_fifo (priority, status, created_at, id);
