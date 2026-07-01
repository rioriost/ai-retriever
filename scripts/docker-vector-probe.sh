#!/bin/sh
set -eu

STACK="${1:-mariadb}"
COMPOSE="${COMPOSE:-docker compose}"

case "$STACK" in
  mariadb)
    $COMPOSE up -d db-mariadb >/dev/null
    $COMPOSE exec -T db-mariadb mariadb -uwordpress -pwordpress wordpress <<'SQL'
SELECT VERSION() AS version;
DROP TABLE IF EXISTS retriever_vector_probe;
CREATE TABLE retriever_vector_probe (
  id INT NOT NULL AUTO_INCREMENT,
  label VARCHAR(32) NOT NULL,
  embedding VECTOR(3) NOT NULL,
  PRIMARY KEY (id),
  VECTOR INDEX (embedding) M=3 DISTANCE=cosine
);
INSERT INTO retriever_vector_probe (label, embedding) VALUES
  ('x-axis', VEC_FromText('[1,0,0]')),
  ('y-axis', VEC_FromText('[0,1,0]')),
  ('near-x', VEC_FromText('[0.9,0.1,0]'));
EXPLAIN SELECT label, VEC_DISTANCE_COSINE(embedding, VEC_FromText('[1,0,0]')) AS distance
FROM retriever_vector_probe
ORDER BY distance ASC
LIMIT 3;
SELECT label, VEC_DISTANCE_COSINE(embedding, VEC_FromText('[1,0,0]')) AS distance
FROM retriever_vector_probe
ORDER BY distance ASC
LIMIT 3;
DROP TABLE retriever_vector_probe;
SQL
    ;;
  mysql)
    $COMPOSE up -d db-mysql >/dev/null
    $COMPOSE exec -T db-mysql mysql -uwordpress -pwordpress wordpress <<'SQL'
SELECT VERSION() AS version;
SELECT 'MySQL vector dialect is intentionally not assumed by AI Retriever yet.' AS note;
SQL
    ;;
  *)
    echo "Usage: $0 mariadb|mysql" >&2
    exit 2
    ;;
esac
