ALTER TABLE requests
  ADD COLUMN sender_id INT NULL AFTER user_id,
  ADD COLUMN requires_response TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE requests
  ADD CONSTRAINT fk_requests_sender_id
    FOREIGN KEY (sender_id) REFERENCES users(id)
    ON DELETE SET NULL;

ALTER TABLE requests
  ADD INDEX idx_requests_sender_id (sender_id);
