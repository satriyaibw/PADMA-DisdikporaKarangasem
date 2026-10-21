#!/usr/bin/env bash
# ============================================================================
# tunnel-up.sh — Start Cloudflare Quick Tunnels for Laravel + Metabase
# ============================================================================
# Usage: ./scripts/tunnel/tunnel-up.sh
# Prints final public URLs and credentials. Safe to re-run (stops old tunnels).
# ============================================================================

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TUNNEL_DIR="$REPO_ROOT/.tunnel"
STATE_FILE="$TUNNEL_DIR/state.json"
ENV_FILE="$REPO_ROOT/dashboard-karangasem/.env"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log()  { printf "\033[1;32m[%s]\033[0m %s\n" "$(date +%H:%M:%S)" "$*"; }
warn() { printf "\033[1;33m[%s] WARNING:\033[0m %s\n" "$(date +%H:%M:%S)" "$*"; }
err()  { printf "\033[1;31m[%s] ERROR:\033[0m %s\n" "$(date +%H:%M:%S)" "$*" >&2; }

stop_old_tunnels() {
  if [[ -f "$STATE_FILE" ]]; then
    local old_app_pid old_mb_pid
    old_app_pid=$(jq -r '.app_pid // empty' "$STATE_FILE" 2>/dev/null || echo "")
    old_mb_pid=$(jq -r '.metabase_pid // empty' "$STATE_FILE" 2>/dev/null || echo "")
    for pid in "$old_app_pid" "$old_mb_pid"; do
      if [[ -n "$pid" && "$pid" != "null" ]] && kill -0 "$pid" 2>/dev/null; then
        log "Stopping old tunnel PID $pid"
        kill "$pid" 2>/dev/null || true
      fi
    done
  fi
}

wait_for_hostname() {
  local logfile="$1" name="$2" timeout=60
  local url="" start_ts
  start_ts=$(date +%s)
  while [[ -z "$url" ]]; do
    if [[ $(($(date +%s) - start_ts)) -gt $timeout ]]; then
      err "Timeout waiting for $name tunnel hostname"
      tail -20 "$logfile"
      return 1
    fi
    url=$(grep -oE "https://[a-z0-9-]+\.trycloudflare\.com" "$logfile" 2>/dev/null | head -1 || true)
    [[ -n "$url" ]] && break
    sleep 1
  done
  echo "$url"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
mkdir -p "$TUNNEL_DIR"

log "Stopping any existing tunnels..."
stop_old_tunnels

# Start Laravel tunnel (port 8000)
log "Starting Cloudflare tunnel for Laravel (port 8000)..."
APP_LOG="$TUNNEL_DIR/app-tunnel.log"
nohup cloudflared tunnel --url http://localhost:8000 --protocol http2 --edge-ip-version 4 --no-autoupdate >"$APP_LOG" 2>&1 &
APP_PID=$!

# Start Metabase tunnel (port 3000)
log "Starting Cloudflare tunnel for Metabase (port 3000)..."
MB_LOG="$TUNNEL_DIR/metabase-tunnel.log"
nohup cloudflared tunnel --url http://localhost:3000 --protocol http2 --edge-ip-version 4 --no-autoupdate >"$MB_LOG" 2>&1 &
MB_PID=$!

# Wait for hostnames
log "Waiting for tunnel hostnames..."
APP_URL=$(wait_for_hostname "$APP_LOG" "Laravel")
MB_URL=$(wait_for_hostname "$MB_LOG" "Metabase")

log "Laravel tunnel:  $APP_URL"
log "Metabase tunnel: $MB_URL"

# Update Metabase site-url via API so public embed links use the tunnel hostname
log "Updating Metabase site-url to $MB_URL ..."
SECRET=$(grep -E '^METABASE_EMBEDDING_SECRET=' "$ENV_FILE" | cut -d= -f2-)
ADMIN_EMAIL=$(grep -E '^METABASE_ADMIN_EMAIL=' "$ENV_FILE" | cut -d= -f2-)
ADMIN_PASS=$(grep -E '^METABASE_ADMIN_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)

if [[ -z "$SECRET" || -z "$ADMIN_EMAIL" || -z "$ADMIN_PASS" ]]; then
  warn "Metabase credentials not in .env; skipping site-url update"
else
  SESSION=$(curl -s -X POST http://localhost:3000/api/session \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASS\"}" | jq -r '.id // empty')
  if [[ -n "$SESSION" && "$SESSION" != "null" ]]; then
    curl -s -X PUT "http://localhost:3000/api/setting/site-url" \
      -H "X-Metabase-Session: $SESSION" \
      -H "Content-Type: application/json" \
      -d "{\"value\":\"$MB_URL\"}" >/dev/null && log "Metabase site-url updated" || warn "Failed to update Metabase site-url"
  else
    warn "Could not obtain Metabase session for site-url update"
  fi
fi

# Update .env with public URLs
log "Updating .env with public URLs..."
sed -i "s|^APP_URL=.*|APP_URL=$APP_URL|" "$ENV_FILE"
sed -i "s|^METABASE_SITE_URL=.*|METABASE_SITE_URL=$MB_URL|" "$ENV_FILE"

# Ensure METABASE_INTERNAL_URL exists (defaults to Docker service name)
if ! grep -q '^METABASE_INTERNAL_URL=' "$ENV_FILE"; then
  echo "METABASE_INTERNAL_URL=http://metabase:3000" >> "$ENV_FILE"
else
  sed -i "s|^METABASE_INTERNAL_URL=.*|METABASE_INTERNAL_URL=http://metabase:3000|" "$ENV_FILE"
fi

# Clear config cache and optimize
log "Clearing config cache and optimizing..."
cd "$REPO_ROOT/dashboard-karangasem"
vendor/bin/sail artisan config:clear >/dev/null
vendor/bin/sail artisan optimize >/dev/null

# Write state file
cat > "$STATE_FILE" <<EOF
{
  "app_url": "$APP_URL",
  "metabase_url": "$MB_URL",
  "app_pid": $APP_PID,
  "metabase_pid": $MB_PID,
  "started_at": "$(date -Iseconds)"
}
EOF

# ---------------------------------------------------------------------------
# Output summary
# ---------------------------------------------------------------------------
echo
echo "=================================================================="
echo "  Cloudflare Quick Tunnels ACTIVE"
echo "=================================================================="
echo "  Webapp (Laravel):     $APP_URL"
echo "    - Public dashboard: $APP_URL/dashboard"
echo "    - Admin panel:      $APP_URL/admin"
echo "    - VIP dashboard:    $APP_URL/vip/dashboard"
echo
echo "  Metabase:             $MB_URL"
echo "    - Public dashboard: $MB_URL/public/dashboard/a5a63c98-29d1-4f69-b430-062b23f58888"
echo
echo "  Credentials:"
echo "    Email:    admin@karangasem.go.id"
echo "    Password: GFMCNrsY72vlwQl3A1b"
echo
echo "  State file: $STATE_FILE"
echo "  Logs:       $APP_LOG  /  $MB_LOG"
echo
echo "  ⚠ SECURITY NOTE: Quick Tunnels are PUBLIC with NO authentication."
echo "     Metabase Admin UI is exposed at $MB_URL"
echo "     For production, use a named tunnel + Cloudflare Access."
echo
echo "  To stop tunnels: ./scripts/tunnel/tunnel-down.sh"
echo "=================================================================="