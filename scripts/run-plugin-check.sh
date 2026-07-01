#!/bin/sh
set -eu

PLUGIN_SLUG="${PLUGIN_SLUG:-ritriever}"
WP_PATH="${WP_PATH:-/var/www/html}"
PLUGIN_CHECK_FLAGS="${PLUGIN_CHECK_FLAGS:---format=table}"
PLUGIN_ZIP="${PLUGIN_ZIP:-}"

if [ "$PLUGIN_ZIP" != "" ] && [ "${PLUGIN_ZIP#/}" = "$PLUGIN_ZIP" ]; then
  PLUGIN_ZIP="$(pwd)/${PLUGIN_ZIP}"
fi

run_wp() {
  if [ "${WPCLI_COMMAND:-}" != "" ]; then
    # shellcheck disable=SC2086
    $WPCLI_COMMAND "$@"
    return
  fi

  if [ "${WP_CONTAINER:-}" != "" ]; then
    if [ "${WP_ALLOW_ROOT:-1}" = "1" ]; then
      container exec "$WP_CONTAINER" wp --allow-root --path="$WP_PATH" "$@"
    else
      container exec "$WP_CONTAINER" wp --path="$WP_PATH" "$@"
    fi
    return
  fi

  if command -v wp >/dev/null 2>&1; then
    wp --path="${LOCAL_WP_PATH:-$WP_PATH}" "$@"
    return
  fi

  if [ "${COMPOSE:-}" != "" ]; then
    # shellcheck disable=SC2086
    $COMPOSE run --rm "${WPCLI_SERVICE:-wpcli-mariadb}" --path="$WP_PATH" "$@"
    return
  fi

  echo "No WP-CLI runner found. Set WP_CONTAINER, WPCLI_COMMAND, COMPOSE, or install wp." >&2
  exit 1
}

if [ "$PLUGIN_ZIP" != "" ] && [ -f "$PLUGIN_ZIP" ]; then
  if [ "${WP_CONTAINER:-}" != "" ]; then
    CONTAINER_ZIP="/tmp/${PLUGIN_SLUG}.zip"
    container cp "$PLUGIN_ZIP" "${WP_CONTAINER}:${CONTAINER_ZIP}"
    run_wp plugin install "$CONTAINER_ZIP" --force --activate >/dev/null
  else
    run_wp plugin install "$PLUGIN_ZIP" --force --activate >/dev/null
  fi
fi

run_wp plugin is-installed plugin-check >/dev/null 2>&1 || run_wp plugin install plugin-check --activate
run_wp plugin activate plugin-check >/dev/null

# shellcheck disable=SC2086
OUTPUT_FILE="$(mktemp)"
if run_wp plugin check "$PLUGIN_SLUG" $PLUGIN_CHECK_FLAGS >"$OUTPUT_FILE" 2>&1; then
  CHECK_STATUS=0
else
  CHECK_STATUS=$?
fi
cat "$OUTPUT_FILE"
if [ "$CHECK_STATUS" -ne 0 ]; then
  rm -f "$OUTPUT_FILE"
  exit "$CHECK_STATUS"
fi
if awk '$3 == "ERROR" || $3 == "WARNING" { found = 1 } END { exit found ? 0 : 1 }' "$OUTPUT_FILE"; then
  rm -f "$OUTPUT_FILE"
  echo "Plugin Check reported warnings or errors." >&2
  exit 1
fi
rm -f "$OUTPUT_FILE"
