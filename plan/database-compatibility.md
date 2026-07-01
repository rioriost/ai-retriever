# Database Compatibility Plan

## Supported targets

### MariaDB 11.7+

MariaDB 11.7 introduces:

- `VECTOR(n)` data type.
- `VECTOR INDEX`.
- `VEC_FromText()`.
- `VEC_DISTANCE()`, `VEC_DISTANCE_COSINE()`, `VEC_DISTANCE_EUCLIDEAN()`.
- HNSW-like approximate nearest neighbor indexing.
- Index options such as `M` and `DISTANCE=cosine|euclidean`.

Initial DDL target:

```sql
CREATE TABLE ritriever_chunks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  chunk_index INT UNSIGNED NOT NULL,
  chunk_text LONGTEXT NOT NULL,
  content_hash CHAR(64) NOT NULL,
  embedding_model VARCHAR(191) NOT NULL,
  embedding VECTOR(1536) NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY post_chunk_model (post_id, chunk_index, embedding_model),
  KEY post_lookup (post_id),
  KEY model_lookup (embedding_model),
  VECTOR INDEX (embedding) M=8 DISTANCE=cosine
);
```

### MySQL 9.x

MySQL 9.x vector support must be validated per target. The scaffold treats MySQL 9.x as a capability candidate, but native vector search is disabled by default. Enable experiments with the `ritriever_mysql_vector_enabled` filter and provide exact DDL through `ritriever_mysql_vector_create_table_sql` only after validating the target server.

Before declaring production support for a MySQL target, verify:

1. Vector column syntax.
2. Vector literal/conversion function syntax.
3. Distance function names.
4. Vector index creation syntax.
5. Whether `ORDER BY distance LIMIT k` uses the vector index.
6. Transaction/insert behavior under WordPress write patterns.

## Explicitly unsupported

- MariaDB < 11.7.
- MySQL 8.0 / 8.4 LTS without native vector indexing.
- BLOB/JSON full-scan vector retrieval as a primary supported mode.

Those can be added later as a separate fallback backend, but they should not share the same performance promises.
