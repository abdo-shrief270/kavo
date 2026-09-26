-- A dedicated role for backups.
--
-- Separate from kaabosh_owner so a leaked backup credential cannot alter the
-- schema, and separate from kaabosh_app so it is obvious in pg_stat_activity
-- which connections are the backup.
--
-- REPLICATION is what pg_basebackup and pg_receivewal need; it grants no DML.

CREATE ROLE kaabosh_backup LOGIN REPLICATION PASSWORD :'backup_password';

-- Read-only visibility for the drill's verification queries.
GRANT pg_read_all_data TO kaabosh_backup;
