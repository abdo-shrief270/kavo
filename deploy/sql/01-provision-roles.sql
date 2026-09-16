-- Kavo database roles. Run once per environment, as a superuser, BEFORE the
-- first migration.
--
-- The split is what makes row-level security real rather than decorative:
-- Postgres exempts a table's owner and any BYPASSRLS role from its policies,
-- so the application must be neither.
--
--   kavo_owner  owns the schema, runs migrations and backfills.
--               Holds BYPASSRLS because expand/contract backfills legitimately
--               need to write across every tenant. Never used to serve a
--               request.
--
--   kavo_app    serves every application request and queued job.
--               NOT the owner, NOT a superuser, explicitly NOBYPASSRLS.
--               Policies therefore always apply to it, including when an
--               Eloquent global scope has been bypassed.
--
-- Verify after provisioning:
--   SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname LIKE 'kavo%';
-- kavo_app must show false for both columns. If it does not, RLS is off.

CREATE ROLE kavo_owner LOGIN PASSWORD :'owner_password' CREATEDB BYPASSRLS;
CREATE ROLE kavo_app   LOGIN PASSWORD :'app_password'   NOBYPASSRLS;

CREATE DATABASE kavo OWNER kavo_owner;

-- Stop kavo_app creating objects it would then own (an owner bypasses FORCE
-- only when BYPASSRLS is also set, but not owning anything is simpler to reason about).
\connect kavo
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO kavo_app;
