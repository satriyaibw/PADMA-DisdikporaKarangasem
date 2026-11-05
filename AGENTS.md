# AGENTS.md — Dashboard Data Pendidikan Disdikpora Karangasem

## Layout

- `dashboard-karangasem/` — Laravel 13 + Filament 5 app (main). Read its nested `AGENTS.md` before editing PHP; it contains Laravel Boost, Sail, PHPUnit, and Pint rules. Its Sail-only rule is for local dev; production uses `docker exec` (see below).
- `database/sql/` — metrics DDL, roles, equivalence tests; the landing snapshot SQL is in `dashboard-karangasem/database/sql/landing_snapshot.sql`.
- `deploy/` — backup/restore, cron examples, Metabase compose, Nginx configs (dev + `*.prod.conf`).
- `scripts/` — landing snapshot pipeline, backbone mirror sync, Metabase IaC, Cloudflare tunnels.
- `docs/` — runbook, landing snapshot, security checklist, Metabase IDs, UAT.
- `docker-compose.prod.yml` — production topology (app, app_db, Metabase, Metabase metadata DB, backbone mirror, Metabase proxy, named tunnel).
- `MasterPlan.md`, `metabase_questions.md`, `README.md` — planning and dashboard catalog.

## Runtime modes (read first)

- **Local dev**: from `dashboard-karangasem/`, use `vendor/bin/sail ...`. Follow `dashboard-karangasem/AGENTS.md` for Laravel/PHPUnit/Pint rules.
- **Production on this server**: root `docker-compose.prod.yml` + root `.env.prod`. Containers: `padma-app` (nginx + php-fpm + supervisor), `padma-pgsql` (`app_db`), `padma-metabase`, `padma-metabase-postgres`, `padma-backbone-postgres` (mirror), `padma-metabase-proxy`, `padma-cloudflared`.
- Production bind-mounts the repo into `padma-app`; use `docker exec padma-app php artisan ...`, **not** Sail. Rebuilding the image is only needed for Dockerfile/Nginx/Supervisor changes.
- Production OPcache has `opcache.validate_timestamps=0`. After PHP/composer/view changes, **restart `padma-app`** or the old code keeps serving. Snapshot JSON is read per request, so `landing-snapshot.sh apply` needs no restart.
- Two env files: compose reads root `.env.prod`; Laravel reads `dashboard-karangasem/.env`. `APP_KEY` lives in the app `.env`; most runtime flags are injected by compose. Both are root-owned; `.env.prod` is mode 600 and gitignored — use `sudo` only when necessary and never print or copy secrets.
- Production supervisor runs `schedule:work` and two `queue:work` workers inside `padma-app`. Do **not** add a duplicate host `schedule:run` cron in this mode.

## Critical architecture rules

| Rule | Reason |
|---|---|
| **Laravel never queries `backbone_client`** (source or mirror) | Separation from Pusdatin data; app only uses `app_db` |
| **Only Metabase and `scripts/landing-snapshot.sh` read the mirror**, read-only (`analis`/`padma_ro`); only `scripts/backbone-mirror-sync.sh` refreshes it | Prevents accidental writes to education data |
| **`padma-backbone-postgres` is a copy**, not the source | Source is BackboneQuery's `db-target` container and may be offline; the mirror keeps PADMA alive |
| **Public dashboard is defined in `scripts/metabase/iac/`**, not the live instance | Reproducible; UI edits are overwritten by `apply.py` |
| **`app_db` is separate from `backbone_client`** | `app_db` holds users, sessions, cache, queue, audit, permissions only |
| **`METABASE_EMBEDDING_SECRET` must equal `MB_EMBEDDING_SECRET_KEY`** | JWT HS256 signing; mismatch breaks all embeds |
| **Never touch `MB_ENCRYPTION_SECRET_KEY`** | Encrypts DB credentials in Metabase metadata; changing it breaks connections |
| **`METABASE_SITE_URL` (browser) ≠ `METABASE_INTERNAL_URL` (probe)** | Browser needs public hostname; the Docker probe needs `http://metabase:3000` |
| **Public embed uses UUID, not numeric ID** | `/public/dashboard/{uuid}` only accepts `public_uuid`; numeric ID shows "Unauthenticated" |

## Commands

### Local dev (from `dashboard-karangasem/`)

```bash
cp .env.example .env
vendor/bin/sail up -d --build
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --seed
vendor/bin/sail npm install && vendor/bin/sail npm run build

vendor/bin/sail artisan test --compact
vendor/bin/sail artisan test --compact tests/Feature/LandingSnapshotTest.php
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail composer audit
vendor/bin/sail artisan security:scan-pii
vendor/bin/sail artisan metabase:check-embedding
vendor/bin/sail artisan schedule:list
```

- Full suite currently **~409 tests / 938 assertions** (Nov 2026); update this line when the suite changes.
- Tests need a `testing` database owned by `app` and `DB_PASSWORD`. Create it once with `vendor/bin/sail exec pgsql psql -U app -d postgres -c 'CREATE DATABASE testing OWNER app;'`. `phpunit.xml` forces `DB_HOST=pgsql` and `DB_DATABASE=testing`, but deliberately does **not** set `DB_PASSWORD`; CI injects it.
- If `vendor/bin/phpunit` or `vendor/bin/pint` is missing, the checkout was installed with `--no-dev`; use CI or a temporary `composer install` copy.
- **Never run the test suite inside `padma-app` against the production database.** Docker env (`DB_DATABASE=app_db`, `APP_ENV=production`, etc.) can win over `phpunit.xml`; use CI or a temporary copy, or override every DB/env var explicitly. Docker env can also produce false failures.

### Production (from repo root; prefix with `sudo` if `.env.prod` is not readable)

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml ps
docker compose --env-file .env.prod -f docker-compose.prod.yml logs -f app
docker compose --env-file .env.prod -f docker-compose.prod.yml restart app
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build app
docker exec padma-app php artisan migrate --force
docker exec padma-app php artisan config:clear
docker exec padma-app php artisan optimize:clear && docker exec padma-app php artisan optimize
docker restart padma-app   # after PHP/composer/view changes (OPcache)
```

- Manual backup: `./deploy/backup/run-backup.sh`. In production set `APP_DB_CONTAINER=padma-pgsql` and `METABASE_DB_CONTAINER=padma-metabase-postgres` (the script defaults target the dev compose). Safe restore drill: `./deploy/backup/restore-drill.sh` (same container overrides).
- Mirror sync: `./scripts/backbone-mirror-sync.sh`; status/verify with `--status` / `--verify`. Cron example: `deploy/cron/backbone-mirror-sync.cron`.
- Dev Metabase restart: `docker compose -f deploy/metabase/docker-compose.yml restart metabase`. Production: use the root compose and service `metabase`.
- Rotate embed secret: see `docs/runbook.md` §5.
- VIP deactivate expired: `docker exec padma-app php artisan vip:deactivate-expired`.
- Prune audit logs: `docker exec padma-app php artisan audit:prune --days=365`.
- Start/stop dev Quick Tunnels: `./scripts/tunnel/tunnel-up.sh` / `./scripts/tunnel/tunnel-down.sh`.

### Landing snapshot (production)

The script default `BACKBONE_CONTAINER=backbone_client` is wrong on this server; always override:

```bash
BACKBONE_CONTAINER=padma-backbone-postgres \
BACKBONE_DB_USER=padma_ro \
BACKBONE_DB_NAME=backbone_client \
./scripts/landing-snapshot.sh review

./scripts/landing-snapshot.sh apply <hash>
./scripts/landing-snapshot.sh verify --strict
```

### Metabase dashboard IaC (from `scripts/metabase/iac/`)

```bash
python3 tools/validate.py
python3 tools/apply.py --dry-run
python3 tools/apply.py
python3 tools/verify.py
python3 tools/export.py --check   # drift check
```

- Grid guard from repo root: `python3 scripts/metabase/check-layout.py` (pass `--dashboard <id>` for another dashboard).
- Dashboard YAML `expect` values are snapshots of the data; after a pull they can go stale and `verify.py` may report `BELUM SESUAI` even when the SQL is correct. Update `expect` as a separate dashboard change, not as part of landing work.
- Legacy semester-filter-only repair: `scripts/metabase/apply-semester-filter.py`. It does not create cards/tabs; use the IaC tools for rebuilds.

## Landing snapshot — non-obvious facts

- `storage/app/landing/snapshot.json` is gitignored and read per request; landing does no DB query. **Never edit the JSON by hand.**
- Pipeline: `review` (read-only query + artifact) → `apply <hash>` → `verify --strict`. Review artifacts are `storage/app/landing/review.json` and `review-*.md`.
- Landing definitions must match dashboard Q-EXE-01..04:
  - schools: `Soft_delete=0 AND keaktifan=1` in Karangasem (`kode_wilayah` prefix `2208`);
  - rombel: `Soft_delete=0`, `jenis_rombel=1` (Kelas), latest semester;
  - students: active `dbo.registrasi_peserta_didik` (`Soft_delete=0`, `jenis_keluar_id IS NULL`) at active schools, distinct valid 10-digit NISN;
  - PTK: `dbo.ptk_terdaftar` (`Soft_delete=0`, `jenis_keluar_id IS NULL`) + `qc.vld_ptk.aktif=1`, `ptk_induk=1`, at active schools.
- “Data per” = `sources.pulled_at` = `max(sync.pull_log.run_finished_at)`; fallback `sources.last_sync`, then `generated_at`. Staleness uses `review.applied_at`/`generated_at`, **not** row-level `last_sync`.
- Landing shows only “Data per” (snapshot pull date); do not reintroduce a second “last updated” label.
- SEO `dateModified`/`lastmod` use the snapshot date through `LandingSeoService`; do not fall back to `config/landing.php` when a valid snapshot exists.
- `sync.pull_log` timestamps are local WITA even though the DB session is UTC; use them as-is.
- Reference snapshot (data per 29 Oct 2026): **604 schools / 68.983 students / 3.482 rombels / 4.977 PTK**. Landing and dashboard must agree; if they differ, fix definitions, not the JSON.
- If the snapshot is invalid or missing, landing falls back to `config/landing.php` and logs `Snapshot landing tidak valid dan diabaikan`; run `verify --strict` and inspect `storage/logs/laravel.log`.

## Metabase public dashboard — key facts

- Dashboard `2`; public UUID **`791f4341-b548-4669-bdeb-92bc3573e447`** (verify with `SELECT public_uuid FROM report_dashboard WHERE id = 2;`). 6 tabs, 46 cards + 6 helper cards for dropdowns.
- VIP currently reuses dashboard `2`; do not assume a separate VIP dashboard exists. Check `docs/metabase-ids.md`.
- Source of truth: `scripts/metabase/iac/dashboard.public.yaml` + `cards/*.yaml`. No numeric Metabase IDs in YAML: fields are `{schema, table, column}`, cards/collections/dashboard by name, resolved at apply time.
- Rebuild workflow: `validate.py` → `apply.py --dry-run` → `apply.py` → `verify.py`. It is idempotent (second run reports `Total perubahan: 0`) and prints `METABASE_PUBLIC_DASHBOARD_ID/UUID` for `.env`.
- Two card names are intentionally duplicated (`Total Rombel Aktif (Kelas)` = Q-EXE-02/Q-ROM-01; `Total Peserta Didik Aktif` = Q-EXE-03/Q-PD-01). Card identity = dashboard slot `(tab, name)`, then unique name, then identical SQL — never name alone.
- Layout: `row` is cumulative and declared cells must not overlap. `apply.py` refuses overlap; `check-layout.py` verifies. Metabase reflows collisions at render time, but footer height uses declared rows, so overlaps paint over “Didukung oleh Metabase”.
- Static list values must be `[value, label]` order; reversed order breaks filtering and shows raw codes.
- Use native dimension field filters with optional `[[AND {{tag}}]]` blocks. Cleared parameters are sent as `null`; text-variable filters then fail with “Parameter yang diperlukan hilang”.
- `PUT /api/dashboard/{id}/cards` is deprecated and fails 500 without `tabs`. Use `PUT /api/dashboard/{id}` with `name`, `parameters`, `tabs`, `dashcards` (full document).
- Semester filters are per-tab, not global (ATS and Sarana Prasarana); the other tabs pin to a `semester_acuan` CTE.
- Quick Tunnel dev: do not pin `MB_SITE_URL` in `deploy/metabase/.env`; `tunnel-up.sh` sets it via API. Production sets it from `.env.prod`/compose.

## Scheduled jobs (WITA / UTC+8)

- Production compose: supervisor in `padma-app` runs `schedule:work` + two queue workers. Do not add a host `schedule:run` cron in this mode.
- Host-based deployment only: install `deploy/cron/dashboard-schedule.cron` (example; runs `schedule:run` every minute).
- Laravel schedule: 00:30 `vip:deactivate-expired`; 03:00 `audit:prune --days=365`; hourly `metabase:check-embedding`; Mon 05:00 `security:scan-pii`.
- Host cron examples: 02:00 backup (`deploy/cron/dashboard-backup.cron`); mirror sync 03:10, 06:40, 09:10, 15:10, 21:10 (`deploy/cron/backbone-mirror-sync.cron`, after the Pusdatin pull, usually 02:00).
- Order matters: backup 02:00 before prune 03:00; mirror sync after the source pull.

## CI (`.github/workflows/security.yml`)

- `dependency-audit`: PHP 8.5, `composer install --no-scripts`, `composer audit --locked`; uploads the report on failure.
- `tests`: PHP 8.5, Postgres 18 service, Node 22; creates the `testing` database, maps `pgsql` to `127.0.0.1`, injects `DB_PASSWORD=secret`; runs `php artisan test --compact` then `vendor/bin/pint --test`.
- `metabase-iac`: Python 3.12, `pip install -r requirements.txt`, `python3 tools/validate.py`.
- CI is the authoritative full-suite run when the production checkout lacks dev dependencies.

## Troubleshooting

| Symptom | First check |
|---|---|
| PHP change not visible in production | `docker restart padma-app` (OPcache `validate_timestamps=0`) |
| Landing date/numbers stale | `./scripts/backbone-mirror-sync.sh --status`; check `sync.pull_log`; run landing `review`/`apply` |
| Landing shows config fallback date | Snapshot invalid/missing; grep `Snapshot landing` in `storage/logs/laravel.log`; run `verify --strict` |
| Dashboard data stale | Mirror sync status; run `./scripts/backbone-mirror-sync.sh` |
| White iframe / embed broken | `docker exec padma-app php artisan metabase:check-embedding` |
| VIP accesses after expiry | `docker exec padma-app php artisan vip:deactivate-expired`; check supervisor |
| `/up` returns 500 | `grep 'Health check gagal' dashboard-karangasem/storage/logs/laravel.log` |
| `.env` changes ignored | `docker exec padma-app php artisan config:clear` (or `optimize:clear && optimize`) |
| Landing redirects to `/dashboard` | `LANDING_ENABLED=false`; set `true`, then `optimize:clear && optimize` |
| Landing not indexed | `SEO_INDEXABLE=false`, or a static Nginx location intercepting robots/sitemap |
| Card overlaps footer | `python3 scripts/metabase/check-layout.py`; fix `row`/`col` |
| Old dashboard layout after card change | Full reload; bump `ASSET_VERSION`, then `config:cache` |
| Backup fails | `tail -n 20 dashboard-karangasem/storage/logs/backup.log` |

## Conventions

- PHP 8.5, Laravel 13.17, Filament 5, PostgreSQL 16 (mirror) / 18 (`app_db`), Metabase OSS v0.55.4, Node 22, Vite 8, Tailwind 4.
- `CACHE_STORE=database` is required for the TOTP replay guard; don't switch production/tests to `array`.
- Production TLS terminates at Cloudflare; compose sets `APP_DEBUG=false`, `FORCE_HTTPS=false`, `HSTS_ENABLED=false`, `SESSION_SECURE_COOKIE=false`. Don't flip these without testing the tunnel.
- Admin actions must go through the Filament panel; direct SQL bypasses audit observers.
- `robots.txt` and `sitemap.xml` are dynamic Laravel routes; never add static Nginx locations for them.
- PII scanner is fail-closed; false positives on reference-table `nama` columns are human-reviewed, not silently ignored.
- Quick Tunnels are dev-only and ephemeral; production uses the named tunnel: `https://padma.disdikkarangasem-lab.my.id` (app) and `https://metabase-padma.disdikkarangasem-lab.my.id` (embed proxy). Metabase admin stays on `127.0.0.1:3100` and is never exposed directly.

## References

| File | Purpose |
|---|---|
| `dashboard-karangasem/AGENTS.md` | Laravel Boost, Sail, PHPUnit, Pint, and PHP conventions |
| `README.md` | Project overview and user-facing setup; test counts may lag the suite |
| `MasterPlan.md` | 7-phase global plan and database map |
| `docs/runbook.md` | Restart, backup, secret rotation, incidents, contacts |
| `docs/landing-snapshot.md` | Landing snapshot definitions and review/apply/verify flow |
| `docs/metabase-ids.md` | Live dashboard IDs, embed config, secret rotation |
| `docs/security-checklist.md` | Phase 5 security verification |
| `docs/uat.md` | Manual QA scenarios |
| `deploy/backup/README.md` | Backup and restore drill usage |
| `scripts/metabase/iac/README.md` | Card-as-code prerequisites, commands, rules, troubleshooting |
| `metabase_questions.md` | 46 dashboard cards, 6 tabs, SQL, filters, layout |
| `docker-compose.prod.yml` | Production services and network topology |
| `scripts/backbone-mirror-sync.sh` | Mirror refresh, `--status`, `--verify`, degraded mode |
| `deploy/cron/backbone-mirror-sync.cron` | Mirror refresh schedule |
