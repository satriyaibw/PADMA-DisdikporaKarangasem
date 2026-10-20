#!/usr/bin/env bash
#
# Pipeline snapshot statistik landing PADMA.
#
#   ./scripts/landing-snapshot.sh review
#   ./scripts/landing-snapshot.sh apply <query-hash>
#   ./scripts/landing-snapshot.sh verify
#
# Query hanya membaca database backbone (READ ONLY). Hasil query disimpan di
# storage/app/landing/review.json dan TIDAK langsung dipakai landing. Landing
# baru memakai snapshot setelah `apply` dengan hash hasil review.
#
# Variabel opsional:
#   BACKBONE_CONTAINER   nama container PostgreSQL backbone (default: backbone_client)
#   BACKBONE_DB_USER     role database (default: postgres; disarankan role read-only)
#   BACKBONE_DB_NAME     nama database (default: backbone_client)
#   BACKBONE_DB_PASSWORD password role, bila diperlukan
#   LANDING_SNAPSHOT_SEMESTER  semester yang direview, mis. 20261 (default: terbaru)

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="${ROOT_DIR}/dashboard-karangasem"
SQL_FILE="${APP_DIR}/database/sql/landing_snapshot.sql"
REVIEW_JSON_REL="storage/app/landing/review.json"
REVIEW_JSON="${APP_DIR}/${REVIEW_JSON_REL}"
SAIL="${APP_DIR}/vendor/bin/sail"

CONTAINER="${BACKBONE_CONTAINER:-backbone_client}"
DB_USER="${BACKBONE_DB_USER:-postgres}"
DB_NAME="${BACKBONE_DB_NAME:-backbone_client}"
SEMESTER="${LANDING_SNAPSHOT_SEMESTER:-}"

usage() {
    cat <<'EOF'
Penggunaan:
  scripts/landing-snapshot.sh review
  scripts/landing-snapshot.sh apply <query-hash>
  scripts/landing-snapshot.sh verify [--strict]

Alur:
  1. review  menjalankan query read-only, menampilkan ringkasan, dan menulis
             artifact review. Snapshot belum diubah.
  2. apply   menulis snapshot hanya bila hash yang diberikan sama dengan hash
             hasil review. Hash berubah bila data/query berubah.
  3. verify  memeriksa struktur, hash review, dan umur snapshot.

Jalankan review lagi setelah sinkronisasi Pusdatin.
EOF
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

require_file() {
    [[ -f "$1" ]] || fail "File tidak ditemukan: $1"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Perintah '$1' tidak tersedia."
}

ensure_backbone() {
    require_command docker
    docker inspect "$CONTAINER" >/dev/null 2>&1 \
        || fail "Container '$CONTAINER' tidak ditemukan. Jalankan deploy/metabase/backbone-init/setup atau set BACKBONE_CONTAINER."

    local running
    running="$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null || true)"
    [[ "$running" == "true" ]] || fail "Container '$CONTAINER' tidak berjalan."
}

run_query() {
    require_file "$SQL_FILE"
    require_file "$SAIL"
    ensure_backbone

    mkdir -p "$(dirname "$REVIEW_JSON")"

    local -a docker_args=(exec -i)
    if [[ -n "${BACKBONE_DB_PASSWORD:-}" ]]; then
        # Salin dari environment host tanpa menaruh nilai password di argv.
        docker_args+=(-e PGPASSWORD)
    fi

    printf 'Menjalankan query read-only ke %s/%s (semester: %s)...\n' \
        "$CONTAINER" "$DB_NAME" "${SEMESTER:-terbaru}"

    docker "${docker_args[@]}" "$CONTAINER" \
        psql -U "$DB_USER" -d "$DB_NAME" \
        -v semester="$SEMESTER" \
        -A -t -q -f - < "$SQL_FILE" > "$REVIEW_JSON"

    [[ -s "$REVIEW_JSON" ]] || fail "Query tidak menghasilkan output. Periksa container dan log psql."

    printf 'Hasil query: %s\n' "$REVIEW_JSON"
}

run_artisan() {
    require_file "$SAIL"
    (cd "$APP_DIR" && "$SAIL" artisan landing:snapshot "$@")
}

action="${1:-review}"

case "$action" in
    review)
        run_query
        run_artisan review --input="$REVIEW_JSON_REL"
        ;;
    apply)
        [[ $# -ge 2 && -n "${2:-}" ]] \
            || fail "Hash review wajib diberikan: scripts/landing-snapshot.sh apply <hash>"
        [[ -f "$REVIEW_JSON" ]] \
            || fail "Hasil review belum ada: $REVIEW_JSON. Jalankan review terlebih dahulu."
        run_artisan apply --input="$REVIEW_JSON_REL" --approve="$2"
        ;;
    verify)
        shift
        run_artisan verify "$@"
        ;;
    -h|--help|help)
        usage
        ;;
    *)
        usage >&2
        fail "Aksi tidak dikenal: $action"
        ;;
esac
