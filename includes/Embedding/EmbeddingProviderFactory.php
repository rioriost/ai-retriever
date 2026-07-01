<?php
declare(strict_types=1);

namespace RiTriever\Embedding;

use RiTriever\Settings;

final class EmbeddingProviderFactory
{
    public static function make(): EmbeddingProviderInterface
    {
        $provider = (string) Settings::get("embedding_provider");
        if ($provider === "openai") {
            return new OpenAiEmbeddingProvider();
        }
        return new CustomHttpEmbeddingProvider();
    }
}
