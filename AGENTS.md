# AGENTS.md — Dashboard Data Pendidikan Disdikpora Karangasem

## Repository Structure

```
.
├── dashboard-karangasem/     # Laravel 13 + Filament 5 application (main app)
├── database/sql/             # DDL: metrics views, admin role, equivalence tests
├── deploy/
│   ├── backup/               # run-backup.sh, restore-drill.sh, lib.sh
│   ├── cron/                 # crontab examples for scheduler + backup
│   ├── metabase/             # docker-compose.yml for Metabase OSS v0.55.4
│   └── nginx/                # reverse proxy configs (dashboard.conf, metabase.conf)
├── docs/                     # runbook, security-checklist, UAT, landing-snapshot, etc.
├── scripts/
│   ├── landing-snapshot.sh   # landing page snapshot pipeline
│   ├── metabase/
│   │   └── iac/              # CARD AS CODE dashboard publik (YAML + export/apply/verify)
│   └── tunnel/               # Cloudflare Quick Tunnel management
│       ├── tunnel-up.sh      # starts tunnels, updates .env + Metabase site-url
│       └── tunnel-down.sh    # stops tunnels, clears state
├── .tunnel/                  # gitignored tunnel state (PIDs, logs, URLs)
├── MasterPlan.md             # 7-phase global plan
└── metabase_questions.md     # 46 kartu & 6 tab dashboard publik (SQL, filter, layout)
```

## Critical Architecture Rules (Never Violate)

| Rule | Reason |
|------|--------|
| **Laravel never queries `backbone_client`** | Separation from Pusdatin source data |
| **Only Metabase touches `backbone_client`** with read-only `analis` role | Prevents accidental writes to education data |
| **Public dashboard is defined in `scripts/metabase/iac/`**, not in the live instance | Reproducible on any machine; UI edits are overwritten by `apply.py` |
| **`app_db` is separate from `backbone_client`** | `app_db` holds users, sessions, audit only |
| **`METABASE_EMBEDDING_SECRET` must equal `MB_EMBEDDING_SECRET_KEY`** | JWT HS256 signing for signed embeds; mismatch breaks all embeds |
| **Never touch `MB_ENCRYPTION_SECRET_KEY`** | Encrypts DB credentials in Metabase metadata; changing it breaks all connections |
| **`METABASE_SITE_URL` (browser) ≠ `METABASE_INTERNAL_URL` (probe)** | Browser needs public hostname; `metabase:check-embedding` runs in Docker and needs internal service name |
| **Public embed uses UUID, not numeric ID** | Metabase `/public/dashboard/{uuid}` only accepts `public_uuid`; numeric ID shows "Unauthenticated" |

## Developer Commands (Run from `dashboard-karangasem/`)

### Setup
```bash
cd dashboard-karangasem
cp .env.example .env
# Fill: ADMIN_EMAIL, ADMIN_PASSWORD (min 12 chars, upper+lower+digit),
# METABASE_SITE_URL, METABASE_EMBEDDING_SECRET (32+ chars, identical to Metabase),
# METABASE_PUBLIC_DASHBOARD_ID, METABASE_VIP_DASHBOARD_ID
vendor/bin/sail up -d --build
vendor/bin/sail artisan migrate --seed
vendor/bin/sail npm install && vendor/bin/sail npm run build
```

### Testing (Must Run Through Sail)
```bash
vendor/bin/sail artisan test --compact              # Unit + Feature (expect ~402 tests, 927 assertions)
vendor/bin/sail bin pint --format agent             # Fix code style (run after PHP changes)
vendor/bin/sail composer audit                      # Dependency advisory check
vendor/bin/sail artisan security:scan-pii           # PII scan (exit 0 = clean)
vendor/bin/sail artisan metabase:check-embedding    # Validates embed config
vendor/bin/sail artisan schedule:list               # Verify 4 scheduled jobs
```

### Key Env Variables (Testing vs Production)

| Variable | Test Value | Production |
|----------|------------|------------|
| `CACHE_STORE` | `database` (required for TOTP lock) | `database` |
| `SEO_INDEXABLE` | `false` | `true` (after domain final) |
| `LANDING_ENABLED` | `true` | `true` |
| `APP_DEBUG` | `true` | **`false` (mandatory)** |
| `TRUSTED_PROXIES` | `127.0.0.1` | Real proxy IPs |
| `METABASE_SITE_URL` | `http://localhost:3000` | Public HTTPS URL (tunnel/domain) |
| `METABASE_INTERNAL_URL` | `http://metabase:3000` | Internal Docker hostname |

> `APP_DEBUG=true` in production leaks stack traces via `/up` health endpoint.

### Common Operations

| Task | Command |
|------|---------|
| Clear config cache after `.env` change | `vendor/bin/sail artisan config:clear` |
| Restart app only | `vendor/bin/sail restart app` |
| Restart Metabase | `docker compose -f ../deploy/metabase/docker-compose.yml restart metabase` |
| Manual backup | `../deploy/backup/run-backup.sh` |
| Restore drill (safe, throws away) | `../deploy/backup/restore-drill.sh` |
| Rotate embed secret | See `docs/runbook.md` §5 |
| VIP deactivate expired | `vendor/bin/sail artisan vip:deactivate-expired` |
| Prune audit logs | `vendor/bin/sail artisan audit:prune --days=365` |
| Start Cloudflare tunnels | `./scripts/tunnel/tunnel-up.sh` |
| Stop Cloudflare tunnels | `./scripts/tunnel/tunnel-down.sh` |
| Check dashboard grid layout | `python3 scripts/metabase/check-layout.py` (exit 0 = tidak ada sel bertumpuk) |
| Lint dashboard spec (offline) | `python3 scripts/metabase/iac/tools/validate.py` |
| Preview dashboard apply | `python3 scripts/metabase/iac/tools/apply.py --dry-run` |
| Rebuild public dashboard | `python3 scripts/metabase/iac/tools/apply.py` |
| Verify dashboard vs spec | `python3 scripts/metabase/iac/tools/verify.py` |
| Re-export spec from instance | `python3 scripts/metabase/iac/tools/export.py` (or `--check` for drift) |

### Scheduled Jobs (WITA / UTC+8)

| Time | Job | Command |
|------|-----|---------|
| Every minute | Scheduler trigger | `php artisan schedule:run` |
| 00:30 | Deactivate expired VIPs | `vip:deactivate-expired` |
| 02:00 | Daily backup | `run-backup.sh` |
| 03:00 | Prune audit >365 days | `audit:prune --days=365` |
| Hourly | Check embed config | `metabase:check-embedding` |
| Mon 05:00 | PII scan | `security:scan-pii` |

**Order matters:** 00:30 → 02:00 → 03:00 so backup reads before prune touches same tables.

### CI Pipeline (`.github/workflows/security.yml`)

- Runs on push to `main`, `fase-*`, PRs, and weekly Mon 03:00 UTC
- PHP 8.5, Postgres 18 (service container), Node 22
- `composer install --no-scripts` → `npm ci` → `npm run build` → `php artisan test --compact` → `vendor/bin/pint --test`
- `DB_PASSWORD=secret` injected via GitHub Actions env (not in repo)
- `phpunit.xml` forces `DB_HOST=pgsql` mapped to `127.0.0.1` via `/etc/hosts`

## Troubleshooting Quick Reference

| Symptom | First Check |
|---------|-------------|
| White iframe / embed broken | `vendor/bin/sail artisan metabase:check-embedding` |
| VIP accesses after expiry | `vendor/bin/sail artisan vip:deactivate-expired` then verify cron |
| `/up` returns 500 | `grep 'Health check gagal' storage/logs/laravel.log` |
| `.env` changes ignored | `vendor/bin/sail artisan config:clear` |
| Landing redirects to `/dashboard` | `LANDING_ENABLED=false` — set `true` then `optimize:clear && optimize` |
| Canonical shows localhost/tunnel | `APP_URL` not set to final domain |
| Landing not indexed | `SEO_INDEXABLE=false` or static `robots.txt` in Nginx |
| Scheduler not running | `crontab -l` — ensure `schedule:run` every minute |
| Admin MFA loop | Complete TOTP setup (QR + 6-digit code) |
| Card overlaps footer | Grid cells overlap — run `python3 scripts/metabase/check-layout.py`, fix `row`/`col` |
| Old dashboard layout after card change | Full reload (Ctrl+Shift+R); bump `ASSET_VERSION` then `config:cache` |
| Backup fails | `tail -n 20 storage/logs/backup.log` |

## Documentation References

| File | Purpose |
|------|---------|
| `docs/runbook.md` | Full operational runbook (restart, backup, incidents, contacts) |
| `docs/landing-snapshot.md` | Landing page snapshot pipeline (review/apply/verify) |
| `docs/security-checklist.md` | Phase 5 security verification checklist |
| `docs/metabase-ids.md` | Dashboard IDs, embed config, secret rotation |
| `docs/uat.md` | Manual QA scenarios (T9, T10 not automatable) |
| `deploy/backup/README.md` | Backup/restore drill usage |
| `scripts/tunnel/tunnel-up.sh` | Quick Tunnel startup (updates .env + Metabase site-url via API) |
| `scripts/tunnel/tunnel-down.sh` | Quick Tunnel shutdown |
| `metabase_questions.md` | 46 kartu & 6 tab dashboard publik (SQL, filter, layout) |
| `scripts/metabase/iac/README.md` | Card as code: prasyarat, 4 perintah, aturan, troubleshooting |

## Metabase Dashboard Publik — Key Facts

- **Dashboard ID**: `2` (public UUID: `a5a63c98-29d1-4f69-b430-062b23f58888`)
- **Database**: `backbone_client` (id `2`, role `analis`, read-only)
- **Tabs**: 6 (Eksekutif, Sekolah, Rombel, Peserta Didik, ATS, Sarana Prasarana)
- **Cards**: 46 native SQL (8/8/7/8/7/8 per tab)
- **Filter semester**: per-tab, not global
  - **ATS tab**: `p-semester-ats` → `semester_ats` (field 1411), 10 periods, default = ALL (3,032)
  - **Sarana Prasarana**: `p-semester` → `semester_ruang` (1699) + `semester_sanitasi` (1760), 26 periods, default = latest with data (20261)
  - Other 4 tabs: no filter; cards pinned to `semester_acuan` CTE (active semester if data exists, else MAX)
- **Static list values** must be `[value, label]` order (e.g., `["20261", "2026/2027 Gasal"]`) — reversed order breaks filtering AND shows raw codes
- **Parameter mapping**: `PUT /api/dashboard/2` with full `tabs` + `dashcards` (per-dashcard endpoint deprecated, requires tabs)
- **Layout check**: `python3 scripts/metabase/check-layout.py` (exit 0 = no overlapping cells)
- **Source of truth**: `scripts/metabase/iac/` — `dashboard.public.yaml` + `cards/*.yaml`. No numeric Metabase ids in YAML: fields are `{schema, table, column}`, cards/collections/dashboard by name, resolved at apply time (`tools/resolver.py`). See `scripts/metabase/iac/README.md`.
- **Rebuild on another machine**: `validate.py` → `apply.py --dry-run` → `apply.py` → `verify.py` (idempotent; second run reports `Total perubahan: 0`; prints `METABASE_PUBLIC_DASHBOARD_ID/UUID` for `.env`)
- **Two card names are intentionally duplicated** (`Total Rombel Aktif (Kelas)` = Q-EXE-02/Q-ROM-01, `Total Peserta Didik Aktif` = Q-EXE-03/Q-PD-01). Card identity = dashboard slot `(tab, name)`, then unique name, then identical SQL — never name alone
- **`apply.py` refuses to run when grid cells overlap** (`--force` override exists but is never appropriate in production)
- **Legacy quick-fix**: `python3 scripts/metabase/apply-semester-filter.py` still works for semester-filter-only repairs (payload: `semester-filter.sql.json`, backup to `scripts/metabase/.backup/`), but it does not create cards/tabs/dashboard — use the IaC tools for rebuilds

## Project-Specific Conventions

- **PHP 8.5**, **Laravel 13.17**, **Filament 5**, **PostgreSQL 16/18**
- Use `vendor/bin/sail` prefix for ALL commands (PHP, Artisan, Composer, Node, NPM)
- Tests use `database` cache store (not `array`) to properly test TOTP mutex
- Public embed requires `METABASE_PUBLIC_DASHBOARD_UUID` (UUID, not numeric ID)
- `robots.txt` and `sitemap.xml` are dynamic Laravel routes — **no static Nginx locations**
- Landing page stats are snapshotted JSON (`storage/app/landing/snapshot.json`), not live queries
- PII scanner is fail-closed; false positives on reference table `nama` columns are human-reviewed, not ignored
- Admin actions **must** go through Filament panel — direct SQL bypasses audit observers
- **Quick Tunnels are ephemeral** — hostname changes on every start; `tunnel-up.sh` rewrites `.env` + Metabase `site-url` via API
- Metabase `MB_SITE_URL` must NOT be pinned in `deploy/metabase/.env` — let `tunnel-up.sh` set it via API
- **Metabase dashcards created via API must use cumulative grid rows**: `row = previous row + previous size_y` (e.g. 0 and 6). Sequential indices (0, 1, 2) shrink the computed grid height, so cards overflow their container and paint over the "Didukung oleh Metabase" footer — the footer sits in normal flow, not fixed
- **Declared grid cells must not overlap** (same `col` span + `row` range). Metabase reflows colliding cards downward at render time, but the dashboard height (footer position) still uses the declared `row`s, so content grows taller than its container and the last card paints over the footer. Verify per tab: `max(row + size_y)` of the declared layout must equal the rendered content height. Real case: Sarpras had SAR-07 at `row 12 col 12` over SAR-04 (`row 8–16 col 12`) → footer covered by 162 px; fixed with SAR-05 `12×12`, SAR-07 `row 16`, SAR-08 `row 24`
- Dashboard wrappers (`public.dashboard`, `vip.dashboard`) use a flex-column single-viewport layout (`body` flex, `header` `flex: 0 0 auto`, `iframe` `flex: 1 1 auto; min-height: 0`). Do not reintroduce `height: calc(100vh - Xrem)` — header height varies on small screens and causes double scrollbars
- **Metabase field filter params sent as `null` when cleared** — text-variable filters fail with "Parameter yang diperlukan hilang". Use native dimension field filters (project convention) with optional `[[AND {{tag}}]]` blocks instead
- **Metabase static list values**: write as `[[value, label]]` — element 1 = sent to query, element 2 = displayed. Reversed order breaks filtering silently
- **Metabase `PUT /api/dashboard/{id}/cards` is deprecated** and fails 500 without `tabs` in payload. Use `PUT /api/dashboard/{id}` with `name`, `parameters`, `tabs`, `dashcards` (full document)
