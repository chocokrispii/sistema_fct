ALTER TABLE webhook_inbox
    ADD COLUMN response_status SMALLINT UNSIGNED NULL AFTER payload,
    ADD COLUMN response_body TEXT NULL AFTER response_status;

ALTER TABLE webhook_outbox
    ADD COLUMN response_body TEXT NULL AFTER http_status;
