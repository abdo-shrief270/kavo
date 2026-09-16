# Backups

ADR 0001 §8 ranks this the highest reliability-per-effort item in the plan, and
the reason is blunt: **nightly `pg_dump` means the worst case is losing a full
day of every tenant's orders.** Continuous WAL archiving takes the recovery
point objective from ~24 hours to ~5 minutes, costs a few dollars a month, and
is about an hour of setup.

A single-instance Postgres with snapshot backups is a development database.
What makes it a production one is being able to prove you can get the data
back — which is why the restore drill here is automated rather than a
paragraph in a runbook.

## Two paths

**`pgbackrest.conf` — the recommended path.** Incremental backups, parallel
compression, retention, and restore-to-point-in-time, straight to S3-compatible
storage (Cloudflare R2 or Backblaze B2). Use this in production.

**`postgres-backup.sh` — the dependency-free path.** `pg_basebackup` plus
`pg_receivewal`, needing nothing beyond the Postgres client tools that are
already on the box. Slower and without incrementals, but it works everywhere
and is what the drill exercises.

Both write to the same layout, so `postgres-restore.sh` reads either.

## Setup, once per environment

```bash
# 1. Postgres must archive WAL. Requires a restart, so do it before go-live.
sudo cat deploy/backup/postgresql-archiving.conf >> /etc/postgresql/16/main/postgresql.conf
sudo systemctl restart postgresql

# 2. A replication user for base backups.
sudo -u postgres psql -f deploy/backup/01-backup-role.sql

# 3. Schedule. Base backup nightly, WAL streams continuously.
sudo crontab -e
#   0 2 * * *  /var/www/kavo-production/current/deploy/backup/postgres-backup.sh
#   0 4 * * 0  /var/www/kavo-production/current/deploy/backup/restore-drill.sh
```

`pg_receivewal` runs under Supervisor, not cron — it is a long-lived stream,
and a gap in it is a gap in the recovery window.

## The drill

`restore-drill.sh` restores the most recent backup into a scratch cluster,
starts it, and asserts the tenant and payment row counts are within tolerance
of production. It runs weekly and fails loudly.

**A drill that has been run once is a drill that has stopped being true.** The
point of scheduling it is that the failure surfaces on a Sunday morning rather
than during the incident.

### What running it actually found

These scripts were not written and assumed correct — they were run against a
real cluster, and the first three attempts failed:

1. **The configuration was not in the backup.** On Debian and Ubuntu,
   `postgresql.conf`, `pg_hba.conf` and `pg_ident.conf` live in
   `/etc/postgresql/<ver>/<cluster>/`, *outside* the data directory, so
   `pg_basebackup` never captured them. The restored cluster had no
   configuration and refused to start. A bare-metal recovery would have failed
   at exactly the wrong moment.
2. **Permissions.** Both tarballs carry a `.` entry whose mode is applied to
   the target directory, and the config directory is `0755`. Postgres refuses
   to start on a data directory that is not `0700` or `0750`, so the restore
   has to re-tighten *after* extracting, not before.
3. **The drill hung instead of failing.** `psql` inherited the live cluster's
   `pg_hba`, prompted for a password, and blocked forever with no terminal
   attached. Cron would never have reported it. Every `psql` call now passes
   `-w`, so it fails fast rather than hanging.

None of these are visible by reading the scripts. They are the reason the
drill is automated.

### Proven, not asserted

The final run demonstrated point-in-time recovery, which is the entire claim
behind WAL archiving:

- base backup taken with **1 tenant**
- 25 more tenants written afterwards, WAL archived (10 segments, 0 failures)
- restore replayed the archive and came back with **26 tenants** and all 18
  `tenant_isolation` policies intact

That is the difference between "we lose up to a day" and "we lose up to a few
minutes" — measured, on this schema.

## Off-box is the whole point

A backup on the same VPS as the database protects against `DROP TABLE`. It
does not protect against losing the VPS — which, on a single-box deployment,
is the failure that ends the company. Set `BACKUP_S3_*` and let the script ship
them; local retention is a cache, not a backup.
