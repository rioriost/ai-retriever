=== AI Retriever ===
Contributors: rioriost
Tags: search, semantic search, vector search, embeddings, rag
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds RAG-style vector retrieval to WordPress search using native database vector indexes and configurable embeddings.

== Description ==

AI Retriever adds RAG-style vector retrieval to WordPress search. Published content is embedded, stored in a local vector table, and blended with standard WordPress search results.

The plugin is intended for WordPress sites that can use native database vector support, primarily MariaDB 11.7 or later. It can index posts, pages, and configured public post types, then use those vectors during front-end search.

Main features:

* RAG-style search blending with standard WordPress search.
* Native vector storage in the WordPress database.
* OpenAI and Azure OpenAI support for external embedding APIs.
* Local or self-hosted embedding support through Ollama, LM Studio, Infinity, TEI, or Custom HTTP endpoints.
* RAG target language selection from WordPress-supported locales.
* Admin diagnostics for database support, embedding providers, indexing progress, and live vector queries.

External API use is optional. When an external embedding provider is configured, post content and configured custom field values are sent to that provider to create embeddings.

== Installation ==

1. Upload the `ai-retriever` folder to `/wp-content/plugins/`, or install the release ZIP from the WordPress admin.
2. Activate `AI Retriever` from the Plugins screen.
3. Open Settings -> AI Retriever.
4. Run the database capability test.
5. Choose the RAG target language.
6. Configure and test the embedding provider.
7. Initialize the index.

== Frequently Asked Questions ==

= Which external embedding providers are supported? =

OpenAI and Azure OpenAI are supported as external providers.

= Can I use a local embedding server? =

Yes. Ollama, LM Studio, Infinity, TEI, and Custom HTTP endpoints are available for local or self-hosted embeddings.

= Does this plugin send content to external APIs? =

Only when an external embedding provider is configured. Post content and any configured custom fields are sent to the selected embedding provider during indexing.

= Does it require native vector database support? =

Yes. MariaDB 11.7 or later is the primary supported target for native vector columns and indexes.

= What happens when I change the target language or embedding model? =

The vector table must be rebuilt. Run initialization again after changing target language, provider, model, dimensions, or vector distance settings.

== Screenshots ==

No screenshots are included in this release.

== Changelog ==

= 0.2.0 =
* Rename the public plugin name and WordPress.org slug to AI Retriever.
* Add RAG target language selection based on WordPress-supported locales.
* Restrict external embedding providers to OpenAI and Azure OpenAI.
* Add WordPress.org release gates for PHPCS, Plugin Check, readme, i18n, and package contents.

== Upgrade Notice ==

= 0.2.0 =
Changing the target language or embedding settings requires reinitializing the vector index.
