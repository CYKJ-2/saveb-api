#!/usr/bin/env bash
# =====================================================
#  ERP -> API database sync script (one-click migration)
# =====================================================
# Staging server (admin_chen box).
# Use copy-erp-data.ps1 on local dev instead.
# Files are written under ./.erp-sync/ alongside this script,
# so .ps1 and .sh can be shipped together without huge
# DB dumps living in /tmp.
# =====================================================
set -euo pipefail

# --- Hard-coded config (staging server) ---
ERP_CONTAINER="saveb-erp-phase4-task2-a823095-20260718-190342-postgres-1"
API_CONTAINER="saveb-api-postgres"
DB_USER="phase4"
DB_NAME="phase4"

# --- Sync dir: relative to this script's location ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYNC_DIR="$SCRIPT_DIR/.erp-sync"
DUMP_FILE="$SYNC_DIR/erp_backup.sql"
DUMP_FILTERED="$SYNC_DIR/erp_backup_filtered.sql"

mkdir -p "$SYNC_DIR"

echo ""
echo "=========================================="
echo "  ERP -> API database one-click sync"
echo "=========================================="
echo "Source:  $ERP_CONTAINER"
echo "Target:  $API_CONTAINER"
echo "DB:      $DB_USER@$DB_NAME"
echo "Sync dir: $SYNC_DIR"
echo ""

# --- 1/4: pg_dump ---
echo "[1/4] Exporting from $ERP_CONTAINER ..."
docker exec "$ERP_CONTAINER" pg_dump \
    -U "$DB_USER" -d "$DB_NAME" \
    -c --if-exists -O -x \
    -f /tmp/erp_backup.sql
docker cp "$ERP_CONTAINER:/tmp/erp_backup.sql" "$DUMP_FILE"
origSize=$(du -m "$DUMP_FILE" | cut -f1)
echo "  OK: exported (${origSize} MB)"

# --- 2/4: filter Laravel migrations table ---
echo ""
echo "[2/4] Filtering Laravel migrations table ..."
docker run --rm \
    -v "$DUMP_FILE:/tmp/in.sql" \
    -v "$SYNC_DIR:/out" \
    alpine:3.19 \
    sh -c "grep -v 'INSERT INTO public.migrations VALUES' /tmp/in.sql > /out/erp_backup_filtered.sql"
filtSize=$(du -m "$DUMP_FILTERED" | cut -f1)
echo "  OK: filtered (${filtSize} MB)"

# --- 3/4: drop/recreate API schema ---
echo ""
echo "[3/4] Resetting API database schema ..."
printf "DROP SCHEMA public CASCADE;\nCREATE SCHEMA public;\n" > "$SYNC_DIR/reset.sql"
docker cp "$SYNC_DIR/reset.sql" "$API_CONTAINER:/tmp/reset.sql"
docker exec "$API_CONTAINER" psql \
    -U "$DB_USER" -d "$DB_NAME" \
    -f /tmp/reset.sql >/dev/null 2>&1
echo "  OK: schema reset"

# --- 4/4: import ---
echo ""
echo "[4/4] Importing SQL into $API_CONTAINER ..."
docker cp "$DUMP_FILTERED" "$API_CONTAINER:/tmp/erp_restore.sql"
docker exec "$API_CONTAINER" psql \
    -U "$DB_USER" -d "$DB_NAME" \
    -f /tmp/erp_restore.sql >/dev/null 2>&1
echo "  OK: imported"

# --- Verify ---
echo ""
echo "=========================================="
echo "  Done. Verification:"
echo "=========================================="
printf "SELECT COUNT(*) FROM users;\n"  > "$SYNC_DIR/count_users.sql"
printf "SELECT COUNT(*) FROM orders;\n" > "$SYNC_DIR/count_orders.sql"
docker cp "$SYNC_DIR/count_users.sql"  "$API_CONTAINER:/tmp/count_users.sql"
docker cp "$SYNC_DIR/count_orders.sql" "$API_CONTAINER:/tmp/count_orders.sql"
tables=$(docker exec "$API_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -tA -c "\dt" | wc -l)
users=$(docker exec "$API_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -tA -f /tmp/count_users.sql)
orders=$(docker exec "$API_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -tA -f /tmp/count_orders.sql)
echo "  Tables:       $tables"
echo "  users rows:   $users"
echo "  orders rows:  $orders"
echo ""
echo "  Source ERP container untouched."
echo "  SQL files:    $SYNC_DIR   (rm -rf $SYNC_DIR to clean up)"
