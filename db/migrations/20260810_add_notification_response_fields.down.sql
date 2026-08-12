ALTER TABLE requests
  DROP FOREIGN KEY fk_requests_sender_id;

ALTER TABLE requests
  DROP INDEX idx_requests_sender_id;

ALTER TABLE requests
  DROP COLUMN sender_id,
  DROP COLUMN requires_response;
