# RiTriever Architecture Plan

## Goal

Create a separate WordPress plugin that performs native-database semantic retrieval using MariaDB 11.7+ (or a MySQL 9.x installation with verified vector type + vector index support), then blends RAG results with standard WordPress search results.

## Core modules

1. **Settings**
   - Search mode: `off`, `shadow`, `a_b_admin`, `full`.
   - Embedding provider: WordPress AI Client, OpenAI/Azure OpenAI fallback, or local/custom HTTP endpoint.
   - Embedding dimensions/model.
   - Native vector index parameters: distance (`cosine` or `euclidean`) and `M`.
   - Badge display option.

2. **Vector schema/capability layer**
   - Detect MariaDB vs MySQL and exact version.
   - MariaDB 11.7+: create `VECTOR(n)` column and `VECTOR INDEX`.
   - MySQL 9.x: require a dialect-specific DDL filter until exact syntax and optimizer behavior are verified on target.

3. **Embedding layer**
   - `EmbeddingProviderInterface`.
   - `OpenAiEmbeddingProvider`.
   - `CustomHttpEmbeddingProvider` for Infinity, Ollama proxies, local services, or vendor-specific embedding APIs.

4. **Indexing layer**
   - Hook `save_post` and `before_delete_post`.
   - Extract post title, excerpt, and stripped content.
   - Chunk text with overlap.
   - Embed each chunk.
   - Replace all chunks for `(post_id, embedding_model)`.

5. **Retrieval/search layer**
   - Vector retrieval: query embedding → `ORDER BY VEC_DISTANCE*()` → top K post IDs.
   - Lexical retrieval: bounded secondary `WP_Query` with native search.
   - Reciprocal rank fusion.
   - Main query rewrite: `post__in` + `orderby=post__in`.
   - Optional source badges above result title.

## Why copy only selected code from wp_rag_search_plugin?

Reused concepts:
- `pre_get_posts` rewrite pattern.
- `posts_search` suppression for rewritten queries.
- source badges.
- query cache shape.
- post eligibility logic.
- settings/defaults/logging shape.

Not reused directly:
- Dify providers.
- Dify diagnostics.
- Dify workflow proxy.
- provider HTTP retrieve contracts.

The new project's center is a local native-vector repository, not an external RAG provider.
