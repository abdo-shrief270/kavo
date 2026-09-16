-- A dedicated role for backups.
--
-- Separate from kavo_owner so a leaked backup credential cannot alter the
-- schema, and separate from kavo_app so it is obvious in pg_stat_activity
-- which connections are the backup.
--
-- REPLICATION is what pg_basebackup and pg_receivewal need; it grants no DML.

CREATE ROLE kavo_backup LOGIN REPLICATION PASSWORD :'backup_password';

-- Read-only visibility for the drill's verification queries.
GRANT pg_read_all_data TO kavo_backup;
