#!/bin/sh
set -eu

COMPOSE="${COMPOSE:-docker compose}"
$COMPOSE down -v --remove-orphans
