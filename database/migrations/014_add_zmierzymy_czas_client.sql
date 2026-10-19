-- M-04: dedicated `zmierzymy-czas` client (until now the app shared API_KEY_APP_A/B).
-- The real key is NEVER stored in SQL: ClientRepository::syncLegacyClients() replaces the
-- hash from ZMIERZYMY_CZAS_API_KEY at startup. Until that variable is set the row carries an
-- unguessable random hash, so nobody can authenticate as this client. Idempotent (PK source_app).
INSERT IGNORE INTO email_clients
  (source_app, api_key_hash, active, queue_weight, queue_credit, created_at, updated_at)
VALUES
  ('zmierzymy-czas', SHA2(CONCAT('unconfigured:', UUID(), RAND()), 256), 1, 1, 0, NOW(), NOW());
