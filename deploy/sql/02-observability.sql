-- Postgres-level query observability. Run once per environment as a
-- superuser, after 01-provision-roles.sql.
--
-- Deliberately done at the database rather than only in the application: an
-- app-level listener only sees queries the app made, and only while it is
-- healthy. These keep working when the app is the thing that is broken.
--
-- pg_stat_statements and auto_explain must also be loaded at startup. Add to
-- postgresql.conf and restart:
--
--   shared_preload_libraries = 'pg_stat_statements,auto_explain'
--
--   -- Aggregate stats for the slowest and most frequent queries, with no
--   -- per-query logging overhead.
--   pg_stat_statements.max = 10000
--   pg_stat_statements.track = top
--
--   -- Always-on safety net, independent of application code. Anything over
--   -- half a second lands in Postgres's own log.
--   log_min_duration_statement = 500
--
--   -- Automatic EXPLAIN on slow queries. Invaluable when a plan degrades
--   -- under real data volume and you cannot reproduce it locally.
--   auto_explain.log_min_duration = 1000
--   auto_explain.log_analyze = off       -- 'on' re-executes; too costly in prod
--   auto_explain.log_buffers = on
--   auto_explain.log_nested_statements = on

CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Read-only visibility for the application role, so the platform console can
-- surface slow queries without a second set of credentials.
GRANT SELECT ON pg_stat_statements TO kavo_app;
GRANT pg_read_all_stats TO kavo_app;
