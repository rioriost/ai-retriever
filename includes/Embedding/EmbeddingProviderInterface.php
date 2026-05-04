<?php
declare(strict_types=1);

namespace WPRetliever\Embedding;

interface EmbeddingProviderInterface {
	/** @return float[] */
	public function embed( string $text ): array;
	/** @param string[] $texts @return array<int, float[]> */
	public function embed_many( array $texts ): array;
	public function model(): string;
}
