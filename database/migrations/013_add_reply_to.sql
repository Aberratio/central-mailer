-- Optional per-message Reply-To (POST /emails `replyTo`). NULL means "use the global
-- reply-to from EmailBrandConfig". Batches do not support a per-message reply-to.
ALTER TABLE email_queue
  ADD COLUMN reply_to_email VARCHAR(255) NULL AFTER context_id,
  ADD COLUMN reply_to_name VARCHAR(255) NULL AFTER reply_to_email;
