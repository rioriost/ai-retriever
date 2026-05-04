<?php
declare(strict_types=1);

namespace WPRetliever\Provider;

final class ResultHit
{
    public function __construct(
        public int $post_id,
        public float $score,
        public string $source = "rag",
        public string $snippet = "",
    ) {}
}
