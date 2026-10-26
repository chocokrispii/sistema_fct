SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_inbox'
       AND COLUMN_NAME = 'response_status') = 0,
    'ALTER TABLE webhook_inbox ADD COLUMN response_status SMALLINT UNSIGNED NULL AFTER payload',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_inbox'
       AND COLUMN_NAME = 'response_body') = 0,
    'ALTER TABLE webhook_inbox ADD COLUMN response_body TEXT NULL AFTER response_status',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_outbox'
       AND COLUMN_NAME = 'response_body') = 0,
    'ALTER TABLE webhook_outbox ADD COLUMN response_body TEXT NULL AFTER http_status',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
