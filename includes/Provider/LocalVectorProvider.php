<?php
declare(strict_types=1);

namespace WPRetriever\Provider;

use WPRetriever\Database\LocalVectorRepository;
use WPRetriever\Embedding\EmbeddingProviderFactory;
use WPRetriever\Settings;
use WPRetriever\TextNormalizer;

final class LocalVectorProvider
{
    public function retrieve(string $query): RetrieveResult
    {
        try {
            $embedder = EmbeddingProviderFactory::make();
            $embedding = $embedder->embed(TextNormalizer::vector_query($query));
            $results = new LocalVectorRepository()->search_with_chunks(
                $embedding,
                $embedder->model(),
                (int) Settings::get("top_k"),
            );
            $min = (float) Settings::get("min_score");
            $hits = [];
            foreach ($results as $post_id => $result) {
                $score = (float) ($result["score"] ?? 0.0);
                if ($score >= $min) {
                    $hits[] = new ResultHit(
                        (int) $post_id,
                        $score,
                        "rag",
                        (string) ($result["chunk_text"] ?? ""),
                    );
                }
            }
            return RetrieveResult::success($hits);
        } catch (\Throwable $e) {
            return RetrieveResult::failure($e->getMessage());
        }
    }
}
