ALTER TABLE email_attachments
  ADD COLUMN content_id VARCHAR(255) NULL AFTER storage_path,
  ADD INDEX idx_email_attachments_content_id (content_id);
