#!/usr/bin/env bash
# Install outside release bundles as each server project's pre-migrate.sh.
# Uses the new dedicated infrastructure; legacy installations need their existing backup command.
set -Eeuo pipefail
umask 077
revision="${1:?commit required}"
[[ "$revision" =~ ^[a-f0-9]{40}$ ]] || exit 64
directory=/home/admin_chen/www/backups
mkdir -p "$directory"
container="$(docker ps -q --filter label=com.docker.compose.project=saveb-infra --filter label=com.docker.compose.service=postgres)"
[[ "$container" =~ ^[a-f0-9]+$ ]] || { echo "Expected one running infrastructure PostgreSQL container"; exit 1; }
file="$directory/$(date -u +%Y%m%dT%H%M%SZ)-$revision-$$.dump"
docker exec "$container" sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > "$file"
test -s "$file"
# Validate archive readability; daily full restore verification remains a separate task.
docker exec -i "$container" pg_restore --list < "$file" > /dev/null
sha256sum "$file" > "$file.sha256"
echo "Pre-migration database backup created and archive verified."
