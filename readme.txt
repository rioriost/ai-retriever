=== AI Retriever ===
Contributors: rioriost
Tags: search, rag, vector search, embeddings, ai
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds RAG-style vector retrieval to WordPress search using native database vector indexes and configurable embedding providers.

== Description ==

AI Retriever adds RAG-style vector retrieval to WordPress search. Published content is embedded, stored in a local vector table, and blended with standard WordPress search results.

External embedding providers are limited to OpenAI and Azure OpenAI. Local or self-hosted providers include Ollama, LM Studio, Infinity, TEI, and Custom HTTP endpoints.

The admin UI lets site owners choose the RAG target language from WordPress-supported locales. The locale list is loaded through the WordPress translation API so it can follow current core language support.

== Installation ==

1. Upload the `ai-retriever` folder to `/wp-content/plugins/`, or install the release ZIP from the WordPress admin.
2. Activate `AI Retriever` from the Plugins screen.
3. Open Settings -> AI Retriever.
4. Run the database capability test.
5. Configure the target language and embedding provider.
6. Test the embedding provider, then initialize the index.

== Frequently Asked Questions ==

= Which external embedding providers are supported? =

OpenAI and Azure OpenAI are supported as external providers. Local/self-hosted OpenAI-compatible endpoints can be configured through the local provider options.

= Does this plugin send content to external APIs? =

Only when an external embedding provider is configured. Post content and any configured custom fields are sent to the selected embedding provider during indexing.

= Does it require native vector database support? =

Yes. MariaDB 11.7 or later is the primary supported target for native vector columns and indexes.

== Changelog ==

= 0.2.0 =
* Rename the public plugin name to AI Retriever.
* Add RAG target language selection.
* Restrict external embedding providers to OpenAI and Azure OpenAI.

== Upgrade Notice ==

= 0.2.0 =
Changing the target language or embedding settings requires reinitializing the vector index.
