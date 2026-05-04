# Search and Indexing Flow

## Indexing flow

1. `save_post` fires.
2. Ignore autosaves/revisions.
3. Check post type/status/password/exclusion eligibility.
4. Build normalized document text:
   - title
   - excerpt
   - stripped post content
5. Create a content hash including embedding model and dimension.
6. If hash unchanged, skip.
7. Chunk text with overlap.
8. Call embedding provider in batch.
9. Delete existing chunks for the post/model.
10. Insert new chunks with `VECTOR` values via `VEC_FromText()`.
11. Update postmeta sync state.
12. Purge query cache.

## Query flow

1. Front-end main search triggers `pre_get_posts`.
2. Search mode gate runs.
3. Query cache lookup.
4. Vector retrieve:
   - embed user query
   - native vector distance query
   - group chunk results by `post_id`
5. Standard search retrieve:
   - secondary `WP_Query` with `fields=ids`
6. Filter both ID lists through `PostFilter`.
7. Merge via reciprocal rank fusion.
8. Build source map:
   - `rag`
   - `core`
   - `both`
9. Rewrite main query with `post__in` + `orderby=post__in`.
10. Suppress WordPress `s` LIKE clause for rewritten query.
11. Render title badges if enabled.

## Badge behavior

- `[RAG][標準検索]` when found by both retrieval paths.
- `[RAG]` when found only by vector retrieval.
- `[標準検索]` when found only by standard search.
- Controlled by `display_source_badges`.
