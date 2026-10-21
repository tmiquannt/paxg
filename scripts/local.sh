#!/usr/bin/env bash
set -euo pipefail
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd -- "$project_dir"
export LOCAL_UID="$(id -u)"
export LOCAL_GID="$(id -g)"
restore_wordpress_stopped=0

backup_local_database() {
  local backup_root="$project_dir/.runtime/backups"
  local backup_name partial_dir backup_dir
  [[ ! -L "$project_dir/.runtime" && ! -L "$backup_root" ]] || {
    printf '%s\n' 'Refusing to use a symlinked local runtime or backup directory.' >&2
    return 2
  }
  command -v gzip >/dev/null 2>&1 || { printf '%s\n' 'gzip is required for local database backups.' >&2; return 2; }
  if ! command -v sha256sum >/dev/null 2>&1 && ! command -v shasum >/dev/null 2>&1; then
    printf '%s\n' 'sha256sum or shasum is required for local database backups.' >&2
    return 2
  fi

  umask 077
  mkdir -p -- "$backup_root" || { printf '%s\n' 'Could not create the local backup directory.' >&2; return 1; }
  chmod 700 -- "$backup_root" || { printf '%s\n' 'Could not secure the local backup directory.' >&2; return 1; }
  backup_name="$(date -u +%Y%m%dT%H%M%SZ)-$$"
  partial_dir="$backup_root/.${backup_name}.partial"
  backup_dir="$backup_root/$backup_name"
  mkdir -m 700 -- "$partial_dir" || { printf '%s\n' 'Could not create a private backup staging directory.' >&2; return 1; }

  if ! docker compose exec -T database sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --user=root --single-transaction --quick --hex-blob --routines --triggers --events --databases mini_erp_local' | gzip -n > "$partial_dir/mini-erp.sql.gz"; then
    rm -rf -- "$partial_dir"
    printf '%s\n' 'Database dump failed; no backup was published.' >&2
    return 1
  fi
  if ! gzip -t -- "$partial_dir/mini-erp.sql.gz"; then
    rm -rf -- "$partial_dir"
    printf '%s\n' 'Compressed database dump failed its integrity check.' >&2
    return 1
  fi

  if ! {
    printf 'format=1\n'
    printf 'database=mini_erp_local\n'
    printf 'created_utc=%s\n' "$backup_name"
  } > "$partial_dir/MANIFEST.txt"; then
    rm -rf -- "$partial_dir"
    printf '%s\n' 'Could not write the backup manifest.' >&2
    return 1
  fi
  if command -v sha256sum >/dev/null 2>&1; then
    if ! (cd -- "$partial_dir" && sha256sum mini-erp.sql.gz > SHA256SUMS); then
      rm -rf -- "$partial_dir"
      printf '%s\n' 'Could not write the backup checksum.' >&2
      return 1
    fi
  else
    if ! (cd -- "$partial_dir" && shasum -a 256 mini-erp.sql.gz > SHA256SUMS); then
      rm -rf -- "$partial_dir"
      printf '%s\n' 'Could not write the backup checksum.' >&2
      return 1
    fi
  fi
  if ! chmod 600 -- "$partial_dir"/*; then
    rm -rf -- "$partial_dir"
    printf '%s\n' 'Could not secure the completed local backup.' >&2
    return 1
  fi
  if ! mv -- "$partial_dir" "$backup_dir"; then
    rm -rf -- "$partial_dir"
    printf '%s\n' 'Could not publish the completed local backup.' >&2
    return 1
  fi
  printf '%s\n' "$backup_dir"
}

validate_local_backup() {
  local backup_name="$1"
  local backup_root="$project_dir/.runtime/backups"
  local backup_dir="$backup_root/$backup_name"
  [[ "$backup_name" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9]+$ ]] || { printf '%s\n' 'Backup name is invalid.' >&2; return 2; }
  [[ ! -L "$project_dir/.runtime" && ! -L "$backup_root" ]] || { printf '%s\n' 'Refusing to use a symlinked local runtime or backup directory.' >&2; return 2; }
  [[ -d "$backup_dir" && ! -L "$backup_dir" ]] || { printf '%s\n' 'Local backup directory was not found.' >&2; return 2; }
  [[ -f "$backup_dir/MANIFEST.txt" && ! -L "$backup_dir/MANIFEST.txt" \
    && -f "$backup_dir/SHA256SUMS" && ! -L "$backup_dir/SHA256SUMS" \
    && -s "$backup_dir/mini-erp.sql.gz" && ! -L "$backup_dir/mini-erp.sql.gz" ]] || {
    printf '%s\n' 'Local backup is incomplete.' >&2
    return 2
  }
  grep -Fxq 'format=1' "$backup_dir/MANIFEST.txt" \
    && grep -Fxq 'database=mini_erp_local' "$backup_dir/MANIFEST.txt" \
    && grep -Fxq "created_utc=$backup_name" "$backup_dir/MANIFEST.txt" || {
      printf '%s\n' 'Backup manifest does not match this local database.' >&2
      return 2
    }
  local checksum_line
  checksum_line="$(cat -- "$backup_dir/SHA256SUMS")"
  [[ "$checksum_line" =~ ^[[:xdigit:]]{64}[[:space:]][[:space:]]mini-erp\.sql\.gz$ ]] || {
    printf '%s\n' 'Backup checksum manifest has an unexpected format.' >&2
    return 2
  }
  if command -v sha256sum >/dev/null 2>&1; then
    if ! (cd -- "$backup_dir" && sha256sum -c SHA256SUMS >/dev/null); then
      printf '%s\n' 'Local backup checksum verification failed.' >&2
      return 2
    fi
  elif command -v shasum >/dev/null 2>&1; then
    if ! (cd -- "$backup_dir" && shasum -a 256 -c SHA256SUMS >/dev/null); then
      printf '%s\n' 'Local backup checksum verification failed.' >&2
      return 2
    fi
  else
    printf '%s\n' 'sha256sum or shasum is required to validate a local backup.' >&2
    return 2
  fi
  gzip -t -- "$backup_dir/mini-erp.sql.gz"
}

restore_local_database() {
  local backup_name="${2:-}"
  local backup_dir="$project_dir/.runtime/backups/${2:-}"
  local confirmation pre_restore_backup
  [[ "$#" -eq 2 ]] || { printf '%s\n' 'Usage: bash scripts/local.sh restore <backup-name>' >&2; return 2; }
  validate_local_backup "$backup_name" || return $?
  [[ -r /dev/tty ]] || { printf '%s\n' 'Restore requires an interactive terminal confirmation.' >&2; return 2; }
  printf '\nThis replaces the entire local mini_erp_local database.\n' >&2
  printf 'A pre-restore database backup will be created first. Type RESTORE mini_erp_local to continue: ' > /dev/tty
  IFS= read -r confirmation < /dev/tty || return 2
  [[ "$confirmation" == 'RESTORE mini_erp_local' ]] || { printf '%s\n' 'Restore cancelled.' >&2; return 2; }

  restore_wordpress_stopped=1
  docker compose stop wordpress
  pre_restore_backup="$(backup_local_database)" || return $?
  printf 'Pre-restore backup: %s\n' "$pre_restore_backup" >&2
  docker compose exec -T database sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --execute="DROP DATABASE IF EXISTS \`mini_erp_local\`; CREATE DATABASE \`mini_erp_local\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
  if ! gzip -dc -- "$backup_dir/mini-erp.sql.gz" | docker compose exec -T database sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root'; then
    printf 'Database import failed. The pre-restore backup is preserved at %s.\n' "$pre_restore_backup" >&2
    return 1
  fi
  docker compose up -d --wait --wait-timeout 180 wordpress
  restore_wordpress_stopped=0
  docker compose exec -T wordpress php /opt/erp-scripts/health-local.php
  printf 'Database restored from %s. Uploads and plugin source were not changed.\n' "$backup_name"
}

ensure_wordpress_running_after_restore() {
  if [[ "$restore_wordpress_stopped" == '1' ]]; then
    docker compose start wordpress >/dev/null || true
  fi
}
trap ensure_wordpress_running_after_restore EXIT

case "${1:-help}" in
  start)
    docker compose config --quiet
    docker compose build tools
    docker compose up -d --wait --wait-timeout 180 database wordpress
    docker compose run --rm --no-deps tools composer install --working-dir=wp-content/plugins/mini-erp --no-dev --no-interaction --no-progress
    docker compose exec -T wordpress php /opt/erp-scripts/install-local.php
    docker compose run --rm --no-deps tools composer install --no-interaction --no-progress
    ;;
  stop) docker compose stop ;;
  down) docker compose down ;; # Bind-mounted state is retained; no --volumes or deletion.
  backup)
    [[ "$#" -eq 1 ]] || { printf '%s\n' 'Usage: bash scripts/local.sh backup' >&2; exit 2; }
    backup_local_database
    ;;
  restore) restore_local_database "$@" ;;
  health)
    docker compose ps
    docker compose exec -T wordpress php /opt/erp-scripts/health-local.php
    docker compose exec -T wordpress php /opt/erp-scripts/install-local.php --check
    ;;
  test) docker compose run --rm --no-deps tools php vendor/bin/phpunit ;;
  smoke) docker compose run --rm --no-deps tools php scripts/smoke-local.php ;;
  schema-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-master-schema.php ;;
  catalog-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-catalog.php ;;
  catalog-page-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-catalog-page.php ;;
  master-form-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-master-form-pages.php ;;
  master-form-recovery-smoke) node scripts/test-master-form-recovery.js ;;
  sales-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-sales-workflow.php ;;
  inventory-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-inventory-adjustments.php ;;
  reports-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-reports-documents.php ;;
  cash-page-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-cash-pages.php ;;
  ajax-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-ajax-lookups.php ;;
  finance-form-smoke) node scripts/test-finance-form-recovery.js ;;
  inventory-form-smoke) node scripts/test-inventory-form-recovery.js ;;
  order-editor-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-order-editors.php ;;
  user-access-smoke) docker compose exec -T wordpress php /opt/erp-scripts/test-user-access-pagination.php ;;
  user-access-form-smoke) node scripts/test-user-access-form-recovery.js ;;
  logs) docker compose logs --tail 60 database wordpress ;;
    *) printf '%s\n' 'Usage: bash scripts/local.sh {start|stop|down|backup|restore <backup-name>|health|test|smoke|schema-smoke|catalog-smoke|catalog-page-smoke|master-form-smoke|master-form-recovery-smoke|sales-smoke|inventory-smoke|reports-smoke|cash-page-smoke|ajax-smoke|finance-form-smoke|inventory-form-smoke|order-editor-smoke|user-access-smoke|user-access-form-smoke|logs}'; exit 2 ;;
esac
