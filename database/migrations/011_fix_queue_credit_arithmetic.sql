-- queue_weight was INT UNSIGNED, so MySQL/MariaDB evaluated `queue_credit + queue_weight`
-- in BIGINT UNSIGNED arithmetic. queue_credit is a signed deficit counter and goes negative
-- as soon as a client claims more than one email per cycle, which made every worker claim
-- fail with "BIGINT UNSIGNED value is out of range". A signed column keeps the addition signed.
ALTER TABLE email_clients
  MODIFY COLUMN queue_weight INT NOT NULL DEFAULT 1;

-- Heal installations that were blocked by the overflow above.
UPDATE email_clients
SET queue_credit = 0
WHERE queue_credit < 0;
