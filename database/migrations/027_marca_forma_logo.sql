SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'portal_branding'
       AND COLUMN_NAME = 'logo_shape') = 0,
    'ALTER TABLE portal_branding ADD COLUMN logo_shape ENUM(''original'', ''rounded'', ''square'', ''circle'') NOT NULL DEFAULT ''original'' AFTER logo_mime',
    'SELECT 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
