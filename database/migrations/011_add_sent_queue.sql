-- sent_queue records which worker queue actually delivered the email. priority cannot be used
-- for this: fallbackTechnicalToStandard() rewrites a technical email to "normal" before the
-- standard worker sends it. Feeds the admin panel's sent statistics (per app and per worker).
ALTER TABLE email_queue
  ADD COLUMN sent_queue ENUM('standard', 'technical') NULL AFTER sent_at,
  ADD INDEX idx_email_queue_sent_stats (status, sent_at, source_app, sent_queue);

-- Backfill history: the latest attempt_started event carries the queue of the worker that made
-- the (successful) attempt; rows without events fall back to the priority-based guess.
UPDATE email_queue q
SET q.sent_queue = COALESCE(
    (
        SELECT JSON_UNQUOTE(JSON_EXTRACT(e.details, '$.queue'))
        FROM email_events e
        WHERE e.email_id = q.id
          AND e.event_type = 'attempt_started'
          AND JSON_UNQUOTE(JSON_EXTRACT(e.details, '$.queue')) IN ('standard', 'technical')
        ORDER BY e.created_at DESC, e.id DESC
        LIMIT 1
    ),
    CASE WHEN q.priority = 'technical' THEN 'technical' ELSE 'standard' END
)
WHERE q.status = 'sent' AND q.sent_queue IS NULL;
