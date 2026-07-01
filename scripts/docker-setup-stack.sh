#!/bin/sh
set -eu

STACK="${1:-}"
if [ "$STACK" != "mariadb" ] && [ "$STACK" != "mysql" ]; then
  echo "Usage: $0 mariadb|mysql" >&2
  exit 2
fi

COMPOSE="${COMPOSE:-docker compose}"
HOST="${RITRIEVER_HOST:-192.168.1.35}"
DIMENSIONS="${RITRIEVER_EMBEDDING_DIMENSIONS:-16}"
ADMIN_USER="${RITRIEVER_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${RITRIEVER_ADMIN_PASSWORD:-password}"
ADMIN_EMAIL="${RITRIEVER_ADMIN_EMAIL:-admin@example.test}"

if [ "$STACK" = "mariadb" ]; then
  WP_SERVICE="wp-mariadb"
  WPCLI_SERVICE="wpcli-mariadb"
  DB_SERVICE="db-mariadb"
  PORT="${RITRIEVER_WP_MARIADB_PORT:-8081}"
  SEARCH_MODE="full"
  SYNC_ENABLED="true"
else
  WP_SERVICE="wp-mysql"
  WPCLI_SERVICE="wpcli-mysql"
  DB_SERVICE="db-mysql"
  PORT="${RITRIEVER_WP_MYSQL_PORT:-8082}"
  SEARCH_MODE="off"
  SYNC_ENABLED="false"
fi

URL="http://${HOST}:${PORT}"
SETTINGS_JSON=$(cat <<JSON
{"embedding_provider":"custom_http","custom_embedding_endpoint":"http://embedding-mock:8080/embed","custom_embedding_model":"custom-http-${DIMENSIONS}","embedding_dimensions":${DIMENSIONS},"search_mode":"${SEARCH_MODE}","sync_enabled":${SYNC_ENABLED},"post_types":["post","page"],"post_statuses":["publish"],"display_source_badges":true,"vector_distance":"cosine","vector_index_m":8,"top_k":20}
JSON
)

run_wp() {
  $COMPOSE run --rm "$WPCLI_SERVICE" --path=/var/www/html "$@"
}

$COMPOSE up -d embedding-mock "$DB_SERVICE" "$WP_SERVICE"

echo "Waiting for WordPress files in ${WP_SERVICE}..."
tries=0
until run_wp core version >/dev/null 2>&1; do
  tries=$((tries + 1))
  if [ "$tries" -ge 60 ]; then
    echo "WordPress did not become ready in time." >&2
    exit 1
  fi
  sleep 2
done

if ! run_wp core is-installed >/dev/null 2>&1; then
  run_wp core install \
    --url="$URL" \
    --title="RiTriever ${STACK}" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
fi

# Write settings before activation so the activation-created vector table uses
# the same dimensions as the local embedding mock.
run_wp plugin deactivate ritriever >/dev/null 2>&1 || true
run_wp option update ritriever_settings "$SETTINGS_JSON" --format=json >/dev/null
run_wp plugin activate ritriever

run_wp post create \
  --post_type=post \
  --post_status=publish \
  --post_title="MariaDB vector search smoke test" \
  --post_content="RiTriever stores local embeddings in a native database vector column and blends semantic retrieval with standard WordPress search." \
  >/dev/null

run_wp post create \
  --post_type=post \
  --post_status=publish \
  --post_title="Standard WordPress search smoke test" \
  --post_content="This post is useful for checking lexical search fallback, source badges, and result ordering." \
  >/dev/null

echo "${STACK} stack ready: ${URL}"
echo "Admin: ${ADMIN_USER} / ${ADMIN_PASSWORD}"
if [ "$STACK" = "mysql" ]; then
  echo "MySQL stack is configured with sync/search disabled until native vector support is verified."
fi
