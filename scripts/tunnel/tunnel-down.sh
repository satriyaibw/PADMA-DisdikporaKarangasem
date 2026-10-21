#!/usr/bin/env bash
# ============================================================================
# tunnel-down.sh — Stop Cloudflare Quick Tunnels
# ============================================================================
# Usage: ./scripts/tunnel/tunnel-down.sh
# ============================================================================

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
STATE_FILE="$REPO_ROOT/.tunnel/state.json"

log()  { printf "\033[1;32m[%s]\033[0m %s\n" "$(date +%H:%M:%S)" "$*"; }
warn() { printf "\033[1;33m[%s] WARNING:\033[0m %s\n" "$(date +%H:%M:%S)" "$*" >&2; }

if [[ ! -f "$STATE_FILE" ]]; then
  warn "No state file found at $STATE_FILE"
  exit 0
fi

APP_PID=$(jq -r '.app_pid // empty' "$STATE_FILE" 2>/dev/null || echo "")
MB_PID=$(jq -r '.metabase_pid // empty' "$STATE_FILE" 2>/dev/null || echo "")

for name_pid in "Laravel:$APP_PID" "Metabase:$MB_PID"; do
  name="${name_pid%%:*}"
  pid="${name_pid##*:}"
  if [[ -n "$pid" && "$pid" != "null" ]]; then
    if kill -0 "$pid" 2>/dev/null; then
      log "Stopping $name tunnel (PID $pid)..."
      kill "$pid" 2>/dev/null || true
      sleep 1
      if kill -0 "$pid" 2>/dev/null; then
        kill -9 "$pid" 2>/dev/null || true
      fi
    else
      log "$name tunnel (PID $pid) already stopped"
    fi
  fi
done

# Clear state
rm -f "$STATE_FILE"
log "Tunnels stopped. State cleared."