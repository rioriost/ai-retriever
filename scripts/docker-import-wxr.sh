#!/bin/sh
set -eu

STACK="${1:-}"
WXR_PATH="${2:-}"
DELETE_AFTER_IMPORT="${3:-}"
COMPOSE="${COMPOSE:-docker compose}"

if [ "$STACK" != "mariadb" ] && [ "$STACK" != "mysql" ]; then
  echo "Usage: $0 mariadb|mysql path/to/export.xml [--delete-after-import]" >&2
  exit 2
fi

if [ "$WXR_PATH" = "" ]; then
  echo "Usage: $0 mariadb|mysql path/to/export.xml [--delete-after-import]" >&2
  exit 2
fi

case "$WXR_PATH" in
  /*)
    echo "Use a repository-relative WXR path so it is available inside the plugin bind mount." >&2
    exit 2
    ;;
esac

if [ "$DELETE_AFTER_IMPORT" != "" ] && [ "$DELETE_AFTER_IMPORT" != "--delete-after-import" ]; then
  echo "Unknown option: $DELETE_AFTER_IMPORT" >&2
  exit 2
fi

if [ ! -f "$WXR_PATH" ]; then
  echo "WXR file not found: $WXR_PATH" >&2
  exit 1
fi

case "$WXR_PATH" in
  ./*)
    CONTAINER_WXR="/var/www/html/wp-content/plugins/ritriever/${WXR_PATH#./}"
    ;;
  *)
    CONTAINER_WXR="/var/www/html/wp-content/plugins/ritriever/${WXR_PATH}"
    ;;
esac

if [ "$STACK" = "mariadb" ]; then
  WP_SERVICE="wp-mariadb"
  DB_SERVICE="db-mariadb"
  WPCLI_SERVICE="wpcli-mariadb"
else
  WP_SERVICE="wp-mysql"
  DB_SERVICE="db-mysql"
  WPCLI_SERVICE="wpcli-mysql"
fi

$COMPOSE up -d embedding-mock "$DB_SERVICE" "$WP_SERVICE"

run_wp_as_www_data() {
  $COMPOSE run --rm --user 33:33 "$WPCLI_SERVICE" --path=/var/www/html "$@"
}

# The WordPress volume is owned by www-data in the Apache container. Running
# importer operations as uid/gid 33 avoids wp-content/upgrade permission errors.
run_wp_as_www_data plugin install wordpress-importer --activate
run_wp_as_www_data import "$CONTAINER_WXR" --authors=create

if [ "$DELETE_AFTER_IMPORT" = "--delete-after-import" ]; then
  rm -- "$WXR_PATH"
  echo "Deleted imported WXR file: $WXR_PATH"
fi

echo "Imported WXR into ${STACK}: $WXR_PATH"
