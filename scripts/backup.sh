#!/usr/bin/env bash
# Резервное копирование PostgreSQL cp-builder (план §15.4).
# Использование: ./scripts/backup.sh [dest_dir]  (по умолчанию ./backups)
# Формат: pg_dump custom (-Fc), filename: cpbuilder-YYYYMMDD-HHMMSS.dump

set -euo pipefail

DEST_DIR="${1:-backups}"
mkdir -p "$DEST_DIR"

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$DEST_DIR/cpbuilder-$STAMP.dump"

# --clean не используем: восстановление идёт в пустую БД изолированного контура.
docker compose exec -T db pg_dump -U "${DB_USERNAME:-cpbuilder}" \
    -d "${DB_DATABASE:-cpbuilder}" -Fc -f "/tmp/backup.dump"

docker compose cp db:/tmp/backup.dump "$OUT"
docker compose exec -T db rm -f /tmp/backup.dump

# Storage (uploads) бэкапируется из named volume app-storage (смонтирован в web
# на /var/www/html/storage), чтобы не смешивать с дампом БД.
STORAGE_TAR="$DEST_DIR/cpbuilder-storage-$STAMP.tar.gz"
docker compose run --rm --no-deps --entrypoint tar \
    web -czf - -C /var/www/html/storage . > "$STORAGE_TAR"

sha256sum "$OUT" "$STORAGE_TAR" | tee "$DEST_DIR/SHA256SUMS-$STAMP.txt"

echo "Backup завершён:"
echo "  db:       $OUT"
echo "  storage:  $STORAGE_TAR"
echo "Проверка восстановления выполняется в изолированном контуре (docs/runbook.md)."
