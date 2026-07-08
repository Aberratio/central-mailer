-- context_id is an app-defined grouping key (e.g. a competition/event id) used to
-- query delivery statuses for all emails sent on behalf of that context.
ALTER TABLE email_queue
  ADD COLUMN context_id VARCHAR(64) NULL AFTER category,
  ADD INDEX idx_email_queue_context (source_app, context_id, created_at);

ALTER TABLE email_messages
  ADD COLUMN context_id VARCHAR(64) NULL AFTER metadata;
